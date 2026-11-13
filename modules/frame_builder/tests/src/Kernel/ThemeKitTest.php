<?php

declare(strict_types=1);

namespace Drupal\Tests\frame_builder\Kernel;

use Drupal\image\Entity\ImageStyle;
use Drupal\responsive_image\Entity\ResponsiveImageStyle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The design tokens of the active theme.
 */
#[Group('frame_builder')]
#[RunTestsInSeparateProcesses]
class ThemeKitTest extends BuilderKernelTestBase {

  /**
   * A subtheme of the test theme: it declares spacing, colours, image styles
   * and features, and leaves font sizes and sizes to its base theme.
   */
  protected const CAT_THEME = 'frame_builder_test_cat_theme';

  /**
   * The tokens service.
   */
  protected function kit(): object {
    return $this->container->get('frame_builder.theme_kit');
  }

  /**
   * The scales and the palette are those the theme declares, in its order.
   */
  public function testTokensOfTheTheme(): void {
    $kit = $this->kit();
    $this->assertSame(['s' => '0.5rem', 'm' => '1rem', 'l' => '2rem'], $kit->spacing());
    $this->assertSame(['s' => '0.875rem', 'm' => '1rem', 'l' => '1.5rem'], $kit->fontSizes());
    $this->assertSame(['s' => '30rem', 'm' => '45rem', 'full' => '100%'], $kit->sizes());
    $this->assertSame([
      'brand' => ['label' => 'Brand', 'value' => '#112233'],
      'paper' => ['label' => 'Paper', 'value' => '#ffffff'],
    ], $kit->colors());
  }

  /**
   * A theme that declares nothing gets the default scales, no colours and
   * every feature.
   */
  public function testDefaultsWithoutDeclaration(): void {
    $this->useTheme('frame');
    $kit = $this->kit();
    $this->assertSame(['xs' => '0.25rem', 's' => '0.5rem', 'm' => '1rem', 'l' => '2rem', 'xl' => '4rem'], $kit->spacing());
    $this->assertSame(['xs', 's', 'm', 'l', 'xl', '2xl', '3xl'], array_map('strval', array_keys($kit->fontSizes())));
    $this->assertSame('3rem', $kit->fontSizes()['3xl']);
    $this->assertSame(['xs' => '20rem', 's' => '30rem', 'm' => '45rem', 'l' => '60rem', 'xl' => '75rem', 'full' => '100%'], $kit->sizes());
    $this->assertSame([], $kit->colors());
    foreach (['spacing', 'size', 'align', 'text', 'background', 'border', 'position'] as $feature) {
      $this->assertTrue($kit->featureDefault($feature), $feature);
    }
  }

  /**
   * A subtheme changes only what it declares: a scale it declares replaces
   * the one of the base theme as a whole, the others are inherited.
   */
  public function testSubthemeOverridesBaseTheme(): void {
    $this->useTheme(self::CAT_THEME);
    $kit = $this->kit();
    $this->assertSame(['s' => '0.875rem', 'm' => '1rem', 'l' => '1.5rem'], $kit->fontSizes(), 'Font sizes come from the base theme.');
    $this->assertSame(['s' => '30rem', 'm' => '45rem', 'full' => '100%'], $kit->sizes(), 'Sizes come from the base theme.');
    $this->assertArrayNotHasKey('m', $kit->spacing(), 'The spacing scale of the base theme is replaced, not merged.');
    $this->assertSame('4px', $kit->spacing()['tiny']);
    $this->assertArrayNotHasKey('brand', $kit->colors(), 'The palette of the base theme is replaced, not merged.');
    $this->assertNull($kit->color('brand'));
    $this->assertNull($kit->length('m'));

    $this->assertFalse($kit->featureDefault('position'), 'Switched off by the subtheme.');
    $this->assertFalse($kit->featureDefault('background'), 'Switched off by the subtheme, with 0.');
    $this->assertTrue($kit->featureDefault('spacing'), 'Features the subtheme does not name stay as the base theme has them.');
    $this->assertTrue($kit->featureDefault('text'));
  }

  /**
   * Token values that are not a plain CSS length or colour are not offered.
   */
  public function testUnsafeTokensAreRefused(): void {
    $this->useTheme(self::CAT_THEME);
    $kit = $this->kit();
    $spacing = $kit->spacing();
    $this->assertSame('4px', $spacing['tiny']);
    $this->assertSame('50%', $spacing['half']);
    $this->assertSame('-1.5em', $spacing['back']);
    foreach (['bare', 'sum', 'evil', 'word'] as $name) {
      $this->assertArrayNotHasKey($name, $spacing, "The spacing step '$name' is not a number with a unit.");
      $this->assertNull($kit->length($name));
      $this->assertNull($kit->length(['size' => $name]));
    }

    $this->assertSame([
      'ink' => ['label' => 'ink', 'value' => '#000'],
      'primary' => ['label' => 'Primary', 'value' => 'rgb(1, 2, 3)'],
      'glass' => ['label' => 'glass', 'value' => 'hsla(120, 50%, 50%, 0.5)'],
      'named' => ['label' => 'Named', 'value' => 'red'],
      'two words' => ['label' => 'Two words', 'value' => '#abcdef'],
    ], $kit->colors(), 'Colours with a url(), an expression or a second declaration are left out.');
    foreach (['script', 'expr', 'semi'] as $name) {
      $this->assertNull($kit->color($name));
    }
  }

  /**
   * A step of the scale whose length is zero is a step like any other.
   */
  public function testZeroIsAStepOfTheScale(): void {
    $this->useTheme(self::CAT_THEME);
    $this->assertSame('0', $this->kit()->spacing()['none'] ?? NULL);
    $this->assertSame('0', $this->kit()->length(['size' => 'none']));
  }

  /**
   * A chosen length is a step of the spacing scale or a number with a unit.
   */
  #[DataProvider('lengths')]
  public function testLength(mixed $value, ?string $expected): void {
    $this->assertSame($expected, $this->kit()->length($value));
  }

  /**
   * Values of a length choice and the CSS length they give.
   */
  public static function lengths(): array {
    return [
      'token name' => ['m', '1rem'],
      'token choice' => [['size' => 'l'], '2rem'],
      'custom choice' => [['size' => 'custom', 'custom' => '12px'], '12px'],
      'custom, trimmed' => [['size' => 'custom', 'custom' => ' 5% '], '5%'],
      'custom, negative decimal' => [['size' => 'custom', 'custom' => '-1.25em'], '-1.25em'],
      'custom, viewport unit' => [['size' => 'custom', 'custom' => '10vw'], '10vw'],
      'custom, zero' => [['size' => 'custom', 'custom' => '0'], '0'],
      'custom without a value' => [['size' => 'custom'], NULL],
      'custom, no unit' => [['size' => 'custom', 'custom' => '12'], NULL],
      'custom, unknown unit' => [['size' => 'custom', 'custom' => '12pt'], NULL],
      'custom, a function' => [['size' => 'custom', 'custom' => 'calc(100% - 1px)'], NULL],
      'custom, a second declaration' => [['size' => 'custom', 'custom' => '1px; background: red'], NULL],
      'custom, a variable' => [['size' => 'custom', 'custom' => 'var(--x)'], NULL],
      'custom, no leading digit' => [['size' => 'custom', 'custom' => '.5rem'], NULL],
      'custom, two lengths' => [['size' => 'custom', 'custom' => '1px 2px'], NULL],
      'custom, a line break' => [['size' => 'custom', 'custom' => "1px\n;x"], NULL],
      'plain length' => ['12px', '12px'],
      'plain unsafe' => ['1px}body{color:red', NULL],
      'a length as the name of a choice' => [['size' => '12px'], NULL],
      'unknown token' => ['xxl', NULL],
      'unknown token choice' => [['size' => 'xxl'], NULL],
      'none' => [['size' => 'none'], NULL],
      'the word custom alone' => ['custom', NULL],
      'empty string' => ['', NULL],
      'empty choice' => [[], NULL],
      'null' => [NULL, NULL],
    ];
  }

  /**
   * A CSS length becomes the choice the editor stores: the step of the scale
   * with that value, else a custom length; nothing for what is no length.
   */
  public function testLengthChoice(): void {
    $kit = $this->kit();
    $this->assertSame(['size' => 'm'], $kit->lengthChoice('1rem'));
    $this->assertSame(['size' => 's'], $kit->lengthChoice('0.5rem'));
    $this->assertSame(['size' => 'custom', 'custom' => '3px'], $kit->lengthChoice('3px'));
    $this->assertNull($kit->lengthChoice('wide'));
    $this->assertNull($kit->lengthChoice('calc(1px + 2px)'));
    $this->assertNull($kit->lengthChoice(''));
    // Whatever a choice was made from, it gives that length back.
    foreach (['1rem', '0.5rem', '3px'] as $css) {
      $this->assertSame($css, $kit->length($kit->lengthChoice($css)));
    }
  }

  /**
   * A size is one of the theme's sizes or a custom length; unlike a length it
   * is never a plain CSS value.
   */
  public function testSize(): void {
    $kit = $this->kit();
    $this->assertSame('30rem', $kit->size('s'));
    $this->assertSame('100%', $kit->size(['size' => 'full']));
    $this->assertSame('40vh', $kit->size(['size' => 'custom', 'custom' => '40vh']));
    $this->assertNull($kit->size(['size' => 'custom', 'custom' => 'max-content']));
    $this->assertNull($kit->size(['size' => 'custom']));
    $this->assertNull($kit->size('l'), 'A step of the spacing scale is not a size.');
    $this->assertNull($kit->size('12px'));
    $this->assertNull($kit->size(NULL));
    $this->assertNull($kit->size([]));
  }

  /**
   * A font size is a step of the type scale or a custom length.
   */
  public function testFontSize(): void {
    $kit = $this->kit();
    $this->assertSame('1.5rem', $kit->fontSize('l'));
    $this->assertSame('0.875rem', $kit->fontSize(['size' => 's', 'weight' => 'bold']));
    $this->assertSame('18px', $kit->fontSize(['size' => 'custom', 'custom' => '18px']));
    $this->assertNull($kit->fontSize(['size' => 'custom', 'custom' => 'larger']));
    $this->assertNull($kit->fontSize('full'), 'A size of components is not a font size.');
    $this->assertNull($kit->fontSize(['weight' => 'bold']));
    $this->assertNull($kit->fontSize(NULL));
  }

  /**
   * A colour is known by its name in the palette, never by a CSS value.
   */
  public function testColor(): void {
    $kit = $this->kit();
    $this->assertSame('#112233', $kit->color('brand'));
    $this->assertSame('#ffffff', $kit->color('paper'));
    $this->assertNull($kit->color('#112233'));
    $this->assertNull($kit->color('red'));
    $this->assertNull($kit->color(''));
    $this->assertNull($kit->color(NULL));
    $this->assertNull($kit->color(['value' => '#112233']));
  }

  /**
   * Features are on as the theme says; a name that is no feature is off.
   */
  public function testFeatureDefault(): void {
    foreach (['spacing', 'size', 'align', 'text', 'background', 'border', 'position'] as $feature) {
      $this->assertTrue($this->kit()->featureDefault($feature), $feature);
    }
    $this->assertFalse($this->kit()->featureDefault('shadow'));
  }

  /**
   * The default theme has to be Frame or a subtheme of it, at any depth.
   */
  public function testIsSupportedTheme(): void {
    $kit = $this->kit();
    $this->assertTrue($kit->isSupportedTheme(), 'A subtheme of Frame.');
    $this->container->get('theme_installer')->install([self::CAT_THEME, 'stark']);
    $this->config('system.theme')->set('default', self::CAT_THEME)->save();
    $this->assertTrue($kit->isSupportedTheme(), 'A subtheme of a subtheme of Frame.');
    $this->config('system.theme')->set('default', 'frame')->save();
    $this->assertTrue($kit->isSupportedTheme(), 'Frame itself.');
    $this->config('system.theme')->set('default', 'stark')->save();
    $this->assertFalse($kit->isSupportedTheme(), 'A theme that is not based on Frame.');
    $this->config('system.theme')->set('default', 'gone_theme')->save();
    $this->assertFalse($kit->isSupportedTheme(), 'A default theme that is not installed.');
  }

  /**
   * The theme of the request is checked apart from the default theme.
   */
  public function testIsActiveThemeSupported(): void {
    $this->assertTrue($this->kit()->isActiveThemeSupported());
    $this->container->get('theme_installer')->install(['stark', 'frame']);
    $kit = $this->kit();
    $themes = $this->container->get('theme.initialization');
    $manager = $this->container->get('theme.manager');
    $manager->setActiveTheme($themes->initTheme('stark'));
    $this->assertFalse($kit->isActiveThemeSupported(), 'The active theme is not based on Frame.');
    $this->assertTrue($kit->isSupportedTheme(), 'The default theme still is.');
    $manager->setActiveTheme($themes->initTheme('frame'));
    $this->assertTrue($kit->isActiveThemeSupported(), 'Frame itself.');
  }

  /**
   * Without the Image module there are no image styles to offer.
   */
  public function testImageStylesWithoutImageModule(): void {
    $this->assertSame([], $this->kit()->imageStyles());
  }

  /**
   * The image styles the theme lists are offered, in its order; styles that
   * do not exist are left out, and without a list every style is offered.
   */
  public function testImageStyles(): void {
    $this->enableModules(['file', 'image', 'breakpoint', 'responsive_image']);
    $this->installConfig(['image']);
    ResponsiveImageStyle::create(['id' => 'cat_wide', 'label' => 'Cat wide', 'breakpoint_group' => 'responsive_image', 'fallback_image_style' => 'medium'])->save();
    ResponsiveImageStyle::create(['id' => 'cat_other', 'label' => 'Cat other', 'breakpoint_group' => 'responsive_image', 'fallback_image_style' => 'medium'])->save();
    $label = static fn (string $id): string => (string) ImageStyle::load($id)->label();

    $this->assertSame(['style:thumbnail' => $label('thumbnail')], $this->kit()->imageStyles(), 'Only the style the test theme lists.');

    $this->useTheme(self::CAT_THEME);
    $this->assertSame([
      'style:medium' => $label('medium'),
      'style:thumbnail' => $label('thumbnail'),
      'responsive:cat_wide' => 'Cat wide (responsive)',
    ], $this->kit()->imageStyles(), 'A bare id is an image style; the order is that of the list; "missing" is not a style.');

    $this->useTheme('frame');
    $all = $this->kit()->imageStyles();
    foreach (array_keys(ImageStyle::loadMultiple()) as $id) {
      $this->assertSame($label($id), $all["style:$id"] ?? NULL);
    }
    $this->assertSame('Cat wide (responsive)', $all['responsive:cat_wide']);
    $this->assertSame('Cat other (responsive)', $all['responsive:cat_other']);
    $this->assertCount(count(ImageStyle::loadMultiple()) + 2, $all);
  }

}
