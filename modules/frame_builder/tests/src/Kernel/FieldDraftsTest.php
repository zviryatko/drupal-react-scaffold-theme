<?php

declare(strict_types=1);

namespace Drupal\Tests\frame_builder\Kernel;

use Drupal\frame_builder\FieldDrafts;
use Drupal\node\Entity\Node;
use Drupal\Tests\frame_builder\Traits\LayoutTranslationTestTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Unsaved field values of the node form in the builder.
 */
#[Group('frame_builder')]
#[RunTestsInSeparateProcesses]
class FieldDraftsTest extends BuilderKernelTestBase {

  use LayoutTranslationTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['language', 'content_translation'];

  /**
   * The service under test.
   */
  protected function drafts(): FieldDrafts {
    return $this->container->get('frame_builder.field_drafts');
  }

  /**
   * Values are kept, and added values replace those of the same field only.
   */
  public function testMerge(): void {
    $node = $this->createBuilderNode();
    $this->assertSame([], $this->drafts()->get($node));

    $this->drafts()->merge($node, ['title' => [['value' => 'Draft title']], 'sticky' => [['value' => 1]]]);
    $this->assertSame(['title' => [['value' => 'Draft title']], 'sticky' => [['value' => 1]]], $this->drafts()->get($node));

    $this->drafts()->merge($node, ['title' => [['value' => 'Second draft']], 'promote' => [['value' => 0]]]);
    $this->assertEquals([
      'title' => [['value' => 'Second draft']],
      'sticky' => [['value' => 1]],
      'promote' => [['value' => 0]],
    ], $this->drafts()->get($node));

    $this->drafts()->merge($node, []);
    $this->assertCount(3, $this->drafts()->get($node), 'Merging nothing keeps what is there.');
    $this->assertSame('A page', Node::load($node->id())->label(), 'Nothing is written to the node.');
  }

  /**
   * Saved fields are forgotten one by one, or all at once.
   */
  public function testForget(): void {
    $node = $this->createBuilderNode();
    $this->drafts()->forget($node);
    $this->drafts()->forget($node, ['title']);
    $this->assertSame([], $this->drafts()->get($node), 'Forgetting what is not there does nothing.');

    $this->drafts()->merge($node, ['title' => [['value' => 'Draft']], 'sticky' => [['value' => 1]], 'promote' => [['value' => 1]]]);
    $this->drafts()->forget($node, ['title', 'not_a_draft']);
    $this->assertEquals(['sticky' => [['value' => 1]], 'promote' => [['value' => 1]]], $this->drafts()->get($node));
    $this->drafts()->forget($node, []);
    $this->assertCount(2, $this->drafts()->get($node), 'An empty list of fields forgets none.');
    $this->drafts()->forget($node, ['sticky', 'promote']);
    $this->assertSame([], $this->drafts()->get($node));

    $this->drafts()->merge($node, ['title' => [['value' => 'Draft']], 'sticky' => [['value' => 1]]]);
    $this->drafts()->forget($node);
    $this->assertSame([], $this->drafts()->get($node));
  }

  /**
   * Every user has drafts of their own.
   */
  public function testPerUser(): void {
    $node = $this->createBuilderNode();
    $first = $this->createUser(['access content']);
    $second = $this->createUser(['access content']);

    $this->setCurrentUser($first);
    $this->drafts()->merge($node, ['title' => [['value' => 'Of the first']]]);
    $this->setCurrentUser($second);
    $this->assertSame([], $this->drafts()->get($node));
    $this->assertSame($node, $this->drafts()->apply($node));
    $this->drafts()->merge($node, ['title' => [['value' => 'Of the second']]]);
    $this->assertSame('Of the second', $this->drafts()->apply($node)->label());
    $this->drafts()->forget($node);

    $this->setCurrentUser($first);
    $this->assertSame(['title' => [['value' => 'Of the first']]], $this->drafts()->get($node), 'Forgetting the drafts of one user keeps those of another.');
    $this->assertSame('Of the first', $this->drafts()->apply($node)->label());
  }

  /**
   * Drafts belong to one page.
   */
  public function testPerNode(): void {
    $node = $this->createBuilderNode();
    $other = $this->createBuilderNode(['title' => 'Another page']);
    $this->drafts()->merge($node, ['title' => [['value' => 'Draft']]]);
    $this->assertSame([], $this->drafts()->get($other));
    $this->drafts()->merge($other, ['sticky' => [['value' => 1]]]);
    $this->drafts()->forget($node);
    $this->assertSame(['sticky' => [['value' => 1]]], $this->drafts()->get($other));
    $this->assertSame([], $this->drafts()->get($node));
  }

  /**
   * Every translation of a page has drafts of its own.
   */
  public function testPerTranslation(): void {
    $this->setUpTranslation('synced');
    $node = $this->createBuilderNode();
    $translation = $this->translate($node, 'Eine Seite');
    $translation->save();

    $this->drafts()->merge($translation, ['title' => [['value' => 'Entwurf']]]);
    $this->assertSame([], $this->drafts()->get($node));
    $this->assertSame(['title' => [['value' => 'Entwurf']]], $this->drafts()->get($this->reload($node, 'de')), 'The draft is of the translation, not of one object of it.');
    $this->drafts()->merge($node, ['title' => [['value' => 'Draft']]]);

    $applied = $this->drafts()->apply($translation);
    $this->assertSame('de', $applied->language()->getId());
    $this->assertSame('Entwurf', $applied->label());
    $this->assertSame('Draft', $this->drafts()->apply($node)->label());

    $this->drafts()->forget($node);
    $this->assertSame(['title' => [['value' => 'Entwurf']]], $this->drafts()->get($translation));
  }

  /**
   * The drafts are put on a copy of the node; the node stays as it is.
   */
  public function testApply(): void {
    $node = $this->createBuilderNode(['sticky' => 0]);
    $this->assertSame($node, $this->drafts()->apply($node), 'Without drafts the node itself is returned.');

    $this->drafts()->merge($node, ['title' => [['value' => 'Draft title']], 'sticky' => [['value' => 1]]]);
    $copy = $this->drafts()->apply($node);
    $this->assertNotSame($node, $copy);
    $this->assertSame('Draft title', $copy->label());
    $this->assertTrue($copy->isSticky());
    $this->assertSame($node->id(), $copy->id());
    $this->assertSame($node->getRevisionId(), $copy->getRevisionId());
    $this->assertSame('A page', $node->label());
    $this->assertFalse($node->isSticky());
    $this->assertSame('A page', Node::load($node->id())->label());

    $this->drafts()->forget($node, ['title']);
    $copy = $this->drafts()->apply($node);
    $this->assertSame('A page', $copy->label());
    $this->assertTrue($copy->isSticky());
  }

  /**
   * Drafts of fields the node does not have, and values that do not fit
   * their field, are left out; the others are applied.
   */
  public function testApplySkipsWhatDoesNotFit(): void {
    $node = $this->createBuilderNode();
    // An entity reference cannot take a list as its entity.
    $unfit = [['entity' => ['not', 'an', 'entity']]];
    $refused = FALSE;
    try {
      (clone $node)->set('uid', $unfit);
    }
    catch (\Throwable) {
      $refused = TRUE;
    }
    $this->assertTrue($refused, 'The field refuses the value.');
    $this->drafts()->merge($node, [
      'field_removed' => [['value' => 'Its field is gone']],
      'uid' => $unfit,
      'title' => [['value' => 'Draft title']],
    ]);
    $copy = $this->drafts()->apply($node);
    $this->assertNotSame($node, $copy);
    $this->assertSame('Draft title', $copy->label());
    $this->assertFalse($copy->hasField('field_removed'));
    $this->assertSame('1', (string) $node->getOwnerId(), 'The node keeps its author.');
  }

}
