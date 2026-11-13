<?php

declare(strict_types=1);

namespace Drupal\Tests\frame_builder\Kernel;

use Drupal\frame_builder\ComponentCatalog;
use Drupal\image\Entity\ImageStyle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Single Directory Components as the editor sees them.
 *
 * The component "cat-kinds" of the test theme has one prop of every kind.
 */
#[Group('frame_builder')]
#[RunTestsInSeparateProcesses]
class ComponentCatalogTest extends BuilderKernelTestBase {

  protected const KINDS = 'frame_builder_test_theme:cat-kinds';
  protected const LISTING = 'frame_builder_test_theme:cat-listing';
  protected const PROBE = 'frame_builder_test_theme:probe';
  protected const CAT_THEME = 'frame_builder_test_cat_theme';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['file', 'image'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['image']);
  }

  /**
   * The catalog service.
   */
  protected function catalog(): ComponentCatalog {
    return $this->container->get('frame_builder.catalog');
  }

  /**
   * The editor's description of a component, by id.
   */
  protected function component(string $id): array {
    $config = array_column($this->catalog()->editorConfig($this->createBuilderNode()), NULL, 'id');
    $this->assertArrayHasKey($id, $config);
    return $config[$id];
  }

  /**
   * The editor fields of a component, by name.
   */
  protected function fields(string $id): array {
    return array_column($this->component($id)['fields'], NULL, 'name');
  }

  /**
   * Asserts that a field is exactly this, whatever the order of its keys.
   */
  protected function assertEditorField(array $expected, array $fields): void {
    $name = $expected['name'];
    $this->assertArrayHasKey($name, $fields);
    $actual = $fields[$name];
    ksort($expected);
    ksort($actual);
    $this->assertSame($expected, $actual, "The field '$name'.");
  }

  /**
   * A component id is a kind and the rest.
   */
  #[DataProvider('ids')]
  public function testParse(string $id, array $expected): void {
    $this->assertSame($expected, ComponentCatalog::parse($id));
  }

  /**
   * Component ids and their parts.
   */
  public static function ids(): array {
    return [
      'field' => ['drupal_field:body', [ComponentCatalog::FIELD, 'body']],
      'view' => ['drupal_view:recipes:builder_1', [ComponentCatalog::VIEW, 'recipes:builder_1']],
      'block' => ['drupal_block:system_menu_block:main', [ComponentCatalog::BLOCK, 'system_menu_block:main']],
      'saved' => ['drupal_saved:1b9d6bcd-bbfd-4b2d-9b5d-ab8dfbbd4bed', [ComponentCatalog::SAVED, '1b9d6bcd-bbfd-4b2d-9b5d-ab8dfbbd4bed']],
      'paragraph' => ['drupal_paragraph:quote', [ComponentCatalog::PARAGRAPH, 'quote']],
      'component' => ['frame:heading', ['frame', 'heading']],
      'no colon' => ['heading', ['heading', '']],
      'empty' => ['', ['', '']],
    ];
  }

  /**
   * The components of the active theme and of its base themes are offered,
   * sorted by id; those of modules and of other themes are not.
   */
  public function testDefinitions(): void {
    $this->enableModules(['sdc_test']);
    $catalog = $this->catalog();
    $ids = array_keys($catalog->definitions());
    $this->assertArrayHasKey('sdc_test:my-button', $this->container->get('plugin.manager.sdc')->getDefinitions());
    $this->assertSame([], array_filter($ids, static fn (string $id): bool => str_starts_with($id, 'sdc_test:')), 'Components of modules are left out.');

    foreach (['frame:heading', 'frame:columns', 'frame:text', 'frame:image', self::PROBE, self::KINDS, self::LISTING] as $id) {
      $this->assertContains($id, $ids);
    }
    $sorted = $ids;
    sort($sorted);
    $this->assertSame($sorted, $ids);
    $this->assertSame(['frame', 'frame_builder_test_theme'], array_values(array_unique(array_map(static fn (string $id): string => ComponentCatalog::parse($id)[0], $ids))));

    $this->assertSame('Heading', $catalog->definition('frame:heading')['name']);
    $this->assertSame('Probe', $catalog->definition(self::PROBE)['name']);
    $this->assertSame([], $catalog->definition('drupal_field:title'), 'Fields, views and blocks of Drupal have no definition.');
    $this->assertSame([], $catalog->definition('sdc_test:my-button'));
    $this->assertSame([], $catalog->definition('frame:gone'));
    $this->assertSame([], $catalog->definition(''));
  }

  /**
   * What is offered follows the active theme.
   */
  public function testDefinitionsOfOtherThemes(): void {
    $this->useTheme('frame');
    $ids = array_keys($this->catalog()->definitions());
    $this->assertContains('frame:heading', $ids);
    $this->assertNotContains(self::PROBE, $ids, 'Components of a subtheme are not offered in its base theme.');

    $this->useTheme('stark');
    $this->assertSame([], $this->catalog()->definitions(), 'A theme that is not based on Frame has none of its components.');
    $this->assertSame([], $this->catalog()->libraries());
  }

  /**
   * A component that the theme replaces is offered once, as the theme's
   * version; components not made for a UI and obsolete ones are not offered.
   */
  public function testReplacedAndHiddenComponents(): void {
    $this->assertSame([], $this->catalog()->replaced());
    $this->assertArrayHasKey('frame:divider', $this->catalog()->definitions());

    $this->useTheme(self::CAT_THEME);
    $catalog = $this->catalog();
    $definitions = $catalog->definitions();
    $this->assertSame(['frame:divider' => self::CAT_THEME . ':cat-divider'], $catalog->replaced());
    $this->assertArrayNotHasKey('frame:divider', $definitions);
    $this->assertArrayHasKey(self::CAT_THEME . ':cat-divider', $definitions);
    $this->assertArrayHasKey(self::KINDS, $definitions, 'Components of the base themes stay.');
    // Not offered, yet known: a layout that names it still renders, as the replacement.
    $this->assertSame('Divider', $catalog->definition('frame:divider')['name']);
    $this->assertArrayHasKey('style', $catalog->props('frame:divider'));
    $this->assertSame([], $catalog->definition(self::CAT_THEME . ':cat-hidden'), 'A hidden component that replaces nothing is not known.');

    $all = $this->container->get('plugin.manager.sdc')->getDefinitions();
    foreach (['cat-hidden' => 'noUi', 'cat-old' => 'obsolete'] as $name => $why) {
      $this->assertArrayHasKey(self::CAT_THEME . ':' . $name, $all);
      $this->assertArrayNotHasKey(self::CAT_THEME . ':' . $name, $definitions, $why);
    }
    $this->assertNotContains('core/components.frame--divider', $catalog->libraries());
    $this->assertContains('core/components.' . self::CAT_THEME . '--cat-divider', $catalog->libraries());
    $this->assertNotContains('core/components.' . self::CAT_THEME . '--cat-hidden', $catalog->libraries());
  }

  /**
   * Components left out by the site (frame_builder.settings) or by their theme
   * (thirdPartySettings.frame_builder.offer: false) are not offered, yet a
   * layout that has one still renders it; the settings page lists them.
   */
  public function testComponentsNotOffered(): void {
    $this->useTheme(self::CAT_THEME);
    $this->config('frame_builder.settings')->set('hidden_components', [self::PROBE])->save();
    $catalog = $this->catalog();
    $definitions = $catalog->definitions();
    $utility = self::CAT_THEME . ':cat-utility';
    foreach ([self::PROBE => 'by the site', $utility => 'by the theme'] as $id => $why) {
      $this->assertArrayNotHasKey($id, $definitions, "Not offered: left out $why.");
      $this->assertNotSame([], $catalog->definition($id), "Known: a layout that has it renders ($why).");
    }
    $this->assertArrayHasKey(self::KINDS, $definitions);
    $config = array_column($catalog->editorConfig($this->createBuilderNode()), NULL, 'id');
    $this->assertTrue($config[self::PROBE]['hidden']);
    $this->assertTrue($config[$utility]['hidden']);
    $this->assertArrayNotHasKey('hidden', $config[self::KINDS]);
    // The settings page offers to choose among all of them.
    $candidates = $catalog->candidates();
    $this->assertArrayHasKey(self::PROBE, $candidates);
    $this->assertArrayHasKey($utility, $candidates);
    $this->assertArrayNotHasKey(self::CAT_THEME . ':cat-hidden', $candidates, 'noUi');
    $this->assertArrayNotHasKey('frame:divider', $candidates, 'replaced');
    // Rendered on a page.
    $build = $this->container->get('frame_builder.renderer')->build([
      'root' => ['props' => []],
      'content' => [['type' => $utility, 'props' => ['id' => 'u', 'label' => 'Still here']]],
    ]);
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);
    $this->assertStringContainsString('Still here', $html);
  }

  /**
   * The example of a component names replaced components as the theme does,
   * at every depth.
   */
  public function testExample(): void {
    $example = ['type' => self::KINDS, 'props' => ['plain' => 'An example', 'main' => [['type' => 'frame:divider', 'props' => ['style' => 'solid']]]]];
    $this->assertSame($example, $this->component(self::KINDS)['example']);
    $this->assertNull($this->component(self::PROBE)['example'], 'A component without an example.');
    $this->assertSame('frame:heading', $this->component('frame:heading')['example']['type']);

    $this->useTheme(self::CAT_THEME);
    $example['props']['main'][0]['type'] = self::CAT_THEME . ':cat-divider';
    $this->assertSame($example, $this->component(self::KINDS)['example']);
  }

  /**
   * Prop schemas and slot names of a component; none for what is no SDC.
   */
  public function testPropsAndSlots(): void {
    $catalog = $this->catalog();
    $this->assertSame(['title', 'count', 'shown', 'tone'], array_keys($catalog->props(self::PROBE)));
    $this->assertSame(['calm', 'loud'], $catalog->props(self::PROBE)['tone']['enum']);
    $this->assertSame('length', $catalog->props('frame:columns')['gap']['x-builder-widget']);
    $this->assertArrayHasKey('picture_markup', $catalog->props(self::KINDS), 'Hidden props are props.');
    $this->assertSame([], $catalog->props('drupal_field:title'));
    $this->assertSame([], $catalog->props('frame:gone'));

    $this->assertSame(['content'], $catalog->slots(self::PROBE));
    $this->assertSame(['main', 'aside'], $catalog->slots(self::KINDS));
    $this->assertSame(['actions'], $catalog->slots('frame:hero'));
    $this->assertSame([], $catalog->slots('frame:heading'));
    $this->assertSame([], $catalog->slots('drupal_block:system_powered_by_block'));
    $this->assertSame([], $catalog->slots('frame:gone'));
  }

  /**
   * Text, long text, rich text and links are language specific, unless the
   * prop opts out; so are the title of a block and the paragraph of a
   * Paragraph block.
   */
  public function testTranslatableProps(): void {
    $catalog = $this->catalog();
    $this->assertSame(['plain', 'untitled', 'long', 'rich', 'pinned', 'link', 'maybe', 'entries'], $catalog->translatableProps(self::KINDS), 'A list of texts is in the language of the page too.');
    $this->assertSame(['title'], $catalog->translatableProps(self::PROBE), 'Numbers, checkboxes and choices are the same in every language.');
    $this->assertSame(['text', 'eyebrow'], $catalog->translatableProps('frame:heading'));
    $this->assertSame(['label', 'url'], $catalog->translatableProps('frame:button'));
    $this->assertSame(['text'], $catalog->translatableProps('frame:text'));
    $this->assertSame(['caption'], $catalog->translatableProps('frame:image'), 'A picked image is not translated.');

    $this->assertSame(['title'], $catalog->translatableProps('drupal_block:system_powered_by_block'));
    $this->assertSame(['paragraph'], $catalog->translatableProps('drupal_paragraph:quote'));
    $this->assertSame([], $catalog->translatableProps('drupal_field:title'));
    $this->assertSame([], $catalog->translatableProps('drupal_view:recipes:builder_1'));
    $this->assertSame([], $catalog->translatableProps('frame:gone'));
  }

  /**
   * The formatted text values of a layout are found with their format, in
   * slots at any depth.
   */
  public function testHtmlValues(): void {
    $items = [
      self::item('frame:text', 't1', ['text' => ['value' => '<p>One</p>', 'format' => 'basic_html']]),
      self::item('frame:columns', 'c1', [
        'layout' => '2',
        'content' => [
          self::item('frame:text', 't2', ['text' => ['value' => '<p>Two</p>', 'format' => 'full_html']]),
          self::item('frame:card', 'k1', [
            'heading' => ['value' => '<p>Not a rich text prop</p>', 'format' => 'full_html'],
            'content' => [
              self::item(self::KINDS, 'k2', [
                'rich' => ['value' => '<p>Three</p>'],
                'pinned' => ['format' => 'plain_text'],
                'plain' => 'Plain',
                'aside' => [self::item('frame:text', 't3', ['text' => ['value' => 4, 'format' => 'restricted_html']])],
              ]),
            ],
          ]),
        ],
      ]),
      self::item('frame:text', 't4', ['text' => '<p>A plain string has no format to check</p>']),
      self::item('frame:gone', 'g1', ['text' => ['value' => '<p>Unknown</p>', 'format' => 'full_html']]),
      self::item('frame:hero', 'h1', ['heading' => 'Hero', 'actions' => 'not a list']),
      ['type' => 'frame:text'],
      ['props' => ['text' => ['value' => 'x', 'format' => 'y']]],
    ];
    $this->assertSame([
      ['basic_html', '<p>One</p>'],
      ['full_html', '<p>Two</p>'],
      ['', '<p>Three</p>'],
      ['plain_text', ''],
      ['restricted_html', '4'],
    ], $this->catalog()->htmlValues($items));
    $this->assertSame([], $this->catalog()->htmlValues([]));
  }

  /**
   * Every offered component has its asset library attached to the editor.
   */
  public function testLibraries(): void {
    $catalog = $this->catalog();
    $libraries = $catalog->libraries();
    $this->assertContains('core/components.frame--heading', $libraries);
    $this->assertContains('core/components.frame--accordion-item', $libraries);
    $this->assertContains('core/components.frame_builder_test_theme--probe', $libraries);
    $this->assertContains('core/components.frame_builder_test_theme--cat-kinds', $libraries);
    $this->assertCount(count($catalog->definitions()), $libraries);
    // The library core makes of the assets of a component.
    $this->assertNotEmpty($this->container->get('library.discovery')->getLibraryByName('core', 'components.frame--heading'));
  }

  /**
   * What the editor is told about a component: label, icon, group,
   * description and slots, with defaults for what a component leaves out.
   */
  public function testComponentDescription(): void {
    $kinds = $this->component(self::KINDS);
    $this->assertSame('Catalog kinds', $kinds['label']);
    $this->assertSame('star', $kinds['icon']);
    $this->assertSame('Catalog test', $kinds['group']);
    $this->assertSame('One prop of every kind the catalog turns into an editor field, and some it does not.', $kinds['description']);
    $this->assertSame([['name' => 'main', 'label' => 'Main'], ['name' => 'aside', 'label' => 'aside']], $kinds['slots'], 'A slot without a title is known by its name.');

    $listing = $this->component(self::LISTING);
    $this->assertSame('Components', $listing['group'], 'The group of components that name none.');
    $this->assertSame('', $listing['icon']);
    $this->assertSame([], $listing['slots']);

    $probe = $this->component(self::PROBE);
    $this->assertSame('Test', $probe['group']);
    $this->assertSame([['name' => 'content', 'label' => 'Content']], $probe['slots']);
    $this->assertSame('heading', $this->component('frame:heading')['icon']);
  }

  /**
   * Text props: a line of text, with the first example as its start value.
   */
  public function testTextFields(): void {
    $fields = $this->fields(self::KINDS);
    $this->assertEditorField([
      'name' => 'plain',
      'label' => 'Plain',
      'description' => 'A line of text.',
      'default' => 'From examples',
      'widget' => 'text',
      'translatable' => TRUE,
      'required' => TRUE,
    ], $fields);
    $this->assertEditorField([
      'name' => 'untitled',
      'label' => 'untitled',
      'description' => '',
      'default' => NULL,
      'widget' => 'text',
      'translatable' => TRUE,
      'required' => FALSE,
    ], $fields);
    $this->assertSame('text', $fields['fixed']['widget']);
    $this->assertFalse($fields['fixed']['translatable'], 'A prop can opt out of translation.');
    $this->assertSame('textarea', $fields['long']['widget']);
    $this->assertTrue($fields['long']['translatable']);
    $this->assertSame('text', $fields['maybe']['widget'], 'A prop that may be null is a field of its other type.');
    $this->assertSame('Probe title', $this->fields(self::PROBE)['title']['default'], 'Without examples the default is the start value.');
  }

  /**
   * Rich text props: the editor of a text format, pinned or free.
   */
  public function testHtmlFields(): void {
    $fields = $this->fields(self::KINDS);
    $this->assertEditorField([
      'name' => 'rich',
      'label' => 'Rich',
      'description' => '',
      'default' => '<p>Rich</p>',
      'widget' => 'html',
      'translatable' => TRUE,
      'required' => FALSE,
    ], $fields);
    $this->assertSame('html', $fields['pinned']['widget']);
    $this->assertSame('plain_text', $fields['pinned']['format']);
    $this->assertSame('html', $this->fields('frame:text')['text']['widget']);
  }

  /**
   * Numbers and checkboxes.
   */
  public function testNumberAndBooleanFields(): void {
    $fields = $this->fields(self::KINDS);
    $this->assertEditorField([
      'name' => 'amount',
      'label' => 'Amount',
      'description' => '',
      'default' => 1.5,
      'widget' => 'number',
      'translatable' => FALSE,
      'required' => FALSE,
    ], $fields);
    $this->assertSame('number', $fields['whole']['widget']);
    $this->assertNull($fields['whole']['default']);
    $this->assertSame('number', $fields['maybe_count']['widget'], 'Null or an integer: a number.');
    $this->assertEditorField([
      'name' => 'flag',
      'label' => 'Flag',
      'description' => '',
      'default' => FALSE,
      'widget' => 'boolean',
      'translatable' => FALSE,
      'required' => FALSE,
    ], $fields);
    $probe = $this->fields(self::PROBE);
    $this->assertSame(3, $probe['count']['default']);
    $this->assertTrue($probe['shown']['default']);
  }

  /**
   * A prop with an enum is a select, labelled by "meta:enum".
   */
  public function testSelectFields(): void {
    $fields = $this->fields(self::KINDS);
    $this->assertEditorField([
      'name' => 'choice',
      'label' => 'Choice',
      'description' => '',
      'default' => 'a',
      'widget' => 'select',
      'translatable' => FALSE,
      'options' => [['value' => 'a', 'label' => 'Option A'], ['value' => 'b', 'label' => 'b']],
      'required' => FALSE,
    ], $fields);
    $this->assertSame('loud', $fields['mood']['default']);
    $this->assertNull($fields['open']['default']);

    $level = $this->fields('frame:heading')['level'];
    $this->assertSame('select', $level['widget']);
    $this->assertSame('h2', $level['default']);
    $this->assertSame(['value' => 'h1', 'label' => 'H1: page title'], $level['options'][0]);
    $layout = $this->fields('frame:columns')['layout'];
    $this->assertSame(['2', '3', '4', '1-2', '2-1', '1-3', '3-1'], array_column($layout['options'], 'value'));
    $this->assertSame('1/3 + 2/3', $layout['options'][3]['label']);
  }

  /**
   * A prop with a URI format is a link.
   */
  public function testUrlFields(): void {
    $this->assertEditorField([
      'name' => 'link',
      'label' => 'Link',
      'description' => '',
      'default' => NULL,
      'widget' => 'url',
      'translatable' => TRUE,
      'required' => FALSE,
    ], $this->fields(self::KINDS));
    $button = $this->fields('frame:button');
    $this->assertSame('url', $button['url']['widget']);
    $this->assertSame('/', $button['url']['default']);
    $this->assertTrue($button['url']['required']);
  }

  /**
   * An image prop is picked from the media library with one of the image
   * styles of the theme; loading and link are offered when the component
   * takes the image as Drupal renders it.
   */
  public function testImageFields(): void {
    $fields = $this->fields(self::KINDS);
    $styles = [['value' => 'style:thumbnail', 'label' => (string) ImageStyle::load('thumbnail')->label()]];
    $this->assertEditorField([
      'name' => 'picture',
      'label' => 'Picture',
      'description' => '',
      'default' => NULL,
      'widget' => 'image',
      'imageStyles' => $styles,
      'imageOptions' => TRUE,
      'translatable' => FALSE,
      'required' => FALSE,
    ], $fields);
    $this->assertSame('image', $fields['photo']['widget']);
    $this->assertFalse($fields['photo']['imageOptions'], 'A component that only takes the URL has no loading and link options.');
    $this->assertSame($styles, $fields['photo']['imageStyles']);
  }

  /**
   * Props the builder fills by itself, and an object that does not say what
   * is in it, have no field. Lists and groups are covered by ListPropsTest.
   */
  public function testPropsWithoutField(): void {
    $fields = $this->fields(self::KINDS);
    foreach (['picture_markup', 'picture_srcset', 'picture_sizes', 'picture_alt', 'options'] as $name) {
      $this->assertArrayNotHasKey($name, $fields);
    }
    $image = $this->fields('frame:image');
    $this->assertArrayNotHasKey('image_picture', $image);
    $this->assertArrayNotHasKey('alt', $image);
    $this->assertSame('list', $fields['entries']['widget'], 'A list of texts has a field.');
    $this->assertArrayHasKey('caption', $image);
  }

  /**
   * A colour prop offers the palette and starts empty; a length prop offers
   * the spacing scale and starts with its default as a choice.
   */
  public function testColorAndLengthFields(): void {
    $fields = $this->fields(self::KINDS);
    $this->assertEditorField([
      'name' => 'tint',
      'label' => 'Tint',
      'description' => '',
      'default' => NULL,
      'widget' => 'color',
      'colors' => [
        ['value' => 'brand', 'label' => 'Brand', 'color' => '#112233'],
        ['value' => 'paper', 'label' => 'Paper', 'color' => '#ffffff'],
      ],
      'translatable' => FALSE,
      'required' => FALSE,
    ], $fields);
    $this->assertEditorField([
      'name' => 'gap',
      'label' => 'Gap',
      'description' => '',
      'default' => ['size' => 'm'],
      'widget' => 'length',
      'sizes' => [
        ['value' => 's', 'label' => 'S (0.5rem)'],
        ['value' => 'm', 'label' => 'M (1rem)'],
        ['value' => 'l', 'label' => 'L (2rem)'],
      ],
      'translatable' => FALSE,
      'required' => FALSE,
    ], $fields);
    $this->assertSame(['size' => 'custom', 'custom' => '3px'], $fields['inset']['default'], 'A default that is no step of the scale is a custom length.');
    $this->assertNull($fields['loose']['default'], 'A default that is no length is no default.');
    $this->assertSame(['size' => 'l'], $this->fields('frame:divider')['space']['default']);
  }

  /**
   * An icon prop is a picker of its enum values, drawn with a class.
   */
  public function testIconFields(): void {
    $fields = $this->fields(self::KINDS);
    $this->assertEditorField([
      'name' => 'symbol',
      'label' => 'Symbol',
      'description' => '',
      'default' => NULL,
      'widget' => 'icon',
      'translatable' => FALSE,
      'options' => [['value' => 'star', 'label' => 'star'], ['value' => 'heart', 'label' => 'heart']],
      'iconClass' => 'icon icon-{name}',
      'required' => FALSE,
    ], $fields);
    $this->assertSame('{name}', $fields['glyph']['iconClass'], 'Without a class the name of the icon is its class.');
  }

  /**
   * The fields of a component are its props in their order, the library
   * field, then the features under "Layout and style".
   */
  public function testOrderOfFields(): void {
    $kinds = $this->component(self::KINDS)['fields'];
    $this->assertSame([
      'plain', 'untitled', 'fixed', 'long', 'rich', 'pinned', 'amount', 'whole', 'flag', 'choice', 'mood', 'open', 'link',
      'picture', 'photo', 'tint', 'gap', 'inset', 'loose', 'symbol', 'glyph', 'maybe', 'maybe_count', 'entries',
      '_library', '_spacing', '_size', '_align', '_text', '_background', '_border',
    ], array_column($kinds, 'name'), 'The component switches its position off.');
    $library = array_column($kinds, NULL, 'name')['_library'];
    $this->assertSame(['name' => '_library', 'label' => 'Library', 'widget' => 'library', 'default' => NULL, 'required' => FALSE, 'translatable' => FALSE, 'offer' => FALSE], $library);
    $this->assertSame('Layout and style', array_column($kinds, NULL, 'name')['_spacing']['heading']);

    $this->assertSame(
      ['title', 'count', 'shown', 'tone', '_library', '_spacing', '_size', '_align', '_text', '_background', '_border', '_position'],
      array_column($this->component(self::PROBE)['fields'], 'name'),
    );
    $this->assertSame(['endpoint', 'heading', '_library'], array_column($this->component(self::LISTING)['fields'], 'name'), 'A component without features ends with the library field.');
    $this->assertNotContains('_background', array_column($this->component('frame:badge')['fields'], 'name'));
  }

  /**
   * The components of the theme come first in the list, sorted by id.
   */
  public function testOrderOfComponents(): void {
    $catalog = $this->catalog();
    $config = $catalog->editorConfig($this->createBuilderNode());
    $ids = array_keys($catalog->definitions());
    $this->assertSame($ids, array_slice(array_column($config, 'id'), 0, count($ids)));
    foreach (array_slice($config, count($ids)) as $component) {
      $this->assertStringStartsWith('drupal_', $component['id']);
    }
    foreach ($config as $component) {
      $this->assertSame(['id', 'label', 'icon', 'group', 'description', 'fields', 'slots'], array_values(array_intersect(array_keys($component), ['id', 'label', 'icon', 'group', 'description', 'fields', 'slots'])), $component['id']);
      $this->assertCount(1, array_keys(array_column($component['fields'], 'name'), '_library', TRUE), $component['id'] . ' has one library field.');
    }
  }

}
