<?php

declare(strict_types=1);

namespace Drupal\Tests\frame_builder\Kernel;

use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\Core\Render\Element;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\NodeInterface;
use Drupal\Tests\frame_builder\Traits\RenderingTestTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The page of a node built with the builder, and the tokens of the theme on
 * every page.
 */
#[Group('frame_builder')]
#[RunTestsInSeparateProcesses]
class NodeViewTest extends BuilderKernelTestBase {

  use RenderingTestTrait;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['user']);
    FieldStorageConfig::create(['field_name' => 'field_note', 'entity_type' => 'node', 'type' => 'string'])->save();
    foreach (['landing', 'plain'] as $type) {
      if ($type === 'plain') {
        $this->createBuilderType('plain', 'synced', FALSE);
      }
      FieldConfig::create(['field_name' => 'field_note', 'entity_type' => 'node', 'bundle' => $type, 'label' => 'Note'])->save();
      foreach (['default', 'teaser'] as $mode) {
        EntityViewDisplay::create(['targetEntityType' => 'node', 'bundle' => $type, 'mode' => $mode, 'status' => TRUE])
          ->setComponent('field_note', ['type' => 'string', 'label' => 'above'])
          ->save();
      }
    }
  }

  /**
   * The render array of a node in a view mode, as built for the page.
   */
  protected function viewNode(NodeInterface $node, string $mode = 'full'): array {
    $builder = $this->container->get('entity_type.manager')->getViewBuilder('node');
    return $builder->build($builder->view($node, $mode));
  }

  /**
   * A page with a note and a layout of one heading.
   */
  protected function pageWithLayout(array $values = []): NodeInterface {
    $node = $this->createBuilderNode($values + ['field_note' => 'The note of the page']);
    return $this->saveLayout($node, self::layout(self::item('frame:heading', 'h1', ['text' => 'Built with the builder'])));
  }

  /**
   * A page without a layout is shown as the content type displays it.
   */
  public function testFullViewWithoutLayout(): void {
    $node = $this->createBuilderNode(['field_note' => 'The note of the page']);
    $build = $this->viewNode($node);
    $this->assertArrayHasKey('field_note', $build);
    $this->assertArrayNotHasKey('frame_layout', $build);
    $this->assertStringContainsString('The note of the page', $this->renderHtml($build));
  }

  /**
   * Once a page has a layout, its full view is the layout and nothing else.
   */
  public function testFullViewWithLayout(): void {
    $build = $this->viewNode($this->pageWithLayout());
    $this->assertSame(['frame_layout'], Element::children($build));
    $this->assertSame('frame:heading', $build['frame_layout'][0]['#component']);
    $html = $this->renderHtml($build);
    $this->assertSame(1, substr_count($html, 'Built with the builder'), 'The layout is printed once.');
    $this->assertStringNotContainsString('The note of the page', $html, 'Fields are shown only where a Field block is placed.');
  }

  /**
   * A Field block brings a field back into the page, where it is placed.
   */
  public function testFieldBlockInTheFullView(): void {
    $node = $this->createBuilderNode(['field_note' => 'The note of the page']);
    $this->saveLayout($node, self::layout(
      self::item('frame:heading', 'h1', ['text' => 'Built with the builder']),
      self::item('drupal_field:field_note', 'f1', ['formatter' => 'string', 'label' => 'hidden']),
    ));
    $html = $this->renderHtml($this->viewNode($node));
    $this->assertSame(1, substr_count($html, 'The note of the page'));
    $this->assertLessThan(strpos($html, 'The note of the page'), strpos($html, 'Built with the builder'));
  }

  /**
   * A layout without content keeps the normal display, not an empty page.
   */
  public function testEmptyLayoutKeepsTheDisplay(): void {
    $node = $this->createBuilderNode(['field_note' => 'The note of the page']);
    $this->saveLayout($node, self::layout());
    $build = $this->viewNode($node);
    $this->assertArrayHasKey('field_note', $build);
    $this->assertArrayNotHasKey('frame_layout', $build);
  }

  /**
   * Teasers and other view modes keep their display.
   */
  public function testOtherViewModesAreUntouched(): void {
    $node = $this->pageWithLayout();
    foreach (['teaser', 'default', 'search_result'] as $mode) {
      $build = $this->viewNode($node, $mode);
      $this->assertArrayHasKey('field_note', $build, $mode);
      $this->assertArrayNotHasKey('frame_layout', $build, $mode);
    }
    $html = $this->renderHtml($this->viewNode($node, 'teaser'));
    $this->assertStringContainsString('The note of the page', $html);
    $this->assertStringNotContainsString('Built with the builder', $html);
  }

  /**
   * A content type that does not use the builder keeps its display, also
   * when a page of it has a layout stored.
   */
  public function testTypeWithoutBuilderIsUntouched(): void {
    $node = $this->pageWithLayout(['type' => 'plain']);
    $this->assertFalse($node->get('frame_layout')->isEmpty());
    $build = $this->viewNode($node);
    $this->assertArrayHasKey('field_note', $build);
    $this->assertArrayNotHasKey('frame_layout', $build);
  }

  /**
   * Without a default theme based on Frame the page keeps its display.
   */
  public function testThemeWithoutBuilderIsUntouched(): void {
    $node = $this->pageWithLayout();
    $this->container->get('theme_installer')->install(['stark']);
    $this->config('system.theme')->set('default', 'stark')->save();
    $node = $this->container->get('entity_type.manager')->getStorage('node')->load($node->id());
    $build = $this->viewNode($node);
    $this->assertArrayHasKey('field_note', $build);
    $this->assertArrayNotHasKey('frame_layout', $build);
  }

  /**
   * The full view shows the layout of the revision that is viewed.
   */
  public function testFullViewOfARevision(): void {
    $node = $this->pageWithLayout();
    $first = $node->getRevisionId();
    $node->setNewRevision(TRUE);
    $this->saveLayout($node, self::layout(self::item('frame:heading', 'h1', ['text' => 'The second version'])));
    $storage = $this->container->get('entity_type.manager')->getStorage('node');

    $this->assertStringContainsString('Built with the builder', $this->renderHtml($this->viewNode($storage->loadRevision($first))));
    $html = $this->renderHtml($this->viewNode($storage->load($node->id())));
    $this->assertStringContainsString('The second version', $html);
    $this->assertStringNotContainsString('Built with the builder', $html);
  }

  /**
   * The values of the tokens of the page reach the layout: it is built for
   * the node that is viewed.
   */
  public function testLayoutIsBuiltForTheNode(): void {
    $node = $this->createBuilderNode(['title' => 'Fish & chips']);
    $this->saveLayout($node, self::layout(self::item('frame:heading', 'h1', ['text' => 'About [node:title]'])));
    $this->assertStringContainsString('About Fish &amp; chips', $this->renderHtml($this->viewNode($node)));
  }

  /**
   * The design tokens of the theme are custom properties on every page of
   * it, with the first colour as the accent.
   */
  public function testPageAttachments(): void {
    $attachments = [];
    $this->container->get('module_handler')->invoke('frame_builder', 'page_attachments', [&$attachments]);
    $head = $attachments['#attached']['html_head'];
    $this->assertCount(1, $head);
    $this->assertSame('frame_builder_tokens', $head[0][1]);
    $this->assertSame('style', $head[0][0]['#tag']);
    $this->assertSame(
      ':where(:root) { --frame-space-s: 0.5rem; --frame-space-m: 1rem; --frame-space-l: 2rem; '
      . '--frame-font-s: 0.875rem; --frame-font-m: 1rem; --frame-font-l: 1.5rem; '
      . '--frame-border-thin: 1px; --frame-border-medium: 2px; --frame-border-thick: 4px; '
      . '--frame-radius-s: 0.25rem; --frame-radius-m: 0.5rem; --frame-radius-l: 1rem; --frame-radius-full: 9999px; '
      . '--frame-color-brand: #112233; --frame-color-paper: #ffffff; --frame-accent: #112233 }',
      (string) $head[0][0]['#value'],
    );
  }

  /**
   * The accent is the colour named "primary" when there is one; names
   * become valid property names; unsafe values are not printed.
   */
  public function testPageAttachmentsOfASubtheme(): void {
    $this->useTheme('frame_builder_test_cat_theme');
    $attachments = [];
    $this->container->get('module_handler')->invoke('frame_builder', 'page_attachments', [&$attachments]);
    $css = (string) $attachments['#attached']['html_head'][0][0]['#value'];
    $this->assertStringContainsString('--frame-space-tiny: 4px; --frame-space-half: 50%; --frame-space-back: -1.5em;', $css);
    $this->assertStringContainsString('--frame-font-l: 1.5rem', $css, 'The type scale of the base theme.');
    $this->assertStringContainsString('--frame-color-ink: #000;', $css);
    $this->assertStringContainsString('--frame-color-two-words: #abcdef', $css);
    $this->assertStringEndsWith('--frame-accent: rgb(1, 2, 3) }', $css);
    foreach (['calc(', 'url(', 'expression', 'color: red', 'background: blue', '--frame-space-evil', '--frame-color-script'] as $unsafe) {
      $this->assertStringNotContainsString($unsafe, $css);
    }
    $this->assertSame(1, substr_count($css, '{'));
    $this->assertSame(1, substr_count($css, '}'));
  }

  /**
   * A theme without colours has no accent; a theme that is not based on
   * Frame gets no tokens at all.
   */
  public function testPageAttachmentsOfOtherThemes(): void {
    $this->useTheme('frame');
    $attachments = [];
    $this->container->get('module_handler')->invoke('frame_builder', 'page_attachments', [&$attachments]);
    $css = (string) $attachments['#attached']['html_head'][0][0]['#value'];
    $this->assertStringContainsString('--frame-space-xs: 0.25rem', $css);
    $this->assertStringContainsString('--frame-font-3xl: 3rem', $css);
    $this->assertStringNotContainsString('--frame-color-', $css);
    $this->assertStringNotContainsString('--frame-accent', $css);

    $this->useTheme('stark');
    $attachments = [];
    $this->container->get('module_handler')->invoke('frame_builder', 'page_attachments', [&$attachments]);
    $this->assertSame([], $attachments);
  }

}
