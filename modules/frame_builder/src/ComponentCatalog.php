<?php

namespace Drupal\frame_builder;

use Drupal\Core\Block\BlockManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\FieldTypePluginManagerInterface;
use Drupal\Core\Field\FormatterPluginManager;
use Drupal\Core\Theme\ActiveTheme;
use Drupal\Core\Theme\ComponentPluginManager;
use Drupal\Core\Theme\ThemeManagerInterface;
use Drupal\node\NodeInterface;

/**
 * Turns SDC definitions into what the editor needs: fields, slots, defaults.
 *
 * The prop "sub-type" is read from the JSON schema of the prop, with the same
 * keywords Drupal Canvas uses where one exists:
 * - enum (+ meta:enum labels)            => select
 * - format: uri | uri-reference | iri... => url (autocomplete)
 * - contentMediaType: image/*            => image (media library, with an
 *   image style or responsive image style). `x-builder-picture` names
 *   another prop of the component that receives the image as Drupal renders
 *   it (a <picture> or <img srcset>, as the responsive image style says);
 *   `x-builder-srcset`, `x-builder-sizes` and `x-builder-alt` name props
 *   for the parts, for a template that builds the <img> itself. Give those
 *   props `x-builder-widget: hidden`.
 * - contentMediaType: text/html          => html (CKEditor 5 of a text format;
 *   `x-builder-format: <format id>` pins the format)
 * - x-builder-widget: length             => a step of the theme's spacing
 *   scale or a custom length; the component receives a CSS length
 * - x-builder-widget: icon, with enum    => icon picker; the icon is drawn
 *   with `x-builder-icon-class`, "{name}" being the enum value
 * - x-builder-widget: <widget>           => explicit override
 *
 * Text, textarea, html and url props are translatable in the synced
 * translation mode; `x-builder-translatable: false` opts a prop out.
 */
class ComponentCatalog {

  /**
   * Built-in components that come from Drupal, by the prefix of their id:
   * "drupal_field:<field name>", "drupal_view:<view>:<display>" and
   * "drupal_block:<block plugin id>"; and "drupal_saved:<uuid>", a block of
   * the library (Library), shown as it is saved now.
   */
  public const FIELD = 'drupal_field';
  public const VIEW = 'drupal_view';
  public const BLOCK = 'drupal_block';
  public const SAVED = 'drupal_saved';
  public const PARAGRAPH = 'drupal_paragraph';

  /**
   * The Views display type whose displays are offered in the builder.
   */
  public const VIEW_DISPLAY = 'frame_builder';

  /**
   * Widgets whose value is language specific.
   */
  protected const TRANSLATABLE = ['text', 'textarea', 'html', 'url'];

  /**
   * See replaced().
   */
  protected array $replaced = [];

  /**
   * Components a layout can name: those offered, and those the theme
   * replaced, which core renders as their replacement.
   */
  protected array $known = [];

  public function __construct(
    protected ComponentPluginManager $components,
    protected ThemeManagerInterface $themeManager,
    protected ModuleHandlerInterface $moduleHandler,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected FormatterPluginManager $formatters,
    protected FieldTypePluginManagerInterface $fieldTypes,
    protected BlockManagerInterface $blocks,
    protected ComponentFeatures $features,
    protected Library $library,
    protected Paragraphs $paragraphs,
    protected ?ConfigFactoryInterface $configFactory = NULL,
  ) {}

  /**
   * Whether the editor offers a component: not when the site left it out (the
   * page "Components of the page builder") or its theme did
   * (thirdPartySettings.frame_builder.offer: false). Such a component is not
   * in the list of blocks, yet a layout that has it still shows it.
   */
  public function isOffered(string $id, array $definition): bool {
    if (($definition['thirdPartySettings']['frame_builder']['offer'] ?? TRUE) === FALSE) {
      return FALSE;
    }
    $hidden = $this->configFactory?->get('frame_builder.settings')->get('hidden_components') ?? [];
    return !in_array($id, $hidden, TRUE);
  }

  /**
   * The components a site can offer in the builder: those of a theme and its
   * base themes, without obsolete ones, those not meant for the UI (noUi) and
   * those the theme replaced with its own. Keyed by id, sorted.
   */
  public function candidates(?ActiveTheme $theme = NULL): array {
    $theme ??= $this->themeManager->getActiveTheme();
    $themes = array_merge([$theme->getName()], array_keys($theme->getBaseThemeExtensions()));
    $candidates = [];
    $replaced = [];
    foreach ($this->components->getDefinitions() as $id => $definition) {
      if (($definition['status'] ?? '') === 'obsolete' || !in_array($definition['provider'] ?? '', $themes, TRUE)) {
        continue;
      }
      if (!empty($definition['replaces'])) {
        $replaced[$definition['replaces']] = $id;
      }
      if (empty($definition['noUi'])) {
        $candidates[$id] = $definition;
      }
    }
    $candidates = array_diff_key($candidates, $replaced);
    ksort($candidates);
    return $candidates;
  }

  /**
   * The SDC definition of a component, empty for Drupal's fields, views and
   * blocks. What ComponentFeatures reads the features of a component from.
   */
  public function definition(string $id): array {
    $this->definitions();
    return $this->known[$id] ?? [];
  }

  /**
   * Splits a component id into its kind and the rest: "drupal_field:body"
   * gives ["drupal_field", "body"]. SDC ids give their provider as kind.
   */
  public static function parse(string $id): array {
    return explode(':', $id, 2) + [1 => ''];
  }

  /**
   * SDC definitions usable in the builder, keyed by component id.
   *
   * Components of the active theme and of its base themes. Components of
   * modules are left out for now: most of them are administrative UI.
   */
  public function definitions(): array {
    $theme = $this->themeManager->getActiveTheme();
    $themes = array_merge([$theme->getName()], array_keys($theme->getBaseThemeExtensions()));
    $definitions = [];
    $replaced = [];
    $all = [];
    $notOffered = [];
    foreach ($this->components->getDefinitions() as $id => $definition) {
      $provider = $definition['provider'] ?? '';
      if (($definition['status'] ?? '') === 'obsolete' || !in_array($provider, $themes, TRUE)) {
        continue;
      }
      // Also of a replacement that is not offered itself (noUi): that is how
      // a theme hides a component of its base theme.
      $all[$id] = $definition;
      if (!empty($definition['replaces'])) {
        $replaced[$definition['replaces']] = $id;
      }
      if (!empty($definition['noUi'])) {
        continue;
      }
      // Left out by the site or the theme: known, as a layout can have it.
      if (!$this->isOffered($id, $definition)) {
        $notOffered[$id] = $definition;
        continue;
      }
      $definitions[$id] = $definition;
    }
    // A component that a theme replaces with its own version is offered once,
    // as the theme's version. Layouts that name the replaced one still work:
    // core renders the replacement in its place.
    $definitions = array_diff_key($definitions, $replaced);
    $this->replaced = $replaced;
    ksort($definitions);
    $this->known = $definitions + array_diff_key($notOffered, $replaced) + array_intersect_key($all, $replaced);
    return $definitions;
  }

  /**
   * Components of base themes that the active theme replaces: replaced id =>
   * id of the replacement.
   */
  public function replaced(): array {
    $this->definitions();
    return $this->replaced;
  }

  /**
   * Prop schemas of an SDC, keyed by prop name. Empty for built-ins.
   */
  public function props(string $id): array {
    return $this->definition($id)['props']['properties'] ?? [];
  }

  /**
   * Slot names of a component.
   */
  public function slots(string $id): array {
    return array_keys($this->definition($id)['slots'] ?? []);
  }

  /**
   * Names of the props that hold language specific text or links.
   */
  public function translatableProps(string $id): array {
    if (self::parse($id)[0] === self::BLOCK) {
      return ['title'];
    }
    // A translation names its own revision of the paragraph.
    if (self::parse($id)[0] === self::PARAGRAPH) {
      return ['paragraph'];
    }
    $names = [];
    foreach ($this->props($id) as $name => $schema) {
      $field = $this->field($name, $schema);
      if ($field && $field['translatable']) {
        $names[] = $name;
      }
    }
    return $names;
  }

  /**
   * Formatted text values of a layout, as [format id, text] pairs.
   *
   * Used when saving, to check that the user may use the formats.
   */
  public function htmlValues(array $items): array {
    $found = [];
    foreach ($items as $item) {
      $id = (string) ($item['type'] ?? '');
      $values = is_array($item['props'] ?? NULL) ? $item['props'] : [];
      foreach ($this->props($id) as $name => $schema) {
        $this->htmlIn($values[$name] ?? NULL, $schema, $found);
      }
      foreach ($this->slots($id) as $name) {
        if (is_array($values[$name] ?? NULL)) {
          $found = array_merge($found, $this->htmlValues($values[$name]));
        }
      }
    }
    return $found;
  }

  /**
   * The help of a component for editors: `help` in its *.component.yml, or
   * `thirdPartySettings.frame_builder.help`. Plain text; an empty line
   * starts a paragraph.
   */
  public static function help(array $definition): string {
    $help = $definition['help'] ?? $definition['thirdPartySettings']['frame_builder']['help'] ?? '';
    return is_string($help) ? trim($help) : '';
  }

  /**
   * How deep lists and groups go: a field in a list in a group in a list.
   * Deeper, a prop has no field, is not checked and is not passed on.
   */
  public const DEPTH = 4;

  /**
   * The schema of a prop as the builder reads it.
   *
   * A schema can say what a value is in more than one way. "allOf" is all
   * of its parts together. Of "oneOf" and "anyOf" the builder offers one:
   * the first part that says what type it is. A schema that only points
   * elsewhere ("$ref") or names no type in any part is not understood.
   *
   * @return array|null
   *   The schema with a "type" where it had one of those; the schema as it
   *   is when it has none of them (no type is text); NULL when it is not
   *   understood.
   */
  public static function schema(array $schema, int $depth = 0): ?array {
    if (isset($schema['type']) || $depth > 4) {
      return $schema;
    }
    $rest = array_diff_key($schema, ['allOf' => 1, 'oneOf' => 1, 'anyOf' => 1]);
    if (is_array($schema['allOf'] ?? NULL)) {
      $merged = [];
      foreach ($schema['allOf'] as $part) {
        $part = is_array($part) ? self::schema($part, $depth + 1) : NULL;
        if (!$part) {
          return NULL;
        }
        $merged = array_replace_recursive($merged, $part);
      }
      return self::schema($rest + $merged, $depth + 1);
    }
    foreach (['oneOf', 'anyOf'] as $keyword) {
      foreach (is_array($schema[$keyword] ?? NULL) ? $schema[$keyword] : [] as $part) {
        $part = is_array($part) ? self::schema($part, $depth + 1) : NULL;
        if ($part && isset($part['type']) && $part['type'] !== 'null') {
          return $rest + $part;
        }
      }
      if (isset($schema[$keyword])) {
        return NULL;
      }
    }
    return isset($schema['$ref']) ? NULL : $schema;
  }

  /**
   * What is required and has no value, in a layout that is about to be
   * saved: props of components, and what is in their lists and groups.
   * Also what has a value the schema does not allow: not one of the
   * choices, a number out of its range, a text too long.
   *
   * @return string[]
   *   A sentence for each, naming the component and the field.
   */
  public function missing(array $items, int $depth = 0): array {
    $found = [];
    foreach ($depth > 20 ? [] : $items as $item) {
      $id = is_array($item) ? (string) ($item['type'] ?? '') : '';
      $definition = $this->definition($id);
      $values = $definition && is_array($item['props'] ?? NULL) ? $item['props'] : [];
      if ($definition) {
        $this->missingIn($values, $definition['props'] ?? [], (string) ($definition['name'] ?? $id), $found);
      }
      foreach ($this->slots($id) as $name) {
        if (is_array($values[$name] ?? NULL)) {
          $found = array_merge($found, $this->missing($values[$name], $depth + 1));
        }
      }
    }
    return $found;
  }

  /**
   * Required values that are missing in an object: the props of a component,
   * a group, a value of a list.
   *
   * @param mixed $values
   *   The object.
   * @param array $schema
   *   Its schema: 'properties' and 'required'.
   * @param string $where
   *   What it is, for the message: "Links", "Links › Links 2".
   * @param string[] $found
   *   Collects the messages.
   */
  protected function missingIn(mixed $values, array $schema, string $where, array &$found, int $depth = 0): void {
    $values = is_array($values) ? $values : [];
    $required = is_array($schema['required'] ?? NULL) ? $schema['required'] : [];
    foreach (is_array($schema['properties'] ?? NULL) && $depth <= self::DEPTH ? $schema['properties'] : [] as $name => $property) {
      // Only what an editor can fill in.
      $property = is_array($property) ? self::schema($property) : NULL;
      if (!$property || !($field = $this->field((string) $name, $property, $depth))) {
        continue;
      }
      $value = $values[$name] ?? NULL;
      if (in_array($name, $required, TRUE) && self::isEmpty($value)) {
        $found[] = sprintf('%s: %s is required.', $where, $field['label']);
        continue;
      }
      if (!self::isEmpty($value) && ($problem = self::problem($value, $property, $field))) {
        $found[] = sprintf('%s: %s %s.', $where, $field['label'], $problem);
        continue;
      }
      if ($field['widget'] === 'list' && !isset($field['fields']) && is_array($value)) {
        foreach (array_values($value) as $index => $entry) {
          if (!self::isEmpty($entry) && ($problem = self::problem($entry, self::schema($property['items']) ?? [], $field['item']))) {
            $found[] = sprintf('%s: %s %d %s.', $where, $field['label'], $index + 1, $problem);
          }
        }
      }
      if ($field['widget'] === 'group') {
        // A group that is not required and has nothing in it is left out as a whole.
        if (!self::isEmpty($value)) {
          $this->missingIn($value, $property, $where . ' › ' . $field['label'], $found, $depth + 1);
        }
      }
      elseif ($field['widget'] === 'list' && isset($field['fields']) && is_array($value)) {
        foreach (array_values($value) as $index => $entry) {
          $this->missingIn($entry, self::schema($property['items']) ?? [], $where . ' › ' . $field['label'] . ' ' . ($index + 1), $found, $depth + 1);
        }
      }
      if ($field['widget'] === 'list' && is_array($value) && !empty($field['min']) && count($value) < $field['min'] && (count($value) || in_array($name, $required, TRUE))) {
        $found[] = sprintf('%s: %s needs at least %d.', $where, $field['label'], $field['min']);
      }
    }
  }

  /**
   * What is wrong with a value, as the end of a sentence about its field;
   * NULL when the schema allows it.
   *
   * Text with a token in it is not measured: it is another text on the page.
   */
  protected static function problem(mixed $value, array $schema, array $field): ?string {
    $widget = $field['widget'] ?? '';
    if (in_array($widget, ['select', 'icon'], TRUE) && !empty($schema['enum']) && is_scalar($value) && !in_array((string) $value, array_map('strval', $schema['enum']), TRUE)) {
      return 'is not one of the choices';
    }
    if ($widget === 'number' && is_numeric($value)) {
      if (is_numeric($schema['minimum'] ?? NULL) && $value < $schema['minimum']) {
        return 'must be ' . $schema['minimum'] . ' or more';
      }
      if (is_numeric($schema['maximum'] ?? NULL) && $value > $schema['maximum']) {
        return 'must be ' . $schema['maximum'] . ' or less';
      }
      $step = $schema['multipleOf'] ?? NULL;
      if (is_numeric($step) && $step > 0 && abs(round($value / $step) - $value / $step) > 1e-9) {
        return 'must be a multiple of ' . $step;
      }
    }
    if (in_array($widget, ['text', 'textarea', 'email', 'date', 'time'], TRUE) && is_string($value) && !str_contains($value, '[')) {
      $length = mb_strlen($value);
      if (is_numeric($schema['minLength'] ?? NULL) && $length < $schema['minLength']) {
        return 'needs at least ' . (int) $schema['minLength'] . ' characters';
      }
      if (is_numeric($schema['maxLength'] ?? NULL) && $length > $schema['maxLength']) {
        return 'can have at most ' . (int) $schema['maxLength'] . ' characters';
      }
      if (is_string($schema['pattern'] ?? NULL) && @preg_match('~' . str_replace('~', '\\~', $schema['pattern']) . '~u', $value) === 0) {
        return 'does not have the form it needs';
      }
      $valid = match ($widget) {
        'email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== FALSE,
        'date' => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $value),
        'time' => (bool) preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $value),
        default => TRUE,
      };
      if (!$valid) {
        return ['email' => 'is not an email address', 'date' => 'is not a date', 'time' => 'is not a time'][$widget];
      }
    }
    if (in_array($widget, ['list', 'choices'], TRUE) && is_array($value)) {
      if (is_numeric($schema['maxItems'] ?? NULL) && count($value) > $schema['maxItems']) {
        return 'can have at most ' . (int) $schema['maxItems'];
      }
      if (!empty($schema['uniqueItems']) && count(array_unique(array_map('serialize', $value))) < count($value)) {
        return 'has the same value twice';
      }
      if ($widget === 'choices' && array_diff(array_map('strval', array_filter($value, 'is_scalar')), array_map('strval', $field['options'] ? array_column($field['options'], 'value') : []))) {
        return 'has a value that is not one of the choices';
      }
    }
    return NULL;
  }

  /**
   * Whether a value of a layout is no value: nothing, an empty text or list,
   * rich text without text, an image that was not picked. No is a value, and
   * so is 0.
   */
  protected static function isEmpty(mixed $value): bool {
    if ($value === NULL || $value === '' || $value === []) {
      return TRUE;
    }
    if (is_string($value)) {
      return trim($value) === '';
    }
    if (!is_array($value)) {
      return FALSE;
    }
    // Rich text: {"value", "format"}.
    if (array_key_exists('value', $value) && array_key_exists('format', $value)) {
      return trim(strip_tags((string) $value['value'], '<img><drupal-media><iframe><video><audio>')) === '';
    }
    // A picked image: {"media" | "url", ...}.
    if (array_key_exists('media', $value) || array_key_exists('url', $value)) {
      return empty($value['media']) && trim((string) ($value['url'] ?? '')) === '';
    }
    foreach ($value as $part) {
      if (!self::isEmpty($part)) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Formatted text in one value: the value itself, or inside a list or a
   * group of values (props of the types array and object).
   */
  protected function htmlIn(mixed $value, array $schema, array &$found, int $depth = 0): void {
    if (!is_array($value) || $depth > 8) {
      return;
    }
    if (($schema['contentMediaType'] ?? '') === 'text/html') {
      $found[] = [(string) ($value['format'] ?? ''), (string) ($value['value'] ?? '')];
      return;
    }
    if (is_array($schema['items'] ?? NULL)) {
      foreach ($value as $entry) {
        $this->htmlIn($entry, $schema['items'], $found, $depth + 1);
      }
    }
    foreach (is_array($schema['properties'] ?? NULL) ? $schema['properties'] : [] as $name => $property) {
      $this->htmlIn($value[$name] ?? NULL, is_array($property) ? $property : [], $found, $depth + 1);
    }
  }

  /**
   * Asset libraries of every component, attached to the editor page.
   */
  public function libraries(): array {
    return array_map(
      static fn (string $id): string => 'core/components.' . str_replace(':', '--', $id),
      array_keys($this->definitions()),
    );
  }

  /**
   * The component list for the editor (drupalSettings).
   */
  public function editorConfig(NodeInterface $node): array {
    $config = [];
    $offered = $this->definitions();
    // With the components the theme replaced: not offered, yet a layout that
    // names one shows it (as its replacement) and the editor must know it.
    foreach ($this->known as $id => $definition) {
      $required = $definition['props']['required'] ?? [];
      $fields = [];
      foreach ($definition['props']['properties'] ?? [] as $name => $schema) {
        $field = $this->field($name, $schema);
        if ($field) {
          $fields[] = $field + ['required' => in_array($name, $required, TRUE)];
        }
      }
      $slots = [];
      foreach ($definition['slots'] ?? [] as $name => $slot) {
        $slots[] = ['name' => $name, 'label' => $slot['title'] ?? $name];
      }
      $config[] = [
        'id' => $id,
        'label' => $definition['name'] ?? $id,
        // The icon in the list of blocks: a name of the editor's icon set, or
        // CSS classes of an icon font of the theme. None gives the default.
        'icon' => (string) ($definition['thirdPartySettings']['frame_builder']['icon'] ?? ''),
        // A typical use of the component, as a layout item: for the AI
        // assistant and for documentation.
        'example' => $this->example($definition),
        'group' => $definition['group'] ?? 'Components',
        'description' => $definition['description'] ?? '',
        // Help for editors: what the component is for and how to use it.
        // The "?" next to its name in the editor shows it.
        'help' => self::help($definition),
        'fields' => array_merge($fields, $this->features->fields($definition)),
        'slots' => $slots,
      ] + (isset($offered[$id]) ? [] : ['hidden' => TRUE]);
    }
    // Fields, views and blocks get the features too: spacing, background...
    $drupal = array_merge($this->fieldComponents($node), $this->viewComponents(), $this->blockComponents(), $this->paragraphComponents(), $this->savedComponents());
    $features = $this->features->fields([]);
    foreach ($drupal as &$component) {
      $component['fields'] = array_merge($component['fields'], $features);
    }
    unset($component);
    $config = array_merge($config, $drupal);
    foreach ($config as &$component) {
      // After the component's own fields, before "Layout and style".
      $at = count($component['fields']);
      foreach ($component['fields'] as $index => $field) {
        if (isset($field['heading'])) {
          $at = $index;
          break;
        }
      }
      // Paragraph blocks are what is saved to the library. Other components
      // show the box only when they are a copy of a saved block.
      $field = self::libraryField() + ['offer' => self::parse($component['id'])[0] === self::PARAGRAPH && empty($component['hidden'])];
      array_splice($component['fields'], $at, 0, [$field]);
    }
    return $config;
  }

  /**
   * The example a component gives of its use, with the components the
   * active theme replaced named as the theme names them.
   */
  protected function example(array $definition): ?array {
    $example = $definition['thirdPartySettings']['frame_builder']['example'] ?? NULL;
    if (!is_array($example) || !is_string($example['type'] ?? NULL)) {
      return NULL;
    }
    $rename = function (array $item) use (&$rename): array {
      $item['type'] = $this->replaced()[$item['type']] ?? $item['type'];
      foreach (is_array($item['props'] ?? NULL) ? $item['props'] : [] as $name => $value) {
        if (LayoutMigrator::isItems($value)) {
          $item['props'][$name] = array_map($rename, $value);
        }
      }
      return $item;
    };
    return $rename($example);
  }

  /**
   * The editor's description of one saved block, as a component.
   */
  public function savedComponent(Entity\SavedBlock $saved): array {
    return [
      'id' => self::SAVED . ':' . $saved->uuid(),
      'label' => (string) $saved->label(),
      'icon' => 'library',
      'group' => 'Library',
      'description' => 'A saved block. It is shown as it is saved now, on every page that uses it.',
      // What the block is comes first: it has no fields of its own.
      'fields' => array_merge([self::libraryField()], $this->features->fields([])),
      'slots' => [],
      'saved' => $saved->uuid(),
      'info' => $this->library->info($saved),
    ];
  }

  /**
   * The editor's description of one item of the Paragraphs Library, as a
   * component: the same as a saved block.
   */
  public function libraryComponent(ContentEntityInterface $library): array {
    $paragraph = $this->paragraphs->libraryParagraph($library);
    $user = $library->getRevisionUser();
    return [
      'id' => self::SAVED . ':' . $library->uuid(),
      'label' => (string) $library->label(),
      'icon' => 'library',
      'group' => 'Library',
      'description' => 'A paragraph of the library. It is shown as it is saved now, on every page that uses it.',
      'fields' => array_merge([self::libraryField()], $this->features->fields([])),
      'slots' => [],
      'saved' => $library->uuid(),
      'info' => [
        'type' => $paragraph ? (string) $paragraph->type->entity?->label() : '',
        'saved' => (string) \Drupal::service('date.formatter')->format((int) $library->getChangedTime(), 'short'),
        'author' => $user ? (string) $user->getDisplayName() : '',
        'message' => (string) $library->getRevisionLogMessage(),
      ],
    ];
  }

  /**
   * One component per paragraph type (Paragraphs module).
   */
  protected function paragraphComponents(): array {
    $components = [];
    foreach ($this->paragraphs->types() as $id => $type) {
      $components[] = [
        'id' => self::PARAGRAPH . ':' . $id,
        'label' => $type['label'],
        'icon' => 'paragraph',
        'group' => 'Paragraphs',
        'description' => $type['description'] ?: 'A paragraph, edited with its own form.',
        'fields' => [[
          'name' => 'paragraph',
          'label' => 'Content',
          'widget' => 'paragraph',
          'bundle' => $id,
          'default' => NULL,
          'required' => FALSE,
          'translatable' => TRUE,
        ]],
        'slots' => [],
      ];
      // Made by the builder itself, for pages that use a paragraph of the
      // library: not something to add by hand. Blocks of this type that
      // pages already have keep working.
      if ($id === Paragraphs::LIBRARY_TYPE && $this->paragraphs->libraryAvailable()) {
        $components[count($components) - 1]['hidden'] = TRUE;
      }
    }
    return $components;
  }

  /**
   * One component per block of the library.
   */
  protected function savedComponents(): array {
    $components = array_map(fn (Entity\SavedBlock $saved): array => ['fields' => []] + $this->savedComponent($saved), $this->library->all());
    foreach ($this->paragraphs->libraryItems() as $library) {
      $components[] = ['fields' => []] + $this->libraryComponent($library);
    }
    return array_values($components);
  }

  /**
   * The field of the right column that saves to, and tells about, the library.
   */
  protected static function libraryField(): array {
    return ['name' => '_library', 'label' => 'Library', 'widget' => 'library', 'default' => NULL, 'required' => FALSE, 'translatable' => FALSE];
  }

  /**
   * Formatters that can show a field, as formatter id => label.
   */
  public function formatterOptions(NodeInterface $node, string $field_name): array {
    if (!$node->hasField($field_name)) {
      return [];
    }
    $definition = $node->getFieldDefinition($field_name);
    $options = [];
    foreach ($this->formatters->getOptions($definition->getType()) as $id => $label) {
      $class = $this->formatters->getDefinition($id)['class'];
      if ($class::isApplicable($definition)) {
        $options[$id] = (string) $label;
      }
    }
    // The default formatter of the field type comes first: it is what a
    // newly chosen field starts with.
    $default = $this->fieldTypes->getDefinition($definition->getType())['default_formatter'] ?? NULL;
    if ($default && isset($options[$default])) {
      $options = [$default => $options[$default]] + $options;
    }
    return $options;
  }

  /**
   * One component per field of the page: the field shown with a formatter.
   */
  protected function fieldComponents(NodeInterface $node): array {
    $components = [];
    foreach ($node->getFieldDefinitions() as $name => $definition) {
      if ($name === LayoutStorage::FIELD || !($name === 'title' || $definition->isDisplayConfigurable('view'))) {
        continue;
      }
      $options = $this->formatterOptions($node, $name);
      if (!$options) {
        continue;
      }
      $components[] = [
        'id' => self::FIELD . ':' . $name,
        'label' => (string) $definition->getLabel(),
        'icon' => 'field',
        'group' => 'Fields',
        'description' => "The $name field of this page, in the language being viewed.",
        'fields' => [
          [
            'name' => 'formatter',
            'label' => 'Format',
            'widget' => 'formatter',
            'fieldName' => $name,
            // `settings`: whether the formatter has a settings form at all.
            'options' => array_map(
              fn (string $id, string $label): array => [
                'value' => $id,
                'label' => $label,
                'settings' => (bool) $this->formatters->getDefinition($id)['class']::defaultSettings(),
              ],
              array_keys($options),
              $options,
            ),
            'default' => $this->displayedFormatter($node, $name, $options) ?? array_key_first($options),
            'required' => TRUE,
            'translatable' => FALSE,
          ],
          [
            'name' => 'label',
            'label' => 'Label',
            'widget' => 'select',
            'options' => [
              ['value' => 'hidden', 'label' => '- Hidden -'],
              ['value' => 'above', 'label' => 'Above'],
              ['value' => 'inline', 'label' => 'Inline'],
              ['value' => 'visually_hidden', 'label' => '- Visually hidden -'],
            ],
            'default' => 'above',
            'required' => TRUE,
            'translatable' => FALSE,
          ],
          [
            'name' => 'label_style',
            'label' => 'Label style',
            'default' => NULL,
            'required' => FALSE,
            'translatable' => FALSE,
            'description' => 'How the name of the field looks, when it is shown.',
          ] + $this->features->textStyleField(),
        ],
        'slots' => [],
      ];
    }
    usort($components, static fn (array $a, array $b): int => strnatcasecmp($a['label'], $b['label']));
    return $components;
  }

  /**
   * How the content type shows a field in its default display: the format
   * and settings a Field block starts with. A reference to media is then
   * the image, not the name of the media item.
   *
   * @return array|null
   *   ['type' => formatter id, 'settings' => [...]], NULL when the field is
   *   hidden there or shown with a formatter that is not offered.
   */
  protected function displayedFormatter(NodeInterface $node, string $field_name, array $options): ?array {
    $display = $this->entityTypeManager->getStorage('entity_view_display')->load('node.' . $node->bundle() . '.default');
    $component = $display?->getComponent($field_name);
    if (!$component || !isset($options[$component['type'] ?? ''])) {
      return NULL;
    }
    return ['type' => $component['type'], 'settings' => $component['settings'] ?? []];
  }

  /**
   * One component per view display of the "Component" display type.
   *
   * Other displays (pages, blocks, feeds) are not offered: a view is made
   * available to the builder on purpose, by adding such a display to it.
   */
  protected function viewComponents(): array {
    if (!$this->moduleHandler->moduleExists('views')) {
      return [];
    }
    $components = [];
    /** @var \Drupal\views\ViewEntityInterface[] $views */
    $views = $this->entityTypeManager->getStorage('view')->loadByProperties(['status' => TRUE]);
    foreach ($views as $view) {
      $displays = array_filter($view->get('display'), static fn (array $display): bool => $display['display_plugin'] === self::VIEW_DISPLAY);
      foreach ($displays as $display_id => $display) {
        $components[] = [
          'id' => self::VIEW . ':' . $view->id() . ':' . $display_id,
          'label' => $view->label() . (count($displays) > 1 ? ': ' . ($display['display_title'] ?? $display_id) : ''),
          'icon' => 'view',
          'group' => 'Views',
          'description' => (string) $view->get('description'),
          'fields' => [],
          'slots' => [],
        ];
      }
    }
    usort($components, static fn (array $a, array $b): int => strnatcasecmp($a['label'], $b['label']));
    return $components;
  }

  /**
   * One component per Drupal block that can be placed without more input.
   *
   * Left out: blocks that need a context (they are bound to a page or an
   * entity), Layout Builder's field and inline blocks, the main content block
   * and blocks hidden from the block UI.
   */
  protected function blockComponents(): array {
    $components = [];
    foreach ($this->blocks->getDefinitions() as $id => $definition) {
      $base = explode(':', $id)[0];
      if (!empty($definition['_block_ui_hidden']) || in_array($base, ['broken', 'system_main_block', 'field_block', 'extra_field_block', 'inline_block'], TRUE)) {
        continue;
      }
      foreach ($definition['context_definitions'] ?? [] as $context) {
        if ($context->isRequired()) {
          continue 2;
        }
      }
      $components[] = [
        'id' => self::BLOCK . ':' . $id,
        'label' => (string) $definition['admin_label'],
        'icon' => 'block',
        'group' => 'Blocks',
        'collapsed' => TRUE,
        'description' => (string) ($definition['category'] ?? ''),
        'fields' => [
          [
            'name' => 'title',
            'label' => 'Title',
            'description' => 'Shown above the block. Leave empty for no title.',
            'widget' => 'text',
            'default' => NULL,
            'required' => FALSE,
            'translatable' => TRUE,
          ],
        ],
        'slots' => [],
      ];
    }
    usort($components, static fn (array $a, array $b): int => strnatcasecmp($a['label'], $b['label']));
    return $components;
  }

  /**
   * Maps a prop schema to an editor field, NULL for shapes not supported yet.
   */
  protected function field(string $name, array $schema, int $depth = 0): ?array {
    $schema = self::schema($schema);
    // What the schema fixes is not a choice; "id" is the id of the item in
    // the layout, which the builder keeps.
    if (!$schema || array_key_exists('const', $schema) || ($depth === 0 && $name === 'id')) {
      return NULL;
    }
    $type = $schema['type'] ?? 'string';
    if (is_array($type)) {
      // A number or a text is typed as text: a text field takes both.
      $types = array_values(array_diff($type, ['null']));
      $type = in_array('string', $types, TRUE) && count($types) > 1 && !array_intersect($types, ['array', 'object']) ? 'string' : ($types[0] ?? 'string');
    }
    $field = [
      'name' => $name,
      'label' => $schema['title'] ?? $name,
      'description' => $schema['description'] ?? '',
      'default' => $schema['examples'][0] ?? $schema['default'] ?? NULL,
    ];
    // Several values (a list of links, of rows, of slides) and values that
    // belong together (an image with its caption): each value is a field of
    // its own kind, as a multiple value field of Drupal has a widget per
    // value.
    if (!isset($schema['x-builder-widget']) && in_array($type, ['array', 'object'], TRUE)) {
      return $depth < self::DEPTH ? $this->compoundField($field, $type, $schema, $depth) : NULL;
    }
    $media_type = $schema['contentMediaType'] ?? '';
    $widget = $schema['x-builder-widget'] ?? match (TRUE) {
      // Yes or no is a checkbox, also when the schema lists both.
      $type === 'boolean' => 'boolean',
      !empty($schema['enum']) => 'select',
      $type === 'integer', $type === 'number' => 'number',
      $type !== 'string' => NULL,
      in_array($schema['format'] ?? '', ['uri', 'uri-reference', 'iri', 'iri-reference'], TRUE) => 'url',
      ($schema['format'] ?? '') === 'date-time' => 'datetime',
      in_array($schema['format'] ?? '', ['date', 'time', 'email'], TRUE) => $schema['format'],
      str_starts_with($media_type, 'image/') => 'image',
      $media_type === 'text/html' => 'html',
      default => 'text',
    };
    // "hidden": a prop the builder fills by itself (srcset, sizes, alt of an image).
    if (!$widget || $widget === 'hidden') {
      return NULL;
    }
    $field['widget'] = $widget;
    if ($widget === 'image') {
      $field['imageStyles'] = $this->features->imageStyleOptions();
      // A component that takes the rendered image can have it load eagerly and link somewhere.
      $field['imageOptions'] = isset($schema['x-builder-picture']);
    }
    if ($widget === 'color') {
      // One of the colours of the theme's palette.
      $kit = $this->features->kit();
      $field['colors'] = array_map(
        static fn (string $name, array $color): array => ['value' => $name, 'label' => $color['label'], 'color' => $color['value']],
        array_keys($kit->colors()),
        $kit->colors(),
      );
      $field['default'] = NULL;
    }
    if ($widget === 'length') {
      // A step of the theme's spacing scale, or a custom length.
      $field['sizes'] = $this->features->sizeOptions();
      $field['default'] = is_string($field['default']) ? $this->features->kit()->lengthChoice($field['default']) : NULL;
    }
    $field['translatable'] = in_array($widget, self::TRANSLATABLE, TRUE) && ($schema['x-builder-translatable'] ?? TRUE) !== FALSE;
    // Limits the schema sets, for the input: they are checked when the page
    // is saved (problem()).
    if ($widget === 'number') {
      $field += array_filter([
        'min' => $schema['minimum'] ?? NULL,
        'max' => $schema['maximum'] ?? NULL,
        'step' => $schema['multipleOf'] ?? ($type === 'integer' ? 1 : NULL),
      ], 'is_numeric');
    }
    if (in_array($widget, ['text', 'textarea', 'email'], TRUE) && is_numeric($schema['maxLength'] ?? NULL)) {
      $field['maxLength'] = (int) $schema['maxLength'];
    }
    if (!empty($schema['enum']) && $widget !== 'boolean') {
      $labels = $schema['meta:enum'] ?? [];
      $field['options'] = array_map(
        static fn ($value): array => ['value' => $value, 'label' => (string) ($labels[$value] ?? $value)],
        array_values($schema['enum']),
      );
    }
    if ($widget === 'icon') {
      $field['iconClass'] = $schema['x-builder-icon-class'] ?? '{name}';
    }
    if ($widget === 'html' && isset($schema['x-builder-format'])) {
      $field['format'] = $schema['x-builder-format'];
    }
    return $field;
  }

  /**
   * The field of a prop of the type array ("list") or object ("group").
   *
   * A list has one field for each of its values ('item'), or, when a value
   * is an object, the fields of that object ('fields'). A group has the
   * fields of its properties. NULL when the schema does not say what the
   * values are, or when none of them has a field.
   */
  protected function compoundField(array $field, string $type, array $schema, int $depth): ?array {
    $of = $type === 'array' ? ($schema['items'] ?? NULL) : $schema;
    $of = is_array($of) ? self::schema($of) : NULL;
    if (!$of) {
      return NULL;
    }
    // Several of a set of choices: a checkbox for each.
    if ($type === 'array' && !empty($of['enum']) && !array_filter($of['enum'], static fn ($option): bool => !is_scalar($option))) {
      $labels = $of['meta:enum'] ?? [];
      return [
        'widget' => 'choices',
        'options' => array_map(static fn ($value): array => ['value' => $value, 'label' => (string) ($labels[$value] ?? $value)], array_values($of['enum'])),
        'default' => is_array($field['default']) ? $field['default'] : NULL,
        'translatable' => FALSE,
      ] + $field;
    }
    $of_type = $of['type'] ?? 'string';
    $of_type = is_array($of_type) ? (current(array_diff($of_type, ['null'])) ?: 'string') : $of_type;
    if ($of_type === 'object') {
      $required = is_array($of['required'] ?? NULL) ? $of['required'] : [];
      $fields = [];
      foreach (is_array($of['properties'] ?? NULL) ? $of['properties'] : [] as $name => $property) {
        if (is_array($property) && ($sub = $this->field((string) $name, $property, $depth + 1))) {
          $fields[] = $sub + ['required' => in_array($name, $required, TRUE)];
        }
      }
      if (!$fields) {
        return NULL;
      }
      $field['fields'] = $fields;
      $translatable = (bool) array_filter(array_column($fields, 'translatable'));
    }
    elseif ($type === 'array' && ($item = $this->field('value', $of, $depth + 1))) {
      // The label of the list is the label of each of its values.
      $field['item'] = ['label' => $of['title'] ?? $field['label']] + $item;
      $translatable = $item['translatable'];
    }
    else {
      return NULL;
    }
    $field['widget'] = $type === 'array' ? 'list' : 'group';
    $field['default'] = is_array($field['default']) ? $field['default'] : NULL;
    if ($type === 'array') {
      $field += array_filter(['min' => (int) ($schema['minItems'] ?? 0), 'max' => (int) ($schema['maxItems'] ?? 0)]);
      // What a value added to the list starts with: the example of a value.
      $start = $of['examples'][0] ?? $of['default'] ?? NULL;
      if ($start !== NULL && is_array($start) === isset($field['fields'])) {
        $field['start'] = $start;
      }
      // The property that names a value in the folded list; without it,
      // the first text of the value.
      if (isset($field['fields']) && is_string($schema['x-builder-summary'] ?? NULL) && in_array($schema['x-builder-summary'], array_column($field['fields'], 'name'), TRUE)) {
        $field['summary'] = $schema['x-builder-summary'];
      }
    }
    // In the language of the page when anything in it is.
    $field['translatable'] = $translatable && ($schema['x-builder-translatable'] ?? TRUE) !== FALSE;
    return $field;
  }

}
