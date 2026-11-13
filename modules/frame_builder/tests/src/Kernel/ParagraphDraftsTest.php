<?php

declare(strict_types=1);

namespace Drupal\Tests\frame_builder\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\frame_builder\ParagraphDrafts;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Unsaved values of the paragraphs of Paragraph blocks: kept per user, page,
 * language and block.
 */
#[Group('frame_builder')]
#[RunTestsInSeparateProcesses]
class ParagraphDraftsTest extends ParagraphsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['language'];

  /**
   * The service under test.
   */
  protected ParagraphDrafts $drafts;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->drafts = $this->container->get('frame_builder.paragraph_drafts');
  }

  /**
   * Values are kept by block and replaced by the next ones.
   */
  public function testSetAndGet(): void {
    $node = $this->createBuilderNode();
    $this->assertNull($this->drafts->get($node, 'b1'), 'Nothing kept is NULL, not an empty draft.');

    $this->drafts->set($node, 'b1', [self::TEXT_FIELD => [['value' => 'One']]]);
    $this->drafts->set($node, 'b2', [self::TEXT_FIELD => [['value' => 'Two']]]);
    $this->assertSame([self::TEXT_FIELD => [['value' => 'One']]], $this->drafts->get($node, 'b1'));
    $this->assertSame([self::TEXT_FIELD => [['value' => 'Two']]], $this->drafts->get($node, 'b2'));
    $this->assertNull($this->drafts->get($node, 'b3'));

    $this->drafts->set($node, 'b1', [self::TEXT_FIELD => [['value' => 'Changed']]]);
    $this->assertSame([self::TEXT_FIELD => [['value' => 'Changed']]], $this->drafts->get($node, 'b1'));
    // A form with every field emptied is a draft too.
    $this->drafts->set($node, 'b2', []);
    $this->assertSame([], $this->drafts->get($node, 'b2'));
  }

  /**
   * One block is forgotten, or all blocks of the page.
   */
  public function testForget(): void {
    $node = $this->createBuilderNode();
    $other = $this->createBuilderNode();
    foreach (['b1', 'b2', 'b3'] as $block) {
      $this->drafts->set($node, $block, [self::TEXT_FIELD => $block]);
    }
    $this->drafts->set($other, 'b1', [self::TEXT_FIELD => 'Other page']);

    $this->drafts->forget($node, 'b2');
    $this->assertNull($this->drafts->get($node, 'b2'));
    $this->assertNotNull($this->drafts->get($node, 'b1'));
    $this->assertNotNull($this->drafts->get($node, 'b3'));
    // Forgetting what is not kept changes nothing.
    $this->drafts->forget($node, 'missing');
    $this->assertNotNull($this->drafts->get($node, 'b1'));

    // The block forgotten before is not brought back; the rest goes.
    $this->drafts->set($node, 'b4', [self::TEXT_FIELD => 'b4']);
    $this->drafts->forget($node);
    foreach (['b1', 'b2', 'b3', 'b4'] as $block) {
      $this->assertNull($this->drafts->get($node, $block), $block);
    }
    $this->assertSame([self::TEXT_FIELD => 'Other page'], $this->drafts->get($other, 'b1'), 'The drafts of another page stay.');

    // Forgetting everything twice, and on a page without drafts, is fine.
    $this->drafts->forget($node);
    $this->drafts->set($node, 'b1', [self::TEXT_FIELD => 'Again']);
    $this->assertSame([self::TEXT_FIELD => 'Again'], $this->drafts->get($node, 'b1'));
    $this->drafts->forget($node, 'b1');
    $this->assertNull($this->drafts->get($node, 'b1'));
  }

  /**
   * The last block forgotten one by one leaves nothing for "forget all".
   */
  public function testForgetOneByOneKeepsTheIndexRight(): void {
    $node = $this->createBuilderNode();
    $this->drafts->set($node, 'b1', [self::TEXT_FIELD => 'One']);
    $this->drafts->set($node, 'b2', [self::TEXT_FIELD => 'Two']);
    $this->drafts->forget($node, 'b1');
    $this->drafts->forget($node);
    $this->assertNull($this->drafts->get($node, 'b2'), 'A block kept after another was forgotten is still known to the page.');
  }

  /**
   * Drafts belong to the user who typed them.
   */
  public function testPerUser(): void {
    $node = $this->createBuilderNode();
    $first = $this->setUpCurrentUser();
    $this->drafts->set($node, 'b1', [self::TEXT_FIELD => 'Of the first']);

    $second = $this->setUpCurrentUser();
    $this->assertNull($this->drafts->get($node, 'b1'), 'Another user does not get the values.');
    $this->drafts->set($node, 'b1', [self::TEXT_FIELD => 'Of the second']);
    $this->drafts->forget($node);

    $this->setCurrentUser($first);
    $this->assertSame([self::TEXT_FIELD => 'Of the first'], $this->drafts->get($node, 'b1'), 'What another user forgets is their own.');
    $this->setCurrentUser($second);
    $this->assertNull($this->drafts->get($node, 'b1'));
  }

  /**
   * Drafts belong to one page and one language of it.
   */
  public function testPerNodeAndLanguage(): void {
    $this->addLanguage('de');
    $node = $this->createBuilderNode();
    $german = $node->addTranslation('de', ['title' => 'Eine Seite']);
    $node->save();
    $other = $this->createBuilderNode();

    $this->drafts->set($node, 'b1', [self::TEXT_FIELD => 'English']);
    $this->assertNull($this->drafts->get($other, 'b1'));
    $this->assertNull($this->drafts->get($german, 'b1'));

    $this->drafts->set($german, 'b1', [self::TEXT_FIELD => 'Deutsch']);
    $this->assertSame([self::TEXT_FIELD => 'English'], $this->drafts->get($node, 'b1'));
    $this->assertSame([self::TEXT_FIELD => 'Deutsch'], $this->drafts->get($german, 'b1'));

    $this->drafts->forget($german);
    $this->assertNull($this->drafts->get($german, 'b1'));
    $this->assertSame([self::TEXT_FIELD => 'English'], $this->drafts->get($node, 'b1'), 'Forgetting one language keeps the other.');
  }

  /**
   * Values are put on a paragraph; what does not fit is skipped.
   */
  public function testApply(): void {
    $paragraph = $this->createParagraph('Saved');
    ParagraphDrafts::apply($paragraph, [
      self::TEXT_FIELD => [['value' => 'Typed']],
      'field_that_was_removed' => [['value' => 'Old']],
    ]);
    $this->assertSame('Typed', self::paragraphText($paragraph));
    $this->assertSame('Saved', self::paragraphText($this->reloadParagraph($paragraph)), 'Applying saves nothing.');

    // An emptied field is a value.
    ParagraphDrafts::apply($paragraph, [self::TEXT_FIELD => []]);
    $this->assertTrue($paragraph->get(self::TEXT_FIELD)->isEmpty());

    // A value that no longer fits the field is skipped, the rest is applied.
    FieldStorageConfig::create(['entity_type' => 'paragraph', 'field_name' => 'field_page', 'type' => 'entity_reference', 'settings' => ['target_type' => 'node']])->save();
    FieldConfig::create(['entity_type' => 'paragraph', 'bundle' => 'text_block', 'field_name' => 'field_page', 'label' => 'Page'])->save();
    $first = $this->createBuilderNode();
    $second = $this->createBuilderNode();
    $paragraph = $this->createParagraph('Saved');
    ParagraphDrafts::apply($paragraph, [
      // An id and an entity that are not the same page: the field refuses.
      'field_page' => ['target_id' => $first->id(), 'entity' => $second],
      self::TEXT_FIELD => 'Typed',
      'status' => FALSE,
    ]);
    $this->assertTrue($paragraph->get('field_page')->isEmpty());
    $this->assertSame('Typed', self::paragraphText($paragraph));
    $this->assertFalse($paragraph->isPublished());

    ParagraphDrafts::apply($paragraph, []);
    $this->assertSame('Typed', self::paragraphText($paragraph));
  }

}
