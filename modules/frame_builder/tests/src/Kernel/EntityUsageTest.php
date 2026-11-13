<?php

declare(strict_types=1);

namespace Drupal\Tests\frame_builder\Kernel;

use Drupal\Core\Entity\EntityInterface;
use Drupal\media\Entity\Media;
use Drupal\Tests\frame_builder\Traits\ParagraphsTestTrait;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * With Entity Usage, what a layout uses is recorded: media, linked content
 * and saved blocks, for pages and for saved blocks.
 */
#[Group('frame_builder')]
#[RunTestsInSeparateProcesses]
class EntityUsageTest extends BuilderKernelTestBase {

  use MediaTypeCreationTrait;
  use ParagraphsTestTrait;

  /**
   * The component of the test theme.
   */
  protected const PROBE = 'frame_builder_test_theme:probe';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['entity_usage', 'file', 'image', 'media', 'media_test_source'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $this->requireModules('entity_usage');
    parent::setUp();
    $this->installEntitySchema('file');
    $this->installEntitySchema('media');
    $this->installSchema('file', ['file_usage']);
    $this->installSchema('entity_usage', ['entity_usage']);
    $this->installConfig(['entity_usage', 'media']);
    $this->createMediaType('test', ['id' => 'test']);
  }

  /**
   * Creates a media item.
   */
  protected function createMedia(string $name): Media {
    $media = Media::create(['bundle' => 'test', 'name' => $name, 'field_media_test' => $name]);
    $media->save();
    return $media;
  }

  /**
   * What uses an entity, as recorded for layouts.
   *
   * @return string[]
   *   "type:id:revision" of every recorded use by a layout, sorted.
   */
  protected function sources(EntityInterface $target): array {
    $found = [];
    foreach ($this->container->get('entity_usage.usage')->listSources($target, FALSE) as $row) {
      if ($row['method'] === 'frame_builder') {
        $this->assertSame('frame_layout', $row['field_name']);
        $found[] = $row['source_type'] . ':' . $row['source_id'] . ':' . $row['source_vid'];
      }
    }
    sort($found);
    return $found;
  }

  /**
   * The plugin is there, and reads the layout field although it is a base
   * field.
   */
  public function testPlugin(): void {
    $manager = $this->container->get('plugin.manager.entity_usage.track');
    $this->assertTrue($manager->hasDefinition('frame_builder'));
    $this->assertFalse($this->config('entity_usage.settings')->get('track_enabled_base_fields'));
    $plugin = $manager->createInstance('frame_builder');
    $node = $this->createBuilderNode();
    $this->assertSame(['frame_layout'], array_keys($plugin->getReferencingFields($node, ['string_long'])));
    $saved = $this->container->get('frame_builder.library')->create('Promo', self::item(self::PROBE, 'p'));
    $this->assertSame(['frame_layout'], array_keys($plugin->getReferencingFields($saved, ['string_long'])), 'A saved block has a layout too.');
    $this->assertSame([], $plugin->getReferencingFields($this->createMedia('No layout'), ['string_long']));
  }

  /**
   * The targets of a layout: picked and embedded media, linked content and
   * linked saved blocks, each once, and only what exists.
   */
  public function testTargets(): void {
    $picked = $this->createMedia('Picked');
    $background = $this->createMedia('Background');
    $embedded = $this->createMedia('Embedded');
    $linked = $this->createBuilderNode(['title' => 'Linked']);
    $in_text = $this->createBuilderNode(['title' => 'Linked in text']);
    $saved = $this->container->get('frame_builder.library')->create('Promo', self::item(self::PROBE, 'p'));
    $node = $this->createBuilderNode();
    $this->saveLayout($node, self::layout(
      self::item(self::PROBE, 'p1', [
        'image' => ['media' => (int) $picked->id(), 'url' => '/x.png'],
        '_background' => ['image' => ['media' => (int) $background->id()]],
        'url' => 'entity:node/' . $linked->id(),
        'content' => [
          self::item(self::PROBE, 'p2', [
            'text' => ['format' => 'basic_html', 'value' => '<p><drupal-media data-entity-type="media" data-entity-uuid="' . $embedded->uuid() . '"></drupal-media> <a href="/node/' . $in_text->id() . '">link</a> <a href="/node/99999">gone</a></p>'],
            // The same media again, and one that does not exist.
            'image' => ['media' => (int) $picked->id()],
            'other' => ['media' => 99999],
          ]),
          self::savedItem('s1', $saved->uuid()),
          self::savedItem('s2', '0b6f0000-0000-4000-8000-000000000000'),
        ],
      ]),
    ));
    $targets = $this->container->get('plugin.manager.entity_usage.track')->createInstance('frame_builder')->getTargetEntities($node->get('frame_layout')->first());
    $this->assertEqualsCanonicalizing([
      'media|' . $picked->id(),
      'media|' . $background->id(),
      'media|' . $embedded->id(),
      'node|' . $linked->id(),
      'node|' . $in_text->id(),
      'frame_saved|' . $saved->id(),
    ], $targets);

    $node->set('frame_layout', 'not json');
    $this->assertSame([], $this->container->get('plugin.manager.entity_usage.track')->createInstance('frame_builder')->getTargetEntities($node->get('frame_layout')->first()));
  }

  /**
   * Saving a page records what its layout uses, per revision of the page.
   */
  public function testPageUsage(): void {
    $media = $this->createMedia('Picked');
    $linked = $this->createBuilderNode(['title' => 'Linked']);
    $node = $this->createBuilderNode();
    $this->assertSame([], $this->sources($media));

    $node->setNewRevision(TRUE);
    $this->saveLayout($node, self::layout(self::item(self::PROBE, 'p1', ['image' => ['media' => (int) $media->id()], 'url' => 'entity:node/' . $linked->id()])));
    $with_media = 'node:' . $node->id() . ':' . $node->getRevisionId();
    $this->assertSame([$with_media], $this->sources($media));
    $this->assertSame([$with_media], $this->sources($linked));

    // A new revision without the media: the older revision still uses it.
    $node->setNewRevision(TRUE);
    $this->saveLayout($node, self::layout(self::item(self::PROBE, 'p1', ['url' => 'entity:node/' . $linked->id()])));
    $without_media = 'node:' . $node->id() . ':' . $node->getRevisionId();
    $this->assertSame([$with_media], $this->sources($media));
    $this->assertSame([$with_media, $without_media], $this->sources($linked));

    // The same revision saved again without the link: no longer a use.
    $node->setNewRevision(FALSE);
    $this->saveLayout($node, self::layout(self::item(self::PROBE, 'p1')));
    $this->assertSame([$with_media], $this->sources($linked));
  }

  /**
   * Pages are listed as using the saved blocks they link to, not the ones
   * they have a copy of; a saved block as using its media and links.
   */
  public function testSavedBlockUsage(): void {
    $library = $this->container->get('frame_builder.library');
    $media = $this->createMedia('In the saved block');
    $saved = $library->create('Promo', self::item(self::PROBE, 'p1', ['image' => ['media' => (int) $media->id()]]));
    $this->assertSame(['frame_saved:' . $saved->id() . ':0'], $this->sources($media), 'The saved block uses the media.');

    $linking = $this->createBuilderNode(['title' => 'Links to it']);
    $this->saveLayout($linking, self::layout(self::savedItem('s1', $saved->uuid())));
    $copying = $this->createBuilderNode(['title' => 'Has a copy']);
    $item = $library->item($saved);
    $item['props']['_library'] = ['uuid' => $saved->uuid(), 'label' => 'Promo'];
    $this->saveLayout($copying, self::layout($item));

    $this->assertSame(['node:' . $linking->id() . ':' . $linking->getRevisionId()], $this->sources($saved), 'Only the page that links to the saved block uses it.');
    $this->assertSame(
      ['frame_saved:' . $saved->id() . ':0', 'node:' . $copying->id() . ':' . $copying->getRevisionId()],
      $this->sources($media),
      'The copy uses the media itself; the linking page uses it through the saved block.',
    );

    // The saved block is updated without the media.
    $library->setItem($saved, self::item(self::PROBE, 'p1'));
    $saved->save();
    $this->assertSame(['node:' . $copying->id() . ':' . $copying->getRevisionId()], $this->sources($media));
  }

}
