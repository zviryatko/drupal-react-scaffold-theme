<?php

declare(strict_types=1);

namespace Drupal\Tests\frame_builder\Kernel;

use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\frame_builder\ComponentCatalog;
use Drupal\node\NodeInterface;
use Drupal\views\Entity\View;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * What Drupal gives the editor as components: the fields of the page, views
 * made for the builder and blocks.
 */
#[Group('frame_builder')]
#[RunTestsInSeparateProcesses]
class ComponentCatalogSourcesTest extends BuilderKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['block', 'block_test'];

  /**
   * The "Component" display of views has no config schema yet (see
   * ViewsPluginsTest::testConfigSchemaOfTheDisplay()): views that use it are
   * saved without the check.
   *
   * @var bool
   */
  protected $strictConfigSchema = FALSE;

  /**
   * The catalog service.
   */
  protected function catalog(): ComponentCatalog {
    return $this->container->get('frame_builder.catalog');
  }

  /**
   * The components of one group of the editor, by id, in their order.
   */
  protected function group(string $group, ?NodeInterface $node = NULL): array {
    $config = $this->catalog()->editorConfig($node ?? $this->createBuilderNode());
    return array_column(array_filter($config, static fn (array $component): bool => $component['group'] === $group), NULL, 'id');
  }

  /**
   * Adds a field to the "landing" content type.
   */
  protected function addField(string $name, string $type, string $label, array $storage_settings = []): void {
    FieldStorageConfig::create(['field_name' => $name, 'entity_type' => 'node', 'type' => $type, 'settings' => $storage_settings])->save();
    FieldConfig::create(['field_name' => $name, 'entity_type' => 'node', 'bundle' => 'landing', 'label' => $label])->save();
  }

  /**
   * Creates a view of content with a master display and the given displays.
   *
   * @param string $id
   *   Id of the view.
   * @param string $label
   *   Its name.
   * @param array $displays
   *   Display id => [display plugin, title].
   * @param array $values
   *   Other values of the view.
   */
  protected function createView(string $id, string $label, array $displays, array $values = []): void {
    $display = ['default' => ['id' => 'default', 'display_plugin' => 'default', 'display_title' => 'Default', 'position' => 0, 'display_options' => []]];
    foreach ($displays as $display_id => [$plugin, $title]) {
      $display[$display_id] = ['id' => $display_id, 'display_plugin' => $plugin, 'display_title' => $title, 'position' => count($display), 'display_options' => $plugin === 'page' ? ['path' => "cat/$id/$display_id"] : []];
    }
    View::create($values + ['id' => $id, 'label' => $label, 'base_table' => 'node_field_data', 'base_field' => 'nid', 'display' => $display])->save();
  }

  /**
   * The title and every field with a configurable display is a component,
   * sorted by label; the layout itself is not.
   */
  public function testFieldComponents(): void {
    $this->addField('field_note', 'string', 'Note');
    $this->addField('field_count', 'integer', 'Count');
    $fields = $this->group('Fields');
    $this->assertSame(['drupal_field:field_count', 'drupal_field:field_note', 'drupal_field:title'], array_keys($fields));
    $this->assertSame(['Count', 'Note', 'Title'], array_column($fields, 'label'));
    $node = $this->createBuilderNode();
    $this->assertTrue($node->hasField('frame_layout'));
    $this->assertFalse($node->getFieldDefinition('title')->isDisplayConfigurable('view'), 'The title is offered although its display is not configurable.');
    $this->assertFalse($node->getFieldDefinition('status')->isDisplayConfigurable('view'));
    $this->assertArrayNotHasKey('drupal_field:status', $fields, 'Another field without a configurable display is not offered.');

    $note = $fields['drupal_field:field_note'];
    $this->assertSame('field', $note['icon']);
    $this->assertSame([], $note['slots']);
    $this->assertSame('The field_note field of this page, in the language being viewed.', $note['description']);
    $this->assertSame(
      ['formatter', 'label', 'label_style', '_library', '_spacing', '_size', '_align', '_text', '_background', '_border', '_position'],
      array_column($note['fields'], 'name'),
      'A field has the features of the theme, like a component with an empty definition.',
    );
    $this->assertFalse(array_column($note['fields'], NULL, 'name')['_library']['offer']);
  }

  /**
   * A Field block chooses a formatter of the field, the position of its
   * label and the look of the label.
   */
  public function testFieldComponentFields(): void {
    $this->addField('field_count', 'integer', 'Count');
    $fields = array_column($this->group('Fields')['drupal_field:field_count']['fields'], NULL, 'name');

    $this->assertSame([
      'name' => 'formatter',
      'label' => 'Format',
      'widget' => 'formatter',
      'fieldName' => 'field_count',
      'options' => [
        ['value' => 'number_integer', 'label' => 'Default', 'settings' => TRUE],
        ['value' => 'number_unformatted', 'label' => 'Unformatted', 'settings' => FALSE],
      ],
      'default' => 'number_integer',
      'required' => TRUE,
      'translatable' => FALSE,
    ], $fields['formatter'], 'Whether a formatter has settings is told with it.');

    $this->assertSame('select', $fields['label']['widget']);
    $this->assertSame(['hidden', 'above', 'inline', 'visually_hidden'], array_column($fields['label']['options'], 'value'));
    $this->assertSame('above', $fields['label']['default']);
    $this->assertTrue($fields['label']['required']);

    $this->assertSame('textstyle', $fields['label_style']['widget']);
    $this->assertSame(['s', 'm', 'l'], array_column($fields['label_style']['sizes'], 'value'));
    $this->assertSame(['brand', 'paper'], array_column($fields['label_style']['colors'], 'value'));
    $this->assertNull($fields['label_style']['default']);
    $this->assertFalse($fields['label_style']['translatable']);
  }

  /**
   * The formatters that can show a field, the default one of the field type
   * first.
   */
  public function testFormatterOptions(): void {
    $this->addField('field_related', 'entity_reference', 'Related', ['target_type' => 'node']);
    $this->addField('field_note', 'string', 'Note');
    $node = $this->createBuilderNode();
    $catalog = $this->catalog();

    $options = $catalog->formatterOptions($node, 'field_related');
    $this->assertSame('entity_reference_label', array_key_first($options), 'The default formatter of the field type comes first.');
    $this->assertSame('Label', $options['entity_reference_label']);
    $this->assertArrayHasKey('entity_reference_entity_view', $options);
    $this->assertArrayHasKey('entity_reference_entity_id', $options);
    $this->assertArrayNotHasKey('frame_builder_media_responsive', $options, 'A formatter that does not apply to the field is not offered.');
    $all = array_keys($this->container->get('plugin.manager.field.formatter')->getOptions('entity_reference'));
    $this->assertNotSame('entity_reference_label', $all[0], 'It is not the first one by itself.');

    $this->assertSame(['string' => 'Plain text'], $catalog->formatterOptions($node, 'field_note'));
    $this->assertSame(['string' => 'Plain text'], $catalog->formatterOptions($node, 'title'));
    $this->assertSame([], $catalog->formatterOptions($node, 'field_gone'));
  }

  /**
   * A Field block starts with the formatter and settings the content type
   * shows the field with; a field hidden there starts with the first
   * formatter.
   */
  public function testDisplayedFormatter(): void {
    $this->addField('field_count', 'integer', 'Count');
    $this->addField('field_note', 'string', 'Note');
    $this->addField('field_extra', 'string', 'Extra');
    $default = fn (string $field): mixed => array_column($this->group('Fields')["drupal_field:$field"]['fields'], NULL, 'name')['formatter']['default'];

    $this->assertSame('number_integer', $default('field_count'), 'Without a display of the content type.');

    $display = EntityViewDisplay::create(['targetEntityType' => 'node', 'bundle' => 'landing', 'mode' => 'default', 'status' => TRUE]);
    $display->setComponent('field_count', ['type' => 'number_unformatted', 'label' => 'inline']);
    $display->setComponent('field_note', ['type' => 'string', 'settings' => ['link_to_entity' => TRUE]]);
    $display->removeComponent('field_extra');
    $display->save();

    $this->assertSame(['type' => 'number_unformatted', 'settings' => []], $default('field_count'));
    $note = $default('field_note');
    $this->assertSame(['type', 'settings'], array_keys($note));
    $this->assertSame('string', $note['type']);
    $this->assertTrue($note['settings']['link_to_entity'], 'The settings of the display come with the formatter.');
    $this->assertSame('string', $default('field_extra'), 'Hidden in the display.');

    // Another view mode does not count.
    $teaser = EntityViewDisplay::create(['targetEntityType' => 'node', 'bundle' => 'landing', 'mode' => 'teaser', 'status' => TRUE]);
    $teaser->setComponent('field_extra', ['type' => 'string', 'settings' => ['link_to_entity' => TRUE]]);
    $teaser->save();
    $this->assertSame('string', $default('field_extra'));
  }

  /**
   * The fields are those of the content type of the page.
   */
  public function testFieldComponentsFollowTheContentType(): void {
    $this->addField('field_note', 'string', 'Note');
    $this->createBuilderType('other');
    $this->assertArrayHasKey('drupal_field:field_note', $this->group('Fields'));
    $this->assertArrayNotHasKey('drupal_field:field_note', $this->group('Fields', $this->createBuilderNode(['type' => 'other'])));
  }

  /**
   * Without the Views module there are no view components.
   */
  public function testNoViewsWithoutViewsModule(): void {
    $this->assertSame([], $this->group('Views'));
  }

  /**
   * Every "Component" display of an enabled view is a component; pages,
   * embeds and the master display are not.
   */
  public function testViewComponents(): void {
    $this->enableModules(['views']);
    $this->createView('cat_recipes', 'Recipes', ['builder_1' => ['frame_builder', 'Component'], 'page_1' => ['page', 'Page']], ['description' => 'Every recipe of the site.']);
    $this->createView('cat_news', 'News', ['builder_1' => ['frame_builder', 'Latest'], 'builder_2' => ['frame_builder', 'Archive'], 'embed_1' => ['embed', 'Embed']]);
    $this->createView('cat_pages', 'Pages only', ['page_1' => ['page', 'Page'], 'embed_1' => ['embed', 'Embed']]);
    $this->createView('cat_off', 'Disabled', ['builder_1' => ['frame_builder', 'Component']], ['status' => FALSE]);

    $views = $this->group('Views');
    $this->assertSame(['drupal_view:cat_news:builder_2', 'drupal_view:cat_news:builder_1', 'drupal_view:cat_recipes:builder_1'], array_keys($views), 'Sorted by label.');
    $this->assertSame(['News: Archive', 'News: Latest', 'Recipes'], array_column($views, 'label'), 'The display is named when a view has more than one.');

    $recipes = $views['drupal_view:cat_recipes:builder_1'];
    $this->assertSame('view', $recipes['icon']);
    $this->assertSame('Every recipe of the site.', $recipes['description']);
    $this->assertSame([], $recipes['slots']);
    $this->assertSame(['_library', '_spacing', '_size', '_align', '_text', '_background', '_border', '_position'], array_column($recipes['fields'], 'name'), 'A view has no settings of its own, only the features.');
    $this->assertSame('Layout and style', $recipes['fields'][1]['heading']);
  }

  /**
   * Blocks that can be placed without more input are components, sorted by
   * label, with an optional title.
   */
  public function testBlockComponents(): void {
    $blocks = $this->group('Blocks');
    $definitions = $this->container->get('plugin.manager.block')->getDefinitions();

    foreach (['system_powered_by_block', 'system_branding_block', 'test_html', 'test_access', 'system_menu_block:main'] as $id) {
      $this->assertArrayHasKey("drupal_block:$id", $blocks);
    }
    $this->assertArrayHasKey('drupal_block:test_context_aware', $blocks, 'A context that is optional does not keep a block out.');

    $this->assertArrayHasKey('test_context_aware_unsatisfied', $definitions);
    $this->assertArrayNotHasKey('drupal_block:test_context_aware_unsatisfied', $blocks, 'A block that needs a context.');
    $this->assertArrayHasKey('node_syndicate_block', $definitions);
    $this->assertArrayNotHasKey('drupal_block:node_syndicate_block', $blocks, 'A block hidden from the block UI.');
    $this->assertArrayHasKey('system_main_block', $definitions);
    $this->assertArrayNotHasKey('drupal_block:system_main_block', $blocks, 'The main content of the page.');
    $this->assertArrayHasKey('broken', $definitions);
    $this->assertArrayNotHasKey('drupal_block:broken', $blocks);

    $labels = array_column($blocks, 'label');
    $sorted = $labels;
    usort($sorted, 'strnatcasecmp');
    $this->assertSame($sorted, $labels);

    $powered = $blocks['drupal_block:system_powered_by_block'];
    $this->assertSame('Powered by Drupal', $powered['label']);
    $this->assertSame('block', $powered['icon']);
    $this->assertSame('System', $powered['description'], 'The category of the block.');
    $this->assertTrue($powered['collapsed']);
    $this->assertSame([], $powered['slots']);
    $this->assertSame(['title', '_library', '_spacing', '_size', '_align', '_text', '_background', '_border', '_position'], array_column($powered['fields'], 'name'));
    $this->assertSame([
      'name' => 'title',
      'label' => 'Title',
      'description' => 'Shown above the block. Leave empty for no title.',
      'widget' => 'text',
      'default' => NULL,
      'required' => FALSE,
      'translatable' => TRUE,
    ], $powered['fields'][0]);
  }

  /**
   * The list for the editor: components of the theme, then fields, views
   * and blocks. Paragraphs and saved blocks are there only when they exist.
   */
  public function testOrderOfSources(): void {
    $this->enableModules(['views']);
    $this->createView('cat_recipes', 'Recipes', ['builder_1' => ['frame_builder', 'Component']]);
    $config = $this->catalog()->editorConfig($this->createBuilderNode());
    $kinds = array_values(array_unique(array_map(static fn (array $component): string => ComponentCatalog::parse($component['id'])[0], $config)));
    $this->assertSame(['frame', 'frame_builder_test_theme', ComponentCatalog::FIELD, ComponentCatalog::VIEW, ComponentCatalog::BLOCK], $kinds);
    $this->assertSame([], $this->group('Paragraphs'));
    $this->assertSame([], $this->group('Library'));
  }

  /**
   * The features of fields, views and blocks are those of the theme.
   */
  public function testFeaturesOfSourcesFollowTheTheme(): void {
    $this->useTheme('frame_builder_test_cat_theme');
    $title = $this->group('Fields')['drupal_field:title'];
    $this->assertSame(['formatter', 'label', 'label_style', '_library', '_spacing', '_size', '_align', '_text', '_border'], array_column($title['fields'], 'name'), 'The theme switches background and position off.');
  }

}
