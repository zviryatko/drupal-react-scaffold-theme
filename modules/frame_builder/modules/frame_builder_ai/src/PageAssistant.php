<?php

namespace Drupal\frame_builder_ai;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\Dto\HostnameFilterDto;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\Component\Serialization\Json;
use Drupal\Component\Utility\Html;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\node\NodeInterface;
use Drupal\frame_builder\ComponentCatalog;
use Drupal\frame_builder\ComponentFeatures;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\frame_builder\LayoutMigrator;
use Drupal\Core\Utility\Token;
use Drupal\frame_builder\LayoutRenderer;
use Drupal\frame_builder\LayoutStorage;
use Drupal\frame_builder\MediaItems;
use Drupal\frame_builder\ThemeKit;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * The assistant of the page builder: composes a page from the components of
 * the theme, in a conversation.
 *
 * It uses the chat provider and model that are the default in the AI module
 * (/admin/config/ai/settings). The model gets, as its instructions:
 * - what a layout is (the format the builder stores);
 * - every component it may use: name, description, and for each prop its
 *   type, description and choices; its slots;
 * - the design tokens of the theme: spacing scale and palette;
 * - the page: title, content type, language, and its layout as it is now.
 *
 * It answers with a message and, when it changes the page, a whole layout.
 * The model only chooses and fills components. It writes no code, and what
 * it returns goes through LayoutSanitizer, so nothing reaches the editor
 * that an editor could not have made by hand. Nothing is saved here: the
 * editor shows the proposal as an unsaved change that can be undone.
 */
class PageAssistant {

  /**
   * Widgets as the type the model is told a prop has. Props of other
   * widgets (images, formatters, paragraphs...) are not offered to it.
   */
  protected const TYPES = [
    'text' => 'string',
    'textarea' => 'string, may have line breaks',
    'html' => 'string of simple HTML (p, h2, h3, h4, ul, ol, li, a, strong, em, blockquote)',
    'url' => 'string: a path of this site starting with "/", or an absolute URL',
    'select' => 'one of the options',
    'choices' => 'a list (JSON array) of some of the options, each at most once',
    'email' => 'string: an email address',
    'date' => 'string: a date as YYYY-MM-DD',
    'time' => 'string: a time as HH:MM',
    'icon' => 'one of the options',
    'boolean' => 'boolean',
    'color' => 'the name of a colour of the palette',
    'number' => 'number',
    'length' => 'a size of the spacing scale, as {"size": "<name>"}',
    'image' => 'an image of the media library, as {"media": <id found with search_media>}',
    'paragraph' => 'filled in by the person; or a copy of an existing paragraph, as {"copy": <id found with search_paragraphs>}',
  ];

  public function __construct(
    protected AiProviderPluginManager $providers,
    protected ComponentCatalog $catalog,
    protected ThemeKit $themeKit,
    protected LayoutSanitizer $sanitizer,
    protected LayoutMigrator $migrator,
    protected ModuleHandlerInterface $moduleHandler,
    protected LayoutRenderer $layoutRenderer,
    protected RendererInterface $renderer,
    protected MediaItems $mediaItems,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AccountInterface $currentUser,
    protected MetaTags $metaTags,
    protected LayoutStorage $layouts,
    protected Token $token,
  ) {}

  /**
   * How many times the model may use a tool before it has to answer.
   */
  protected const MAX_STEPS = 6;

  /**
   * Whether the AI module has a default provider and model for chat.
   */
  public function isReady(): bool {
    $default = $this->providers->getDefaultProviderForOperationType('chat');
    return !empty($default['provider_id']) && !empty($default['model_id']);
  }

  /**
   * One turn: asks the model and returns its message and proposed layout.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The page (translation) being built.
   * @param array $messages
   *   The conversation: [['role' => 'user'|'assistant', 'content' => ...]],
   *   the last one being the request.
   * @param array $data
   *   The layout as it is in the editor.
   * @param string|null $image
   *   A screenshot of the canvas (JPEG data), for models that take images.
   * @param array $look
   *   What the editor measured of the theme in the browser: colours, fonts,
   *   widths, CSS custom properties.
   *
   * @return array
   *   'message'; 'data' (a layout for the editor, NULL when the model
   *   proposed none); 'steps' (the tools it used); 'check' (it asks to see
   *   a screenshot of the result).
   */
  public function respond(NodeInterface $node, array $messages, array $data, ?string $image = NULL, array $look = []): array {
    $default = $this->providers->getDefaultProviderForOperationType('chat');
    if (empty($default['provider_id']) || empty($default['model_id'])) {
      throw new \RuntimeException('No default chat provider is set in the AI module.');
    }
    $components = $this->catalog->editorConfig($node);
    $current = is_array($data['content'] ?? NULL) ? $data['content'] : [];

    $instructions = $this->instructions($node, $components, $current, $look);
    $provider = $this->providers->createInstance($default['provider_id']);
    $steps = [];
    // The model works in steps: it may look for media and look at what its
    // layout renders to, and gets the results as the next message, until it
    // answers with the final layout.
    for ($step = 0; ; $step++) {
      $chat = [];
      foreach ($messages as $index => $message) {
        $chat_message = new ChatMessage($message['role'], $message['content']);
        // A screenshot of the canvas, sent by the editor with the request.
        if ($image !== NULL && $index === array_key_last($messages) && $message['role'] === 'user') {
          $chat_message->setImageFromBinary($image, 'image/jpeg');
        }
        $chat[] = $chat_message;
      }
      $input = new ChatInput($chat);
      $input->setSystemPrompt($instructions);
      // The AI module checks the links in an answer against the domains the
      // site allows (its hostname filter). By default it reads the answer as
      // HTML or Markdown for that, which would rewrite the JSON asked for
      // here: the answer is data, so it is checked as plain text.
      if (class_exists(HostnameFilterDto::class) && method_exists($input, 'setHostnameFilter')) {
        $input->setHostnameFilter(new HostnameFilterDto(plainTextMode: TRUE));
      }
      $text = (string) $provider->chat($input, $default['model_id'], ['frame_builder'])->getNormalized()->getText();
      $answer = $this->parse($text);
      if ($answer['action'] === NULL || $step >= self::MAX_STEPS) {
        break;
      }
      $result = match ($answer['action']) {
        'search_media' => ['media' => $this->searchMedia((string) ($answer['query'] ?? ''))],
        'search_paragraphs' => ['paragraphs' => $this->searchParagraphs((string) ($answer['query'] ?? ''), is_string($answer['paragraph_type'] ?? NULL) ? $answer['paragraph_type'] : NULL)],
        'search_content' => ['content' => $this->searchContent((string) ($answer['query'] ?? ''), $node)],
        'preview' => $this->preview($node, is_array($answer['layout']) ? $answer['layout'] : [], $components, $current),
        'page_shell' => $this->pageShell($node),
        'list_tokens' => ['tokens' => $this->listTokens(is_string($answer['token_type'] ?? NULL) ? $answer['token_type'] : NULL)],
        default => ['error' => 'Unknown action. Use "search_media", "search_paragraphs", "search_content", "list_tokens", "preview" or "page_shell", or give your final answer.'],
      };
      $steps[] = $answer['action'] . (str_starts_with($answer['action'], 'search_') ? ': ' . ($answer['query'] ?? '') : '') . ($answer['action'] === 'list_tokens' && !empty($answer['token_type']) ? ': ' . $answer['token_type'] : '');
      $messages[] = ['role' => 'assistant', 'content' => $text];
      $messages[] = ['role' => 'user', 'content' => 'Result of ' . $answer['action'] . ":\n" . json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        . ($step + 1 >= self::MAX_STEPS ? "\nThat was your last step: give your final answer now." : '')];
    }

    $result = ['message' => $answer['message'], 'data' => NULL, 'steps' => $steps, 'check' => !empty($answer['check'])];
    if (is_array($answer['layout'])) {
      $content = $this->sanitizer->sanitize($answer['layout'], $components, $current, $node, TRUE);
      $proposed = $this->count($content);
      // A translation that shares the structure of the original: only its
      // texts can differ, whatever the model returned.
      if ($this->layouts->isSyncedTranslation($node)) {
        $content = $this->onlyTexts($current, $content);
        $result['translation'] = TRUE;
      }
      $result['data'] = $this->migrator->migrate(['root' => ['props' => []], 'content' => $content], TRUE);
      $result['dropped'] = $this->count($answer['layout']) - $proposed;
    }
    // Meta tags it proposed become unsaved values of the page settings.
    if (is_array($answer['metatags'] ?? NULL)) {
      $result['metatags'] = $this->metaTags->propose($node, $answer['metatags']) ?: NULL;
    }
    return $result;
  }

  /**
   * The current items with the texts of the proposed ones: what a translation
   * that shares the structure of the original may change.
   */
  protected function onlyTexts(array $current, array $proposed): array {
    $texts = [];
    $collect = function (array $items) use (&$collect, &$texts): void {
      foreach ($items as $item) {
        $texts[(string) ($item['props']['id'] ?? '')] = $item;
        foreach ($item['props'] as $value) {
          if (LayoutMigrator::isItems($value)) {
            $collect($value);
          }
        }
      }
    };
    $collect($proposed);
    $apply = function (array $items) use (&$apply, $texts): array {
      foreach ($items as &$item) {
        $type = (string) ($item['type'] ?? '');
        $from = $texts[(string) ($item['props']['id'] ?? '')] ?? NULL;
        foreach ($this->catalog->translatableProps($type) as $name) {
          // Not the paragraph of a Paragraph block: that is translated in its form.
          if ($name !== 'paragraph' && $from && ($from['type'] ?? '') === $type && array_key_exists($name, $from['props'])) {
            $item['props'][$name] = $from['props'][$name];
          }
        }
        foreach ($item['props'] as $name => $value) {
          if (LayoutMigrator::isItems($value)) {
            $item['props'][$name] = $apply($value);
          }
        }
      }
      return $items;
    };
    return $apply($current);
  }

  /**
   * The model's tool to see which tokens exist.
   *
   * @param string|null $type
   *   A token type ("node", "site", "user", ...). Without one: the tokens of
   *   the page (node), the site and the date.
   *
   * @return array
   *   Up to eighty of [token, name, description]. A token that leads to more
   *   tokens says of which type: [node:author] is of the type "user", so
   *   [node:author:display-name] exists.
   */
  public function listTokens(?string $type = NULL): array {
    $info = $this->token->getInfo();
    // The Token module names the date of the request "current-date", core "date".
    $types = $type !== NULL && isset($info['types'][$type]) ? [$type] : ['node', 'site', isset($info['types']['current-date']) ? 'current-date' : 'date'];
    $tokens = [];
    foreach ($types as $name) {
      // A type can be an alias: "current-date" has the tokens of "date".
      $source = $info['types'][$name]['type'] ?? $name;
      foreach ($info['tokens'][$source] ?? [] as $token => $definition) {
        $entry = ['token' => "[$name:$token]", 'name' => (string) ($definition['name'] ?? $token)];
        if (!empty($definition['description'])) {
          $entry['description'] = mb_substr(strip_tags((string) $definition['description']), 0, 140);
        }
        if (!empty($definition['type']) && isset($info['types'][$definition['type']])) {
          $entry['more_tokens_of_type'] = $definition['type'];
        }
        if (!empty($definition['dynamic'])) {
          $entry['token'] = "[$name:$token:?]";
        }
        $tokens[] = $entry;
      }
    }
    return array_slice($tokens, 0, 80);
  }

  /**
   * The model's tool to find images: media items by words in their name,
   * their alternative text or their file name. Without words: the newest.
   *
   * @return array
   *   Up to twelve of [id, name, alt, file, width, height], which the user
   *   may see.
   */
  public function searchMedia(string $query): array {
    $types = $this->mediaItems->imageTypes();
    if (!$types) {
      return [];
    }
    $storage = $this->entityTypeManager->getStorage('media');
    $ids = $storage->getQuery()->accessCheck(TRUE)->condition('bundle', array_keys($types), 'IN')->condition('status', 1)->sort('changed', 'DESC')->range(0, 12);
    // Any of the words: models ask in sentences.
    $words = array_filter(preg_split('/[^\p{L}\p{N}]+/u', $query), static fn (string $word): bool => mb_strlen($word) > 2);
    if ($words) {
      $any = $ids->orConditionGroup();
      foreach (array_slice($words, 0, 6) as $word) {
        $any->condition('name', $word, 'CONTAINS');
        foreach (array_unique($types) as $field) {
          $any->condition("$field.alt", $word, 'CONTAINS');
          $any->condition("$field.entity.filename", $word, 'CONTAINS');
        }
      }
      $ids->condition($any);
    }
    $found = [];
    foreach ($storage->loadMultiple($ids->execute()) as $media) {
      // The query checks no permission for media: the entity does.
      if ($media->access('view') && ($described = $this->mediaItems->describe($media))) {
        $item = $media->get($types[$media->bundle()])->first();
        $found[] = [
          'id' => $described['id'],
          'name' => (string) $described['label'],
          'alt' => $described['alt'],
          'file' => basename($described['url']),
          'width' => (int) $item->width,
          'height' => (int) $item->height,
        ];
      }
    }
    return $found;
  }

  /**
   * The model's tool to find existing paragraphs, by words in their text.
   *
   * @return array
   *   Up to ten of [id, paragraph_type, text], which the user may see. A
   *   Paragraph block of the same type can start as a copy of one.
   */
  public function searchParagraphs(string $query, ?string $bundle = NULL): array {
    if (!$this->entityTypeManager->hasDefinition('paragraph')) {
      return [];
    }
    $storage = $this->entityTypeManager->getStorage('paragraph');
    $fields = [];
    foreach (\Drupal::service('entity_field.manager')->getFieldStorageDefinitions('paragraph') as $name => $definition) {
      if (!$definition->isBaseField() && in_array($definition->getType(), ['string', 'string_long', 'text', 'text_long', 'text_with_summary'], TRUE)) {
        $fields[] = $name;
      }
    }
    $ids = $storage->getQuery()->accessCheck(TRUE)->condition('status', 1)->sort('id', 'DESC')->range(0, 30);
    if ($bundle) {
      $ids->condition('type', $bundle);
    }
    $words = array_filter(preg_split('/[^\p{L}\p{N}]+/u', $query), static fn (string $word): bool => mb_strlen($word) > 2);
    if ($words && $fields) {
      $any = $ids->orConditionGroup();
      foreach (array_slice($words, 0, 6) as $word) {
        foreach ($fields as $field) {
          $any->condition("$field.value", $word, 'CONTAINS');
        }
      }
      $ids->condition($any);
    }
    $found = [];
    foreach ($storage->loadMultiple($ids->execute()) as $paragraph) {
      if (count($found) >= 10 || !$paragraph->access('view', $this->currentUser)) {
        continue;
      }
      $text = [];
      foreach ($fields as $field) {
        if ($paragraph->hasField($field) && !$paragraph->get($field)->isEmpty()) {
          $text[] = trim(preg_replace('/\s+/', ' ', strip_tags((string) $paragraph->get($field)->value)));
        }
      }
      // The same text again (older copies of a paragraph) is listed once.
      $summary = mb_substr(implode(' | ', array_filter($text)), 0, 240);
      $found[$paragraph->bundle() . ':' . $summary] ??= ['id' => (int) $paragraph->id(), 'paragraph_type' => $paragraph->bundle(), 'text' => $summary];
    }
    return array_values($found);
  }

  /**
   * The model's tool to find pages to link to: content by words in its title.
   *
   * @return array
   *   Up to ten of [title, type, path], which the user may see.
   */
  public function searchContent(string $query, NodeInterface $page): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $ids = $storage->getQuery()->accessCheck(TRUE)->condition('status', 1)->sort('changed', 'DESC')->range(0, 10);
    $words = array_filter(preg_split('/[^\p{L}\p{N}]+/u', $query), static fn (string $word): bool => mb_strlen($word) > 2);
    if ($words) {
      $any = $ids->orConditionGroup();
      foreach (array_slice($words, 0, 6) as $word) {
        $any->condition('title', $word, 'CONTAINS');
      }
      $ids->condition($any);
    }
    $langcode = $page->language()->getId();
    $found = [];
    foreach ($storage->loadMultiple($ids->execute()) as $node) {
      $node = $node->hasTranslation($langcode) ? $node->getTranslation($langcode) : $node;
      $found[] = ['title' => (string) $node->label(), 'type' => (string) $node->type->entity?->label(), 'link' => 'entity:node/' . $node->id()];
    }
    return $found;
  }

  /**
   * The model's tool to see where the layout sits: the page as the frontend
   * theme renders it around the layout (header, navigation, title, sidebars,
   * footer), as simplified HTML with a mark where the layout goes.
   */
  public function pageShell(NodeInterface $node): array {
    $request = \Drupal::request();
    $layouts = \Drupal::service('frame_builder.layouts');
    try {
      // The page with one line of text for a layout.
      $layouts->setPreview($node, ['content' => [['type' => 'frame_builder:nothing', 'props' => ['id' => 'nothing']]]]);
      $sub = Request::create($node->toUrl('canonical', ['language' => $node->language()])->toString(), 'GET', [], $request->cookies->all(), [], $request->server->all());
      if ($request->hasSession()) {
        $sub->setSession($request->getSession());
      }
      $html = (string) \Drupal::service('http_kernel')->handle($sub, HttpKernelInterface::SUB_REQUEST)->getContent();
    }
    catch (\Throwable $e) {
      return ['error' => 'The page could not be rendered: ' . $e->getMessage()];
    }
    finally {
      $layouts->clearPreview($node);
    }
    // Structure and text only: tags with their class, role and link, without
    // what is there for the logged-in user (toolbar, contextual links, tabs).
    $body = $this->simplify(Html::load(preg_match('#<body\b[^>]*>(.*)</body>#is', $html, $match) ? $match[1] : $html)->getElementsByTagName('body')->item(0));
    return ['html' => mb_substr($body, 0, 9000), 'note' => 'An empty layout is shown here; the builder puts the layout of the page in the main content, where the page shows the fields of the content otherwise.'];
  }

  /**
   * The model's tool to check its work: what a layout renders to.
   *
   * @return array
   *   'html' (the page's markup, without what does not matter for a check),
   *   'left_out' (how many proposed blocks are not components of the site)
   *   and 'error' when rendering failed.
   */
  public function preview(NodeInterface $node, array $layout, array $components, array $current): array {
    $content = $this->sanitizer->sanitize($layout, $components, $current, $node);
    $result = ['left_out' => $this->count($layout) - $this->count($content)];
    try {
      $build = $this->layoutRenderer->build(['content' => $content], $node);
      $html = (string) $this->renderer->renderInIsolation($build);
      // What a reader of the markup needs: structure, classes, text, links and
      // images. No scripts, styles sheets, comments or data attributes.
      $html = preg_replace(['#<(script|style|svg|noscript)\b.*?</\1>#is', '/<!--.*?-->/s', '/\s(data-[\w-]+|id|srcset|sizes|loading|width|height|hreflang|typeof|property)="[^"]*"/', '/\s+/'], ['', '', '', ' '], $html);
      $result['html'] = mb_substr(trim($html), 0, 12000);
    }
    catch (\Throwable $e) {
      $result['error'] = $e->getMessage();
    }
    return $result;
  }

  /**
   * An element as short HTML: its tags with class, role and link, and its
   * text. Scripts, styles, pictures' sources and the administrative parts of
   * a page are left out.
   */
  protected function simplify(?\DOMNode $node): string {
    if (!$node) {
      return '';
    }
    $html = '';
    foreach ($node->childNodes as $child) {
      if ($child instanceof \DOMText) {
        $text = trim(preg_replace('/\s+/', ' ', $child->nodeValue));
        $html .= $text === '' ? '' : htmlspecialchars($text) . ' ';
        continue;
      }
      if (!$child instanceof \DOMElement || in_array($child->nodeName, ['script', 'style', 'svg', 'noscript', 'template', 'source', 'link', 'meta'], TRUE)) {
        continue;
      }
      $class = $child->getAttribute('class');
      if (preg_match('/(^|\s)(admin-toolbar\S*|toolbar\S*|top-bar\S*|contextual|contextual-links|visually-hidden|tabs|local-tasks|messages\S*)(\s|$)/', $class) || in_array($child->getAttribute('id'), ['toolbar-administration', 'admin-toolbar'], TRUE) || $child->hasAttribute('data-contextual-id')) {
        continue;
      }
      $attributes = '';
      foreach (['class', 'role', 'href', 'aria-label'] as $name) {
        if ($child->getAttribute($name) !== '') {
          // Links without their query: it can hold a token of the session.
          $value = $name === 'href' ? explode('?', $child->getAttribute($name))[0] : $child->getAttribute($name);
          $attributes .= ' ' . $name . '="' . htmlspecialchars(mb_substr($value, 0, 80)) . '"';
        }
      }
      $inner = $this->simplify($child);
      // Wrappers that only hold one thing say nothing.
      if ($attributes === '' && in_array($child->nodeName, ['div', 'span'], TRUE)) {
        $html .= $inner;
        continue;
      }
      $html .= '<' . $child->nodeName . $attributes . '>' . trim($inner) . (in_array($child->nodeName, ['img', 'br', 'hr', 'input'], TRUE) ? '' : '</' . $child->nodeName . '>') . ' ';
    }
    return $html;
  }

  /**
   * What the model is told about the translation it works on, empty for the
   * original language of a page.
   */
  protected function translationNote(NodeInterface $node): string {
    if ($node->isDefaultTranslation()) {
      return '';
    }
    $language = $node->language()->getName();
    $source = $node->getUntranslated()->language()->getName();
    if ($this->layouts->isSyncedTranslation($node)) {
      return <<<NOTE

# This is a translation
This page is the $language translation of a $source page, and shares its structure: components cannot be added,
removed, moved or restyled here, only their texts and links differ. Texts that are still in $source have not been
translated yet. When asked to translate or to change text: return the whole layout as it is, with the same items and
the same ids, and change only the text and link props. Write in $language. Keep tokens ([node:title]) and HTML tags
as they are, translate what is around them. Everything else you change is ignored.

NOTE;
    }
    return <<<NOTE

# This is a translation
This page is the $language translation of a $source page, with a layout of its own. Write in $language.

NOTE;
  }

  /**
   * What the model is told about the meta tags of the page, empty when the
   * page has none (no Metatag field on its content type).
   */
  protected function metaTagsNote(NodeInterface $node): string {
    if (!$this->metaTags->field($node) || !($offered = $this->metaTags->offered())) {
      return '';
    }
    $encode = static fn (mixed $value): string => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return <<<NOTE

# Meta tags of the page
The page has meta tags for search engines and social networks. You may propose values for these, as plain text; tokens
work in them:
{$encode($offered)}
Set on this page now (empty: the defaults of the site apply):
{$encode((object) array_intersect_key($this->metaTags->current($node), $offered))}
To propose values, add "metatags": {"<tag>": "<text>"} to your final answer, with only the tags you want to set. Do it
when asked for meta tags or SEO, and when you build a page from nothing. Base them on what the page says, in its language.

NOTE;
  }

  /**
   * The instructions (system prompt) for the model.
   */
  public function instructions(NodeInterface $node, array $components, array $current, array $look = []): string {
    $catalog = [];
    foreach ($components as $component) {
      // A component the theme replaced stays on pages that have it, and is not one to build with.
      if (!empty($component['hidden']) && ComponentCatalog::parse($component['id'])[0] !== ComponentCatalog::PARAGRAPH) {
        continue;
      }
      $entry = ['type' => $component['id'], 'name' => $component['label'], 'group' => $component['group']];
      // Fields of the page and blocks of Drupal are many and alike: their
      // name says what they show, and their two props are described once.
      if (in_array(ComponentCatalog::parse($component['id'])[0], [ComponentCatalog::FIELD, ComponentCatalog::BLOCK], TRUE)) {
        $catalog[] = $entry;
        continue;
      }
      if (!empty($component['description'])) {
        $entry['description'] = mb_substr((string) $component['description'], 0, 300);
      }
      foreach ($component['fields'] as $field) {
        // Features are described once, below; other widgets are not offered.
        if (str_starts_with($field['name'], '_') || !($prop = $this->describeField($field))) {
          continue;
        }
        $entry['props'][$field['name']] = $prop;
      }
      foreach ($component['slots'] as $slot) {
        $entry['slots'][$slot['name']] = $slot['label'];
      }
      if (!empty($component['example'])) {
        $entry['example'] = $component['example'];
      }
      $catalog[] = $entry;
    }
    $context = [
      'title' => (string) $node->label(),
      'content_type' => (string) $node->type->entity?->label(),
      'language' => $node->language()->getId(),
    ];
    // The UI kit of the theme: what the names stand for, so that choices are
    // made knowing how large "l" is and what colour "green" is.
    $tokens = [
      'spacing_sizes' => $this->themeKit->spacing(),
      'font_sizes' => $this->themeKit->fontSizes(),
      'sizes' => $this->themeKit->sizes(),
      'border_widths' => $this->themeKit->borderWidths(),
      'corner_radii' => $this->themeKit->radii(),
      'colors' => array_map(static fn (array $color): string => $color['label'] . ' ' . $color['value'], $this->themeKit->colors()),
      'image_styles' => array_values($this->themeKit->imageStyles()),
      'features_on_every_item' => array_keys(array_filter(array_combine(ComponentFeatures::FEATURES, array_map(fn (string $feature): bool => $this->themeKit->featureDefault($feature), ComponentFeatures::FEATURES)))),
    ];
    // What the editor measured in the browser, on the page of the theme.
    $look = array_filter([
      'page_background' => $look['background'] ?? NULL,
      'text_color' => $look['color'] ?? NULL,
      'text_font' => $look['font'] ?? NULL,
      'text_size' => $look['fontSize'] ?? NULL,
      'heading_font' => $look['headingFont'] ?? NULL,
      'link_color' => $look['linkColor'] ?? NULL,
      'width_of_the_layout_area' => $look['width'] ?? NULL,
      'screen_widths_of_the_theme' => $look['viewports'] ?? NULL,
      'css_custom_properties' => is_array($look['variables'] ?? NULL) ? array_slice($look['variables'], 0, 60, TRUE) : NULL,
    ], static fn ($value): bool => is_scalar($value) ? trim((string) $value) !== '' : !empty($value));
    $encode = static fn (mixed $value): string => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $instructions = <<<PROMPT
You are the assistant of a page builder in a Drupal site. You compose and change the layout of one page by choosing
and filling in components that already exist. You never write code, CSS, scripts or new components, and you never
invent component types, props or options: use only what is listed below.

# The layout

A layout is a list of items. An item is {"type": "<component type>", "props": {...}}.
- "props" holds a value for each prop of the component you want to set, and for each slot a list of items (the
  components inside it). Leave out props you have no value for.
- Give every item a "props.id": keep the id of an item you keep from the current layout, so that what you cannot see
  of it (its images, settings) stays; give a new item a new short id of your own.
- Every item may also have these optional props:
  "_spacing": {"margin": {"size": "<size or auto>", "sides": "all|horizontal|vertical"}, "padding": {...}}
  "_size": {"max_width": {"size": "<a size name>"}, "min_height": {"size": "<a size name>"}}: the item is at most
    that wide and at least that tall; either can be left out. Also "min_width", "exact_width", "max_height",
    "exact_height"; prefer "max_width" for widths, an exact width does not shrink on a narrow screen
  "_align": "start" | "center" | "end"
  "_background": {"color": "<a background colour name>"}
  "_border": {"width": {"size": "<a border width name>"}, "sides": "all|top|bottom|start|end|vertical|horizontal",
    "style": "solid|dashed|dotted|double", "color": "<a colour name>", "radius": {"size": "<a corner radius name>"}}: a
    line around the item or on some sides, and rounded corners; "radius" alone rounds the corners without a line
  "_text": {"size": "<a font size name>", "weight": "normal|medium|bold", "italic": true, "transform":
    "uppercase|lowercase|capitalize", "leading": "tight|normal|loose", "color": "<a colour name>"}: the look of the text
    in the item; any part can be left out. Use it sparingly, for emphasis; components have their own sizes.
- Text props may contain tokens such as [node:title] or [site:name]; they are replaced when the page is shown.
- Images come from the media library of the site only. Find them with the search_media tool and use the id:
  {"media": 12}. Never write an image URL. A background image: "_background": {"image": {"media": 12}, "size": "cover"}.
  If nothing fitting is in the library, leave the image out and say so in your message.
- Position, optional on every item: "_position": {"mode": "relative", "place": "<top|middle|bottom>-<left|center|right>"}
  places the item in the free space of its spot; {"mode": "absolute", "corner": "<top|bottom>-<left|right>",
  "offset": {"size": "<size>"}} pins it on a corner of the item it is in (badges, labels).
- Layout: put items side by side or in a rhythm with the components of the group Layout (their props are the layout
  options: columns, direction, gap, alignment, wrap), and nest them. Use spacing from the scale.
- Components of the group Library are blocks saved for reuse on this site: prefer one when it fits the request.
- Components of the groups Fields, Views and Blocks show existing content of the site: use them where the request
  asks for that content, do not make up their content.
- Components of the group Paragraphs are filled in by the person, in a form: you cannot write their content. You can
  start one as a copy of an existing paragraph (search_paragraphs). Add an empty one only when asked for it, and say
  that it needs filling in. Keep the ones that are on the page.
- Components of the group Fields take "label": "hidden", "above", "inline" or "visually_hidden" (where the name of the
  field is shown), and optionally "label_style", with the same parts as "_text", for the look of that name. Components of the group Blocks take one optional prop, "title".
- Most components have an "example" of a typical use: follow its shape.

# Design tokens of the theme (its UI kit)
{$encode($tokens)}
Sizes are used by name ("l"), colours by name ("green"). Choose colours that give contrast with the page background.

# The look of the frontend theme
{$encode($look ?: ['unknown' => 'not measured'])}
The layout is shown inside the page of the theme: its header, navigation, page title and footer are around it. Use the
page_shell tool to see that page when it matters (for example whether the theme already prints the title of the page,
so that the layout should not repeat it).

# Components you may use
{$encode($catalog)}

# The page
{$encode($context)}

# The current layout of the page
{$encode($current)}
{$this->translationNote($node)}{$this->metaTagsNote($node)}
# Tools

Before your final answer you may use a tool, one per message, up to six times. To use one, answer with only:
{"action": "search_media", "query": "<words; empty for the newest>"}
  Images of the media library whose name, alternative text or file name has one of the words. You get up to twelve:
  id, name, alternative text, file name, width and height.
{"action": "search_paragraphs", "query": "<words; empty for the newest>", "paragraph_type": "<optional: a paragraph type>"}
  Existing paragraphs with one of the words in their text: id, paragraph_type, text. To reuse the content of one, add
  an item of the type "drupal_paragraph:<paragraph_type>" with "paragraph": {"copy": <id>}: the block starts as a copy
  that the person can then change.
{"action": "search_content", "query": "<words in the title; empty for the newest>"}
  Pages of the site to link to: title, type and "link". Use the "link" value as it is in link props.
{"action": "list_tokens", "token_type": "<optional: node, site, user, ...>"}
  The tokens that exist: without a type those of the page, the site and the date. Each with its name; one that leads
  to more tokens says of which type. Ask before using a token you are not sure exists.
{"action": "page_shell"}
  The HTML of the page as the theme renders it around the layout: header, navigation, title, sidebars, footer.
{"action": "preview", "layout": [<a whole layout>]}
  You get the HTML the layout renders to on this site, and how many of your items were left out because they are not
  components of the site. Use it to check your work when the page is not trivial: that nothing was left out, that the
  structure and texts are what you meant. Then fix the layout and give your final answer.

# Your answer

Answer with one JSON object and nothing else, no code fence:
{"message": "<a short answer to the person, in the language they write in: what you did, or a question>",
 "layout": [<the whole new layout: every item of the page, not only the changed ones>],
 "check": <true to be shown a screenshot of the result, to look at it and correct it; else false>}
Use "layout": null when you only answer or ask something and the page stays as it is.
When a message comes with a screenshot, it shows the page as it looks in the editor now.
PROMPT;
    // Sites can add their own guidance: tone of voice, which components to prefer.
    $this->moduleHandler->alter('frame_builder_ai_instructions', $instructions, $node);
    return $instructions;
  }

  /**
   * A field as the model is told about it: what a value is, its label and
   * description, options, an example. For a list, what each value is; for a
   * list of groups and for a group, their fields. NULL for a widget that is
   * not offered to the model.
   */
  protected function describeField(array $field, int $depth = 0): ?array {
    $widget = $field['widget'] ?? 'text';
    if (in_array($widget, ['list', 'group'], TRUE)) {
      $prop = ['type' => $widget === 'list' ? 'a list (JSON array)' . (!empty($field['max']) ? ' of at most ' . $field['max'] : '') : 'an object'];
      if (isset($field['fields'])) {
        $parts = [];
        foreach ($field['fields'] as $sub) {
          if ($depth < 3 && ($described = $this->describeField($sub, $depth + 1))) {
            $parts[$sub['name']] = $described;
          }
        }
        if (!$parts) {
          return NULL;
        }
        $prop[$widget === 'list' ? 'each_item_is_an_object_with' : 'properties'] = $parts;
      }
      elseif (!($prop['each_item'] = $this->describeField($field['item'] ?? [], $depth + 1))) {
        return NULL;
      }
    }
    elseif ($type = self::TYPES[$widget] ?? NULL) {
      $prop = ['type' => $type];
    }
    else {
      return NULL;
    }
    $prop['label'] = $field['label'] ?? $field['name'] ?? '';
    if (!empty($field['description'])) {
      $prop['description'] = mb_substr((string) $field['description'], 0, 300);
    }
    if (!empty($field['options'])) {
      $prop['options'] = array_column($field['options'], 'label', 'value');
    }
    if (!empty($field['required'])) {
      $prop['required'] = TRUE;
    }
    // What the component starts with: tells what a value looks like.
    if ((is_scalar($field['default'] ?? NULL) && $field['default'] !== '') || (is_array($field['default'] ?? NULL) && $field['default'] && $depth === 0)) {
      $prop['example'] = $field['default'];
    }
    return $prop;
  }

  /**
   * Reads the model's answer: the JSON object in it.
   *
   * @return array
   *   'message', 'layout' (a list of items or NULL), and 'action' when the
   *   model asks for a tool instead of answering.
   */
  public function parse(string $text): array {
    $json = trim($text);
    // Models like to wrap JSON in a code fence, or to say a word around it.
    if (preg_match('/```(?:json)?\s*(.+?)```/s', $json, $match)) {
      $json = trim($match[1]);
    }
    $start = strpos($json, '{');
    $end = strrpos($json, '}');
    $answer = $start !== FALSE && $end !== FALSE ? Json::decode(substr($json, $start, $end - $start + 1)) : NULL;
    if (!is_array($answer)) {
      // JSON that does not parse (cut off, most often): not shown as it is.
      if ($start !== FALSE && str_contains($json, '"layout"')) {
        return ['message' => 'The answer of the model could not be read, so nothing was changed. Ask again, or for a smaller change.', 'layout' => NULL, 'action' => NULL];
      }
      // Not JSON: the text is the answer, and nothing changes.
      return ['message' => trim($text), 'layout' => NULL, 'action' => NULL];
    }
    $layout = $answer['layout'] ?? NULL;
    // A layout given as {"content": [...]}, as the builder stores it.
    if (is_array($layout) && isset($layout['content']) && is_array($layout['content'])) {
      $layout = $layout['content'];
    }
    return [
      'message' => is_string($answer['message'] ?? NULL) ? trim($answer['message']) : '',
      'layout' => is_array($layout) && array_is_list($layout) ? $layout : NULL,
      // A tool the model wants to use instead of answering.
      'action' => is_string($answer['action'] ?? NULL) ? $answer['action'] : NULL,
      'query' => $answer['query'] ?? NULL,
      'paragraph_type' => $answer['paragraph_type'] ?? NULL,
      'token_type' => $answer['token_type'] ?? NULL,
      'metatags' => $answer['metatags'] ?? NULL,
      'check' => !empty($answer['check']),
    ];
  }

  /**
   * Number of items in a list, nested ones included.
   */
  protected function count(array $items): int {
    $count = 0;
    foreach ($items as $item) {
      if (!is_array($item)) {
        continue;
      }
      $count++;
      foreach (is_array($item['props'] ?? NULL) ? $item['props'] : [] as $value) {
        if (is_array($value) && $value && array_is_list($value) && is_array($value[0]) && isset($value[0]['type'])) {
          $count += $this->count($value);
        }
      }
    }
    return $count;
  }

}
