<?php

declare(strict_types=1);

namespace Drupal\Tests\frame_builder_ai\Kernel;

use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\media\Entity\Media;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;
use Drupal\Tests\frame_builder\Kernel\BuilderKernelTestBase;
use Drupal\Tests\frame_builder_ai\Traits\AssistantTestTrait;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Base of the kernel tests of the assistant of the page builder.
 *
 * Adds the AI module with the scripted provider of the tests (not yet the
 * default one), and a media library with the "image" media type.
 */
#[RunTestsInSeparateProcesses]
abstract class AssistantKernelTestBase extends BuilderKernelTestBase {

  use AssistantTestTrait;
  use MediaTypeCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['file', 'image', 'media', 'key', 'ai', 'frame_builder_ai', 'frame_builder_ai_test'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $this->skipWithoutModules('ai', 'key');
    parent::setUp();
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    $this->installEntitySchema('media');
    $this->installConfig(['image', 'media', 'ai']);
    $this->createMediaType('image', ['id' => 'image', 'label' => 'Image']);
  }

  /**
   * The components of a page as the editor and the assistant get them.
   */
  protected function components(NodeInterface $node): array {
    return $this->container->get('frame_builder.catalog')->editorConfig($node);
  }

  /**
   * Creates an image of the media library, with a file of its own.
   *
   * @param string $name
   *   Name of the media item.
   * @param string $alt
   *   Alternative text of the image.
   * @param string $filename
   *   Name of the file.
   * @param array $values
   *   Other values of the media item: 'status', 'changed'...
   */
  protected function createImageMedia(string $name, string $alt = '', string $filename = 'picture.png', array $values = []): MediaInterface {
    $uri = 'public://' . $filename;
    copy($this->root . '/core/misc/druplicon.png', $uri);
    $file = File::create(['uri' => $uri, 'status' => 1]);
    $file->save();
    $media = Media::create($values + [
      'bundle' => 'image',
      'name' => $name,
      'status' => 1,
      'field_media_image' => ['target_id' => $file->id(), 'alt' => $alt, 'width' => 88, 'height' => 100],
    ]);
    $media->save();
    return $media;
  }

  /**
   * Enables Paragraphs with the types "quote" and "teaser", each with the
   * plain text field field_text. Skips the test without the module.
   */
  protected function enableParagraphs(): void {
    $this->skipWithoutModules('paragraphs', 'entity_reference_revisions');
    $this->enableModules(['entity_reference_revisions', 'paragraphs']);
    $this->installEntitySchema('paragraph');
    $storage = $this->container->get('entity_type.manager')->getStorage('paragraphs_type');
    FieldStorageConfig::create([
      'field_name' => 'field_text',
      'entity_type' => 'paragraph',
      'type' => 'string_long',
      'cardinality' => FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED,
    ])->save();
    foreach (['quote' => 'Quote', 'teaser' => 'Teaser'] as $id => $label) {
      $storage->create(['id' => $id, 'label' => $label])->save();
      FieldConfig::create(['field_name' => 'field_text', 'entity_type' => 'paragraph', 'bundle' => $id, 'label' => 'Text'])->save();
    }
  }

}
