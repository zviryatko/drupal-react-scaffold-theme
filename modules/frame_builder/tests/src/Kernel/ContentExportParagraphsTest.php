<?php

declare(strict_types=1);

namespace Drupal\Tests\frame_builder\Kernel;

use Drupal\Core\DefaultContent\Existing;
use Drupal\Core\DefaultContent\Exporter;
use Drupal\Core\DefaultContent\Finder;
use Drupal\Core\DefaultContent\Importer;
use Drupal\Core\Serialization\Yaml;
use Drupal\frame_builder\Paragraphs;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Paragraph blocks in Drupal's content export and import: the paragraph is
 * exported with its page and belongs to the imported page.
 */
#[Group('frame_builder')]
#[RunTestsInSeparateProcesses]
class ContentExportParagraphsTest extends ParagraphsKernelTestBase {

  /**
   * A page with a Paragraph block survives an export and an import.
   */
  public function testParagraphBlock(): void {
    if (!class_exists(Exporter::class)) {
      $this->markTestSkipped('Needs the content export of Drupal 11.2 or newer.');
    }
    $node = $this->createBuilderNode(['title' => 'With a paragraph']);
    $paragraph = $this->createParagraph('Exported text', $node);
    $this->saveLayout($node, self::layout(self::paragraphItem('b1', $paragraph)));
    $uuid = $paragraph->uuid();

    $folder = 'public://export';
    $this->container->get(Exporter::class)->exportWithDependencies($node, $folder);
    $data = Yaml::decode(file_get_contents("$folder/node/" . $node->uuid() . '.yml'));
    $this->assertFileExists("$folder/paragraph/$uuid.yml");
    $this->assertSame('paragraph', $data['_meta']['depends'][$uuid]);
    $this->assertSame(['paragraph_uuid' => $uuid], $data['default']['frame_layout'][0]['layout']['content'][0]['props']['paragraph']);
    $this->assertArrayNotHasKey(Paragraphs::FIELD, $data['default'], 'The list of paragraphs is made from the layout on import.');

    $page_uuid = $node->uuid();
    $node->delete();
    if ($left = $this->reloadParagraph($paragraph->id())) {
      $left->delete();
    }
    // Other content takes the ids.
    $other = $this->createBuilderNode(['title' => 'In between']);
    $this->createParagraph('Other', $other);
    $this->createParagraph('Other', $other);

    $this->container->get(Importer::class)->importContent(new Finder($folder), Existing::Skip);

    $repository = $this->container->get('entity.repository');
    $page = $repository->loadEntityByUuid('node', $page_uuid);
    $imported = $repository->loadEntityByUuid('paragraph', $uuid);
    $this->assertNotNull($page);
    $this->assertNotNull($imported);
    $this->assertNotEquals($paragraph->id(), $imported->id());

    $layout = $this->container->get('frame_builder.layouts')->load($page);
    $reference = $layout['content'][0]['props']['paragraph'];
    $this->assertSame((int) $imported->id(), $reference['id']);
    $this->assertSame($uuid, $reference['uuid']);
    $this->assertNotNull($this->container->get('entity_type.manager')->getStorage('paragraph')->loadRevision($reference['vid']), 'The revision the layout names exists.');
    $this->assertSame([(string) $imported->id()], array_column($page->get(Paragraphs::FIELD)->getValue(), 'target_id'), 'The page lists the paragraph as its own.');
    $imported = $this->reloadParagraph($imported->id());
    $this->assertSame('node', $imported->get('parent_type')->value);
    $this->assertSame((string) $page->id(), (string) $imported->get('parent_id')->value, 'The paragraph belongs to the imported page.');
    $this->assertStringContainsString('Exported text', $this->html($this->container->get('frame_builder.renderer')->build($layout, $page)));
  }

}
