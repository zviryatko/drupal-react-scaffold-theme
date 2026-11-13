<?php

declare(strict_types=1);

namespace Drupal\Tests\frame_builder\Kernel;

use Drupal\Component\Serialization\Json;
use Drupal\Core\DefaultContent\Existing;
use Drupal\Core\DefaultContent\Exporter;
use Drupal\Core\DefaultContent\Finder;
use Drupal\Core\DefaultContent\Importer;
use Drupal\Core\Serialization\Yaml;
use Drupal\node\NodeInterface;
use Drupal\Tests\frame_builder\Traits\RenderingTestTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Layouts in Drupal's content export and import: what a layout refers to is
 * named by UUID in the export and by the ids of the site after the import.
 */
#[Group('frame_builder')]
#[RunTestsInSeparateProcesses]
class ContentExportTest extends BuilderKernelTestBase {

  use RenderingTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['file', 'image', 'media', 'breakpoint', 'responsive_image'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    if (!class_exists(Exporter::class)) {
      $this->markTestSkipped('Needs the content export of Drupal 11.2 or newer.');
    }
    parent::setUp();
    $this->setUpImageMedia();
  }

  /**
   * Exports a page with what it depends on and returns the folder.
   */
  protected function export(NodeInterface $node): string {
    $folder = 'public://export-' . $node->id();
    $this->container->get(Exporter::class)->exportWithDependencies($node, $folder);
    return $folder;
  }

  /**
   * The exported file of an entity, decoded.
   */
  protected function exported(string $folder, string $type, string $uuid): array {
    return Yaml::decode(file_get_contents("$folder/$type/$uuid.yml"));
  }

  /**
   * The layout of a page names content by UUID in the export, and the
   * content it names is exported with it.
   */
  public function testExport(): array {
    $media = $this->createImageMedia('A red square');
    $target = $this->createBuilderNode(['title' => 'Target']);
    $node = $this->createBuilderNode(['title' => 'Page']);
    $this->saveLayout($node, self::layout(
      self::item('frame:image', 'i1', ['image' => ['media' => (int) $media->id(), 'url' => '/x.png', 'style' => 'style:thumbnail']]),
      self::item('frame:button', 'b1', ['label' => 'Go', 'url' => 'entity:node/' . $target->id()]),
      self::item('frame:button', 'b2', ['label' => 'Here', 'url' => 'entity:node/' . $node->id()]),
      self::item('frame:link-list', 'l1', ['links' => [['label' => 'In a list', 'url' => 'entity:node/' . $target->id()], ['label' => 'Out', 'url' => 'https://example.org/node/5']]]),
      self::item('frame_builder_test_theme:fn-text', 't1', ['text' => ['value' => '<p><a href="entity:node/' . $target->id() . '">Read</a> and <a href="/node/999">a path</a></p>', 'format' => 'plain']]),
    ));

    $folder = $this->export($node);
    $data = $this->exported($folder, 'node', $node->uuid());
    $this->assertFileExists("$folder/node/" . $target->uuid() . '.yml');
    $this->assertFileExists("$folder/media/" . $media->uuid() . '.yml');
    $depends = $data['_meta']['depends'];
    $this->assertSame('node', $depends[$target->uuid()]);
    $this->assertSame('media', $depends[$media->uuid()]);
    $this->assertArrayNotHasKey($node->uuid(), $depends, 'A page does not depend on itself.');

    $this->assertArrayNotHasKey('value', $data['default']['frame_layout'][0]);
    $items = array_column($data['default']['frame_layout'][0]['layout']['content'], 'props', 'type');
    $this->assertSame(['media_uuid' => $media->uuid(), 'url' => '/x.png', 'style' => 'style:thumbnail'], $items['frame:image']['image']);
    $this->assertSame('entity:node/' . $target->uuid(), $items['frame:link-list']['links'][0]['url']);
    $this->assertSame('https://example.org/node/5', $items['frame:link-list']['links'][1]['url']);
    $this->assertStringContainsString('href="entity:node/' . $target->uuid() . '"', $items['frame_builder_test_theme:fn-text']['text']['value']);
    $this->assertStringContainsString('href="/node/999"', $items['frame_builder_test_theme:fn-text']['text']['value']);
    $this->assertStringNotContainsString('entity:node/' . $target->id() . '"', Json::encode($data['default']['frame_layout']));
    return [$folder, $node->uuid(), $target->uuid(), $media->uuid()];
  }

  /**
   * After an import the layout names the same content by its new ids.
   */
  public function testImport(): void {
    [$folder, $page_uuid, $target_uuid, $media_uuid] = $this->testExport();
    $repository = $this->container->get('entity.repository');
    $old = ['page' => $repository->loadEntityByUuid('node', $page_uuid)->id(), 'target' => $repository->loadEntityByUuid('node', $target_uuid)->id(), 'media' => $repository->loadEntityByUuid('media', $media_uuid)->id()];
    foreach ([['node', $page_uuid], ['node', $target_uuid], ['media', $media_uuid]] as [$type, $uuid]) {
      $repository->loadEntityByUuid($type, $uuid)->delete();
    }
    // Other content takes the ids the exported content had.
    $this->createBuilderNode(['title' => 'In between']);
    $this->createBuilderNode(['title' => 'In between']);
    $this->createBuilderNode(['title' => 'In between']);
    $this->createImageMedia('Another');
    $this->createImageMedia('Another');

    $this->container->get(Importer::class)->importContent(new Finder($folder), Existing::Skip);

    $page = $repository->loadEntityByUuid('node', $page_uuid);
    $target = $repository->loadEntityByUuid('node', $target_uuid);
    $media = $repository->loadEntityByUuid('media', $media_uuid);
    $this->assertNotNull($page);
    $this->assertNotEquals($old['target'], $target->id());
    $this->assertNotEquals($old['media'], $media->id());

    $layout = $this->container->get('frame_builder.layouts')->load($page);
    $items = array_column($layout['content'], 'props', 'type');
    $this->assertSame((int) $media->id(), $items['frame:image']['image']['media']);
    $this->assertArrayNotHasKey('media_uuid', $items['frame:image']['image']);
    $this->assertSame('entity:node/' . $target->id(), $items['frame:link-list']['links'][0]['url']);
    $this->assertStringContainsString('href="entity:node/' . $target->id() . '"', $items['frame_builder_test_theme:fn-text']['text']['value']);
    $buttons = array_column(array_filter($layout['content'], static fn (array $item): bool => $item['type'] === 'frame:button'), 'props');
    $this->assertSame(['entity:node/' . $target->id(), 'entity:node/' . $page->id()], array_column($buttons, 'url'), 'A link of the page to itself gets the id the page has now.');

    $html = $this->renderHtml($this->container->get('frame_builder.renderer')->build($layout, $page));
    $this->assertStringContainsString('href="/node/' . $target->id() . '"', $html);
    $this->assertStringContainsString('/styles/thumbnail/', $html);
  }

}
