<?php

declare(strict_types=1);

namespace Drupal\Tests\frame_builder\Kernel;

use Drupal\frame_builder\ComponentFeatures;
use Drupal\image\Entity\ImageStyle;
use Drupal\Tests\frame_builder\Traits\RenderingTestTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The features every component can have, and the CSS they become.
 */
#[Group('frame_builder')]
#[RunTestsInSeparateProcesses]
class ComponentFeaturesTest extends BuilderKernelTestBase {

  use RenderingTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['file', 'image', 'media', 'breakpoint', 'responsive_image'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->setUpImageMedia();
  }

  /**
   * The features service.
   */
  protected function features(): ComponentFeatures {
    return $this->container->get('frame_builder.features');
  }

  /**
   * An SDC definition that switches features on or off.
   */
  protected static function definition(array $features): array {
    return ['thirdPartySettings' => ['frame_builder' => ['features' => $features]]];
  }

  /**
   * A component has the features of the theme, changed by its own switches.
   */
  public function testEnabled(): void {
    $features = $this->features();
    $this->assertSame(['spacing', 'size', 'align', 'text', 'background', 'border', 'position'], $features->enabled([]));
    $this->assertSame(['spacing', 'size', 'align', 'text'], $features->enabled(self::definition(['position' => FALSE, 'background' => FALSE, 'border' => FALSE])));
    $this->assertSame([], $features->enabled(self::definition(array_fill_keys(ComponentFeatures::FEATURES, FALSE))));
    $this->assertSame(['spacing', 'size', 'align', 'text', 'background', 'border', 'position'], $features->enabled(self::definition(['shadow' => TRUE])), 'A switch that names no feature adds none.');

    $catalog = $this->container->get('frame_builder.catalog');
    $this->assertNotContains('background', $features->enabled($catalog->definition('frame:badge')), 'The badge of Frame switches its background off.');
    $this->assertContains('position', $features->enabled($catalog->definition('frame:badge')));
    $this->assertNotContains('position', $features->enabled($catalog->definition('frame_builder_test_theme:cat-kinds')));
  }

  /**
   * A component can switch on a feature that its theme switches off.
   */
  public function testEnabledOverThemeDefaults(): void {
    $this->useTheme('frame_builder_test_cat_theme');
    $features = $this->features();
    $this->assertSame(['spacing', 'size', 'align', 'text', 'border'], $features->enabled([]), 'The theme switches background and position off.');
    $this->assertSame(['spacing', 'size', 'align', 'text', 'border', 'position'], $features->enabled(self::definition(['position' => TRUE])));
    $this->assertSame(['size', 'align', 'text', 'background', 'border'], $features->enabled(self::definition(['background' => TRUE, 'spacing' => FALSE])));
    $this->assertSame([], $features->positionStyle([], ['_position' => ['mode' => 'absolute', 'corner' => 'top-left']]));
    $this->assertNotSame([], $features->positionStyle(self::definition(['position' => TRUE]), ['_position' => ['mode' => 'absolute', 'corner' => 'top-left']]));
  }

  /**
   * Every feature gives one editor field, with the tokens it offers.
   */
  public function testFields(): void {
    $fields = $this->features()->fields([]);
    $this->assertSame(['_spacing', '_size', '_align', '_text', '_background', '_border', '_position'], array_column($fields, 'name'));
    $this->assertSame(['spacing', 'size', 'align', 'textstyle', 'background', 'border', 'position'], array_column($fields, 'widget'));
    foreach ($fields as $index => $field) {
      $this->assertNull($field['default'], $field['name']);
      $this->assertFalse($field['required'], $field['name']);
      $this->assertFalse($field['translatable'], $field['name']);
      $this->assertSame($index === 0, isset($field['heading']), 'Only the first field starts the "Layout and style" part.');
    }
    $this->assertSame('Layout and style', $fields[0]['heading']);
    $fields = array_column($fields, NULL, 'name');

    $steps = [
      ['value' => 's', 'label' => 'S (0.5rem)'],
      ['value' => 'm', 'label' => 'M (1rem)'],
      ['value' => 'l', 'label' => 'L (2rem)'],
    ];
    $palette = [
      ['value' => 'brand', 'label' => 'Brand', 'color' => '#112233'],
      ['value' => 'paper', 'label' => 'Paper', 'color' => '#ffffff'],
    ];
    $this->assertSame($steps, $fields['_spacing']['sizes']);
    $this->assertSame($steps, $fields['_position']['sizes'], 'The offset of a pinned component is a step of the spacing scale.');
    $this->assertSame([
      ['value' => 's', 'label' => 'S (30rem)'],
      ['value' => 'm', 'label' => 'M (45rem)'],
      ['value' => 'full', 'label' => 'FULL (100%)'],
    ], $fields['_size']['sizes']);
    $this->assertSame([
      ['value' => 's', 'label' => 'S (0.875rem)'],
      ['value' => 'm', 'label' => 'M (1rem)'],
      ['value' => 'l', 'label' => 'L (1.5rem)'],
    ], $fields['_text']['sizes']);
    $this->assertSame($palette, $fields['_text']['colors']);
    $this->assertSame($palette, $fields['_background']['colors']);
    $this->assertSame([['value' => 'style:thumbnail', 'label' => (string) ImageStyle::load('thumbnail')->label()]], $fields['_background']['imageStyles']);
    $this->assertArrayNotHasKey('sizes', $fields['_align']);
  }

  /**
   * Switched off features have no field; the heading moves to the first
   * field that is left.
   */
  public function testFieldsOfSwitchedOffFeatures(): void {
    $features = $this->features();
    $fields = $features->fields(self::definition(['spacing' => FALSE, 'text' => FALSE]));
    $this->assertSame(['_size', '_align', '_background', '_border', '_position'], array_column($fields, 'name'));
    $this->assertSame('Layout and style', $fields[0]['heading']);
    $this->assertSame([], $features->fields(self::definition(array_fill_keys(ComponentFeatures::FEATURES, FALSE))));
  }

  /**
   * The options of length and image fields, and of a text style field.
   */
  public function testOptions(): void {
    $features = $this->features();
    $this->assertSame(['S (0.5rem)', 'M (1rem)', 'L (2rem)'], array_column($features->sizeOptions(), 'label'));
    $this->assertSame(['style:thumbnail'], array_column($features->imageStyleOptions(), 'value'));
    $field = $features->textStyleField();
    $this->assertSame(['widget', 'sizes', 'colors'], array_keys($field));
    $this->assertSame('textstyle', $field['widget']);
    $this->assertSame(['s', 'm', 'l'], array_column($field['sizes'], 'value'));
    $this->assertSame(['brand', 'paper'], array_column($field['colors'], 'value'));
    $this->assertSame($this->container->get('frame_builder.theme_kit'), $features->kit());
  }

  /**
   * The look of text becomes declarations; what is not a known choice gives
   * none.
   */
  public function testTextStyle(): void {
    $features = $this->features();
    $this->assertSame([
      'font-size' => '1.5rem',
      'font-weight' => '700',
      'font-style' => 'italic',
      'text-transform' => 'uppercase',
      'line-height' => '1.2',
      'color' => '#112233',
    ], $features->textStyle(['size' => 'l', 'weight' => 'bold', 'italic' => TRUE, 'transform' => 'uppercase', 'color' => 'brand', 'leading' => 'tight']));
    $this->assertSame(['font-size' => '18px', 'font-weight' => '500', 'line-height' => '1.8'], $features->textStyle(['size' => 'custom', 'custom' => '18px', 'weight' => 'medium', 'italic' => FALSE, 'leading' => 'loose']));
    $this->assertSame(['font-weight' => '400', 'text-transform' => 'capitalize', 'line-height' => '1.5'], $features->textStyle(['weight' => 'normal', 'transform' => 'capitalize', 'leading' => 'normal']));
    $this->assertSame([], $features->textStyle([
      'size' => 'huge',
      'custom' => '18px',
      'weight' => '900',
      'transform' => 'none; color: red',
      'color' => '#ff0000',
      'leading' => '3',
    ]), 'Values that are no choice of the editor are not printed.');
    $this->assertSame([], $features->textStyle([]));
  }

  /**
   * A border: a width of the theme on the sides chosen, a line style, a
   * colour of the palette (the text's without one); rounded corners.
   */
  public function testBorderStyle(): void {
    $features = $this->features();
    $kit = $this->container->get('frame_builder.theme_kit');
    $this->assertSame(['thin' => '1px', 'medium' => '2px', 'thick' => '4px'], $kit->borderWidths(), 'The default scale.');
    $this->assertSame(['s' => '0.25rem', 'm' => '0.5rem', 'l' => '1rem', 'full' => '9999px'], $kit->radii());

    $this->assertSame(
      ['border-width' => '2px', 'border-style' => 'solid', 'border-color' => '#112233', 'border-radius' => '0.5rem', 'overflow' => 'clip'],
      $features->style([], ['_border' => ['width' => ['size' => 'medium'], 'color' => 'brand', 'radius' => ['size' => 'm']]]),
    );
    $this->assertSame(
      ['border-block-end-width' => '3px', 'border-block-end-style' => 'dashed', 'border-block-end-color' => 'currentColor'],
      $features->style([], ['_border' => ['width' => ['size' => 'custom', 'custom' => '3px'], 'sides' => 'bottom', 'style' => 'dashed']]),
      'A custom width; no colour is the colour of the text.',
    );
    $this->assertSame(
      ['border-inline-width' => '1px', 'border-inline-style' => 'solid', 'border-inline-color' => 'currentColor'],
      $features->style([], ['_border' => ['width' => ['size' => 'thin'], 'sides' => 'horizontal', 'style' => 'groove;color:red', 'color' => 'not-in-the-palette']]),
      'Only the line styles and colours offered are printed.',
    );
    $this->assertSame(['border-radius' => '9999px', 'overflow' => 'clip'], $features->style([], ['_border' => ['radius' => ['size' => 'full']]]), 'Rounded corners without a line; what is inside follows them.');
    $this->assertSame([], $features->style([], ['_border' => ['width' => ['size' => 'huge'], 'radius' => ['size' => 'custom', 'custom' => '1px; color: red']]]));
    $this->assertSame([], $features->style(self::definition(['border' => FALSE]), ['_border' => ['width' => ['size' => 'thin']]]));

    // From a breakpoint on.
    $this->enableModules(['breakpoint']);
    $responsive = $features->responsive([], ['_border' => ['at' => ['tablet' => ['radius' => ['size' => 'l']]]]]);
    $this->assertStringContainsString('border-radius: 1rem !important; overflow: clip !important;', $responsive['css']);

    // The editor's field: the palette and both scales.
    $field = array_column($features->fields([]), NULL, 'name')['_border'];
    $this->assertSame('border', $field['widget']);
    $this->assertSame(['value' => 'thin', 'label' => 'THIN (1px)'], $field['widths'][0]);
    $this->assertSame(['value' => 'full', 'label' => 'FULL (9999px)'], end($field['radii']));
    $this->assertSame(['value' => 'brand', 'label' => 'Brand', 'color' => '#112233'], $field['colors'][0]);
  }

  /**
   * Margin and padding: a step or a custom length, on all sides or on one
   * axis; "auto" for margins only.
   */
  public function testSpacingStyle(): void {
    $features = $this->features();
    $this->assertSame(['margin' => '1rem', 'padding' => '12px'], $features->style([], ['_spacing' => [
      'margin' => ['size' => 'm'],
      'padding' => ['size' => 'custom', 'custom' => '12px'],
    ]]));
    $this->assertSame(['margin-inline' => 'auto', 'padding-block' => '2rem'], $features->style([], ['_spacing' => [
      'margin' => ['size' => 'auto', 'sides' => 'horizontal'],
      'padding' => ['size' => 'l', 'sides' => 'vertical'],
    ]]));
    $this->assertSame(['margin-block' => '0.5rem'], $features->style([], ['_spacing' => [
      'margin' => ['size' => 's', 'sides' => 'vertical'],
      'padding' => ['size' => 'auto'],
    ]]), 'Padding has no "auto".');
    $this->assertSame(['padding' => '0.5rem'], $features->style([], ['_spacing' => [
      'margin' => ['size' => 'custom', 'custom' => '1px; position: fixed'],
      'padding' => ['size' => 's', 'sides' => 'diagonal'],
    ]]), 'An unsafe length is dropped; unknown sides mean all sides.');
    $this->assertSame([], $features->style([], ['_spacing' => 'm']));
    $this->assertSame([], $features->style([], ['_spacing' => []]));
    $this->assertSame([], $features->style(self::definition(['spacing' => FALSE]), ['_spacing' => ['margin' => ['size' => 'm']]]));
  }

  /**
   * Width is a limit and height a minimum, of the theme's sizes or custom.
   */
  public function testSizeStyle(): void {
    $features = $this->features();
    $this->assertSame(['max-width' => '30rem', 'min-height' => '40vh'], $features->style([], ['_size' => [
      'max_width' => ['size' => 's'],
      'min_height' => ['size' => 'custom', 'custom' => '40vh'],
    ]]));
    $this->assertSame(['min-height' => '100%'], $features->style([], ['_size' => ['max_width' => ['size' => 'xxl'], 'min_height' => 'full']]));
    $this->assertSame([], $features->style(self::definition(['size' => FALSE]), ['_size' => ['max_width' => ['size' => 's']]]));
    // Width and height, each exactly, at least and at most.
    $this->assertSame(
      ['width' => '30rem', 'min-width' => '10px', 'max-width' => '45rem', 'height' => '100%', 'min-height' => '2em', 'max-height' => '50vh'],
      $features->style([], ['_size' => [
        'max_height' => ['size' => 'custom', 'custom' => '50vh'],
        'exact_width' => 's',
        'min_width' => ['size' => 'custom', 'custom' => '10px'],
        'max_width' => 'm',
        'exact_height' => 'full',
        'min_height' => ['size' => 'custom', 'custom' => '2em'],
        'depth' => 's',
      ]]),
    );
  }

  /**
   * Layout and style from a breakpoint of the theme on: rules with the media
   * query of the breakpoint, for the element that carries their key.
   */
  public function testResponsive(): void {
    $this->enableModules(['breakpoint']);
    $features = $this->features();
    $kit = $this->container->get('frame_builder.theme_kit');
    $this->assertSame(['tablet', 'desktop'], array_keys($kit->breakpoints()), 'The breakpoint without a query is every width.');
    $this->assertSame(['label' => 'Tablet', 'query' => 'all and (min-width: 48em)', 'width' => 768], $kit->breakpoints()['tablet']);

    $values = [
      '_spacing' => ['padding' => ['size' => 's'], 'at' => ['tablet' => ['padding' => ['size' => 'l']], 'phone' => ['padding' => ['size' => 'm']]]],
      '_size' => ['at' => ['desktop' => ['max_width' => ['size' => 'm']]]],
      '_align' => ['value' => 'start', 'at' => ['desktop' => 'center', 'tablet' => 'sideways']],
      '_text' => ['weight' => 'bold'],
    ];
    $this->assertSame(['padding' => '0.5rem', 'text-align' => 'start', 'font-weight' => '700'], $features->style([], $values), 'What a component has at every width is not changed by what it has from a breakpoint on.');

    $responsive = $features->responsive([], $values);
    $key = $responsive['key'];
    $this->assertSame(
      "@media all and (min-width: 48em) { [data-frame-builder-at=\"$key\"] { padding: 2rem !important; } }\n"
      . "@media all and (min-width: 75em) { [data-frame-builder-at=\"$key\"] { max-width: 45rem !important; text-align: center !important; } }",
      $responsive['css'],
      'Narrowest first; a breakpoint the theme does not have, and a value that is none, give nothing.',
    );
    // For the canvas of the editor: the breakpoint it shows, without queries.
    $tablet = $features->responsive([], $values, 'tablet');
    $this->assertSame('[data-frame-builder-at="' . $tablet['key'] . '"] { padding: 2rem !important; }', $tablet['css']);
    $this->assertSame(2, substr_count($features->responsive([], $values, 'desktop')['css'], '!important; }'));
    $this->assertNull($features->responsive([], $values, ''), 'The editor shows every width: none of them.');
    $this->assertNull($features->responsive([], ['_spacing' => ['padding' => ['size' => 's']]]));
    $this->assertNull($features->responsive(self::definition(['spacing' => FALSE, 'size' => FALSE, 'align' => FALSE]), $values), 'A feature that is off has no values per breakpoint either.');
  }

  /**
   * Alignment is logical: start, center or end, nothing else.
   */
  #[DataProvider('alignments')]
  public function testAlignStyle(mixed $value, array $expected): void {
    $this->assertSame($expected, $this->features()->style([], ['_align' => $value]));
  }

  /**
   * Stored alignments and the declarations they give.
   */
  public static function alignments(): array {
    return [
      'start' => ['start', ['text-align' => 'start']],
      'center' => ['center', ['text-align' => 'center']],
      'end' => ['end', ['text-align' => 'end']],
      'left is not logical' => ['left', []],
      'justify' => ['justify', []],
      'unsafe' => ['center; color: red', []],
      'a list' => [['center'], []],
      'empty' => ['', []],
    ];
  }

  /**
   * Alignment and text are features like the others: off means not printed.
   */
  public function testAlignAndTextStyleCanBeSwitchedOff(): void {
    $features = $this->features();
    $values = ['_align' => 'center', '_text' => ['weight' => 'bold']];
    $this->assertSame(['text-align' => 'center', 'font-weight' => '700'], $features->style([], $values));
    $this->assertSame(['font-weight' => '700'], $features->style(self::definition(['align' => FALSE]), $values));
    $this->assertSame(['text-align' => 'center'], $features->style(self::definition(['text' => FALSE]), $values));
  }

  /**
   * A background is a colour of the palette and an image with how it fills
   * the component and which part shows.
   */
  public function testBackgroundStyle(): void {
    $features = $this->features();
    $this->assertSame(['background-color' => '#112233'], $features->style([], ['_background' => ['color' => 'brand']]));
    $this->assertSame([], $features->style([], ['_background' => ['color' => '#ff0000']]), 'Only a name of the palette is a colour.');
    $this->assertSame([
      'background-color' => '#ffffff',
      'background-image' => 'url("/sites/default/files/sky.jpg")',
      'background-repeat' => 'no-repeat',
      'background-size' => 'cover',
      'background-position' => '50% 50%',
    ], $features->style([], ['_background' => ['color' => 'paper', 'image' => '/sites/default/files/sky.jpg']]));
    $this->assertSame([
      'background-image' => 'url("/sky.jpg")',
      'background-repeat' => 'no-repeat',
      'background-size' => '100% 100%',
      'background-position' => '100% 0%',
    ], $features->style([], ['_background' => ['image' => ['url' => '/sky.jpg'], 'size' => 'stretch', 'x' => 150, 'y' => -5]]), 'The visible part stays inside the image.');
    $style = $features->style([], ['_background' => ['image' => '/sky.jpg', 'size' => 'contain', 'x' => '33.333', 'y' => 66.66]]);
    $this->assertSame('contain', $style['background-size']);
    $this->assertSame('33.3% 66.7%', $style['background-position']);
    $this->assertSame('auto', $features->style([], ['_background' => ['image' => '/sky.jpg', 'size' => 'auto']])['background-size']);
    $this->assertSame('cover', $features->style([], ['_background' => ['image' => '/sky.jpg', 'size' => '1px; color: red']])['background-size']);
    $this->assertSame([], $features->style([], ['_background' => ['image' => '', 'size' => 'contain']]), 'Without an image there is nothing to size.');
    $this->assertSame([], $features->style(self::definition(['background' => FALSE]), ['_background' => ['color' => 'brand', 'image' => '/sky.jpg']]));
    $this->assertSame([], $features->style([], ['_background' => 'brand']));
  }

  /**
   * The URL of a background cannot leave its url() or run a script.
   */
  #[DataProvider('backgroundUrls')]
  public function testBackgroundUrlIsEscaped(string $url, string $expected): void {
    $this->assertSame($expected, $this->features()->style([], ['_background' => ['image' => $url]])['background-image']);
  }

  /**
   * Image URLs and the CSS url() they give.
   */
  public static function backgroundUrls(): array {
    return [
      'a path' => ['/a/b.png?x=1', 'url("/a/b.png?x=1")'],
      'an address' => ['https://example.com/a.png', 'url("https://example.com/a.png")'],
      'a quote' => ['/a.png"); color: red; x: url("', 'url("/a.png%22%29; color: red; x: url%28%22")'],
      'parentheses and a backslash' => ['/a(1)\\b.png', 'url("/a%281%29%5Cb.png")'],
      'line breaks' => ["/a\n.png\r", 'url("/a.png")'],
      'a tag' => ['/a.png</style>', 'url("/a.png%3C/style>")'],
      'a script' => ['javascript:alert(1)', 'url("alert%281%29")'],
    ];
  }

  /**
   * A picked image is printed with its image style; with a responsive image
   * style the image is left to the rules with media queries.
   */
  public function testBackgroundOfMedia(): void {
    $features = $this->features();
    $media = $this->createImageMedia();
    $this->createBreakpointStyle('cat_art');

    $style = $features->style([], ['_background' => ['image' => ['media' => $media->id(), 'url' => '/original.png', 'style' => 'style:thumbnail']]]);
    $this->assertStringContainsString('/styles/thumbnail/public/picture-', $style['background-image']);
    $this->assertStringStartsWith('url("', $style['background-image']);
    $this->assertNull($features->responsiveBackground([], ['_background' => ['image' => ['media' => $media->id(), 'style' => 'style:thumbnail']]]));

    $values = ['_background' => ['image' => ['media' => $media->id(), 'url' => '/original.png', 'style' => 'responsive:cat_art'], 'size' => 'contain']];
    $this->assertSame([
      'background-repeat' => 'no-repeat',
      'background-size' => 'contain',
      'background-position' => '50% 50%',
    ], $features->style([], $values), 'An inline image would win over every media query.');
  }

  /**
   * A background with a responsive image style becomes rules with the media
   * queries of its breakpoints, for an element marked with their key.
   */
  public function testResponsiveBackground(): void {
    $features = $this->features();
    $media = $this->createImageMedia();
    $this->createBreakpointStyle('cat_art');
    $values = ['_background' => ['image' => ['media' => $media->id(), 'style' => 'responsive:cat_art']]];

    $background = $features->responsiveBackground([], $values);
    $this->assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $background['key']);
    $selector = '[data-frame-builder-bg="' . $background['key'] . '"]';
    $rules = explode("\n", $background['css']);
    $this->assertCount(5, $rules);
    $this->assertMatchesRegularExpression('#^' . preg_quote($selector, '#') . ' \{ background-image: url\("[^"]*/styles/medium/public/picture-[^"]*"\); \}$#', $rules[0], 'The fallback image style, without a query.');
    $this->assertMatchesRegularExpression('#^' . preg_quote($selector, '#') . ' \{ background-image: url\("[^"]*/styles/thumbnail/public/[^"]*"\); \}$#', $rules[1], 'The breakpoint without a media query applies always.');
    $this->assertMatchesRegularExpression('#^@media all and \(min-width: 48em\) \{ ' . preg_quote($selector, '#') . ' \{ background-image: url\("[^"]*/styles/medium/public/[^"]*"\); \} \}$#', $rules[2]);
    $this->assertMatchesRegularExpression('#^@media all and \(min-width: 75em\) \{ ' . preg_quote($selector, '#') . ' \{ background-image: url\("[^"]*/styles/large/public/[^"]*"\); \} \}$#', $rules[3]);
    $this->assertMatchesRegularExpression('#^@media all and \(min-width: 75em\) and \(min-resolution: 2dppx\) \{ ' . preg_quote($selector, '#') . ' \{ background-image: url\("[^"]*/styles/wide/public/[^"]*"\); \} \}$#', $rules[4]);

    $this->assertSame($background, $features->responsiveBackground([], $values), 'The same image gives the same key.');
    $other = $this->createImageMedia();
    $this->assertNotSame($background['key'], $features->responsiveBackground([], ['_background' => ['image' => ['media' => $other->id(), 'style' => 'responsive:cat_art']]])['key']);
  }

  /**
   * Only a picked image with a responsive image style of a component that
   * has a background gives rules.
   */
  public function testResponsiveBackgroundOnlyWhenResponsive(): void {
    $features = $this->features();
    $media = $this->createImageMedia();
    $this->createBreakpointStyle('cat_art');
    $image = ['media' => $media->id(), 'style' => 'responsive:cat_art'];
    $this->assertNotNull($features->responsiveBackground([], ['_background' => ['image' => $image]]));
    $this->assertNull($features->responsiveBackground(self::definition(['background' => FALSE]), ['_background' => ['image' => $image]]));
    $this->assertNull($features->responsiveBackground([], ['_background' => ['image' => '/sky.jpg']]));
    $this->assertNull($features->responsiveBackground([], ['_background' => ['image' => ['media' => $media->id()]]]));
    $this->assertNull($features->responsiveBackground([], ['_background' => ['image' => ['media' => $media->id(), 'style' => 'responsive:gone']]]));
    $this->assertNull($features->responsiveBackground([], ['_background' => ['image' => ['media' => 999, 'style' => 'responsive:cat_art']]]));
    $this->assertNull($features->responsiveBackground([], ['_background' => ['color' => 'brand']]));
    $this->assertNull($features->responsiveBackground([], ['_background' => 'brand']));
    $this->assertNull($features->responsiveBackground([], []));
  }

  /**
   * A placed component takes the free space around it with automatic margins.
   */
  #[DataProvider('places')]
  public function testRelativePosition(string $place, array $expected): void {
    $this->assertSame($expected, $this->features()->positionStyle([], ['_position' => ['mode' => 'relative', 'place' => $place]]));
  }

  /**
   * Places of a component in its cell and the declarations they give.
   */
  public static function places(): array {
    $fit = ['width' => 'fit-content', 'max-width' => '100%'];
    return [
      'top left' => ['top-left', ['margin-block' => '0 auto', 'margin-inline' => '0 auto'] + $fit],
      'middle centre' => ['middle-center', ['margin-block' => 'auto auto', 'margin-inline' => 'auto auto'] + $fit],
      'bottom right' => ['bottom-right', ['margin-block' => 'auto 0', 'margin-inline' => 'auto 0'] + $fit],
      'top centre' => ['top-center', ['margin-block' => '0 auto', 'margin-inline' => 'auto auto'] + $fit],
      'only vertical' => ['bottom', ['margin-block' => 'auto 0']],
      'unknown vertical' => ['above-right', ['margin-inline' => 'auto 0'] + $fit],
      'nothing' => ['', []],
      'not a place' => ['somewhere', []],
    ];
  }

  /**
   * A pinned component sits on a corner of its parent, at a distance of the
   * spacing scale, and can be centred on the corner.
   */
  public function testAbsolutePosition(): void {
    $features = $this->features();
    $this->assertSame(
      ['position' => 'absolute', 'z-index' => '1', 'top' => '0.5rem', 'right' => '0.5rem'],
      $features->positionStyle([], ['_position' => ['mode' => 'absolute', 'corner' => 'top-right', 'offset' => ['size' => 's']]]),
    );
    $this->assertSame(
      ['position' => 'absolute', 'z-index' => '1', 'bottom' => '0', 'left' => '0', 'transform' => 'translate(-50%, 50%)'],
      $features->positionStyle([], ['_position' => ['mode' => 'absolute', 'corner' => 'bottom-left', 'overlap' => TRUE]]),
    );
    $this->assertSame('translate(-50%, -50%)', $features->positionStyle([], ['_position' => ['mode' => 'absolute', 'corner' => 'top-left', 'overlap' => 1]])['transform']);
    $this->assertSame('translate(50%, -50%)', $features->positionStyle([], ['_position' => ['mode' => 'absolute', 'corner' => 'top-right', 'overlap' => TRUE]])['transform']);
    $pinned = $features->positionStyle([], ['_position' => ['mode' => 'absolute', 'corner' => 'bottom-right', 'overlap' => TRUE, 'offset' => ['size' => 'custom', 'custom' => '-4px']]]);
    $this->assertSame('translate(50%, 50%)', $pinned['transform']);
    $this->assertSame('-4px', $pinned['bottom']);
    $this->assertSame('-4px', $pinned['right']);
    $this->assertSame('0', $features->positionStyle([], ['_position' => ['mode' => 'absolute', 'corner' => 'top-left', 'offset' => ['size' => 'custom', 'custom' => 'calc(1px)']]])['top'], 'An unsafe offset is no offset.');
  }

  /**
   * A position that is neither placed nor pinned leaves the component in
   * the flow; a value from before there were modes is pinned.
   */
  public function testPositionInTheFlow(): void {
    $features = $this->features();
    $this->assertSame(
      ['position' => 'absolute', 'z-index' => '1', 'top' => '0', 'left' => '0'],
      $features->positionStyle([], ['_position' => ['corner' => 'top-left']]),
      'Only a corner: pinned.',
    );
    $this->assertSame([], $features->positionStyle([], ['_position' => ['mode' => 'absolute', 'corner' => 'center']]));
    $this->assertSame([], $features->positionStyle([], ['_position' => ['mode' => 'absolute']]));
    $this->assertSame([], $features->positionStyle([], ['_position' => ['mode' => 'fixed', 'corner' => 'top-left']]));
    $this->assertSame([], $features->positionStyle([], ['_position' => ['mode' => '', 'corner' => 'top-left']]));
    $this->assertSame([], $features->positionStyle([], ['_position' => ['place' => 'top-left']]), 'A place without the mode is not placed.');
    $this->assertSame([], $features->positionStyle([], ['_position' => 'absolute']));
    $this->assertSame([], $features->positionStyle([], []));
    $this->assertSame([], $features->positionStyle(self::definition(['position' => FALSE]), ['_position' => ['mode' => 'absolute', 'corner' => 'top-left']]));
    $this->assertSame([], $features->style([], ['_position' => ['mode' => 'absolute', 'corner' => 'top-left']]), 'The position is not part of the other declarations.');
  }

  /**
   * Every feature at once, in the order the declarations are printed.
   */
  public function testStyleOfAllFeatures(): void {
    $style = $this->features()->style([], [
      '_position' => ['mode' => 'relative', 'place' => 'top-left'],
      '_background' => ['color' => 'brand'],
      '_text' => ['color' => 'paper'],
      '_align' => 'end',
      '_size' => ['max_width' => 'm'],
      '_spacing' => ['padding' => ['size' => 'm']],
      'text' => 'A prop of the component',
    ]);
    $this->assertSame([
      'padding' => '1rem',
      'max-width' => '45rem',
      'text-align' => 'end',
      'color' => '#ffffff',
      'background-color' => '#112233',
    ], $style);
    $this->assertSame('padding: 1rem; max-width: 45rem; text-align: end; color: #ffffff; background-color: #112233', ComponentFeatures::css($style));
  }

  /**
   * Declarations become the value of a style attribute.
   */
  public function testCss(): void {
    $this->assertSame('', ComponentFeatures::css([]));
    $this->assertSame('margin: 1rem', ComponentFeatures::css(['margin' => '1rem']));
    $this->assertSame('margin: 1rem; --frame-builder-label-color: #112233', ComponentFeatures::css(['margin' => '1rem', '--frame-builder-label-color' => '#112233']));
  }

}
