<?php

declare(strict_types=1);

namespace Drupal\Tests\frame_builder\Kernel;

use Drupal\Core\Config\Schema\SchemaCheckTrait;
use Drupal\Core\Form\FormState;
use Drupal\frame_builder\ComponentCatalog;
use Drupal\frame_builder\Plugin\views\display\BuilderBlock;
use Drupal\frame_builder\Plugin\views\style\ReactComponent;
use Drupal\Tests\frame_builder\Traits\RenderingTestTrait;
use Drupal\views\Entity\View;
use Drupal\views\ViewExecutable;
use Drupal\views\Views;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The Views plugins of the builder: the "Component" display and the
 * "React component (SDC)" style.
 */
#[Group('frame_builder')]
#[RunTestsInSeparateProcesses]
class ViewsPluginsTest extends BuilderKernelTestBase {

  use RenderingTestTrait;
  use SchemaCheckTrait;

  protected const LISTING = 'frame_builder_test_theme:cat-listing';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['views', 'serialization', 'rest'];

  /**
   * The "Component" display has no config schema yet (see
   * testConfigSchemaOfTheDisplay()): views that use it are saved without
   * the check.
   *
   * @var bool
   */
  protected $strictConfigSchema = FALSE;

  /**
   * Creates a view of the titles of content with three displays next to the
   * master: "builder_1" (Component, drawn by Views), "builder_react"
   * (Component, with the React component style) and "rest_1" (REST export
   * at /api/cat-recipes).
   *
   * @param array $style
   *   Options of the React component style of "builder_react".
   */
  protected function createListing(array $style = []): void {
    $display = fn (string $display_id, string $plugin, string $title, array $options = []): array => [
      'id' => $display_id,
      'display_plugin' => $plugin,
      'display_title' => $title,
      'position' => 0,
      'display_options' => $options,
    ];
    $title = ['id' => 'title', 'table' => 'node_field_data', 'field' => 'title', 'entity_type' => 'node', 'entity_field' => 'title'];
    View::create([
      'id' => 'cat_recipes',
      'label' => 'Recipes',
      'base_table' => 'node_field_data',
      'base_field' => 'nid',
      'display' => [
        'default' => $display('default', 'default', 'Default', [
          'access' => ['type' => 'none', 'options' => []],
          'cache' => ['type' => 'none', 'options' => []],
          'style' => ['type' => 'default'],
          'row' => ['type' => 'fields'],
          'fields' => ['title' => $title + ['plugin_id' => 'field', 'label' => '', 'type' => 'string', 'settings' => ['link_to_entity' => FALSE]]],
          'sorts' => ['title' => $title + ['plugin_id' => 'standard', 'order' => 'ASC']],
        ]),
        'builder_1' => $display('builder_1', 'frame_builder', 'Component'),
        'builder_react' => $display('builder_react', 'frame_builder', 'Interactive', [
          'style' => ['type' => 'frame_component', 'options' => $style + ['component' => self::LISTING, 'rest_display' => 'rest_1', 'props' => '{"heading": "All recipes"}']],
          'defaults' => ['style' => FALSE, 'row' => FALSE],
        ]),
        'rest_1' => $display('rest_1', 'rest_export', 'REST', [
          'path' => 'api/cat-recipes',
          'style' => ['type' => 'serializer', 'options' => ['formats' => ['json' => 'json']]],
          'row' => ['type' => 'data_field'],
          'defaults' => ['style' => FALSE, 'row' => FALSE],
        ]),
        'page_1' => $display('page_1', 'page', 'Page', ['path' => 'cat-recipes']),
      ],
    ])->save();
    $this->container->get('router.builder')->rebuild();
  }

  /**
   * The view of the tests, set to one of its displays.
   */
  protected function listing(string $display_id): ViewExecutable {
    $view = Views::getView('cat_recipes');
    $this->assertTrue($view->setDisplay($display_id));
    return $view;
  }

  /**
   * The "Component" display is a display type of Views, without a path, a
   * menu link or a block of its own.
   */
  public function testDisplayPlugin(): void {
    $definition = $this->container->get('plugin.manager.views.display')->getDefinition(ComponentCatalog::VIEW_DISPLAY);
    $this->assertSame(BuilderBlock::class, $definition['class']);
    $this->assertSame('Component', (string) $definition['title']);
    $this->assertFalse($definition['uses_menu_links']);
    $this->assertEmpty($definition['uses_route'] ?? FALSE);
    $this->assertEmpty($definition['uses_hook_block'] ?? FALSE);
    $this->assertContains('frame_builder', array_keys(Views::fetchPluginNames('display')));
  }

  /**
   * A view with a "Component" display is offered to the builder, under the
   * id the renderer builds it from; its other displays are not.
   */
  public function testDisplayIsOfferedToTheBuilder(): void {
    $this->createListing();
    $config = array_column($this->container->get('frame_builder.catalog')->editorConfig($this->createBuilderNode()), NULL, 'id');
    $views = array_filter(array_keys($config), static fn (string $id): bool => str_starts_with($id, 'drupal_view:'));
    $this->assertSame(['drupal_view:cat_recipes:builder_1', 'drupal_view:cat_recipes:builder_react'], array_values($views));
    $this->assertSame('Recipes: Component', $config['drupal_view:cat_recipes:builder_1']['label']);
    $this->assertSame('Recipes: Interactive', $config['drupal_view:cat_recipes:builder_react']['label']);

    $build = $this->container->get('frame_builder.renderer')->buildItem(self::item('drupal_view:cat_recipes:builder_1', 'v1'));
    $this->assertSame(['#type' => 'view', '#name' => 'cat_recipes', '#display_id' => 'builder_1'], $build);
  }

  /**
   * Executed, the display is the rendered view: its rows, as its style
   * draws them, and attachments of other displays are allowed.
   */
  public function testDisplayRendersTheView(): void {
    $this->createListing();
    $this->createBuilderNode(['title' => 'Risotto']);
    $this->createBuilderNode(['title' => 'Arancini']);
    $view = $this->listing('builder_1');
    $this->assertInstanceOf(BuilderBlock::class, $view->display_handler);
    $this->assertTrue($view->display_handler->usesAttachments());
    $this->assertFalse($view->display_handler->hasPath());

    $build = $view->executeDisplay('builder_1');
    $this->assertCount(2, $view->result);
    $html = $this->renderHtml($build);
    $this->assertStringContainsString('Arancini', $html);
    $this->assertStringContainsString('Risotto', $html);
  }

  /**
   * The style is a style of Views for normal displays, without rows and
   * fields of its own.
   */
  public function testStylePlugin(): void {
    $definition = $this->container->get('plugin.manager.views.style')->getDefinition('frame_component');
    $this->assertSame(ReactComponent::class, $definition['class']);
    $this->assertSame('React component (SDC)', (string) $definition['title']);
    $this->assertSame(['normal'], $definition['display_types']);

    $this->createListing();
    $style = $this->listing('builder_react')->getStyle();
    $this->assertInstanceOf(ReactComponent::class, $style);
    $this->assertFalse($style->usesRowPlugin());
    $this->assertFalse($style->usesFields());
    $this->assertTrue($style->evenEmpty(), 'The component is printed also when the view has no results: it loads them itself.');
    $this->assertSame(['component' => self::LISTING, 'rest_display' => 'rest_1', 'props' => '{"heading": "All recipes"}'], array_intersect_key($style->options, ['component' => 1, 'rest_display' => 1, 'props' => 1]));
  }

  /**
   * The options of the style are empty until they are set.
   */
  public function testStyleDefaults(): void {
    $this->createListing(['component' => '', 'rest_display' => '', 'props' => '']);
    $view = $this->listing('builder_1');
    $style = $this->container->get('plugin.manager.views.style')->createInstance('frame_component');
    $style->init($view, $view->display_handler);
    $this->assertSame('', $style->options['component']);
    $this->assertSame('', $style->options['rest_display']);
    $this->assertSame('', $style->options['props']);
    $this->assertSame([], $style->render(), 'Without a component and a REST display there is nothing to mount.');
  }

  /**
   * The style renders the component with the URL of the REST display as
   * its "endpoint" and the extra props.
   */
  public function testStyleRendersTheComponent(): void {
    $this->createListing();
    $view = $this->listing('builder_react');
    $view->preExecute();
    $view->execute();
    $build = $view->getStyle()->render();
    $this->assertSame([
      '#type' => 'component',
      '#component' => self::LISTING,
      '#props' => ['heading' => 'All recipes', 'endpoint' => '/api/cat-recipes'],
    ], $build);
  }

  /**
   * With the style a display runs no query and prints no rows: the
   * component is all there is, also for a view without results.
   */
  public function testStyleRunsNoQuery(): void {
    $this->createListing();
    $this->createBuilderNode(['title' => 'Risotto']);
    $view = $this->listing('builder_react');
    $build = $view->executeDisplay('builder_react');
    $this->assertSame([], $view->result);
    $this->assertTrue($view->executed);
    $html = $this->renderHtml($build);
    $this->assertMatchesRegularExpression('#<div[^>]*class="[^"]*cat-listing[^"]*"[^>]*data-endpoint="/api/cat-recipes"[^>]*>All recipes</div>#', $html);
    $this->assertStringNotContainsString('Risotto', $html);

    // The same view through its other display does print rows.
    $this->assertStringContainsString('Risotto', $this->renderHtml($this->listing('builder_1')->executeDisplay('builder_1')));
  }

  /**
   * Placed in a layout, the display with the style is the component.
   */
  public function testStyleInALayout(): void {
    $this->createListing();
    $build = $this->container->get('frame_builder.renderer')->build(self::layout(self::item('drupal_view:cat_recipes:builder_react', 'v1', ['_align' => 'center'])));
    $html = $this->renderHtml($build);
    $this->assertStringContainsString('data-endpoint="/api/cat-recipes"', $html);
    $this->assertStringStartsWith('<div style="text-align: center">', trim($html));
  }

  /**
   * The URL of the REST display always wins over an "endpoint" among the
   * extra props; extra props that are no JSON are ignored.
   */
  public function testExtraProps(): void {
    $this->createListing(['props' => '{"endpoint": "https://example.com/other", "heading": "Typed"}']);
    $view = $this->listing('builder_react');
    $this->assertSame(['endpoint' => '/api/cat-recipes', 'heading' => 'Typed'], $view->getStyle()->render()['#props']);

    foreach (['', 'not json', '{"heading": ', '[]', 'null'] as $props) {
      $view = $this->listing('builder_react');
      $view->getStyle()->options['props'] = $props;
      $this->assertSame(['endpoint' => '/api/cat-recipes'], $view->getStyle()->render()['#props'], "Extra props: $props");
    }
  }

  /**
   * Extra props that are JSON but no object are ignored like other input
   * that gives no props.
   */
  public function testExtraPropsThatAreNoObject(): void {
    $this->createListing();
    foreach (['"Recipes"', '5', 'true'] as $props) {
      $view = $this->listing('builder_react');
      $view->getStyle()->options['props'] = $props;
      $this->assertSame(['endpoint' => '/api/cat-recipes'], $view->getStyle()->render()['#props'], "Extra props: $props");
    }
  }

  /**
   * The exposed form of the display is not printed: the component draws
   * the controls.
   */
  public function testExposedFormIsLeftToTheComponent(): void {
    $this->createListing();
    $view = $this->listing('builder_react');
    $view->exposed_widgets = ['#markup' => 'The exposed form'];
    $view->getStyle()->render();
    $this->assertNull($view->exposed_widgets);
  }

  /**
   * The options form offers the components that take an "endpoint" and the
   * REST displays of the view.
   */
  public function testStyleOptionsForm(): void {
    $this->createListing();
    $form = [];
    $form_state = new FormState();
    $this->listing('builder_react')->getStyle()->buildOptionsForm($form, $form_state);

    $this->assertSame([self::LISTING => 'Catalog listing (' . self::LISTING . ')'], array_intersect_key($form['component']['#options'], [self::LISTING => 1, 'frame_builder_test_theme:probe' => 1, 'frame:heading' => 1]));
    foreach (array_keys($form['component']['#options']) as $id) {
      $this->assertArrayHasKey('endpoint', $this->container->get('plugin.manager.sdc')->getDefinition($id)['props']['properties'], "$id takes an endpoint.");
    }
    $this->assertSame(self::LISTING, $form['component']['#default_value']);
    $this->assertTrue($form['component']['#required']);

    $this->assertSame(['rest_1' => 'REST (/api/cat-recipes)'], $form['rest_display']['#options'], 'Pages and other displays are no source of data.');
    $this->assertSame('rest_1', $form['rest_display']['#default_value']);
    $this->assertTrue($form['rest_display']['#required']);
    $this->assertSame('{"heading": "All recipes"}', $form['props']['#default_value']);
  }

  /**
   * A view with the display and the style is valid configuration.
   */
  public function testConfigSchemaOfTheDisplay(): void {
    $this->createListing();
    $typed = $this->container->get('config.typed');
    $this->assertTrue($this->checkConfigSchema($typed, 'views.view.cat_recipes', $this->config('views.view.cat_recipes')->get()));
  }

  /**
   * The options of the style have a config schema.
   */
  public function testConfigSchemaOfTheStyle(): void {
    $definition = $this->container->get('config.typed')->getDefinition('views.style.frame_component');
    $this->assertSame(['component', 'rest_display', 'props'], array_keys(array_intersect_key($definition['mapping'], ['component' => 1, 'rest_display' => 1, 'props' => 1])));
  }

}
