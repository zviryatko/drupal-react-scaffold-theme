<?php

namespace Drupal\frame_builder;

use Drupal\Component\Utility\UrlHelper;

/**
 * Features every component can have, beyond its own props.
 *
 * - spacing: margin and padding, from the theme's spacing scale or a custom
 *   length, on all sides, left and right, or top and bottom.
 * - size: how wide the component may be and how tall it is at least, one of
 *   the theme's sizes or a custom length.
 * - align: the content at the start, in the centre or at the end of the
 *   line. Start and end, not left and right: they swap in right-to-left
 *   languages.
 * - background: a colour of the theme's palette and/or an image, with how it
 *   fills the component and which part of it shows.
 * - position: where the component sits. "relative": placed in the free
 *   space of its place in the flow, top / middle / bottom and start / centre
 *   / end. "absolute": pinned to a corner of the parent component, on top of
 *   its content (badges, tooltips).
 *
 * Which features a component has: the theme's defaults (ThemeKit), changed
 * per component in its *.component.yml:
 *
 * @code
 * thirdPartySettings:
 *   frame_builder:
 *     features: { position: true, background: false }
 * @endcode
 *
 * Values are stored in the layout next to the props, under "_spacing", "_size",
 * "_align", "_background" and "_position", and become inline styles on the root
 * element of the component (through its `attributes`), so a component needs
 * no code for them. Everything printed is a token of the theme or a checked
 * value.
 */
class ComponentFeatures {

  public const FEATURES = ['spacing', 'size', 'align', 'text', 'background', 'position'];

  protected const CORNERS = [
    'top-left' => ['top', 'left', '-50%', '-50%'],
    'top-right' => ['top', 'right', '50%', '-50%'],
    'bottom-left' => ['bottom', 'left', '-50%', '50%'],
    'bottom-right' => ['bottom', 'right', '50%', '50%'],
  ];

  protected const SIZES = ['cover' => 'cover', 'contain' => 'contain', 'stretch' => '100% 100%', 'auto' => 'auto'];

  public function __construct(
    protected ThemeKit $kit,
    protected MediaItems $mediaItems,
  ) {}

  /**
   * Features of a component.
   *
   * @param array $definition
   *   The SDC definition, empty for fields, views and blocks of Drupal.
   */
  public function enabled(array $definition): array {
    $own = $definition['thirdPartySettings']['frame_builder']['features'] ?? [];
    return array_values(array_filter(
      self::FEATURES,
      fn (string $feature): bool => (bool) ($own[$feature] ?? $this->kit->featureDefault($feature)),
    ));
  }

  /**
   * Editor fields of the features of a component.
   */
  public function fields(array $definition): array {
    $sizes = $this->sizeOptions();
    $fields = [];
    foreach ($this->enabled($definition) as $feature) {
      $fields[] = match ($feature) {
        'spacing' => ['name' => '_spacing', 'label' => 'Spacing', 'widget' => 'spacing', 'sizes' => $sizes],
        'size' => ['name' => '_size', 'label' => 'Size', 'widget' => 'size',
          'sizes' => array_map(
            static fn (string $name, string $value): array => ['value' => $name, 'label' => strtoupper($name) . " ($value)"],
            array_keys($this->kit->sizes()),
            $this->kit->sizes(),
          ),
          'description' => 'Width: the component is at most this wide. Height: it is at least this tall.',
        ],
        'align' => ['name' => '_align', 'label' => 'Alignment', 'widget' => 'align'],
        'text' => ['name' => '_text', 'label' => 'Text'] + $this->textStyleField()
          + ['description' => 'For the text in this component. Text that the component gives a size of its own (most headings) keeps that size.'],
        'position' => ['name' => '_position', 'label' => 'Position', 'widget' => 'position', 'sizes' => $sizes,
          'description' => 'Placed: in the free space around it, where it is in the flow. Pinned: on a corner of the component it is in, over its content.',
        ],
        'background' => ['name' => '_background', 'label' => 'Background', 'widget' => 'background',
          'colors' => array_map(
            static fn (string $name, array $color): array => ['value' => $name, 'label' => $color['label'], 'color' => $color['value']],
            array_keys($this->kit->colors()),
            $this->kit->colors(),
          ),
          'imageStyles' => $this->imageStyleOptions(),
        ],
      } + ['default' => NULL, 'required' => FALSE, 'translatable' => FALSE];
    }
    if ($fields) {
      // Shown above the first one: where the component's own props end.
      $fields[0]['heading'] = 'Layout and style';
    }
    return $fields;
  }

  /**
   * What an editor field for the look of text needs: the type scale and the
   * palette of the theme.
   */
  public function textStyleField(): array {
    $sizes = [];
    foreach ($this->kit->fontSizes() as $name => $value) {
      $sizes[] = ['value' => $name, 'label' => strtoupper($name) . " ($value)"];
    }
    return [
      'widget' => 'textstyle',
      'sizes' => $sizes,
      'colors' => array_map(
        static fn (string $name, array $color): array => ['value' => $name, 'label' => $color['label'], 'color' => $color['value']],
        array_keys($this->kit->colors()),
        $this->kit->colors(),
      ),
    ];
  }

  /**
   * CSS declarations for the look of text.
   *
   * @param array $text
   *   'size' (a step of the type scale, or "custom" with 'custom'), 'weight'
   *   (normal, medium, bold), 'italic', 'transform' (uppercase, lowercase,
   *   capitalize), 'color' (a name of the palette), 'leading' (tight,
   *   normal, loose).
   */
  public function textStyle(array $text): array {
    $style = [];
    if ($size = $this->kit->fontSize($text)) {
      $style['font-size'] = $size;
    }
    if ($weight = ['normal' => '400', 'medium' => '500', 'bold' => '700'][$text['weight'] ?? ''] ?? NULL) {
      $style['font-weight'] = $weight;
    }
    if (!empty($text['italic'])) {
      $style['font-style'] = 'italic';
    }
    if (in_array($text['transform'] ?? '', ['uppercase', 'lowercase', 'capitalize'], TRUE)) {
      $style['text-transform'] = $text['transform'];
    }
    if ($leading = ['tight' => '1.2', 'normal' => '1.5', 'loose' => '1.8'][$text['leading'] ?? ''] ?? NULL) {
      $style['line-height'] = $leading;
    }
    if ($color = $this->kit->color($text['color'] ?? NULL)) {
      $style['color'] = $color;
    }
    return $style;
  }

  /**
   * The steps of the theme's spacing scale, as options of a length field.
   */
  public function sizeOptions(): array {
    $sizes = [];
    foreach ($this->kit->spacing() as $name => $value) {
      $sizes[] = ['value' => $name, 'label' => strtoupper($name) . " ($value)"];
    }
    return $sizes;
  }

  /**
   * The theme's tokens, for props that are a length of the scale.
   */
  public function kit(): ThemeKit {
    return $this->kit;
  }

  /**
   * Image style options for editor fields.
   */
  public function imageStyleOptions(): array {
    $options = [];
    foreach ($this->kit->imageStyles() as $value => $label) {
      $options[] = ['value' => $value, 'label' => $label];
    }
    return $options;
  }

  /**
   * CSS declarations of spacing and background.
   */
  public function style(array $definition, array $values): array {
    $enabled = $this->enabled($definition);
    $style = [];
    if (in_array('spacing', $enabled, TRUE) && is_array($values['_spacing'] ?? NULL)) {
      foreach (['margin', 'padding'] as $property) {
        $length = $this->kit->length($values['_spacing'][$property] ?? NULL);
        // "auto" exists for margins only: it takes the free space.
        if ($property === 'margin' && ($values['_spacing'][$property]['size'] ?? '') === 'auto') {
          $length = 'auto';
        }
        if ($length !== NULL) {
          $suffix = ['horizontal' => '-inline', 'vertical' => '-block'][$values['_spacing'][$property]['sides'] ?? ''] ?? '';
          $style[$property . $suffix] = $length;
        }
      }
    }
    if (in_array('size', $enabled, TRUE) && is_array($values['_size'] ?? NULL)) {
      // A limit, not a fixed width: on a narrow screen the component is as
      // wide as its place.
      if ($width = $this->kit->size($values['_size']['width'] ?? NULL)) {
        $style['max-width'] = $width;
      }
      if ($height = $this->kit->size($values['_size']['height'] ?? NULL)) {
        $style['min-height'] = $height;
      }
    }
    if (in_array('align', $enabled, TRUE) && in_array($values['_align'] ?? '', ['start', 'center', 'end'], TRUE)) {
      // Logical values: "start" is the left in English and the right in Arabic.
      $style['text-align'] = $values['_align'];
    }
    if (in_array('text', $enabled, TRUE) && is_array($values['_text'] ?? NULL)) {
      $style += $this->textStyle($values['_text']);
    }
    if (in_array('background', $enabled, TRUE) && is_array($values['_background'] ?? NULL)) {
      $background = $values['_background'];
      if ($color = $this->kit->color($background['color'] ?? NULL)) {
        $style['background-color'] = $color;
      }
      $url = $this->mediaItems->resolve($background['image'] ?? NULL)['url'] ?? '';
      if ($url !== '') {
        // With a responsive image style the image comes from a style sheet
        // with media queries (responsiveBackground()): an inline image
        // would win over all of them.
        if (!$this->mediaItems->backgroundSources($background['image'] ?? NULL)) {
          $style['background-image'] = self::cssUrl($url);
        }
        $style['background-repeat'] = 'no-repeat';
        $style['background-size'] = self::SIZES[$background['size'] ?? 'cover'] ?? 'cover';
        $x = max(0, min(100, (float) ($background['x'] ?? 50)));
        $y = max(0, min(100, (float) ($background['y'] ?? 50)));
        $style['background-position'] = round($x, 1) . '% ' . round($y, 1) . '%';
      }
    }
    return $style;
  }

  /**
   * The background image of a component with a responsive image style, as
   * CSS rules with media queries.
   *
   * @return array|null
   *   'key', to put on the component as data-frame-builder-bg="<key>", and 'css', the
   *   rules for that selector. NULL when the background is not responsive.
   */
  public function responsiveBackground(array $definition, array $values): ?array {
    if (!in_array('background', $this->enabled($definition), TRUE) || !is_array($values['_background'] ?? NULL)) {
      return NULL;
    }
    $sources = $this->mediaItems->backgroundSources($values['_background']['image'] ?? NULL);
    if (!$sources) {
      return NULL;
    }
    $key = substr(hash('sha256', serialize($sources)), 0, 12);
    $selector = '[data-frame-builder-bg="' . $key . '"]';
    $css = [];
    foreach ($sources as [$query, $url]) {
      $rule = $selector . ' { background-image: ' . self::cssUrl($url) . '; }';
      // Media queries come from the breakpoints of the site's themes.
      $css[] = $query === '' ? $rule : '@media ' . preg_replace('/[{}<>;]/', '', $query) . ' { ' . $rule . ' }';
    }
    return ['key' => $key, 'css' => implode("\n", $css)];
  }

  /**
   * A URL as a CSS url(): no quote, backslash, parenthesis or line break can
   * end it early, and no dangerous protocol.
   */
  protected static function cssUrl(string $url): string {
    $url = str_replace(['"', '\\', '(', ')', "\n", "\r", '<'], ['%22', '%5C', '%28', '%29', '', '', '%3C'], UrlHelper::stripDangerousProtocols($url));
    return 'url("' . $url . '")';
  }

  /**
   * CSS declarations of the position of a component, empty when it is just
   * in the flow.
   *
   * - relative: automatic margins take the free space around the component,
   *   which is how CSS places a box in a flex or grid cell, and horizontally
   *   in normal flow too (the box is then as wide as its content). Start and
   *   end follow the reading direction.
   * - absolute: pinned to a corner of the parent, at a distance from it.
   *
   * A value saved before there were modes has only a corner: absolute.
   */
  public function positionStyle(array $definition, array $values): array {
    $position = $values['_position'] ?? NULL;
    if (!is_array($position) || !in_array('position', $this->enabled($definition), TRUE)) {
      return [];
    }
    $mode = $position['mode'] ?? (isset($position['corner']) ? 'absolute' : '');

    if ($mode === 'relative') {
      [$vertical, $horizontal] = explode('-', ($position['place'] ?? '') . '-');
      $style = [];
      $block = ['top' => ['0', 'auto'], 'middle' => ['auto', 'auto'], 'bottom' => ['auto', '0']][$vertical] ?? NULL;
      $inline = ['left' => ['0', 'auto'], 'center' => ['auto', 'auto'], 'right' => ['auto', '0']][$horizontal] ?? NULL;
      if ($block) {
        $style['margin-block'] = implode(' ', $block);
      }
      if ($inline) {
        $style['margin-inline'] = implode(' ', $inline);
        $style['width'] = 'fit-content';
        $style['max-width'] = '100%';
      }
      return $style;
    }

    $corner = $mode === 'absolute' ? self::CORNERS[$position['corner'] ?? ''] ?? NULL : NULL;
    if (!$corner) {
      return [];
    }
    $offset = $this->kit->length($position['offset'] ?? NULL) ?? '0';
    $style = ['position' => 'absolute', 'z-index' => '1', $corner[0] => $offset, $corner[1] => $offset];
    if (!empty($position['overlap'])) {
      // Centred on the corner: half of it outside the parent.
      $style['transform'] = "translate({$corner[2]}, {$corner[3]})";
    }
    return $style;
  }

  /**
   * Declarations as the value of a style attribute.
   */
  public static function css(array $style): string {
    return implode('; ', array_map(static fn (string $property, string $value): string => "$property: $value", array_keys($style), $style));
  }

}
