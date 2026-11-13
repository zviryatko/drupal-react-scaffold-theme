<?php

namespace Drupal\frame_builder;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Theme\ThemeManagerInterface;

/**
 * The design tokens ("UI kit") of the active theme, for the page builder.
 *
 * A theme declares them in its .info.yml, under `frame_builder`;
 * base themes are read first, so a subtheme changes only what differs:
 *
 * @code
 * frame_builder:
 *   uikit:
 *     # Spacing scale: name => CSS length. Offered for margin, padding, offsets.
 *     spacing: { xs: 0.25rem, s: 0.5rem, m: 1rem, l: 2rem, xl: 4rem }
 *     # Palette: the only colours an editor can pick.
 *     colors:
 *       green: { label: 'Green', value: '#008068' }
 *     # Image styles an editor can pick: "<image style>" or
 *     # "responsive:<responsive image style>". Omit to offer all of them.
 *     image_styles: [large, 'responsive:wide']
 *     # Type scale: name => CSS length. Sizes an editor can give text.
 *     font_sizes: { s: 0.875rem, m: 1rem, l: 1.25rem, xl: 1.5rem }
 *     # Sizes: name => CSS length. How wide and how tall a component can be.
 *     sizes: { s: 30rem, m: 45rem, l: 60rem, xl: 75rem, full: 100% }
 *     # Widths of a border: name => CSS length.
 *     border_widths: { thin: 1px, medium: 2px, thick: 4px }
 *     # Rounding of corners: name => CSS length.
 *     radii: { s: 0.25rem, m: 0.5rem, l: 1rem, full: 9999px }
 *   # Features offered on every component (a component.yml can override,
 *   # see ComponentFeatures).
 *   features: { spacing: true, size: true, align: true, text: true, background: true, border: true, position: true }
 * @endcode
 *
 * Editors never type free values for colours; lengths are a token, or a
 * number with a unit ("12px", "5%") that is checked before it is printed.
 */
class ThemeKit {

  /**
   * Used when the theme declares nothing.
   */
  protected const DEFAULTS = [
    'uikit' => [
      'spacing' => ['xs' => '0.25rem', 's' => '0.5rem', 'm' => '1rem', 'l' => '2rem', 'xl' => '4rem'],
      'colors' => [],
      'font_sizes' => ['xs' => '0.75rem', 's' => '0.875rem', 'm' => '1rem', 'l' => '1.25rem', 'xl' => '1.5rem', '2xl' => '2rem', '3xl' => '3rem'],
      'sizes' => ['xs' => '20rem', 's' => '30rem', 'm' => '45rem', 'l' => '60rem', 'xl' => '75rem', 'full' => '100%'],
      'border_widths' => ['thin' => '1px', 'medium' => '2px', 'thick' => '4px'],
      'radii' => ['s' => '0.25rem', 'm' => '0.5rem', 'l' => '1rem', 'full' => '9999px'],
    ],
    'features' => ['spacing' => TRUE, 'size' => TRUE, 'align' => TRUE, 'text' => TRUE, 'background' => TRUE, 'border' => TRUE, 'position' => TRUE],
  ];

  public function __construct(
    protected ThemeManagerInterface $themeManager,
    protected ModuleHandlerInterface $moduleHandler,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Name of the base theme the builder belongs to.
   */
  public const BASE_THEME = 'frame';

  /**
   * Whether the default theme of the site is Frame or a subtheme.
   *
   * The builder works only then: the editor is served by the default theme,
   * and its components, tokens and features are the theme's.
   */
  public function isSupportedTheme(): bool {
    $default = \Drupal::config('system.theme')->get('default');
    $themes = \Drupal::service('theme_handler')->listInfo();
    if (!isset($themes[$default])) {
      return FALSE;
    }
    return $default === self::BASE_THEME || isset($themes[$default]->base_themes[self::BASE_THEME]);
  }

  /**
   * Whether the theme of this request is Frame or based on it.
   */
  public function isActiveThemeSupported(): bool {
    $theme = $this->themeManager->getActiveTheme();
    return $theme->getName() === self::BASE_THEME || isset($theme->getBaseThemeExtensions()[self::BASE_THEME]);
  }

  /**
   * The settings of the active theme merged over its base themes.
   */
  protected function settings(): array {
    $theme = $this->themeManager->getActiveTheme();
    $settings = self::DEFAULTS;
    $extensions = array_reverse($theme->getBaseThemeExtensions());
    $extensions[] = $theme->getExtension();
    foreach ($extensions as $extension) {
      $own = $extension->info['frame_builder'] ?? [];
      // A scale or palette is replaced as a whole: it is an ordered set.
      foreach (['spacing', 'colors', 'image_styles', 'font_sizes', 'sizes', 'border_widths', 'radii'] as $key) {
        if (isset($own['uikit'][$key]) && is_array($own['uikit'][$key])) {
          $settings['uikit'][$key] = $own['uikit'][$key];
        }
      }
      $settings['features'] = array_replace($settings['features'], is_array($own['features'] ?? NULL) ? $own['features'] : []);
    }
    return $settings;
  }

  /**
   * The spacing scale: name => CSS length, smallest first as declared.
   */
  public function spacing(): array {
    return array_filter(array_map(fn ($value) => $this->cssLength((string) $value), $this->settings()['uikit']['spacing']), static fn ($length) => $length !== NULL);
  }

  /**
   * The type scale: name => CSS length, smallest first as declared.
   */
  public function fontSizes(): array {
    return array_filter(array_map(fn ($value) => $this->cssLength((string) $value), $this->settings()['uikit']['font_sizes']), static fn ($length) => $length !== NULL);
  }

  /**
   * The sizes a component can have: name => CSS length, smallest first as
   * declared.
   */
  public function sizes(): array {
    return array_filter(array_map(fn ($value) => $this->cssLength((string) $value), $this->settings()['uikit']['sizes']), static fn ($length) => $length !== NULL);
  }

  /**
   * The widths a border can have: name => CSS length, thinnest first.
   */
  public function borderWidths(): array {
    return $this->scale('border_widths');
  }

  /**
   * How round corners can be: name => CSS length, least first.
   */
  public function radii(): array {
    return $this->scale('radii');
  }

  /**
   * A width of a border as the editor stores a choice: a name of the scale or
   * a custom length. NULL when it is neither.
   */
  public function borderWidth(mixed $value): ?string {
    return $this->choice($value, $this->borderWidths());
  }

  /**
   * A rounding of corners as the editor stores a choice. NULL when none.
   */
  public function radius(mixed $value): ?string {
    return $this->choice($value, $this->radii());
  }

  /**
   * A scale of the kit with only valid lengths.
   */
  protected function scale(string $key): array {
    return array_filter(array_map(fn ($value) => $this->cssLength((string) $value), (array) ($this->settings()['uikit'][$key] ?? [])), static fn ($length) => $length !== NULL);
  }

  /**
   * ['size' => name | 'custom', 'custom' => length] as a CSS length of a scale.
   */
  protected function choice(mixed $value, array $scale): ?string {
    $size = is_array($value) ? (string) ($value['size'] ?? '') : (string) $value;
    if ($size === 'custom') {
      return $this->cssLength((string) ($value['custom'] ?? ''));
    }
    return $scale[$size] ?? NULL;
  }

  /**
   * The breakpoints of the theme that start at a width: those a component
   * can have its own layout and style from (Drupal's Breakpoint module,
   * <theme>.breakpoints.yml, of the active theme or the first base theme
   * that has any). Narrowest first.
   *
   * @return array
   *   By the name of the breakpoint without its theme ("tablet"): 'label',
   *   'query' (its media query) and 'width' (pixels it starts at).
   */
  public function breakpoints(): array {
    if (!$this->moduleHandler->moduleExists('breakpoint')) {
      return [];
    }
    $theme = $this->themeManager->getActiveTheme();
    foreach (array_merge([$theme->getName()], array_keys($theme->getBaseThemeExtensions())) as $group) {
      $found = [];
      foreach (\Drupal::service('breakpoint.manager')->getBreakpointsByGroup($group) as $id => $breakpoint) {
        $query = trim(preg_replace('/[{}<>;]/', '', (string) $breakpoint->getMediaQuery()));
        // Without a query it is every width: what a component has anyway.
        if ($query === '' || !preg_match('/min-width:\s*([\d.]+)(px|em|rem)/', $query, $match)) {
          $all = TRUE;
          continue;
        }
        $name = preg_replace('/[^a-z0-9_]/', '_', strtolower(substr((string) $id, strrpos((string) $id, '.') + (str_contains((string) $id, '.') ? 1 : 0))));
        $found[$name] = [
          'label' => (string) $breakpoint->getLabel(),
          'query' => $query,
          'width' => (int) round($match[1] * ($match[2] === 'px' ? 1 : 16)),
        ];
      }
      if ($found || !empty($all)) {
        uasort($found, static fn (array $a, array $b): int => $a['width'] <=> $b['width']);
        return $found;
      }
    }
    return [];
  }

  /**
   * A size as the editor stores a choice: one of the theme's sizes or a
   * custom length, as a CSS length. NULL when it is neither.
   */
  public function size(mixed $value): ?string {
    $size = is_array($value) ? (string) ($value['size'] ?? '') : (string) $value;
    if ($size === 'custom') {
      return $this->cssLength((string) ($value['custom'] ?? ''));
    }
    return $this->sizes()[$size] ?? NULL;
  }

  /**
   * A font size as the editor stores a choice: a step of the type scale or a
   * custom length, as a CSS length. NULL when it is neither.
   */
  public function fontSize(mixed $value): ?string {
    $size = is_array($value) ? (string) ($value['size'] ?? '') : (string) $value;
    if ($size === 'custom') {
      return $this->cssLength((string) ($value['custom'] ?? ''));
    }
    return $this->fontSizes()[$size] ?? NULL;
  }

  /**
   * The palette: name => ['label' => ..., 'value' => CSS colour].
   */
  public function colors(): array {
    $colors = [];
    foreach ($this->settings()['uikit']['colors'] as $name => $color) {
      $value = is_array($color) ? (string) ($color['value'] ?? '') : (string) $color;
      if (preg_match('/^(#[0-9a-f]{3,8}|(rgb|hsl)a?\([0-9.,%\s\/]+\)|[a-z]+)$/i', $value)) {
        $colors[$name] = ['label' => is_array($color) ? (string) ($color['label'] ?? $name) : $name, 'value' => $value];
      }
    }
    return $colors;
  }

  /**
   * Whether a feature is offered on components by default.
   */
  public function featureDefault(string $feature): bool {
    return !empty($this->settings()['features'][$feature]);
  }

  /**
   * Image styles an editor can pick, as value => label.
   *
   * Values are "style:<id>" or "responsive:<id>".
   */
  public function imageStyles(): array {
    $options = [];
    // Without the Image module there are no image styles to offer.
    if (!$this->entityTypeManager->hasDefinition('image_style')) {
      return $options;
    }
    foreach ($this->entityTypeManager->getStorage('image_style')->loadMultiple() as $style) {
      $options['style:' . $style->id()] = (string) $style->label();
    }
    if ($this->moduleHandler->moduleExists('responsive_image')) {
      foreach ($this->entityTypeManager->getStorage('responsive_image_style')->loadMultiple() as $style) {
        $options['responsive:' . $style->id()] = $style->label() . ' (responsive)';
      }
    }
    $allowed = $this->settings()['uikit']['image_styles'] ?? NULL;
    if (is_array($allowed)) {
      $keys = array_map(static fn ($id): string => str_contains((string) $id, ':') ? (string) $id : "style:$id", $allowed);
      $options = array_intersect_key(array_replace(array_flip($keys), $options), array_flip($keys));
      $options = array_filter($options, 'is_string');
    }
    return $options;
  }

  /**
   * A length an editor chose, as a CSS value: a token of the scale, or a
   * number with a unit. NULL for "none" and for anything else.
   *
   * @param mixed $value
   *   ['size' => token | 'custom', 'custom' => '12px'] or a token name.
   */
  public function length(mixed $value): ?string {
    $size = is_array($value) ? (string) ($value['size'] ?? '') : (string) $value;
    if ($size === 'custom') {
      return $this->cssLength((string) ($value['custom'] ?? ''));
    }
    // A token of the scale; a plain string can also be a length already.
    return $this->spacing()[$size] ?? (is_array($value) ? NULL : $this->cssLength($size));
  }

  /**
   * A CSS length as the editor stores a choice: the step of the scale that
   * has this value, else a custom length.
   */
  public function lengthChoice(string $css): ?array {
    $token = array_search($css, $this->spacing(), TRUE);
    if ($token !== FALSE) {
      return ['size' => $token];
    }
    return $this->cssLength($css) !== NULL ? ['size' => 'custom', 'custom' => $css] : NULL;
  }

  /**
   * A colour of the palette as a CSS value, NULL when it is not in it.
   */
  public function color(mixed $name): ?string {
    return is_string($name) ? ($this->colors()[$name]['value'] ?? NULL) : NULL;
  }

  /**
   * Checks a CSS length: a number and a unit, nothing else.
   */
  protected function cssLength(string $value): ?string {
    $value = trim($value);
    return preg_match('/^-?\d+(\.\d+)?(px|rem|em|%|vw|vh|ch)$/', $value) || $value === '0' ? $value : NULL;
  }

}
