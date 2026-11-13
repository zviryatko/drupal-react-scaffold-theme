<?php

declare(strict_types=1);

namespace Drupal\Tests\frame_builder\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\frame_builder\LayoutRenderer;
use Drupal\node\NodeInterface;
use Drupal\Tests\frame_builder\Traits\RenderingTestTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Field blocks: a field of the page shown with a formatter.
 */
#[Group('frame_builder')]
#[RunTestsInSeparateProcesses]
class LayoutRendererFieldTest extends BuilderKernelTestBase {

  use RenderingTestTrait;

  /**
   * The module field_test refuses to show a field of the name
   * "field_no_view_access".
   *
   * {@inheritdoc}
   */
  protected static $modules = ['entity_test', 'field_test'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->config('system.site')->set('name', 'Test site')->save();
    foreach (['field_note' => ['string', 'Note'], 'field_count' => ['integer', 'Count'], 'field_story' => ['text_long', 'Story'], 'field_no_view_access' => ['string', 'Secret']] as $name => [$type, $label]) {
      FieldStorageConfig::create(['field_name' => $name, 'entity_type' => 'node', 'type' => $type])->save();
      FieldConfig::create(['field_name' => $name, 'entity_type' => 'node', 'bundle' => 'landing', 'label' => $label])->save();
    }
  }

  /**
   * The renderer of layouts.
   */
  protected function layouts(): LayoutRenderer {
    return $this->container->get('frame_builder.renderer');
  }

  /**
   * The render array of a Field block of a page.
   */
  protected function field(NodeInterface $node, string $name, array $values = []): array {
    return $this->layouts()->buildItem(self::item("drupal_field:$name", 'f1', $values), $node);
  }

  /**
   * A field is rendered by the chosen formatter, in a wrapper that carries
   * the position of the label.
   */
  public function testField(): void {
    $node = $this->createBuilderNode(['field_note' => 'A note']);
    $build = $this->field($node, 'field_note', ['formatter' => 'string', 'label' => 'above']);
    $this->assertSame('container', $build['#type']);
    $this->assertSame(['class' => ['frame-builder-field', 'frame-builder-field--label-above']], $build['#attributes']);
    $this->assertSame(['library' => ['frame_builder/field']], $build['#attached']);
    $this->assertSame('field', $build['field']['#theme']);
    $this->assertSame('field_note', $build['field']['#field_name']);
    $this->assertSame('string', $build['field']['#formatter']);
    $this->assertSame('above', $build['field']['#label_display']);

    $html = $this->renderHtml($build);
    $this->assertMatchesRegularExpression('/^<div class="frame-builder-field frame-builder-field--label-above">/', trim($html));
    $this->assertStringContainsString('Note', $html);
    $this->assertStringContainsString('A note', $html);
  }

  /**
   * The title is a field like the others.
   */
  public function testTitleField(): void {
    $node = $this->createBuilderNode(['title' => 'Fish & <em>chips</em>']);
    $html = $this->renderHtml($this->field($node, 'title', ['formatter' => 'string']));
    $this->assertStringContainsString('Fish &amp; &lt;em&gt;chips&lt;/em&gt;', $html);
  }

  /**
   * The label is above, inline, hidden for the eye or hidden; anything
   * else is hidden.
   */
  #[DataProvider('labels')]
  public function testLabelPosition(mixed $label, string $display, string $class): void {
    $node = $this->createBuilderNode(['field_note' => 'A note']);
    $build = $this->field($node, 'field_note', ['label' => $label]);
    $this->assertSame($display, $build['field']['#label_display']);
    $this->assertSame(['frame-builder-field', $class], $build['#attributes']['class']);
  }

  /**
   * Stored label positions, what the field is rendered with and the class
   * of the wrapper.
   */
  public static function labels(): array {
    return [
      'above' => ['above', 'above', 'frame-builder-field--label-above'],
      'inline' => ['inline', 'inline', 'frame-builder-field--label-inline'],
      'visually hidden' => ['visually_hidden', 'visually_hidden', 'frame-builder-field--label-visually-hidden'],
      'hidden' => ['hidden', 'hidden', 'frame-builder-field--label-hidden'],
      'not set' => [NULL, 'hidden', 'frame-builder-field--label-hidden'],
      'not a position' => ['below" onclick="x', 'hidden', 'frame-builder-field--label-hidden'],
      'a list' => [['above'], 'hidden', 'frame-builder-field--label-hidden'],
    ];
  }

  /**
   * A hidden label is not printed, a shown one is.
   */
  public function testLabelInHtml(): void {
    $node = $this->createBuilderNode(['field_note' => 'A note']);
    $this->assertStringContainsString('Note', $this->renderHtml($this->field($node, 'field_note', ['label' => 'inline'])));
    $hidden = $this->renderHtml($this->field($node, 'field_note', ['label' => 'hidden']));
    $this->assertStringNotContainsString('Note', $hidden);
    $this->assertStringContainsString('A note', $hidden);
  }

  /**
   * The formatter can come with settings, which it is rendered with.
   */
  public function testFormatterSettings(): void {
    $node = $this->createBuilderNode(['field_count' => 1234567]);
    $build = $this->field($node, 'field_count', ['formatter' => ['type' => 'number_integer', 'settings' => ['thousand_separator' => ' ', 'prefix_suffix' => FALSE]]]);
    $this->assertSame('number_integer', $build['field']['#formatter']);
    $this->assertStringContainsString('1 234 567', $this->renderHtml($build));

    $plain = $this->field($node, 'field_count', ['formatter' => 'number_unformatted']);
    $this->assertSame('number_unformatted', $plain['field']['#formatter']);
    $this->assertStringContainsString('1234567', $this->renderHtml($plain));

    $default = $this->field($node, 'field_count', ['formatter' => ['type' => 'number_integer', 'settings' => 'not settings']]);
    $this->assertStringContainsString('1234567', $this->renderHtml($default), 'Settings that are no list are the default settings.');
  }

  /**
   * A formatter that does not exist or does not fit the field gives way to
   * the default formatter of the field type.
   */
  #[DataProvider('unfitFormatters')]
  public function testUnfitFormatterFallsBack(mixed $formatter): void {
    $node = $this->createBuilderNode(['field_note' => 'A note']);
    $build = $this->field($node, 'field_note', ['formatter' => $formatter]);
    $this->assertSame('string', $build['field']['#formatter']);
    $this->assertStringContainsString('A note', $this->renderHtml($build));
  }

  /**
   * Formatters that cannot show a line of text.
   */
  public static function unfitFormatters(): array {
    return [
      'of another field type' => ['number_integer'],
      'of another field type, with settings' => [['type' => 'number_integer', 'settings' => ['thousand_separator' => ' ']]],
      'for media references only' => ['frame_builder_media_responsive'],
      'unknown' => ['gone_formatter'],
      'empty' => [''],
      'not set' => [NULL],
      'without a type' => [['settings' => ['link_to_entity' => TRUE]]],
    ];
  }

  /**
   * Formatted text is filtered by its text format, as on any page.
   */
  public function testFormattedTextField(): void {
    $node = $this->createBuilderNode(['field_story' => ['value' => '<script>alert(1)</script>A <b>story</b>', 'format' => 'plain_text']]);
    $html = $this->renderHtml($this->field($node, 'field_story', ['formatter' => 'text_default']));
    $this->assertStringNotContainsString('<script>', $html);
    $this->assertStringContainsString('A &lt;b&gt;story&lt;/b&gt;', $html);
  }

  /**
   * The look of the label reaches the field as custom properties.
   */
  public function testLabelStyle(): void {
    $node = $this->createBuilderNode(['field_note' => 'A note']);
    $build = $this->field($node, 'field_note', ['label' => 'above', 'label_style' => ['size' => 'l', 'weight' => 'bold', 'color' => 'brand', 'transform' => 'uppercase']]);
    $style = '--frame-builder-label-font-size: 1.5rem; --frame-builder-label-font-weight: 700; --frame-builder-label-text-transform: uppercase; --frame-builder-label-color: #112233';
    $this->assertSame($style, $build['#attributes']['style']);
    $this->assertStringContainsString('style="' . $style . '"', $this->renderHtml($build));

    $this->assertArrayNotHasKey('style', $this->field($node, 'field_note', ['label_style' => ['size' => 'huge', 'color' => '#f00']])['#attributes'], 'Nothing that is a token: no style.');
    $this->assertArrayNotHasKey('style', $this->field($node, 'field_note', ['label_style' => 'bold'])['#attributes']);
  }

  /**
   * Nothing is printed without a page, for a field the page does not have,
   * for the layout itself and for an empty field.
   */
  public function testNothingToShow(): void {
    $node = $this->createBuilderNode(['field_note' => 'A note']);
    $layouts = $this->layouts();
    $this->assertNotSame([], $this->field($node, 'field_note'));
    $this->assertSame([], $layouts->buildItem(self::item('drupal_field:field_note', 'f1')), 'Without a page.');
    $this->assertSame([], $this->field($node, 'field_gone'));
    $this->assertSame([], $this->field($node, ''));
    $this->assertSame([], $this->field($node, 'field_count'), 'An empty field.');
    $this->assertSame([], $this->field($node, 'field_count', ['label' => 'above', '_spacing' => ['margin' => ['size' => 'm']]]), 'An empty field prints no label and no styled wrapper.');

    $this->saveLayout($node, self::layout(self::item('frame:heading', 'h1', ['text' => 'Hello'])));
    $this->assertFalse($node->get('frame_layout')->isEmpty());
    $this->assertSame([], $this->field($node, 'frame_layout', ['formatter' => 'basic_string']), 'The layout is not a field to show.');
  }

  /**
   * A field the user may not view is not printed.
   */
  public function testFieldAccess(): void {
    $node = $this->createBuilderNode(['field_note' => 'A note', 'field_no_view_access' => 'A secret']);
    $this->assertFalse($node->get('field_no_view_access')->access('view'));
    $this->assertSame([], $this->field($node, 'field_no_view_access', ['formatter' => 'string', 'label' => 'above']));
    $build = $this->layouts()->build(self::layout(
      self::item('drupal_field:field_no_view_access', 'f1', ['_background' => ['color' => 'brand']]),
      self::item('drupal_field:field_note', 'f2'),
    ), $node);
    $this->assertCount(1, $build);
    $html = $this->renderHtml($build);
    $this->assertStringNotContainsString('A secret', $html);
    $this->assertStringContainsString('A note', $html);
  }

  /**
   * The features of a Field block are on a wrapper of its own: a field has
   * no root element to style.
   */
  public function testFeaturesWrapTheField(): void {
    $node = $this->createBuilderNode(['field_note' => 'A note']);
    $build = $this->field($node, 'field_note', [
      '_spacing' => ['padding' => ['size' => 'm']],
      '_align' => 'center',
      '_position' => ['mode' => 'absolute', 'corner' => 'bottom-right'],
    ]);
    $this->assertSame('container', $build['#type']);
    $this->assertSame(['style' => 'padding: 1rem; text-align: center; position: absolute; z-index: 1; bottom: 0; right: 0'], $build['#attributes']);
    $this->assertSame(['frame-builder-field', 'frame-builder-field--label-hidden'], $build['content']['#attributes']['class']);
    $html = trim($this->renderHtml($build));
    $this->assertMatchesRegularExpression('#^<div style="padding: 1rem; text-align: center; position: absolute; z-index: 1; bottom: 0; right: 0"><div class="frame-builder-field frame-builder-field--label-hidden">.*A note.*</div>\s*</div>$#s', $html);

    $this->assertArrayNotHasKey('content', $this->field($node, 'field_note', ['_align' => 'left']), 'Nothing to print: no wrapper.');
  }

  /**
   * A pinned Field block makes the component it is in what it is pinned to.
   */
  public function testPinnedFieldInAComponent(): void {
    $node = $this->createBuilderNode(['field_note' => 'A note']);
    $build = $this->layouts()->buildItem(self::item('frame_builder_test_theme:probe', 'p1', [
      'content' => [self::item('drupal_field:field_note', 'f1', ['_position' => ['mode' => 'absolute', 'corner' => 'top-left']])],
    ]), $node);
    $this->assertSame('position: relative', (string) $build['#props']['attributes']['style']);
    $this->assertSame('position: absolute; z-index: 1; top: 0; left: 0', $build['#slots']['content'][0]['#attributes']['style']);
    $this->assertStringContainsString('A note', $this->renderHtml($build));
  }

  /**
   * Tokens typed into a field are replaced with values of the page and the
   * site; text in brackets that is no token stays.
   */
  public function testTokensInAField(): void {
    $node = $this->createBuilderNode(['title' => 'Fish & chips', 'field_note' => 'About [node:title] on [site:name], see [1] and [gone:token].']);
    $build = $this->field($node, 'field_note', ['formatter' => 'string', 'label' => 'above']);
    $this->assertArrayHasKey('#markup', $build['field'], 'The field is rendered to replace the tokens in it.');
    $html = $this->renderHtml($build);
    $this->assertStringContainsString('About Fish &amp; chips on Test site, see [1] and [gone:token].', $html);
    $this->assertStringContainsString('Note', $html, 'The label is still printed.');
    $this->assertMatchesRegularExpression('/^<div class="frame-builder-field frame-builder-field--label-above">/', trim($html));
  }

  /**
   * What a token depends on reaches the page with the field: it changes when
   * the page or the site name does.
   */
  public function testTokensInAFieldBubbleCacheMetadata(): void {
    $node = $this->createBuilderNode(['field_note' => '[node:title] on [site:name]']);
    $build = $this->field($node, 'field_note');
    $this->assertContains('node:' . $node->id(), $build['field']['#cache']['tags']);
    $this->assertContains('config:system.site', $build['field']['#cache']['tags']);
  }

  /**
   * A value of a token cannot bring markup into the page.
   */
  public function testTokenValuesAreEscaped(): void {
    $node = $this->createBuilderNode(['title' => '<script>alert(1)</script>', 'field_note' => 'By [node:title]']);
    $html = $this->renderHtml($this->field($node, 'field_note'));
    $this->assertStringNotContainsString('<script>', $html);
    $this->assertStringContainsString('By &lt;script&gt;alert(1)&lt;/script&gt;', $html);
  }

  /**
   * A field without tokens is left to Drupal to render.
   */
  public function testFieldWithoutTokensIsNotRenderedEarly(): void {
    $node = $this->createBuilderNode(['field_note' => 'Brackets [like these] and [ this : one ] are no tokens']);
    $build = $this->field($node, 'field_note');
    $this->assertSame('field', $build['field']['#theme']);
    $this->assertStringContainsString('Brackets [like these] and [ this : one ] are no tokens', $this->renderHtml($build));
  }

}
