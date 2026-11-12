<?php

namespace Drupal\react_scaffold_builder\Controller;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\CsrfRequestHeaderAccessCheck;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\Plugin\Validation\Constraint\EntityChangedConstraint;
use Drupal\Core\Render\HtmlResponse;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\media_library\MediaLibraryState;
use Drupal\node\NodeInterface;
use Drupal\react_scaffold_builder\ComponentCatalog;
use Drupal\react_scaffold_builder\Form\FormatterSettingsForm;
use Drupal\react_scaffold_builder\LayoutRenderer;
use Drupal\react_scaffold_builder\LayoutStorage;
use Drupal\react_scaffold_builder\MediaItems;
use Drupal\react_scaffold_builder\NodeProperties;
use Drupal\react_scaffold_builder\Paragraphs;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The editor page and its JSON endpoints.
 */
class BuilderController extends ControllerBase {

  public function __construct(
    protected ComponentCatalog $catalog,
    protected LayoutRenderer $layoutRenderer,
    protected LayoutStorage $layouts,
    protected NodeProperties $properties,
    protected MediaItems $mediaItems,
    protected RendererInterface $renderer,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('react_scaffold_builder.catalog'),
      $container->get('react_scaffold_builder.renderer'),
      $container->get('react_scaffold_builder.layouts'),
      $container->get('react_scaffold_builder.node_properties'),
      $container->get('react_scaffold_builder.media_items'),
      $container->get('renderer'),
    );
  }

  /**
   * Access to the editor and to saving: the content type uses the builder,
   * the user has the permission and may edit the node.
   */
  public function access(NodeInterface $node, AccountInterface $account) {
    return AccessResult::allowedIf($this->layouts->isEnabled($node))
      ->addCacheableDependency($node->type->entity)
      ->andIf(AccessResult::allowedIfHasPermission($account, 'use react scaffold builder'))
      ->andIf($node->access('update', $account, TRUE));
  }

  /**
   * Title of the editor page.
   */
  public function title(NodeInterface $node): string {
    return (string) $this->t('Builder: @title', ['@title' => $node->label()]);
  }

  /**
   * The edit page of a node (/node/{node}/edit, see RouteSubscriber).
   *
   * The builder for the content types that use it, when the user may use it;
   * the normal edit form otherwise.
   */
  public function edit(NodeInterface $node): array {
    if ($this->layouts->isEnabled($node) && $this->currentUser()->hasPermission('use react scaffold builder')) {
      return $this->host($node);
    }
    return $this->entityFormBuilder()->getForm($node, 'edit');
  }

  /**
   * The builder page: an admin page that holds the editor in a frame.
   */
  public function host(NodeInterface $node): array {
    return [
      '#type' => 'html_tag',
      '#tag' => 'iframe',
      '#attributes' => [
        'src' => Url::fromRoute('react_scaffold_builder.editor_frame', ['node' => $node->id()])->toString(),
        'class' => ['react-scaffold-builder-frame'],
        'title' => $this->t('Page builder'),
      ],
      '#attached' => ['library' => ['react_scaffold_builder/host']],
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * The node's field widgets as a page of its own, for the Content panel.
   */
  public function contentForm(NodeInterface $node, Request $request): HtmlResponse {
    $node = $this->latest($node);
    // Unsaved values this user typed before (FieldDrafts) are put back.
    $drafted = $this->fieldDrafts()->apply($node);
    return $this->barePage([
      'messages' => ['#type' => 'status_messages'],
      'form' => $this->entityFormBuilder()->getForm($drafted, 'builder_content', [
        'rsb_part' => $request->query->get('part') === 'page' ? 'page' : 'fields',
        // Names of the fields that have unsaved values.
        'rsb_draft' => array_keys($this->fieldDrafts()->get($node)),
      ]),
    ]);
  }

  /**
   * The form of the paragraph of a Paragraph block, as a page of its own.
   *
   * Query: `type` (paragraph type), `id` and `vid` of the paragraph when the
   * block has one, `block` (the id of the block: its unsaved values are kept
   * under it), `key` (passed back to the editor with every message).
   */
  public function paragraphForm(NodeInterface $node, Request $request): HtmlResponse {
    /** @var \Drupal\react_scaffold_builder\Paragraphs $paragraphs */
    $paragraphs = \Drupal::service('react_scaffold_builder.paragraphs');
    $bundle = (string) $request->query->get('type');
    if (!isset($paragraphs->types()[$bundle])) {
      throw new NotFoundHttpException();
    }
    $node = $this->latest($node);
    $langcode = $node->language()->getId();
    $block = (string) $request->query->get('block');
    $paragraph = $paragraphs->load(['id' => $request->query->get('id'), 'vid' => $request->query->get('vid')], $bundle);
    if (!$paragraph) {
      $paragraph = $this->entityTypeManager()->getStorage('paragraph')->create(['type' => $bundle, 'langcode' => $langcode]);
    }
    elseif ($paragraph->hasTranslation($langcode)) {
      $paragraph = $paragraph->getTranslation($langcode);
    }
    elseif ($paragraph->isTranslatable()) {
      // A page in another language: its own translation of the paragraph,
      // starting from the original text.
      $paragraph = $paragraph->addTranslation($langcode, $paragraph->toArray());
    }
    // What this user typed before and has not saved yet is put back.
    $values = \Drupal::service('react_scaffold_builder.paragraph_drafts')->get($node, $block);
    if ($values !== NULL) {
      $paragraph = $paragraph->isNew() ? $paragraph : clone $paragraph;
      \Drupal\react_scaffold_builder\ParagraphDrafts::apply($paragraph, $values);
    }
    return $this->barePage([
      'form' => $this->entityFormBuilder()->getForm($paragraph, 'react_scaffold_builder', [
        'rsb_node' => $node,
        'rsb_block' => $block,
        'rsb_key' => (string) $request->query->get('key'),
      ]),
    ]);
  }

  /**
   * Unsaved field values of the node form, per user.
   */
  protected function fieldDrafts() {
    return \Drupal::service('react_scaffold_builder.field_drafts');
  }

  /**
   * A page without regions, blocks or toolbar: only the given content, with
   * the assets of the active theme.
   */
  protected function barePage(array $content): HtmlResponse {
    $html = ['#type' => 'html', 'page' => $content];
    $page = &$html['page'];
    $this->moduleHandler()->invokeAllWith('page_attachments', function (callable $hook) use (&$page) {
      $hook($page);
    });
    $this->renderer->renderRoot($html);
    $response = new HtmlResponse();
    $response->setContent($html);
    return $response;
  }

  /**
   * The editor, in the frame of the builder page.
   *
   * It is served by the default theme, with the libraries of every component
   * attached, so the preview is the theme's own markup, CSS and behaviors.
   */
  public function editor(NodeInterface $node): array {
    $node = $this->latest($node);

    // Text formats the user may use. Those with an editor get the same
    // CKEditor 5 configuration as on any Drupal form.
    $formats = [];
    $editor_attachments = ['library' => [], 'drupalSettings' => []];
    if ($this->moduleHandler()->moduleExists('filter')) {
      $allowed = filter_formats($this->currentUser());
      if ($this->moduleHandler()->moduleExists('editor')) {
        $editor_attachments = \Drupal::service('plugin.manager.editor')->getAttachments(array_keys($allowed)) + $editor_attachments;
      }
      $with_editor = $editor_attachments['drupalSettings']['editor']['formats'] ?? [];
      foreach ($allowed as $id => $format) {
        $formats[] = ['id' => $id, 'label' => $format->label(), 'editor' => isset($with_editor[$id])];
      }
    }

    $endpoints = [
      'render' => Url::fromRoute('react_scaffold_builder.render')->toString(),
      'save' => Url::fromRoute('react_scaffold_builder.save', ['node' => $node->id()])->toString(),
      'urls' => Url::fromRoute('react_scaffold_builder.urls')->toString(),
      'media' => Url::fromRoute('react_scaffold_builder.media')->toString(),
      'tokens' => Url::fromRoute('react_scaffold_builder.tokens')->toString(),
      'content' => Url::fromRoute('react_scaffold_builder.content_form', ['node' => $node->id()], ['query' => ['part' => 'fields']])->toString(),
      'page' => Url::fromRoute('react_scaffold_builder.content_form', ['node' => $node->id()], ['query' => ['part' => 'page']])->toString(),
      'formatter' => Url::fromRoute('react_scaffold_builder.formatter_settings', ['node' => $node->id()])->toString(),
    ];
    // Drupal's own media library, shown in a frame, when the module is installed.
    if ($this->moduleHandler()->moduleExists('media_library') && ($types = array_keys($this->mediaItems->imageTypes()))) {
      $state = MediaLibraryState::create('react_scaffold_builder.media_library_opener', $types, $types[0], 1);
      $endpoints['mediaLibrary'] = Url::fromRoute('react_scaffold_builder.media_frame', [], ['query' => $state->all()])->toString();
    }

    // Core ships HTMX from 11.2: with it, formatter settings open inline.
    $libraries = ['react_scaffold_builder/editor', 'react_scaffold_builder/field'];
    if (\Drupal::service('library.discovery')->getLibraryByName('core', 'drupal.htmx')) {
      $libraries[] = 'core/drupal.htmx';
      $endpoints['formatterForm'] = Url::fromRoute('react_scaffold_builder.formatter_form', ['node' => $node->id()])->toString();
    }

    $data = $this->layouts->load($node, $this->source($node));
    $delete = $node->toUrl('delete-form', ['language' => $node->language()]);
    $draft = $this->drafts()->get($this->draftKey($node));
    if (is_array($draft)) {
      if (is_array($draft['data'] ?? NULL)) {
        // Components may have changed since the draft was kept.
        $draft['data'] = \Drupal::service('react_scaffold_builder.migrator')->migrate($draft['data']);
      }
      $draft['stale'] = ($draft['revision'] ?? NULL) != $node->getRevisionId();
    }
    $endpoints += [
      'draft' => Url::fromRoute('react_scaffold_builder.draft', ['node' => $node->id()])->toString(),
      'library' => Url::fromRoute('react_scaffold_builder.library')->toString(),
      'paragraph' => Url::fromRoute('react_scaffold_builder.paragraph_form', ['node' => $node->id()])->toString(),
      'preview' => Url::fromRoute('react_scaffold_builder.preview', ['node' => $node->id()])->toString(),
    ];
    $settings = [
      'node' => (int) $node->id(),
      'langcode' => $node->language()->getId(),
      'title' => $node->label(),
      'viewUrl' => $this->viewUrl($node),
      'data' => $data ?: NULL,
      'components' => $this->catalog->editorConfig($node),
      // Panels that show a part of the node form (BuilderContentForm).
      'sections' => array_merge(['content'], $this->hasMenuSettings($node) ? ['menu'] : []),
      'formats' => $formats,
      'defaultFormat' => $formats ? filter_default_format($this->currentUser()) : NULL,
      'translation' => $this->translationInfo($node),
      'viewports' => $this->viewports(),
      // Unsaved state this user left behind, restored by the editor.
      'draft' => is_array($draft) ? $draft : NULL,
      'revision' => (int) $node->getRevisionId(),
      'actions' => [
        'draft' => $this->properties->canSaveDraft($node),
        'publish' => $this->properties->canPublish($node),
        'delete' => $node->access('delete') ? $delete->toString() : NULL,
      ],
      'csrfToken' => \Drupal::csrfToken()->get(CsrfRequestHeaderAccessCheck::TOKEN_KEY),
      'endpoints' => $endpoints,
    ];
    // Other modules add to the editor (the AI assistant does).
    $this->moduleHandler()->alter('react_scaffold_builder_editor_settings', $settings, $node);
    return [
      '#markup' => '<div id="react-scaffold-builder"></div>',
      '#attached' => [
        // The preview gets its HTML from the render endpoint, without assets: what components and Field blocks
        // need is attached here.
        'library' => array_merge($libraries, $this->catalog->libraries(), $editor_attachments['library']),
        'drupalSettings' => $editor_attachments['drupalSettings'] + [
          'reactScaffoldBuilder' => $settings,
        ],
      ],
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * Widths offered in the preview: the breakpoints of the theme (Breakpoint
   * module, <theme>.breakpoints.yml), of the first theme in the chain that
   * declares any.
   *
   * A breakpoint with a minimum width gives that width; one without (the
   * smallest screens) gives a common phone width.
   */
  protected function viewports(): array {
    if (!$this->moduleHandler()->moduleExists('breakpoint')) {
      return [];
    }
    $theme = \Drupal::theme()->getActiveTheme();
    foreach (array_merge([$theme->getName()], array_keys($theme->getBaseThemeExtensions())) as $group) {
      $viewports = [];
      foreach (\Drupal::service('breakpoint.manager')->getBreakpointsByGroup($group) as $breakpoint) {
        $width = 375;
        if (preg_match('/min-width:\s*([\d.]+)(px|em|rem)/', $breakpoint->getMediaQuery(), $match)) {
          $width = (int) round($match[1] * ($match[2] === 'px' ? 1 : 16));
        }
        $viewports[$width] = ['label' => (string) $breakpoint->getLabel(), 'width' => $width];
      }
      if ($viewports) {
        ksort($viewports);
        return array_values($viewports);
      }
    }
    return [];
  }

  /**
   * Whether the node form has menu settings: the Menu UI module, a content
   * type with menus enabled, a user who may edit menus.
   */
  protected function hasMenuSettings(NodeInterface $node): bool {
    return $this->moduleHandler()->moduleExists('menu_ui')
      && $node->type->entity?->getThirdPartySetting('menu_ui', 'available_menus', [])
      && $this->currentUser()->hasPermission('administer menu');
  }

  /**
   * How this page relates to its translations, for the editor.
   *
   * `locked` means the structure cannot be changed here: only translatable
   * props (text and links) are editable.
   */
  protected function translationInfo(NodeInterface $node): array {
    $language = $node->language()->getName();
    $mode = $this->layouts->mode($node);
    // `short` is shown in the title bar of the editor, `notice` is its tooltip.
    $info = ['mode' => $mode, 'language' => $language, 'locked' => FALSE, 'notice' => NULL, 'short' => NULL];
    if (!$node->isTranslatable() || (count($node->getTranslationLanguages()) < 2 && $node->isDefaultTranslation())) {
      return $info;
    }
    if ($mode === 'independent') {
      $info['short'] = $node->isDefaultTranslation() ? "$language · translations are independent copies" : "$language · independent copy, not synced";
      $info['notice'] = $node->isDefaultTranslation()
        ? "Translations of this page are independent: each one is a copy made when it was created. Changes here are not applied to them."
        : "This $language translation has its own layout, copied from the original when the translation was created. The pages are independent: nothing is synced, change anything.";
    }
    elseif ($node->isDefaultTranslation()) {
      $info['short'] = "$language · structure shared with translations";
      $info['notice'] = 'This page has translations that share this structure: adding, moving and removing components applies to every language. Text and links are translated per language.';
    }
    else {
      $info['locked'] = TRUE;
      $source = $node->getUntranslated()->language()->getName();
      $info['short'] = "$language translation · text and links only";
      $info['notice'] = "$language translation: the structure comes from the $source page and cannot be changed here. Text and links can be translated; links picked from site content already point to their $language version.";
    }
    return $info;
  }

  /**
   * Renders one component for the preview.
   *
   * Slots are returned as empty markers: the editor mounts its drop zones in
   * them, so nested components stay editable.
   */
  public function render(Request $request): JsonResponse {
    $input = Json::decode($request->getContent()) ?: [];
    $id = (string) ($input['type'] ?? '');
    $node = !empty($input['node']) ? $this->entityTypeManager()->getStorage('node')->load($input['node']) : NULL;
    if ($node && !$node->access('update')) {
      $node = NULL;
    }
    if ($node) {
      $langcode = (string) ($input['langcode'] ?? '');
      $node = $this->latest($node->hasTranslation($langcode) ? $node->getTranslation($langcode) : $node);
      // What the user typed into the node form and has not saved yet.
      $node = $this->fieldDrafts()->apply($node);
      \Drupal::service('react_scaffold_builder.paragraphs')->showDrafts();
    }

    $slots = [];
    foreach ($this->catalog->slots($id) as $name) {
      $slots[$name] = [
        '#type' => 'inline_template',
        '#template' => '<div data-rsb-slot="{{ name }}"></div>',
        '#context' => ['name' => $name],
      ];
    }
    try {
      $build = $this->layoutRenderer->buildItem(['type' => $id, 'props' => $input['props'] ?? []], $node, $slots);
      $drupal = in_array(ComponentCatalog::parse($id)[0], [ComponentCatalog::FIELD, ComponentCatalog::VIEW, ComponentCatalog::BLOCK, ComponentCatalog::SAVED, ComponentCatalog::PARAGRAPH], TRUE);
      if (!$build && !$drupal) {
        return new JsonResponse(['error' => 'Nothing to show: unknown component or no access.'], 404);
      }
      $html = $build ? (string) $this->renderer->renderInIsolation($build) : '';
      if ($drupal && trim(strip_tags($html, '<img><svg><video><iframe><input><select><button>')) === '') {
        $html = $this->placeholder($id);
      }
    }
    catch (\Throwable $e) {
      // An invalid prop must not take the editor down.
      return new JsonResponse(['error' => $e->getMessage()], 422);
    }
    // Pinned to a corner of its parent: the editor applies this to its own
    // wrapper of the component, so selecting and dragging keep working.
    $values = is_array($input['props'] ?? NULL) ? $input['props'] : [];
    $pinned = \Drupal::service('react_scaffold_builder.features')->positionStyle($this->catalog->definition($id), $values);
    // A responsive background is a style sheet in the head of the page; the
    // editor has no page load between changes and gets the rules here.
    $background = \Drupal::service('react_scaffold_builder.features')->responsiveBackground($this->catalog->definition($id), $values);
    return new JsonResponse(['html' => $html, 'wrapper' => $pinned ?: NULL, 'styles' => $background['css'] ?? NULL]);
  }

  /**
   * What the editor shows for a field, view or block that prints nothing on
   * this page (no value, no results, no access): something to see and select.
   */
  protected function placeholder(string $id): string {
    [$kind, $name] = ComponentCatalog::parse($id);
    $build = [
      '#type' => 'inline_template',
      '#template' => '<div class="rsb-placeholder">{{ label }}</div>',
      '#context' => [
        'label' => match ($kind) {
          ComponentCatalog::FIELD => $this->t('The field "@name" has no value on this page.', ['@name' => $name]),
          ComponentCatalog::VIEW => $this->t('The view "@name" shows nothing here.', ['@name' => $name]),
          ComponentCatalog::PARAGRAPH => $this->t('An empty paragraph. Fill in its form on the right.'),
          ComponentCatalog::SAVED => $this->t('This saved block shows nothing here.'),
          default => $this->t('The block "@name" shows nothing here.', ['@name' => $name]),
        },
      ],
    ];
    return (string) $this->renderer->renderInIsolation($build);
  }

  /**
   * The settings form of a field formatter as a page of its own, for the
   * "Format settings" dialog of a Field block.
   */
  public function formatterSettings(NodeInterface $node, Request $request): HtmlResponse {
    $settings = Json::decode((string) $request->query->get('settings')) ?: [];
    return $this->barePage([
      'messages' => ['#type' => 'status_messages'],
      'form' => $this->formBuilder()->getForm(
        FormatterSettingsForm::class,
        $this->latest($node),
        (string) $request->query->get('field'),
        (string) $request->query->get('formatter'),
        is_array($settings) ? $settings : [],
      ),
    ]);
  }

  /**
   * The same settings form as an HTMX fragment (route option `_htmx_route`).
   */
  public function formatterForm(NodeInterface $node, Request $request): array {
    $settings = Json::decode((string) $request->query->get('settings')) ?: [];
    return $this->formBuilder()->getForm(
      FormatterSettingsForm::class,
      $this->latest($node),
      (string) $request->query->get('field'),
      (string) $request->query->get('formatter'),
      is_array($settings) ? $settings : [],
      TRUE,
    );
  }

  /**
   * Saves the layout as a new revision; `publish` or `draft` in the request
   * also set the state of the page.
   */
  public function save(NodeInterface $node, Request $request): JsonResponse {
    $node = $this->latest($node);
    $input = Json::decode($request->getContent()) ?: [];
    if (!is_array($input['data'] ?? NULL)) {
      return new JsonResponse(['errors' => ['No layout received.']], 400);
    }

    // A text format the user may not use is accepted only for text that was
    // already saved with it, as on a Drupal form where such a field is locked.
    $source = $this->source($node);
    $before = $this->layouts->load($node, $source);
    $kept = array_map('serialize', $this->catalog->htmlValues($before['content'] ?? []));
    $formats = filter_formats($this->currentUser());
    foreach ($this->catalog->htmlValues($input['data']['content'] ?? []) as $pair) {
      if (!isset($formats[$pair[0]]) && !in_array(serialize($pair), $kept, TRUE)) {
        return new JsonResponse(['errors' => [sprintf('You may not use the "%s" text format.', $pair[0])]], 422);
      }
    }

    // A new revision that starts from the latest values of every language:
    // saving one translation must not bring back an older copy of another.
    $node = $this->entityTypeManager()->getStorage('node')->createRevision($node);
    $node->setRevisionUserId($this->currentUser()->id());
    $node->setRevisionCreationTime(\Drupal::time()->getRequestTime());
    $node->setRevisionLogMessage('Edited with the page builder.');
    $node->setRevisionTranslationAffected(TRUE);
    // Paragraphs changed in their form are created, or get a new revision,
    // now; the layout names what was saved.
    $paragraphs = [];
    if ($errors = \Drupal::service('react_scaffold_builder.paragraphs')->persist($input['data'], $node, $paragraphs)) {
      return new JsonResponse(['errors' => $errors], 422);
    }
    $this->layouts->store($node, $input['data'], $source);
    if (!empty($input['draft'])) {
      $this->properties->applyDraft($node);
    }
    elseif (!empty($input['publish'])) {
      $this->properties->applyPublish($node);
    }

    $violations = $node->validate();
    $violations->filterByFieldAccess($this->currentUser());
    if (count($violations)) {
      $errors = [];
      foreach ($violations as $violation) {
        // The "modified by another user" check compares changed times of
        // every translation with the published revision. The revision saved
        // here always starts from the latest one, so the check can only
        // misfire (it does when another language was saved in between).
        if ($violation->getConstraint() instanceof EntityChangedConstraint) {
          continue;
        }
        $errors[] = trim($violation->getPropertyPath() . ': ' . strip_tags((string) $violation->getMessage()), ': ');
      }
      if ($errors) {
        return new JsonResponse(['errors' => $errors], 422);
      }
    }
    $node->save();
    $this->drafts()->delete($this->draftKey($node));

    // Aliases made by Pathauto and the new state are read from storage.
    $langcode = $node->language()->getId();
    $storage = $this->entityTypeManager()->getStorage('node');
    $storage->resetCache([$node->id()]);
    $node = $this->latest($storage->load($node->id())->getTranslation($langcode));
    return new JsonResponse([
      'viewUrl' => $this->viewUrl($node),
      'revision' => (int) $node->getRevisionId(),
      // What Paragraph blocks name now, by block id: the editor follows.
      'paragraphs' => $paragraphs ?: NULL,
    ]);
  }

  /**
   * The library of saved blocks: read one, save a new one, change or delete.
   *
   * - GET ?uuid=: the item a saved block consists of (to make a copy of it);
   * - POST {label, item}: a new saved block;
   * - POST {uuid, item?, label?}: replace or rename one;
   * - POST {uuid, delete: true}: remove one. Pages that link to it show
   *   nothing in its place; copies stay.
   *
   * Answers with the editor's description of the block as a component.
   *
   * With the Paragraphs Library module a Paragraph block is saved as an item
   * of that library, and the UUID is the item's (paragraphsLibrary()). A
   * saved block of the builder's own (SavedBlock) is what other sites, and
   * blocks saved before, have.
   */
  public function library(Request $request): JsonResponse {
    /** @var \Drupal\react_scaffold_builder\Library $library */
    $library = \Drupal::service('react_scaffold_builder.library');
    /** @var \Drupal\react_scaffold_builder\Paragraphs $paragraphs */
    $paragraphs = \Drupal::service('react_scaffold_builder.paragraphs');
    if ($request->isMethod('GET')) {
      $uuid = (string) $request->query->get('uuid');
      // A paragraph of the Paragraphs Library: the copy is a Paragraph block
      // that names its paragraph. Saving the page gives the page its own.
      if (($entry = $paragraphs->libraryItem($uuid)) && ($paragraph = $paragraphs->libraryParagraph($entry))) {
        $item = ['type' => ComponentCatalog::PARAGRAPH . ':' . $paragraph->bundle(), 'props' => ['id' => 'copy', 'paragraph' => $paragraphs::reference($paragraph)]];
        $item = \Drupal::service('react_scaffold_builder.migrator')->migrate(['content' => [$item]])['content'][0];
        return new JsonResponse(['item' => $item, 'label' => $entry->label()]);
      }
      $saved = $library->load($uuid);
      $item = $saved ? $library->item($saved) : NULL;
      return $item ? new JsonResponse(['item' => $item, 'label' => $saved->label()]) : new JsonResponse(['errors' => ['This saved block no longer exists.']], 404);
    }
    $input = Json::decode($request->getContent()) ?: [];
    $entry = isset($input['uuid']) ? $paragraphs->libraryItem((string) $input['uuid']) : NULL;
    $block = is_array($input['item'] ?? NULL) && is_string($input['item']['type'] ?? NULL) ? $input['item'] : NULL;
    // Paragraphs go to the Paragraphs Library when the site has it.
    $to_paragraphs_library = !isset($input['uuid']) && $block && $paragraphs->libraryAvailable()
      && ComponentCatalog::parse($block['type'])[0] === ComponentCatalog::PARAGRAPH;
    if ($entry || $to_paragraphs_library) {
      return $this->paragraphsLibrary($entry, $input, $block, $paragraphs);
    }
    $saved = isset($input['uuid']) ? $library->load((string) $input['uuid']) : NULL;
    if (isset($input['uuid']) && !$saved) {
      return new JsonResponse(['errors' => ['This saved block no longer exists.']], 404);
    }
    if ($saved && !empty($input['delete'])) {
      $saved->delete();
      return new JsonResponse(['deleted' => TRUE]);
    }
    $label = trim((string) ($input['label'] ?? ''));
    $item = is_array($input['item'] ?? NULL) && is_string($input['item']['type'] ?? NULL) ? $input['item'] : NULL;
    if (!$saved && ($label === '' || !$item)) {
      return new JsonResponse(['errors' => ['A name and a block are needed.']], 400);
    }
    if ($item) {
      // As when a page is saved: a text format the user may not use is only
      // accepted for text the saved block already has.
      $kept = $saved && ($before = $library->item($saved)) ? array_map('serialize', $this->catalog->htmlValues([$before])) : [];
      $formats = filter_formats($this->currentUser());
      foreach ($this->catalog->htmlValues([$item]) as $pair) {
        if (!isset($formats[$pair[0]]) && !in_array(serialize($pair), $kept, TRUE)) {
          return new JsonResponse(['errors' => [sprintf('You may not use the "%s" text format.', $pair[0])]], 422);
        }
      }
    }
    $node = $this->libraryNode($input);
    $new = !$saved;
    if ($new) {
      // Saved first: its paragraphs belong to it.
      $saved = $library->create(mb_substr($label, 0, 255), $item);
    }
    if ($item) {
      if ($errors = \Drupal::service('react_scaffold_builder.paragraphs')->persistForLibrary($item, $saved, $node)) {
        if ($new) {
          $saved->delete();
        }
        return new JsonResponse(['errors' => $errors], 422);
      }
      $library->setItem($saved, $item);
    }
    if (!$new && $label !== '') {
      $saved->set('label', mb_substr($label, 0, 255));
    }
    $library->record($saved, (string) ($input['message'] ?? ''));
    $saved->save();
    return new JsonResponse(['component' => $this->catalog->savedComponent($saved) + ['fields' => []], 'uuid' => $saved->uuid()]);
  }

  /**
   * The library of the Paragraphs Library module: saves, updates or deletes
   * an item for the editor, with the permissions of that module.
   */
  protected function paragraphsLibrary(?ContentEntityInterface $entry, array $input, ?array $block, Paragraphs $paragraphs): JsonResponse {
    if ($entry && !empty($input['delete'])) {
      if (!$entry->access('delete')) {
        return new JsonResponse(['errors' => ['You may not delete items of the paragraphs library.']], 403);
      }
      $entry->delete();
      return new JsonResponse(['deleted' => TRUE]);
    }
    $allowed = $entry
      ? $entry->access('update')
      : $this->entityTypeManager()->getAccessControlHandler('paragraphs_library_item')->createAccess();
    if (!$allowed) {
      return new JsonResponse(['errors' => ['You may not change the paragraphs library.']], 403);
    }
    $label = mb_substr(trim((string) ($input['label'] ?? '')), 0, 255);
    if (!$block || (!$entry && $label === '')) {
      return new JsonResponse(['errors' => ['A name and a paragraph are needed.']], 400);
    }
    $node = $this->libraryNode($input);
    $errors = [];
    $entry = $paragraphs->saveToLibrary($entry, $label, $block, $node, trim((string) ($input['message'] ?? '')), $errors);
    if (!$entry) {
      return new JsonResponse(['errors' => $errors], 422);
    }
    return new JsonResponse(['component' => $this->catalog->libraryComponent($entry) + ['fields' => []], 'uuid' => $entry->uuid()]);
  }

  /**
   * The page an item sent to the library is on: the paragraphs in it may
   * have values that are not saved yet, kept for this user and page.
   */
  protected function libraryNode(array $input): ?NodeInterface {
    $node = !empty($input['node']) ? $this->entityTypeManager()->getStorage('node')->load($input['node']) : NULL;
    if (!$node instanceof NodeInterface || !$node->access('update')) {
      return NULL;
    }
    $langcode = (string) ($input['langcode'] ?? '');
    return $this->latest($node->hasTranslation($langcode) ? $node->getTranslation($langcode) : $node);
  }

  /**
   * Keeps or discards the unsaved state of the editor, per user.
   *
   * Private tempstore: it belongs to the user, survives reloads and expires
   * by itself. Nothing is written to the node.
   */
  public function draft(NodeInterface $node, Request $request): JsonResponse {
    $node = $this->latest($node);
    $input = Json::decode($request->getContent()) ?: [];
    if (!empty($input['discard']) || !is_array($input['data'] ?? NULL)) {
      $this->drafts()->delete($this->draftKey($node));
      if (!empty($input['discard'])) {
        $this->fieldDrafts()->forget($node);
        \Drupal::service('react_scaffold_builder.paragraph_drafts')->forget($node);
      }
      return new JsonResponse(['discarded' => TRUE]);
    }
    $time = \Drupal::time()->getRequestTime();
    $this->drafts()->set($this->draftKey($node), [
      // With the component versions of this moment: a draft that is restored
      // after a component changed is migrated like saved content.
      'data' => \Drupal::service('react_scaffold_builder.migrator')->migrate($input['data'], TRUE),
      'revision' => (int) ($input['revision'] ?? $node->getRevisionId()),
      'time' => $time,
      'label' => \Drupal::service('date.formatter')->format($time, 'short'),
    ]);
    return new JsonResponse(['time' => $time]);
  }

  /**
   * The page as visitors would see it, with the user's unsaved layout.
   *
   * Field values typed into the node form are part of it too, once they
   * were autosaved (FieldDrafts).
   */
  public function preview(NodeInterface $node): array {
    $node = $this->latest($node);
    $draft = $this->drafts()->get($this->draftKey($node));
    if (is_array($draft['data'] ?? NULL)) {
      $this->layouts->setPreview($node, $draft['data']);
      $this->messenger()->addWarning($this->t('Preview of your unsaved layout. Visitors still see the saved page.'));
    }
    // Unsaved field values too, then core's preview flag: the node is
    // rendered without the render cache.
    $node = $this->fieldDrafts()->apply($node);
    if (is_array($draft['data'] ?? NULL)) {
      $this->layouts->setPreview($node, $draft['data']);
    }
    \Drupal::service('react_scaffold_builder.paragraphs')->showDrafts();
    $node->in_preview = TRUE;
    $build = $this->entityTypeManager()->getViewBuilder('node')->view($node, 'full');
    if (\Drupal::request()->query->has('frame')) {
      // Inside the preview dialog of the editor: no admin toolbar.
      $build['#attached']['library'][] = 'react_scaffold_builder/preview_frame';
    }
    $build['#cache']['max-age'] = 0;
    return $build;
  }

  /**
   * The per-user store of unsaved editor state.
   */
  protected function drafts() {
    return \Drupal::service('tempstore.private')->get('react_scaffold_builder');
  }

  /**
   * Key of a draft: one per node and language.
   */
  protected function draftKey(NodeInterface $node): string {
    return $node->id() . ':' . $node->language()->getId();
  }

  /**
   * Where "View page" goes: the latest revision when it is not the published one.
   */
  protected function viewUrl(NodeInterface $node): string {
    $options = ['language' => $node->language()];
    if (!$node->isDefaultRevision() && $this->moduleHandler()->moduleExists('content_moderation')) {
      return Url::fromRoute('entity.node.latest_version', ['node' => $node->id()], $options)->toString();
    }
    return $node->toUrl('canonical', $options)->toString();
  }

  /**
   * The revision being edited: the latest one, which with content moderation
   * can be a draft newer than the published revision.
   */
  protected function latest(NodeInterface $node): NodeInterface {
    $storage = $this->entityTypeManager()->getStorage('node');
    $revision_id = $storage->getLatestTranslationAffectedRevisionId($node->id(), $node->language()->getId());
    if ($revision_id && $revision_id != $node->getRevisionId()) {
      $revision = $storage->loadRevision($revision_id);
      if ($revision && $revision->hasTranslation($node->language()->getId())) {
        return $revision->getTranslation($node->language()->getId());
      }
    }
    return $node;
  }

  /**
   * The latest revision of the original language, where a synced translation
   * takes its structure from. NULL when the node is the original.
   */
  protected function source(NodeInterface $node): ?NodeInterface {
    return $node->isDefaultTranslation() ? NULL : $this->latest($node->getUntranslated());
  }

  /**
   * Pages of the site whose path starts with what was typed.
   *
   * URL aliases first (content the user can see is offered as content, so
   * the link follows the page; other aliases as the path), then "/node/N".
   */
  protected function pathSuggestions(string $q, string $langcode, callable $describe): array {
    $nodes = $this->entityTypeManager()->getStorage('node');
    // Typed as in the address bar, with the language prefix: aliases have none.
    if (preg_match('#^/([^/]+)(/.*)?$#', $q, $match) && $this->languageManager()->getLanguage($match[1])) {
      $q = $match[2] ?? '/';
    }
    $found = [];
    $add = function (string $path, ?string $alias = NULL) use (&$found, $nodes, $describe): void {
      if (preg_match('#^/node/(\d+)$#', $path, $match)) {
        $node = $nodes->load($match[1]);
        if ($node && $node->access('view')) {
          $found['entity:node/' . $node->id()] ??= $describe($node);
        }
      }
      elseif ($alias !== NULL) {
        $found[$alias] ??= ['label' => $alias, 'value' => $alias, 'url' => $alias, 'meta' => (string) $this->t('Path')];
      }
    };
    if ($this->entityTypeManager()->hasDefinition('path_alias')) {
      $aliases = $this->entityTypeManager()->getStorage('path_alias');
      $query = $aliases->getQuery()->accessCheck(FALSE)->condition('status', 1)->sort('alias')->range(0, 30);
      if ($q !== '/') {
        $query->condition('alias', $q, 'STARTS_WITH');
      }
      $loaded = $aliases->loadMultiple($query->execute());
      // Aliases of the language being edited come first.
      usort($loaded, static fn ($a, $b): int => ($b->language()->getId() === $langcode) <=> ($a->language()->getId() === $langcode));
      foreach ($loaded as $alias) {
        $add($alias->getPath(), $alias->getAlias());
      }
    }
    if (preg_match('#^/node/(\d+)$#', $q)) {
      $add($q);
    }
    return array_slice(array_values($found), 0, 10);
  }

  /**
   * URL autocomplete: content the user can see, by title or by path.
   *
   * The value is "entity:node/<id>", as Drupal's link field stores it: the
   * renderer turns it into the path of the right translation. With `resolve`
   * it describes such a value instead of searching.
   */
  public function urls(Request $request): JsonResponse {
    $storage = $this->entityTypeManager()->getStorage('node');
    $langcode = (string) $request->query->get('langcode');
    $describe = function (NodeInterface $node) use ($langcode): array {
      $node = $node->hasTranslation($langcode) ? $node->getTranslation($langcode) : $node;
      return [
        'label' => $node->label(),
        'value' => 'entity:node/' . $node->id(),
        'url' => $node->toUrl('canonical', ['language' => $node->language()])->toString(),
        'meta' => $node->type->entity?->label(),
      ];
    };

    if ($resolve = (string) $request->query->get('resolve')) {
      $node = preg_match('#^entity:node/(\d+)$#', $resolve, $match) ? $storage->load($match[1]) : NULL;
      return new JsonResponse($node && $node->access('view') ? $describe($node) : NULL);
    }

    $q = trim((string) $request->query->get('q'));
    if (str_starts_with($q, '/')) {
      return new JsonResponse($this->pathSuggestions($q, $langcode, $describe));
    }
    if (mb_strlen($q) < 2) {
      return new JsonResponse([]);
    }
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('title', $q, 'CONTAINS')
      ->sort('changed', 'DESC')
      ->range(0, 10)
      ->execute();
    return new JsonResponse(array_values(array_map($describe, $storage->loadMultiple($ids))));
  }

  /**
   * Image search for the fallback picker, used without the Media Library module.
   */
  public function media(Request $request): JsonResponse {
    if (!$this->moduleHandler()->moduleExists('media') || !($types = $this->mediaItems->imageTypes())) {
      return new JsonResponse([]);
    }
    $storage = $this->entityTypeManager()->getStorage('media');
    $query = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('bundle', array_keys($types), 'IN')
      ->condition('status', 1)
      ->sort('created', 'DESC')
      ->range(0, 24);
    if ($q = trim((string) $request->query->get('q'))) {
      $query->condition('name', $q, 'CONTAINS');
    }
    $items = array_map(fn ($media) => $this->mediaItems->describe($media), $storage->loadMultiple($query->execute()));
    return new JsonResponse(array_values(array_filter($items)));
  }

  /**
   * Drupal's media library as a page of its own, for the frame in the editor.
   *
   * The page has no regions, blocks or toolbar: only the library. Selecting
   * media ends in MediaLibraryOpener::getSelectionResponse().
   */
  public function mediaFrame(Request $request): HtmlResponse {
    $state = MediaLibraryState::fromRequest($request);
    if ($state->getOpenerId() !== 'react_scaffold_builder.media_library_opener') {
      throw new AccessDeniedHttpException();
    }
    return $this->barePage([
      'library' => \Drupal::service('media_library.ui_builder')->buildUi($state),
      '#attached' => ['library' => ['react_scaffold_builder/media_frame']],
    ]);
  }

  /**
   * Token tree for the token browser and the "[" autocomplete.
   *
   * Types reachable from the root types: the global ones (site, date, current
   * user...) and the entity type the layout belongs to.
   */
  public function tokens(Request $request): JsonResponse {
    $info = \Drupal::token()->getInfo();
    $roots = ['node'];
    foreach ($info['types'] as $type => $definition) {
      if (empty($definition['needs-data'])) {
        $roots[] = $type;
      }
    }
    $types = [];
    $queue = $roots;
    while ($type = array_shift($queue)) {
      if (isset($types[$type]) || !isset($info['types'][$type])) {
        continue;
      }
      $tokens = [];
      foreach ($info['tokens'][$type] ?? [] as $name => $token) {
        $chained = isset($token['type']) && isset($info['types'][$token['type']]) ? $token['type'] : NULL;
        $tokens[] = [
          'name' => (string) $name,
          'label' => (string) ($token['name'] ?? $name),
          'description' => strip_tags((string) ($token['description'] ?? '')),
          'type' => $chained,
          'dynamic' => !empty($token['dynamic']),
        ];
        if ($chained) {
          $queue[] = $chained;
        }
      }
      usort($tokens, static fn (array $a, array $b): int => strnatcasecmp($a['label'], $b['label']));
      $types[$type] = [
        'label' => (string) ($info['types'][$type]['name'] ?? $type),
        'description' => strip_tags((string) ($info['types'][$type]['description'] ?? '')),
        'tokens' => $tokens,
      ];
    }
    return new JsonResponse(['roots' => array_values(array_filter($roots, static fn ($type) => isset($types[$type]))), 'types' => $types]);
  }

}
