<?php

declare(strict_types=1);

namespace Drupal\Tests\frame_builder\Kernel;

use Drupal\Core\Render\Element;
use Drupal\frame_builder\LayoutRenderer;
use Drupal\Tests\frame_builder\Traits\RenderingTestTrait;
use Drupal\views\Entity\View;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Drupal blocks and views placed in a layout.
 */
#[Group('frame_builder')]
#[RunTestsInSeparateProcesses]
class LayoutRendererBlockViewTest extends BuilderKernelTestBase {

  use RenderingTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['block', 'block_test', 'views'];

  /**
   * The "Component" display of views has no config schema yet (see
   * ViewsPluginsTest::testConfigSchemaOfTheDisplay()): views that use it are
   * saved without the check.
   *
   * @var bool
   */
  protected $strictConfigSchema = FALSE;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->config('system.site')->set('name', 'Test site')->save();
  }

  /**
   * The renderer of layouts.
   */
  protected function layouts(): LayoutRenderer {
    return $this->container->get('frame_builder.renderer');
  }

  /**
   * The render array of a Drupal block in a layout.
   */
  protected function block(string $plugin_id, array $values = []): array {
    return $this->layouts()->buildItem(self::item("drupal_block:$plugin_id", 'b1', $values), $this->createBuilderNode());
  }

  /**
   * Asserts that a block that prints nothing is only cache data and assets.
   */
  protected function assertPrintsNothing(array $build, string $message = ''): void {
    $this->assertSame([], Element::children($build), $message);
    $this->assertSame([], array_values(array_diff(array_keys($build), ['#cache', '#attached'])), $message);
    $this->assertSame('', trim($this->renderHtml($build)), $message);
  }

  /**
   * Creates a view that lists the titles of content, with a "Component"
   * display "builder_1", a second one "builder_perm" for users who may
   * administer content, and an embed display "embed_1".
   */
  protected function createListing(string $id = 'cat_recipes', bool $status = TRUE): void {
    $display = fn (string $display_id, string $plugin, array $options = []): array => [
      'id' => $display_id,
      'display_plugin' => $plugin,
      'display_title' => $display_id,
      'position' => 0,
      'display_options' => $options,
    ];
    View::create([
      'id' => $id,
      'label' => 'Recipes',
      'base_table' => 'node_field_data',
      'base_field' => 'nid',
      'status' => $status,
      'display' => [
        'default' => $display('default', 'default', [
          'access' => ['type' => 'none', 'options' => []],
          'cache' => ['type' => 'tag', 'options' => []],
          'style' => ['type' => 'default'],
          'row' => ['type' => 'fields'],
          'fields' => [
            'title' => [
              'id' => 'title',
              'table' => 'node_field_data',
              'field' => 'title',
              'entity_type' => 'node',
              'entity_field' => 'title',
              'plugin_id' => 'field',
              'label' => '',
              'type' => 'string',
              'settings' => ['link_to_entity' => FALSE],
            ],
          ],
          'sorts' => [
            'title' => ['id' => 'title', 'table' => 'node_field_data', 'field' => 'title', 'entity_type' => 'node', 'entity_field' => 'title', 'plugin_id' => 'standard', 'order' => 'ASC'],
          ],
        ]),
        'builder_1' => $display('builder_1', 'frame_builder'),
        'builder_perm' => $display('builder_perm', 'frame_builder', [
          'access' => ['type' => 'perm', 'options' => ['perm' => 'administer nodes']],
          'defaults' => ['access' => FALSE],
        ]),
        'embed_1' => $display('embed_1', 'embed'),
      ],
    ])->save();
  }

  /**
   * A block is built by its plugin and wrapped in the block markup of the
   * theme; without a title it has none.
   */
  public function testBlock(): void {
    $build = $this->block('system_powered_by_block');
    $this->assertSame('block', $build['#theme']);
    $this->assertSame('system_powered_by_block', $build['#plugin_id']);
    $this->assertSame('system_powered_by_block', $build['#base_plugin_id']);
    $this->assertNull($build['#derivative_plugin_id']);
    $this->assertSame('', $build['#configuration']['label']);
    $this->assertSame('0', $build['#configuration']['label_display']);
    $this->assertArrayHasKey('content', $build);
    $html = $this->renderHtml($build);
    $this->assertStringContainsString('Powered by', $html);
    $this->assertStringNotContainsString('<h2', $html);
  }

  /**
   * The title of a block is shown above it: trimmed, with tokens replaced,
   * as plain text.
   */
  public function testBlockTitle(): void {
    $build = $this->block('system_powered_by_block', ['title' => '  Made with  ']);
    $this->assertSame('Made with', $build['#configuration']['label']);
    $this->assertSame('visible', $build['#configuration']['label_display']);
    $this->assertMatchesRegularExpression('#<h2[^>]*>Made with</h2>#', $this->renderHtml($build));

    $build = $this->block('system_powered_by_block', ['title' => 'About [site:name] <b>&</b> [site:gone]']);
    $this->assertSame('About Test site <b>&</b> ', $build['#configuration']['label'], 'A token of the site that has no value is removed.');
    $this->assertContains('config:system.site', $build['#cache']['tags'], 'The page changes with the name of the site.');
    $html = $this->renderHtml($build);
    $this->assertStringContainsString('About Test site &lt;b&gt;&amp;&lt;/b&gt;', $html);
    $this->assertStringNotContainsString('<b>&</b>', $html);

    foreach (['', '   ', NULL, ['a', 'list']] as $title) {
      $build = $this->block('system_powered_by_block', ['title' => $title]);
      $this->assertSame('', $build['#configuration']['label']);
      $this->assertSame('0', $build['#configuration']['label_display']);
    }
  }

  /**
   * A block the user may not see prints nothing, but what decided that
   * still reaches the page.
   */
  public function testBlockAccess(): void {
    $store = $this->container->get('keyvalue')->get('block_test');
    $store->set('access', FALSE);
    $denied = $this->block('test_access', ['title' => 'A title']);
    $this->assertPrintsNothing($denied);
    $this->assertSame(0, $denied['#cache']['max-age'], 'The access result of the block is not cacheable.');

    $store->set('access', TRUE);
    $allowed = $this->block('test_access', ['title' => 'A title']);
    $this->assertSame('block', $allowed['#theme']);
    $this->assertSame(0, $allowed['#cache']['max-age']);
    $html = $this->renderHtml($allowed);
    $this->assertStringContainsString('Hello test world', $html);
    $this->assertStringContainsString('A title', $html);
  }

  /**
   * Access is that of the user who views the page.
   */
  public function testBlockAccessOfTheCurrentUser(): void {
    $this->assertPrintsNothing($this->block('user_login_block'), 'The log in form is not for a user who is logged in.');
  }

  /**
   * The cache contexts, tags and age of a block bubble to the page, also
   * when the block is empty.
   */
  public function testBlockCacheMetadata(): void {
    $this->container->get('state')->set('block_test.cache_contexts', ['url.query_args:cat']);
    $this->container->get('state')->set('block_test.cache_max_age', 300);
    $empty = $this->block('test_cache');
    $this->assertPrintsNothing($empty, 'An empty block has no markup of its own.');
    $this->assertContains('url.query_args:cat', $empty['#cache']['contexts']);
    $this->assertSame(300, $empty['#cache']['max-age']);

    $this->container->get('keyvalue')->get('block_test')->set('content', 'Cached content');
    $build = $this->block('test_cache');
    $this->assertSame('block', $build['#theme']);
    $this->assertContains('url.query_args:cat', $build['#cache']['contexts']);
    $this->assertSame(300, $build['#cache']['max-age']);
    $this->assertStringContainsString('Cached content', $this->renderHtml($build));

    $this->assertPrintsNothing($this->block('test_empty'));
  }

  /**
   * A block that no longer exists prints nothing for a visitor.
   */
  public function testMissingBlock(): void {
    $this->setUpCurrentUser([], ['access content']);
    $build = $this->block('gone_block', ['title' => 'Gone', '_spacing' => ['margin' => ['size' => 'm']]]);
    $this->assertArrayNotHasKey('#theme', $build);
    $this->assertArrayNotHasKey('content', $build);
    $this->assertSame('', trim($this->renderHtml($build)));
    $this->assertSame('', trim($this->renderHtml($this->block(''))));
  }

  /**
   * The features of a block are on a wrapper around it; a block that
   * prints nothing gets none.
   */
  public function testFeaturesWrapTheBlock(): void {
    $values = ['_spacing' => ['margin' => ['size' => 'l']], '_background' => ['color' => 'paper']];
    $build = $this->block('system_powered_by_block', $values);
    $this->assertSame('container', $build['#type']);
    $this->assertSame(['style' => 'margin: 2rem; background-color: #ffffff'], $build['#attributes']);
    $this->assertSame('block', $build['content']['#theme']);
    $html = trim($this->renderHtml($build));
    $this->assertStringStartsWith('<div style="margin: 2rem; background-color: #ffffff">', $html);
    $this->assertStringContainsString('Powered by', $html);

    $this->assertPrintsNothing($this->block('test_empty', $values));
  }

  /**
   * A view display made for the builder is a view element of that display.
   */
  public function testView(): void {
    $this->createListing();
    $layouts = $this->layouts();
    $this->assertSame(
      ['#type' => 'view', '#name' => 'cat_recipes', '#display_id' => 'builder_1'],
      $layouts->buildItem(self::item('drupal_view:cat_recipes:builder_1', 'v1')),
    );
  }

  /**
   * Only displays of the "Component" type are rendered, whatever a layout
   * says; a view or display that does not exist prints nothing.
   */
  public function testOnlyBuilderDisplaysAreRendered(): void {
    $this->createListing();
    $layouts = $this->layouts();
    foreach (['cat_recipes:embed_1', 'cat_recipes:default', 'cat_recipes:gone', 'cat_recipes', 'cat_recipes:', 'gone_view:builder_1', ':builder_1', ''] as $name) {
      $this->assertSame([], $layouts->buildItem(self::item("drupal_view:$name", 'v1', ['_align' => 'center'])), "drupal_view:$name");
    }
  }

  /**
   * A view is shown with the user's access to its display.
   */
  public function testViewAccess(): void {
    $this->createListing();
    $item = self::item('drupal_view:cat_recipes:builder_perm', 'v1');
    $this->assertNotSame([], $this->layouts()->buildItem($item), 'A user who may administer content.');

    $this->setUpCurrentUser([], ['access content']);
    $this->assertSame([], $this->layouts()->buildItem($item), 'A user who may not.');
    $this->assertNotSame([], $this->layouts()->buildItem(self::item('drupal_view:cat_recipes:builder_1', 'v2')), 'The display without a restriction.');
  }

  /**
   * The view is rendered by Views, with its rows, and tells the page what
   * it depends on.
   */
  public function testRenderedView(): void {
    $this->createListing();
    $this->createBuilderNode(['title' => 'Risotto']);
    $this->createBuilderNode(['title' => 'Arancini']);
    $build = $this->layouts()->build(self::layout(self::item('drupal_view:cat_recipes:builder_1', 'v1')));
    $html = (string) $this->container->get('renderer')->renderRoot($build);
    $this->assertStringContainsString('Arancini', $html);
    $this->assertStringContainsString('Risotto', $html);
    $this->assertLessThan(strpos($html, 'Risotto'), strpos($html, 'Arancini'), 'In the order of the view.');
    $this->assertContains('config:views.view.cat_recipes', $build['#cache']['tags']);
    $this->assertContains('node_list', $build['#cache']['tags']);
  }

  /**
   * The features of a view are on a wrapper around it.
   */
  public function testFeaturesWrapTheView(): void {
    $this->createListing();
    $this->createBuilderNode(['title' => 'Risotto']);
    $build = $this->layouts()->buildItem(self::item('drupal_view:cat_recipes:builder_1', 'v1', ['_size' => ['max_width' => ['size' => 'm']], '_align' => 'end']));
    $this->assertSame('container', $build['#type']);
    $this->assertSame(['style' => 'max-width: 45rem; text-align: end'], $build['#attributes']);
    $this->assertSame(['#type' => 'view', '#name' => 'cat_recipes', '#display_id' => 'builder_1'], $build['content']);
    $html = trim($this->renderHtml($build));
    $this->assertStringStartsWith('<div style="max-width: 45rem; text-align: end">', $html);
    $this->assertStringContainsString('Risotto', $html);
  }

  /**
   * A view that is switched off is not shown on pages that have it.
   */
  public function testDisabledViewIsNotRendered(): void {
    $this->createListing('cat_off', FALSE);
    $this->createBuilderNode(['title' => 'Risotto']);
    $this->assertSame([], $this->layouts()->buildItem(self::item('drupal_view:cat_off:builder_1', 'v1')));
    $this->assertSame([], $this->layouts()->buildItem(self::item('drupal_view:cat_off:builder_1', 'v2', ['_align' => 'center'])));
  }

  /**
   * Blocks and views sit in the slots of components like any other item.
   */
  public function testBlockAndViewInASlot(): void {
    $this->createListing();
    $this->createBuilderNode(['title' => 'Risotto']);
    $build = $this->layouts()->buildItem(self::item('frame_builder_test_theme:probe', 'p1', [
      'content' => [
        self::item('drupal_block:system_powered_by_block', 'b1'),
        self::item('drupal_block:test_empty', 'b2'),
        self::item('drupal_view:cat_recipes:builder_1', 'v1'),
        self::item('drupal_view:cat_recipes:embed_1', 'v2'),
      ],
    ]));
    $html = $this->renderHtml($build);
    $this->assertMatchesRegularExpression('#<div class="probe__content">.*Powered by.*Risotto.*</div>#s', $html);
  }

}
