<?php

namespace Drupal\frame_builder;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Url;
use Drupal\media\MediaInterface;

/**
 * Describes image media for the editor: URL, thumbnail, alternative text.
 */
class MediaItems {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected FileUrlGeneratorInterface $fileUrlGenerator,
  ) {}

  /**
   * Media types with an image source, as type id => source field name.
   */
  public function imageTypes(): array {
    $types = [];
    // Without the Media module images are plain URLs.
    if (!$this->entityTypeManager->hasDefinition('media_type')) {
      return $types;
    }
    foreach ($this->entityTypeManager->getStorage('media_type')->loadMultiple() as $type) {
      if ($type->getSource()->getPluginId() === 'image') {
        $types[$type->id()] = $type->getSource()->getConfiguration()['source_field'];
      }
    }
    return $types;
  }

  /**
   * Editor data of an image media, NULL when it has no file.
   */
  public function describe(MediaInterface $media): ?array {
    $field = $this->imageTypes()[$media->bundle()] ?? NULL;
    $item = $field ? $media->get($field)->first() : NULL;
    $uri = $item?->entity?->getFileUri();
    if (!$uri) {
      return NULL;
    }
    $style = $this->entityTypeManager->hasDefinition('image_style') ? $this->entityTypeManager->getStorage('image_style')->load('medium') : NULL;
    return [
      'id' => (int) $media->id(),
      'label' => $media->label(),
      'alt' => (string) $item->alt,
      'url' => $this->fileUrlGenerator->generateString($uri),
      'thumb' => $style ? $this->fileUrlGenerator->transformRelative($style->buildUrl($uri)) : $this->fileUrlGenerator->generateString($uri),
    ];
  }

  /**
   * An image value of the layout as what a template needs.
   *
   * @param mixed $value
   *   A URL, or ['url' => ..., 'media' => media id, 'style' => "style:<id>" |
   *   "responsive:<id>"] as the editor stores a picked image.
   *
   * @return array
   *   'url' (of the image style when one is chosen), and when known 'alt',
   *   'srcset' and 'sizes' (responsive image style: every image style it
   *   uses, by width).
   */
  public function resolve(mixed $value): array {
    if (!is_array($value)) {
      return ['url' => is_string($value) ? $value : ''];
    }
    $result = ['url' => (string) ($value['url'] ?? '')];
    if (!$this->entityTypeManager->hasDefinition('media') || !$this->entityTypeManager->hasDefinition('image_style')) {
      return $result;
    }
    $media = !empty($value['media']) ? $this->entityTypeManager->getStorage('media')->load($value['media']) : NULL;
    $field = $media ? ($this->imageTypes()[$media->bundle()] ?? NULL) : NULL;
    $item = $field ? $media->get($field)->first() : NULL;
    $uri = $item?->entity?->getFileUri();
    if (!$uri) {
      return $result;
    }
    $result['alt'] = (string) $item->alt;
    [$kind, $id] = explode(':', (string) ($value['style'] ?? '') . ':');
    $styles = $this->entityTypeManager->getStorage('image_style');
    $url = fn ($style): string => $this->fileUrlGenerator->transformRelative($style->buildUrl($uri));

    if ($kind === 'style' && ($style = $styles->load($id))) {
      $result['url'] = $url($style);
    }
    elseif ($kind === 'responsive' && $this->entityTypeManager->hasDefinition('responsive_image_style')
      && ($responsive = $this->entityTypeManager->getStorage('responsive_image_style')->load($id))) {
      $fallback = $styles->load($responsive->getFallbackImageStyle());
      if ($fallback) {
        $result['url'] = $url($fallback);
      }
      // One srcset with width descriptors: the browser picks by `sizes`.
      $original = ['width' => (int) $item->width, 'height' => (int) $item->height];
      $candidates = [];
      foreach ($styles->loadMultiple($responsive->getImageStyleIds()) as $style) {
        $dimensions = $original;
        $style->transformDimensions($dimensions, $uri);
        if (!empty($dimensions['width'])) {
          $candidates[(int) $dimensions['width']] = $url($style) . ' ' . (int) $dimensions['width'] . 'w';
        }
      }
      ksort($candidates);
      if ($candidates) {
        $result['srcset'] = implode(', ', $candidates);
        $result['sizes'] = '100vw';
        foreach ($responsive->getImageStyleMappings() as $mapping) {
          if (($mapping['image_mapping_type'] ?? '') === 'sizes' && !empty($mapping['image_mapping']['sizes'])) {
            $result['sizes'] = $mapping['image_mapping']['sizes'];
            break;
          }
        }
      }
    }
    return $result;
  }

  /**
   * The media item and file of a picked image, NULL without one.
   *
   * @return array|null
   *   'item' (the image field item), 'uri', and the chosen style as 'kind'
   *   ("style", "responsive" or "") and 'id'.
   */
  protected function picked(mixed $value): ?array {
    if (!is_array($value) || empty($value['media']) || !$this->entityTypeManager->hasDefinition('media') || !$this->entityTypeManager->hasDefinition('image_style')) {
      return NULL;
    }
    $media = $this->entityTypeManager->getStorage('media')->load($value['media']);
    $field = $media ? ($this->imageTypes()[$media->bundle()] ?? NULL) : NULL;
    $item = $field ? $media->get($field)->first() : NULL;
    $uri = $item?->entity?->getFileUri();
    if (!$uri) {
      return NULL;
    }
    [$kind, $id] = explode(':', (string) ($value['style'] ?? '') . ':');
    return ['media' => $media, 'item' => $item, 'uri' => $uri, 'kind' => $kind, 'id' => $id];
  }

  /**
   * Where an image links to.
   *
   * @param string $link
   *   "content" (the page the image is on), "media" (the media item), "file"
   *   (the original image file) or "custom" (the URL in $href); anything
   *   else is no link.
   * @param string $href
   *   For "custom": an absolute URL, a path starting with "/", or
   *   "entity:node/<id>" as the editor stores picked content.
   * @param \Drupal\Core\Entity\EntityInterface|null $page
   *   The entity the image is shown on.
   * @param \Drupal\media\MediaInterface $media
   *   The media item of the image.
   * @param string $uri
   *   URI of the image file.
   */
  public function linkUrl(string $link, string $href, ?EntityInterface $page, MediaInterface $media, string $uri): ?Url {
    try {
      switch ($link) {
        case 'content':
          return $page && !$page->isNew() ? $page->toUrl('canonical', ['language' => $page->language()]) : NULL;

        case 'media':
          return $media->toUrl();

        case 'file':
          return Url::fromUri($this->fileUrlGenerator->generateAbsoluteString($uri));

        case 'custom':
          $href = trim($href);
          if ($href === '') {
            return NULL;
          }
          if (str_starts_with($href, 'entity:')) {
            $url = Url::fromUri($href);
            return $page ? $url->setOption('language', $page->language()) : $url;
          }
          if (str_starts_with($href, '/') || str_starts_with($href, '#') || str_starts_with($href, '?')) {
            return Url::fromUserInput($href);
          }
          // An absolute URL with a protocol Drupal allows (no javascript:).
          return UrlHelper::isValid($href, TRUE) && UrlHelper::stripDangerousProtocols($href) === $href ? Url::fromUri($href) : NULL;
      }
    }
    catch (\Throwable) {
      // A URL that cannot be built is no link.
    }
    return NULL;
  }

  /**
   * A picked image as Drupal renders it: a render array of the image with
   * its image style, or with its responsive image style, which is a
   * <picture> or an <img srcset> exactly as the style is configured (art
   * direction per breakpoint, multipliers, sizes). NULL for a plain URL.
   *
   * The value can also say how the image loads ('loading' => "lazy" |
   * "eager") and where it links to ('link' and 'href', see linkUrl()).
   */
  public function build(mixed $value, ?EntityInterface $page = NULL): ?array {
    $picked = $this->picked($value);
    if (!$picked) {
      return NULL;
    }
    // The themes of the image formatters of core: image style or responsive
    // image style, attributes of the <img>, and a link around it.
    $build = [
      '#item' => $picked['item'],
      '#item_attributes' => ['loading' => ($value['loading'] ?? '') === 'eager' ? 'eager' : 'lazy'],
      '#url' => $this->linkUrl((string) ($value['link'] ?? ''), (string) ($value['href'] ?? ''), $page, $picked['media'], $picked['uri']),
      '#cache' => ['tags' => $picked['media']->getCacheTags()],
    ];
    if ($picked['kind'] === 'responsive' && $this->entityTypeManager->hasDefinition('responsive_image_style')
      && $this->entityTypeManager->getStorage('responsive_image_style')->load($picked['id'])) {
      return $build + ['#theme' => 'responsive_image_formatter', '#responsive_image_style_id' => $picked['id']];
    }
    $style = $picked['kind'] === 'style' && $this->entityTypeManager->getStorage('image_style')->load($picked['id']) ? $picked['id'] : '';
    return $build + ['#theme' => 'image_formatter', '#image_style' => $style];
  }

  /**
   * The images of a responsive image style as CSS can use them for a
   * background: a list of [media query, URL], the first without a query (the
   * default), later ones overriding it. Empty unless the picked image has a
   * responsive image style.
   *
   * A style that maps breakpoints to image styles gives the media queries of
   * those breakpoints (and of their multipliers). A style that uses `sizes`
   * has no queries of its own: its images are stepped by their width, each
   * one used up to the width of the window it can fill.
   */
  public function backgroundSources(mixed $value): array {
    $picked = $this->picked($value);
    if (!$picked || $picked['kind'] !== 'responsive' || !$this->entityTypeManager->hasDefinition('responsive_image_style')) {
      return [];
    }
    $responsive = $this->entityTypeManager->getStorage('responsive_image_style')->load($picked['id']);
    if (!$responsive) {
      return [];
    }
    $styles = $this->entityTypeManager->getStorage('image_style');
    $url = fn (string $id): ?string => ($style = $styles->load($id)) ? $this->fileUrlGenerator->transformRelative($style->buildUrl($picked['uri'])) : NULL;
    $sources = [];
    if ($fallback = $url($responsive->getFallbackImageStyle())) {
      $sources[] = ['', $fallback];
    }
    $breakpoints = \Drupal::service('breakpoint.manager')->getBreakpointsByGroup($responsive->getBreakpointGroup());
    // Smallest breakpoint first, so a larger one that also matches wins.
    $order = array_flip(array_keys($breakpoints));
    $mappings = $responsive->getImageStyleMappings();
    usort($mappings, static fn (array $a, array $b): int => [$order[$a['breakpoint_id']] ?? 0, (float) $a['multiplier']] <=> [$order[$b['breakpoint_id']] ?? 0, (float) $b['multiplier']]);
    foreach ($mappings as $mapping) {
      $query = isset($breakpoints[$mapping['breakpoint_id']]) ? trim($breakpoints[$mapping['breakpoint_id']]->getMediaQuery()) : '';
      $density = (float) $mapping['multiplier'];
      if ($mapping['image_mapping_type'] === 'image_style') {
        $source = $url((string) $mapping['image_mapping']);
        if ($source) {
          $conditions = array_filter([$query, $density > 1 ? "(min-resolution: {$density}dppx)" : '']);
          $sources[] = [implode(' and ', $conditions), $source];
        }
        continue;
      }
      // `sizes`: by the width of each image.
      $candidates = [];
      foreach ($mapping['image_mapping']['sizes_image_styles'] ?? [] as $id) {
        $style = $styles->load($id);
        $dimensions = ['width' => (int) $picked['item']->width, 'height' => (int) $picked['item']->height];
        $style?->transformDimensions($dimensions, $picked['uri']);
        if ($style && !empty($dimensions['width'])) {
          $candidates[(int) $dimensions['width']] = $url($id);
        }
      }
      ksort($candidates);
      $previous = NULL;
      foreach ($candidates as $width => $source) {
        $step = $previous === NULL ? '' : '(min-width: ' . ($previous + 1) . 'px)';
        $sources[] = [implode(' and ', array_filter([$query, $step])), $source];
        $previous = $width;
      }
    }
    return $sources;
  }

}
