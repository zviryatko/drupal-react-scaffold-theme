<?php

declare(strict_types=1);

namespace Drupal\Tests\frame_builder\Traits;

use Drupal\file\Entity\File;
use Drupal\media\Entity\Media;
use Drupal\media\MediaInterface;
use Drupal\responsive_image\Entity\ResponsiveImageStyle;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;

/**
 * What the tests of rendering need: image media with image styles and
 * responsive image styles, and the HTML of a render array.
 *
 * A test that uses the media helpers lists the modules of
 * RenderingTestTrait::MEDIA_MODULES.
 */
trait RenderingTestTrait {

  use MediaTypeCreationTrait;

  /**
   * Installs what image media needs and creates the "image" media type.
   *
   * The image styles are those of the Image module: thumbnail (100 wide),
   * medium (220), large (480) and wide (1090), none of which scales up.
   */
  protected function setUpImageMedia(): void {
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    $this->installEntitySchema('media');
    $this->installConfig(['field', 'image', 'media', 'responsive_image']);
    $this->createMediaType('image', ['id' => 'image', 'label' => 'Image']);
  }

  /**
   * Creates an image media item with a file of 360 by 240 pixels.
   */
  protected function createImageMedia(string $alt = 'A picture', string $name = 'Picture'): MediaInterface {
    static $count = 0;
    $count++;
    $uri = "public://picture-$count.png";
    \Drupal::service('file_system')->copy($this->root . '/core/tests/fixtures/files/image-1.png', $uri);
    $file = File::create(['uri' => $uri, 'status' => 1]);
    $file->save();
    $media = Media::create([
      'bundle' => 'image',
      'name' => $name,
      'status' => 1,
      'field_media_image' => ['target_id' => $file->id(), 'alt' => $alt],
    ]);
    $media->save();
    return $media;
  }

  /**
   * Creates a responsive image style that uses `sizes`: one srcset of the
   * thumbnail, medium and large image styles, with medium as the fallback.
   */
  protected function createSizesStyle(string $id = 'cat_wide', string $sizes = '(min-width: 700px) 700px, 100vw'): ResponsiveImageStyle {
    $style = ResponsiveImageStyle::create([
      'id' => $id,
      'label' => 'Cat wide',
      'breakpoint_group' => 'responsive_image',
      'fallback_image_style' => 'medium',
    ]);
    $style->addImageStyleMapping('responsive_image.viewport_sizing', '1x', [
      'image_mapping_type' => 'sizes',
      'image_mapping' => ['sizes' => $sizes, 'sizes_image_styles' => ['large', 'thumbnail', 'medium']],
    ]);
    $style->save();
    return $style;
  }

  /**
   * Creates a responsive image style that maps the breakpoints of Frame to
   * image styles: thumbnail on mobile, medium from the tablet, large from the
   * desktop and wide on a desktop with a dense screen.
   */
  protected function createBreakpointStyle(string $id = 'cat_art'): ResponsiveImageStyle {
    $style = ResponsiveImageStyle::create([
      'id' => $id,
      'label' => 'Cat art',
      'breakpoint_group' => 'frame',
      'fallback_image_style' => 'medium',
    ]);
    // Added out of order: the smallest breakpoint is still the first rule.
    $style->addImageStyleMapping('frame.desktop', '2x', ['image_mapping_type' => 'image_style', 'image_mapping' => 'wide']);
    $style->addImageStyleMapping('frame.desktop', '1x', ['image_mapping_type' => 'image_style', 'image_mapping' => 'large']);
    $style->addImageStyleMapping('frame.mobile', '1x', ['image_mapping_type' => 'image_style', 'image_mapping' => 'thumbnail']);
    $style->addImageStyleMapping('frame.tablet', '1x', ['image_mapping_type' => 'image_style', 'image_mapping' => 'medium']);
    $style->save();
    return $style;
  }

  /**
   * The HTML of a render array, rendered as a page part would be.
   */
  protected function renderHtml(array $build): string {
    return (string) \Drupal::service('renderer')->renderRoot($build);
  }

}
