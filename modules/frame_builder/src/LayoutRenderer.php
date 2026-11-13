<?php

namespace Drupal\frame_builder;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Block\BlockManagerInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Field\FormatterPluginManager;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\Render\Element;
use Drupal\Core\Render\Markup;
use Drupal\Core\Render\RenderContext;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Template\Attribute;
use Drupal\Core\Url;
use Drupal\Core\Utility\Token;
use Drupal\filter\Entity\FilterFormat;
use Drupal\views\Views;

/**
 * Builds render arrays from the layout document saved by the editor.
 *
 * The published page never loads Puck: every item becomes a core
 * `#type => component` element, so Twig, caching and the React islands of the
 * theme work as for a hand-written template.
 */
class LayoutRenderer {

  /**
   * UUIDs of the saved blocks being built, to stop one that contains itself.
   */
  protected array $building = [];

  /**
   * See showAt().
   */
  protected ?string $showAt = NULL;

  public function __construct(
    protected ComponentCatalog $catalog,
    protected Token $token,
    protected FormatterPluginManager $formatters,
    protected BlockManagerInterface $blocks,
    protected AccountInterface $currentUser,
    protected ComponentFeatures $features,
    protected MediaItems $mediaItems,
    protected RendererInterface $renderer,
    protected Library $library,
    protected Paragraphs $paragraphs,
  ) {}

  /**
   * Render array of a whole layout.
   */
  /**
   * Shows components as they are from this breakpoint on, whatever the
   * width of the window: for the canvas of the editor. NULL: as on a page.
   */
  public function showAt(?string $breakpoint): void {
    $this->showAt = $breakpoint;
  }

  public function build(array $data, ?ContentEntityInterface $entity = NULL): array {
    $build = [];
    foreach ($data['content'] ?? [] as $item) {
      if ($element = $this->buildItem($item, $entity)) {
        $build[] = $element;
      }
    }
    return $build;
  }

  /**
   * Render array of one item.
   *
   * @param array $item
   *   A Puck item: ['type' => component id, 'props' => [...]]. Slot content is
   *   a list of items stored under the slot name in the props.
   * @param \Drupal\Core\Entity\ContentEntityInterface|null $entity
   *   The entity (translation) the layout belongs to: the context of tokens,
   *   fields and the language of links.
   * @param array|null $slots
   *   Render arrays to use for the slots instead of the nested items. The
   *   editor passes placeholders here and fills them itself.
   */
  public function buildItem(array $item, ?ContentEntityInterface $entity = NULL, ?array $slots = NULL): array {
    $id = (string) ($item['type'] ?? '');
    $values = is_array($item['props'] ?? NULL) ? $item['props'] : [];
    // Spacing and background; pinned to a corner of the parent, except in
    // the editor ($slots given), which pins its own wrapper of the component.
    $definition = $this->catalog->definition($id);
    $style = $this->features->style($definition, $values);
    if ($slots === NULL) {
      $style += $this->features->positionStyle($definition, $values);
    }

    // A background with a responsive image style: rules with media queries
    // in the head of the page, for the element marked with their key.
    $attributes = [];
    $attached = [];
    if ($background = $this->features->responsiveBackground($definition, $values)) {
      $attributes['data-frame-builder-bg'] = $background['key'];
      $attached['html_head'][] = [
        ['#tag' => 'style', '#value' => Markup::create($background['css'])],
        'frame_builder_background_' . $background['key'],
      ];
    }
    // Layout and style that differ per breakpoint: the same way.
    if ($responsive = $this->features->responsive($definition, $values, $this->showAt)) {
      $attributes['data-frame-builder-at'] = $responsive['key'];
      $attached['html_head'][] = [
        ['#tag' => 'style', '#value' => Markup::create($responsive['css'])],
        'frame_builder_at_' . $responsive['key'],
      ];
    }
    if ($style) {
      $attributes['style'] = ComponentFeatures::css($style);
    }

    [$kind, $name] = ComponentCatalog::parse($id);
    if (in_array($kind, [ComponentCatalog::FIELD, ComponentCatalog::VIEW, ComponentCatalog::BLOCK, ComponentCatalog::SAVED, ComponentCatalog::PARAGRAPH], TRUE)) {
      $build = match ($kind) {
        ComponentCatalog::PARAGRAPH => $this->buildParagraph($name, $values, $entity),
        ComponentCatalog::SAVED => $this->buildSaved($name, $entity, $values),
        ComponentCatalog::FIELD => $this->buildField($name, $values, $entity),
        ComponentCatalog::VIEW => $this->buildView($name),
        ComponentCatalog::BLOCK => $this->buildBlock($name, $values, $entity),
      };
      // These have no root element of their own to style: they get one.
      if ($attributes && Element::children($build) + array_intersect_key($build, ['#type' => 1, '#theme' => 1])) {
        $build = ['#type' => 'container', '#attributes' => $attributes, '#attached' => $attached, 'content' => $build];
      }
      return $build;
    }
    // Also a component the theme replaced: core renders its replacement.
    if (!$this->catalog->definition($id)) {
      return [];
    }

    $metadata = new BubbleableMetadata();
    $props = [];
    $schemas = $this->catalog->props($id);
    foreach ($schemas as $name => $schema) {
      // The id of the item in the layout is not a prop of the component.
      if ($name === 'id') {
        continue;
      }
      if (str_starts_with($schema['contentMediaType'] ?? '', 'image/')) {
        // A picked image: its URL (of the chosen image style), and for the
        // props the component names, its srcset, sizes and alternative text.
        $image = $this->mediaItems->resolve($values[$name] ?? NULL);
        $props[$name] = $image['url'] !== '' ? UrlHelper::stripDangerousProtocols($image['url']) : NULL;
        foreach (['srcset', 'sizes', 'alt'] as $part) {
          $target = $schema["x-builder-$part"] ?? NULL;
          if ($target && isset($schemas[$target]) && ($image[$part] ?? '') !== '') {
            $props[$target] = $image[$part];
          }
        }
        // The whole image as Drupal renders it, <picture> included, for the
        // prop the component names for it.
        $target = $schema['x-builder-picture'] ?? NULL;
        if ($target && isset($schemas[$target]) && ($picture = $this->mediaItems->build($values[$name] ?? NULL, $entity))) {
          $context = new RenderContext();
          $html = $this->renderer->executeInRenderContext($context, fn () => $this->renderer->render($picture));
          if (!$context->isEmpty()) {
            $metadata->addCacheableDependency($context->pop());
          }
          $props[$target] = Markup::create(trim((string) $html));
        }
        continue;
      }
      if (array_key_exists($name, $props)) {
        // Already filled from an image prop.
        continue;
      }
      $value = $this->value($values[$name] ?? NULL, $schema, $entity, $metadata);
      // A choice left empty falls back to its declared default: core hands
      // some unset props to the template as "" (it does for `variant`),
      // which is not one of the allowed values.
      if ($value === NULL && !empty($schema['enum'])) {
        $value = $schema['default'] ?? $schema['examples'][0] ?? NULL;
      }
      if ($value !== NULL) {
        $props[$name] = $value;
      }
    }
    $props = array_filter($props, static fn ($value): bool => $value !== NULL);

    if ($slots === NULL) {
      $slots = [];
      foreach ($this->catalog->slots($id) as $name) {
        $children = [];
        foreach (is_array($values[$name] ?? NULL) ? $values[$name] : [] as $child) {
          $children[] = $this->buildItem($child, $entity);
          // A child pinned to a corner is placed relative to this component.
          $child_definition = $this->catalog->definition((string) ($child['type'] ?? ''));
          $child_position = $this->features->positionStyle($child_definition, is_array($child['props'] ?? NULL) ? $child['props'] : []);
          if (($child_position['position'] ?? '') === 'absolute') {
            $style += ['position' => 'relative'];
          }
        }
        if ($children = array_filter($children)) {
          $slots[$name] = $children;
        }
      }
    }

    // "position: relative" for a pinned child may have been added above.
    if ($style) {
      $attributes['style'] = ComponentFeatures::css($style);
    }
    if ($attributes) {
      // SDC merges this into the `attributes` every component template prints on its root element.
      $props['attributes'] = new Attribute($attributes);
    }
    $element = [
      '#type' => 'component',
      '#component' => $id,
      '#props' => $props,
      '#slots' => $slots,
    ];
    $metadata->addAttachments($attached);
    $metadata->applyTo($element);
    return $element;
  }

  /**
   * A paragraph, in the revision the layout names and the language of the page.
   */
  protected function buildParagraph(string $bundle, array $values, ?ContentEntityInterface $entity): array {
    $paragraph = $this->paragraphs->forDisplay($values, $bundle, $entity);
    // A paragraph that is not saved yet is the user's own draft.
    if (!$paragraph || (!$paragraph->isNew() && !$paragraph->access('view', $this->currentUser))) {
      return [];
    }
    return \Drupal::entityTypeManager()->getViewBuilder('paragraph')->view($paragraph, 'default');
  }

  /**
   * A block of the library, as it is saved now.
   */
  protected function buildSaved(string $uuid, ?ContentEntityInterface $entity, array $values = []): array {
    // A paragraph of the Paragraphs Library: shown by a "From library"
    // paragraph, as the module itself shows it.
    if ($library = $this->paragraphs->libraryItem($uuid)) {
      if (!$library->access('view', $this->currentUser)) {
        return [];
      }
      $wrapper = $this->paragraphs->wrapperForDisplay($values, $library);
      $build = \Drupal::entityTypeManager()->getViewBuilder('paragraph')->view($wrapper, 'default');
      return ['content' => $build, '#cache' => ['tags' => $library->getCacheTags()]];
    }
    // A saved block inside itself, however deep, is shown once.
    if (isset($this->building[$uuid]) || !($saved = $this->library->load($uuid)) || !($item = $this->library->item($saved))) {
      return [];
    }
    $this->building[$uuid] = TRUE;
    try {
      $build = $this->buildItem($item, $entity);
    }
    finally {
      unset($this->building[$uuid]);
    }
    if (!$build) {
      return [];
    }
    return ['content' => $build, '#cache' => ['tags' => $saved->getCacheTags()]];
  }

  /**
   * Casts a stored value to the type of the prop and replaces tokens.
   *
   * Empty optional values are dropped, so the Twig `|default()` applies.
   */
  protected function value(mixed $value, array $schema, ?ContentEntityInterface $entity, BubbleableMetadata $metadata, int $depth = 0, bool $in_list = FALSE): mixed {
    // As the builder reads the schema: one part of a "oneOf", all of an "allOf".
    $schema = ComponentCatalog::schema($schema);
    if (!$schema) {
      return NULL;
    }
    // What the schema fixes is what the component gets.
    if (array_key_exists('const', $schema)) {
      return $schema['const'];
    }
    if ($value === NULL || $value === '') {
      return NULL;
    }
    if (($schema['contentMediaType'] ?? '') === 'text/html') {
      return $this->formattedText($value, $schema, $entity, $metadata);
    }
    if (str_starts_with($schema['contentMediaType'] ?? '', 'image/')) {
      // A picked image in a list or a group: its URL, of the chosen image style.
      $url = $this->mediaItems->resolve($value)['url'];
      return $url !== '' ? UrlHelper::stripDangerousProtocols($url) : NULL;
    }
    if (($schema['x-builder-widget'] ?? '') === 'image') {
      // An image as an object, for components that take more than its URL:
      // url, alt, width, height, and with a responsive image style srcset
      // and sizes. Only what the schema names, when it names properties.
      $image = $this->mediaItems->resolve($value);
      if (($image['url'] ?? '') === '') {
        return NULL;
      }
      $image['url'] = UrlHelper::stripDangerousProtocols($image['url']);
      $image = array_filter($image, static fn ($part): bool => $part !== '' && $part !== NULL);
      // For components that choose the image by the width of the window
      // themselves: the images of a responsive image style with their media
      // queries, the first for every width.
      if (isset($schema['properties']['sources']) && ($sources = $this->mediaItems->backgroundSources($value))) {
        $image['sources'] = array_map(static fn (array $source): array => ['media' => $source[0], 'url' => UrlHelper::stripDangerousProtocols($source[1])], $sources);
      }
      return is_array($schema['properties'] ?? NULL) ? array_intersect_key($image, $schema['properties']) : $image;
    }
    if (($schema['x-builder-widget'] ?? '') === 'color') {
      // A name of the theme's palette, as its CSS colour.
      return $this->features->kit()->color($value);
    }
    if (($schema['x-builder-widget'] ?? '') === 'length') {
      // A step of the theme's spacing scale or a custom length, as a CSS length.
      return $this->features->kit()->length($value);
    }
    $type = $schema['type'] ?? 'string';
    if (is_array($type)) {
      // A number or a text: the first of the types the value is one of.
      foreach (array_diff($type, ['null']) as $one) {
        $as = $this->value($value, ['type' => $one] + $schema, $entity, $metadata, $depth, $in_list);
        if ($as !== NULL) {
          return $as;
        }
      }
      return NULL;
    }
    // A choice that is none of the choices (the component changed) is no value.
    if (!empty($schema['enum']) && $type !== 'boolean' && (!is_scalar($value) || !in_array((string) $value, array_map('strval', $schema['enum']), TRUE))) {
      return NULL;
    }
    switch ($type) {
      case 'integer':
        return is_numeric($value) ? (int) $value : NULL;

      case 'number':
        return is_numeric($value) ? (float) $value : NULL;

      case 'boolean':
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);

      case 'string':
        if (!is_scalar($value)) {
          return NULL;
        }
        $value = (string) $value;
        if (str_contains($value, '[')) {
          $value = $this->tokens($value, $entity, $metadata, TRUE);
        }
        if (isset($schema['format']) && str_contains($schema['format'], 'ri')) {
          $value = $this->url($value, $entity, $metadata);
        }
        return $value === '' ? NULL : $value;

      case 'array':
        // A list: each of its values as the schema of the values says.
        if (!is_array($value) || !is_array($schema['items'] ?? NULL) || $depth >= ComponentCatalog::DEPTH) {
          return NULL;
        }
        $list = [];
        $filled = FALSE;
        foreach (array_slice(array_values($value), 0, 200) as $entry) {
          $entry = $this->value($entry, $schema['items'], $entity, $metadata, $depth + 1, TRUE);
          $filled = $filled || $entry !== NULL;
          // In a list of lists (the rows of a table) a value keeps its
          // place: a cell without text is an empty cell, not a missing one.
          if ($entry !== NULL || $in_list) {
            $list[] = $entry ?? '';
          }
        }
        return $filled ? $list : NULL;

      case 'object':
        // A group: its properties, those that have a value.
        if (!is_array($value) || !is_array($schema['properties'] ?? NULL) || $depth >= ComponentCatalog::DEPTH) {
          return NULL;
        }
        $group = [];
        $filled = FALSE;
        foreach ($schema['properties'] as $name => $property) {
          $part = is_array($property) ? $this->value($value[$name] ?? NULL, $property, $entity, $metadata, $depth + 1) : NULL;
          // What the schema fixes does not make a group one with content.
          $filled = $filled || ($part !== NULL && !(is_array($property) && array_key_exists('const', $property)));
          if ($part === NULL && is_array($property) && !empty($property['enum'])) {
            $part = $property['default'] ?? $property['examples'][0] ?? NULL;
          }
          if ($part !== NULL) {
            $group[$name] = $part;
          }
        }
        // A group with nothing set in it is no group: the default of a
        // choice in it does not make it one.
        return $filled ? $group : NULL;
    }
    return NULL;
  }

  /**
   * A link value as a URL string.
   *
   * "entity:node/3" (picked in the editor) becomes the path of that content
   * in the language of the page, so one link serves every translation.
   * Anything else is used as typed; Twig escapes it, which does not stop
   * javascript: URLs, so dangerous protocols are stripped.
   */
  protected function url(string $value, ?ContentEntityInterface $entity, BubbleableMetadata $metadata): string {
    if (str_starts_with($value, 'entity:')) {
      try {
        $url = Url::fromUri($value);
        if ($entity) {
          $url->setOption('language', $entity->language());
        }
        $generated = $url->toString(TRUE);
        $metadata->addCacheableDependency($generated);
        return $generated->getGeneratedUrl();
      }
      catch (\Throwable) {
        return '';
      }
    }
    return UrlHelper::stripDangerousProtocols($value);
  }

  /**
   * Formatted text: tokens, then the filters of the text format.
   *
   * The editor stores ['value' => html, 'format' => format id]. The result is
   * safe markup, so Twig prints it as is. A plain string (an example from the
   * component.yml) goes through the fallback format.
   */
  protected function formattedText(mixed $value, array $schema, ?ContentEntityInterface $entity, BubbleableMetadata $metadata): mixed {
    [$text, $format] = is_array($value)
      ? [(string) ($value['value'] ?? ''), (string) ($value['format'] ?? '')]
      : [(string) $value, ''];
    if ($text === '') {
      return NULL;
    }
    $format = $schema['x-builder-format'] ?? $format;
    if (!$format || !FilterFormat::load($format)) {
      $format = filter_fallback_format();
    }
    if (str_contains($text, '[')) {
      $text = $this->tokens($text, $entity, $metadata);
    }
    $metadata->addCacheTags(['config:filter.format.' . $format]);
    return check_markup($text, $format, $entity?->language()->getId() ?? '');
  }

  /**
   * Token data: the entity under its token type.
   */
  protected function tokenData(?ContentEntityInterface $entity): array {
    return $entity ? [$entity->getEntityTypeId() => $entity] : [];
  }

  /**
   * Token options: values in the page's language.
   */
  protected function tokenOptions(?ContentEntityInterface $entity): array {
    return ['clear' => FALSE] + ($entity ? ['langcode' => $entity->language()->getId()] : []);
  }

  /**
   * Replaces the tokens in a text.
   *
   * A token of a kind Drupal knows that has no value here ([node:summary]
   * of a page without one) leaves nothing. Other text in square brackets is
   * text: "See [note: below]" stays as it was typed.
   */
  protected function tokens(string $text, ?ContentEntityInterface $entity, BubbleableMetadata $metadata, bool $plain = FALSE): string {
    $text = (string) ($plain
      ? $this->token->replacePlain($text, $this->tokenData($entity), $this->tokenOptions($entity), $metadata)
      : $this->token->replace($text, $this->tokenData($entity), $this->tokenOptions($entity), $metadata));
    if (str_contains($text, '[')) {
      $types = $this->token->getInfo()['types'] ?? [];
      foreach ($this->token->scan($text) as $type => $left) {
        if (isset($types[$type])) {
          $text = str_replace(array_values($left), '', $text);
        }
      }
    }
    return $text;
  }

  /**
   * A field of the page, rendered by a field formatter.
   *
   * `formatter` is a formatter id or ['type' => id, 'settings' => [...]].
   * The wrapper carries the label position, so the label is styled the same
   * whatever field template the theme has.
   */
  protected function buildField(string $name, array $values, ?ContentEntityInterface $entity): array {
    if (!$entity || $name === LayoutStorage::FIELD || !$entity->hasField($name)) {
      return [];
    }
    $items = $entity->get($name);
    if ($items->isEmpty() || !$items->access('view')) {
      return [];
    }
    $label = in_array($values['label'] ?? '', ['above', 'inline', 'visually_hidden'], TRUE) ? $values['label'] : 'hidden';
    $options = ['label' => $label];
    $formatter = is_array($values['formatter'] ?? NULL) ? $values['formatter'] : ['type' => (string) ($values['formatter'] ?? '')];
    // A formatter that does not fit the field falls back to the default
    // formatter of the field type.
    $definition = $this->formatters->getDefinition((string) ($formatter['type'] ?? ''), FALSE);
    if ($definition && in_array($items->getFieldDefinition()->getType(), $definition['field_types'], TRUE) && $definition['class']::isApplicable($items->getFieldDefinition())) {
      $options['type'] = $formatter['type'];
      if (is_array($formatter['settings'] ?? NULL)) {
        $options['settings'] = $formatter['settings'];
      }
    }
    // The look of the label, as custom properties the field's CSS reads: the
    // label is an element of the field template, not one to put a style on.
    $label_style = [];
    foreach (is_array($values['label_style'] ?? NULL) ? $this->features->textStyle($values['label_style']) : [] as $property => $value) {
      $label_style['--frame-builder-label-' . $property] = $value;
    }
    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['frame-builder-field', 'frame-builder-field--label-' . str_replace('_', '-', $label)]] + ($label_style ? ['style' => ComponentFeatures::css($label_style)] : []),
      '#attached' => ['library' => ['frame_builder/field']],
      'field' => $items->view($options),
    ];
    // Tokens typed into the field ("[site:name]", "[node:author:display-name]")
    // are replaced as in the text of components. The field is rendered here
    // for that, inside a render context so its cache data and assets, and
    // those of the tokens, still bubble. Text in brackets that is no token is
    // left alone.
    if (preg_match('/\[[a-z][a-z0-9_-]*:[^\]\s]+\]/i', (string) json_encode($items->getValue()))) {
      $context = new RenderContext();
      $html = (string) $this->renderer->executeInRenderContext($context, fn () => $this->renderer->render($build['field']));
      $metadata = $context->isEmpty() ? new BubbleableMetadata() : $context->pop();
      $html = $this->token->replace($html, $this->tokenData($entity), ['clear' => FALSE] + $this->tokenOptions($entity), $metadata);
      $build['field'] = ['#markup' => Markup::create($html)];
      $metadata->applyTo($build['field']);
    }
    return $build;
  }

  /**
   * A Drupal block plugin, in the standard block markup of the theme.
   */
  protected function buildBlock(string $plugin_id, array $values, ?ContentEntityInterface $entity): array {
    $metadata = new BubbleableMetadata();
    $title = is_scalar($values['title'] ?? NULL) ? trim((string) $values['title']) : '';
    if (str_contains($title, '[')) {
      $title = $this->tokens($title, $entity, $metadata, TRUE);
    }
    try {
      /** @var \Drupal\Core\Block\BlockPluginInterface $block */
      $block = $this->blocks->createInstance($plugin_id, ['label' => $title, 'label_display' => $title === '' ? '0' : 'visible']);
      $access = $block->access($this->currentUser, TRUE);
      $metadata->addCacheableDependency($access);
      $content = $access->isAllowed() ? $block->build() : [];
    }
    catch (\Throwable) {
      // A block that is gone, or one that needs a context it does not get.
      return [];
    }
    $metadata->addCacheableDependency($block);
    if (Element::isEmpty($content)) {
      // Nothing to print, but what decided that still has to bubble.
      $build = [];
      $metadata->applyTo($build);
      return $build;
    }
    $build = [
      '#theme' => 'block',
      '#attributes' => [],
      '#configuration' => $block->getConfiguration(),
      '#plugin_id' => $block->getPluginId(),
      '#base_plugin_id' => $block->getBaseId(),
      '#derivative_plugin_id' => $block->getDerivativeId(),
      'content' => $content,
    ];
    $metadata->applyTo($build);
    return $build;
  }

  /**
   * Render array of an embedded view display ("view_id:display_id").
   */
  protected function buildView(string $display): array {
    [$view_id, $display_id] = explode(':', $display . ':');
    $view = $view_id && \Drupal::moduleHandler()->moduleExists('views') ? Views::getView($view_id) : NULL;
    // Only displays made for the builder, also when the layout says otherwise.
    if (!$view || ($view->storage->getDisplay($display_id)['display_plugin'] ?? '') !== ComponentCatalog::VIEW_DISPLAY || !$view->access($display_id)) {
      return [];
    }
    return [
      '#type' => 'view',
      '#name' => $view_id,
      '#display_id' => $display_id,
    ];
  }

}
