<?php

namespace Drupal\frame_builder;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Block\BlockManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\FieldTypePluginManagerInterface;
use Drupal\Core\Field\FormatterPluginManager;
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
  ) {}

  /**
   * The SDC definition of a component, empty for Drupal's fields, views and
   * blocks. What ComponentFeatures reads the features of a component from.
   */
  public function definition(string $id): array {
    return $this->definitions()[$id] ?? [];
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
    foreach ($this->components->getDefinitions() as $id => $definition) {
      $provider = $definition['provider'] ?? '';
      if (!empty($definition['noUi']) || ($definition['status'] ?? '') === 'obsolete') {
        continue;
      }
      if (!in_array($provider, $themes, TRUE)) {
        continue;
      }
      $definitions[$id] = $definition;
      if (!empty($definition['replaces'])) {
        $replaced[$definition['replaces']] = $id;
      }
    }
    // A component that a theme replaces with its own version is offered once,
    // as the theme's version. Layouts that name the replaced one still work:
    // core renders the replacement in its place.
    $definitions = array_diff_key($definitions, $replaced);
    $this->replaced = $replaced;
    ksort($definitions);
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
    return $this->definitions()[$id]['props']['properties'] ?? [];
  }

  /**
   * Slot names of a component.
   */
  public function slots(string $id): array {
    return array_keys($this->definitions()[$id]['slots'] ?? []);
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
        if (($schema['contentMediaType'] ?? '') === 'text/html' && is_array($values[$name] ?? NULL)) {
          $found[] = [(string) ($values[$name]['format'] ?? ''), (string) ($values[$name]['value'] ?? '')];
        }
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
    foreach ($this->definitions() as $id => $definition) {
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
        'fields' => array_merge($fields, $this->features->fields($definition)),
        'slots' => $slots,
      ];
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
  protected function field(string $name, array $schema): ?array {
    $type = $schema['type'] ?? 'string';
    if (is_array($type)) {
      $type = current(array_diff($type, ['null'])) ?: 'string';
    }
    $field = [
      'name' => $name,
      'label' => $schema['title'] ?? $name,
      'description' => $schema['description'] ?? '',
      'default' => $schema['examples'][0] ?? $schema['default'] ?? NULL,
    ];
    $media_type = $schema['contentMediaType'] ?? '';
    $widget = $schema['x-builder-widget'] ?? match (TRUE) {
      !empty($schema['enum']) => 'select',
      $type === 'boolean' => 'boolean',
      $type === 'integer', $type === 'number' => 'number',
      $type !== 'string' => NULL,
      in_array($schema['format'] ?? '', ['uri', 'uri-reference', 'iri', 'iri-reference'], TRUE) => 'url',
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
    if (!empty($schema['enum'])) {
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

}
