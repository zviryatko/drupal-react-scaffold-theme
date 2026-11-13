<?php

declare(strict_types=1);

namespace Drupal\Tests\frame_builder\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * With Entity Usage, what the paragraphs of a layout use counts for the
 * page they are on.
 */
#[Group('frame_builder')]
#[RunTestsInSeparateProcesses]
class EntityUsageParagraphsTest extends ParagraphsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['entity_usage'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $this->requireModules('entity_usage');
    parent::setUp();
    $this->installSchema('entity_usage', ['entity_usage']);
    $this->installConfig(['entity_usage']);
    // The paragraph type refers to content.
    FieldStorageConfig::create(['entity_type' => 'paragraph', 'field_name' => 'field_page', 'type' => 'entity_reference', 'settings' => ['target_type' => 'node']])->save();
    FieldConfig::create(['entity_type' => 'paragraph', 'bundle' => 'text_block', 'field_name' => 'field_page', 'label' => 'Page'])->save();
  }

  /**
   * Saves a page whose layout has a paragraph that refers to content.
   *
   * @return \Drupal\node\NodeInterface[]
   *   The page and the content its paragraph refers to.
   */
  protected function pageWithAParagraphThatRefers(): array {
    $target = $this->createBuilderNode(['title' => 'Referred to']);
    $node = $this->createBuilderNode();
    $this->container->get('frame_builder.paragraph_drafts')->set($node, 'b1', [self::TEXT_FIELD => 'Text', 'field_page' => [['target_id' => $target->id()]]]);
    $data = self::layout(self::paragraphItem('b1'));
    $saved = [];
    $node->setNewRevision(TRUE);
    $this->assertSame([], $this->container->get('frame_builder.paragraphs')->persist($data, $node, $saved));
    $this->saveLayout($node, $data);
    $this->assertSame((string) $target->id(), (string) $this->reloadParagraph($saved['b1']['id'])->get('field_page')->target_id);
    return [$node, $target];
  }

  /**
   * Paragraphs themselves are not listed as using or being used.
   */
  public function testParagraphsAreNotSources(): void {
    [, $target] = $this->pageWithAParagraphThatRefers();
    $sources = $this->container->get('entity_usage.usage')->listSources($target);
    $this->assertArrayNotHasKey('paragraph', $sources);
  }

  /**
   * What a paragraph of the layout refers to is used by the page.
   */
  public function testWhatAParagraphUsesCountsForThePage(): void {
    [$node, $target] = $this->pageWithAParagraphThatRefers();
    $sources = $this->container->get('entity_usage.usage')->listSources($target);
    $this->assertArrayHasKey('node', $sources);
    $this->assertSame([(int) $node->id()], array_map('intval', array_keys($sources['node'])));
    $this->assertContains((string) $node->getRevisionId(), array_map('strval', array_column($sources['node'][$node->id()], 'source_vid')));
  }

}
