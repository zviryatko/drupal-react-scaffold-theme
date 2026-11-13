<?php

declare(strict_types=1);

namespace Drupal\Tests\frame_builder\Kernel;

use Drupal\frame_builder\LayoutStorage;
use Drupal\frame_builder\Paragraphs;
use Drupal\node\NodeInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Saving a page saves its paragraphs: new ones are created, changed ones get
 * a revision, and each belongs to one page or saved block.
 */
#[Group('frame_builder')]
#[RunTestsInSeparateProcesses]
class ParagraphsPersistTest extends ParagraphsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['language', 'content_translation'];

  /**
   * The service under test.
   */
  protected Paragraphs $paragraphs;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->paragraphs = $this->container->get('frame_builder.paragraphs');
  }

  /**
   * Keeps values typed in the form of a block, as its autosave does.
   */
  protected function type(NodeInterface $node, string $block, ?string $text): void {
    $this->container->get('frame_builder.paragraph_drafts')->set($node, $block, [self::TEXT_FIELD => $text === NULL ? [] : [['value' => $text]]]);
  }

  /**
   * Whether the user has unsaved values for a block.
   */
  protected function hasDraft(NodeInterface $node, string $block): bool {
    return $this->container->get('frame_builder.paragraph_drafts')->get($node, $block) !== NULL;
  }

  /**
   * Saves a page as the save endpoint does: paragraphs, then the layout.
   *
   * @return array
   *   [errors, what the blocks name now, the layout as stored].
   */
  protected function savePage(NodeInterface $node, array $data): array {
    $node->setNewRevision(TRUE);
    $saved = [];
    $errors = $this->paragraphs->persist($data, $node, $saved);
    if (!$errors) {
      $this->saveLayout($node, $data);
    }
    return [$errors, $saved, $data];
  }

  /**
   * A new block with typed values gets a new paragraph of the page.
   */
  public function testNewParagraph(): void {
    $node = $this->createBuilderNode();
    $this->type($node, 'b1', 'Typed');
    $data = self::layout(self::paragraphItem('b1', ['draft' => 'k1']), self::item('frame_builder_test_theme:probe', 'p'));
    $saved = [];
    $this->assertSame([], $this->paragraphs->persist($data, $node, $saved));

    $this->assertSame(['b1'], array_keys($saved));
    $paragraph = $this->reloadParagraph($saved['b1']['id']);
    $this->assertSame('Typed', self::paragraphText($paragraph));
    $this->assertSame('text_block', $paragraph->bundle());
    $this->assertSame(Paragraphs::reference($paragraph), $saved['b1']);
    $this->assertSame($saved['b1'], $data['content'][0]['props']['paragraph'], 'The layout names what was saved, without the mark of unsaved values.');
    $this->assertSame('frame_builder_test_theme:probe', $data['content'][1]['type'], 'Other blocks are left as they are.');

    // The paragraph belongs to the page.
    $this->assertSame('node', $paragraph->get('parent_type')->value);
    $this->assertSame((string) $node->id(), (string) $paragraph->get('parent_id')->value);
    $this->assertSame(Paragraphs::FIELD, $paragraph->get('parent_field_name')->value);
    $this->assertFalse($this->hasDraft($node, 'b1'), 'What was saved is no longer unsaved.');

    // Stored, the page lists it.
    $this->saveLayout($node, $data);
    $this->assertSame([['target_id' => $saved['b1']['id'], 'target_revision_id' => $saved['b1']['vid']]], array_map(
      static fn (array $value): array => ['target_id' => (int) $value['target_id'], 'target_revision_id' => (int) $value['target_revision_id']],
      $node->get(Paragraphs::FIELD)->getValue(),
    ));
    $this->assertSame($node->id(), $paragraph->getParentEntity()->id());
  }

  /**
   * A changed paragraph gets a new revision; older revisions of the page
   * keep showing it as it was.
   */
  public function testChangedParagraphGetsARevision(): void {
    $node = $this->createBuilderNode();
    $this->type($node, 'b1', 'First');
    [, $saved] = $this->savePage($node, self::layout(self::paragraphItem('b1')));
    $first = $saved['b1'];
    $first_revision = $node->getRevisionId();

    $this->type($node, 'b1', 'Second');
    [$errors, $saved, $data] = $this->savePage($node, self::layout(self::paragraphItem('b1', $first)));
    $this->assertSame([], $errors);
    $this->assertSame($first['id'], $saved['b1']['id'], 'The same paragraph.');
    $this->assertSame($first['uuid'], $saved['b1']['uuid']);
    $this->assertGreaterThan($first['vid'], $saved['b1']['vid'], 'In a new revision.');
    $this->assertSame(1, $this->paragraphCounts()[0]);

    $this->assertSame('First', self::paragraphText($this->paragraphs->load($first, 'text_block')));
    $this->assertSame('Second', self::paragraphText($this->paragraphs->load($saved['b1'], 'text_block')));

    // Each revision of the page renders its own revision of the paragraph.
    $layouts = $this->container->get('frame_builder.layouts');
    $renderer = $this->container->get('frame_builder.renderer');
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $storage->resetCache();
    $old = $storage->loadRevision($first_revision);
    $this->assertStringContainsString('First', $this->html($renderer->build($layouts->load($old), $old)));
    $this->assertCount(1, $old->get(Paragraphs::FIELD));
    $this->assertSame('First', self::paragraphText($old->get(Paragraphs::FIELD)->entity), 'The older revision of the page lists the paragraph as it was.');
    $new = $storage->load($node->id());
    $html = $this->html($renderer->build($layouts->load($new), $new));
    $this->assertStringContainsString('Second', $html);
    $this->assertStringNotContainsString('First', $html);
    $this->assertSame([$first['id']], array_map('intval', array_column($new->get(Paragraphs::FIELD)->getValue(), 'target_id')));
    $this->assertSame('Second', self::paragraphText($new->get(Paragraphs::FIELD)->entity));
  }

  /**
   * The field of the page lists the revisions the layout names, and a
   * paragraph that was not changed keeps its revision when the page is saved.
   */
  public function testThePageListsTheRevisionsOfTheLayout(): void {
    $this->markTestSkipped('BUG: saving a page as a new revision gives every paragraph of the layout one more revision (Entity Reference Revisions does that for the frame_paragraphs field), so the field lists revisions the layout does not name and unchanged paragraphs grow a revision per save.');
    $node = $this->createBuilderNode();
    $this->type($node, 'b1', 'First');
    [, $saved, $data] = $this->savePage($node, self::layout(self::paragraphItem('b1')));
    $this->assertSame([$saved['b1']['vid']], array_map('intval', array_column($node->get(Paragraphs::FIELD)->getValue(), 'target_revision_id')));
    $this->assertSame([1, 1], $this->paragraphCounts());

    // Saved again without a change of the paragraph.
    [, $again] = $this->savePage($node, $data);
    $this->assertSame([], $again);
    $this->assertSame([$saved['b1']['vid']], array_map('intval', array_column($node->get(Paragraphs::FIELD)->getValue(), 'target_revision_id')));
    $this->assertSame([1, 1], $this->paragraphCounts());
  }

  /**
   * A paragraph without unsaved values is not saved again.
   */
  public function testUnchangedParagraphIsLeftAlone(): void {
    $node = $this->createBuilderNode();
    $paragraph = $this->createParagraph('Kept', $node);
    $reference = Paragraphs::reference($paragraph);
    $data = self::layout(
      self::paragraphItem('b1', $reference + ['draft' => 'k1']),
      // A block whose form was opened and never filled in.
      self::paragraphItem('b2', ['draft' => 'k2']),
      self::paragraphItem('b3'),
    );
    $saved = [];
    $this->assertSame([], $this->paragraphs->persist($data, $node, $saved));
    $this->assertSame([], $saved);
    $this->assertSame([1, 1], $this->paragraphCounts(), 'No paragraph and no revision is made.');
    $this->assertSame($reference, $data['content'][0]['props']['paragraph'], 'The mark of unsaved values is taken out.');
    $this->assertArrayNotHasKey('paragraph', $data['content'][1]['props'], 'A block without a paragraph names nothing.');
    $this->assertArrayNotHasKey('paragraph', $data['content'][2]['props']);
  }

  /**
   * Blocks in slots are saved like the ones at the top.
   */
  public function testNestedBlocks(): void {
    $node = $this->createBuilderNode();
    $this->type($node, 'inner', 'Inner');
    $this->type($node, 'deep', 'Deep');
    $data = self::layout(self::item('frame_builder_test_theme:probe', 'outer', ['content' => [
      self::paragraphItem('inner'),
      self::item('frame_builder_test_theme:probe', 'middle', ['content' => [self::paragraphItem('deep', ['draft' => 'k'])]]),
    ]]));
    [$errors, $saved, $data] = $this->savePage($node, $data);
    $this->assertSame([], $errors);
    $this->assertEqualsCanonicalizing(['inner', 'deep'], array_keys($saved));
    $this->assertSame($saved['inner'], $data['content'][0]['props']['content'][0]['props']['paragraph']);
    $this->assertSame($saved['deep'], $data['content'][0]['props']['content'][1]['props']['content'][0]['props']['paragraph']);
    $this->assertSame('Deep', self::paragraphText($this->reloadParagraph($saved['deep']['id'])));
    $this->assertCount(2, $node->get(Paragraphs::FIELD));
  }

  /**
   * A block that names a paragraph of something else gets a paragraph of
   * its own: a page never writes to the paragraph of another owner.
   */
  public function testForeignParagraphIsDuplicated(): void {
    $node = $this->createBuilderNode();
    $other_page = $this->createBuilderNode();
    $foreign = $this->createParagraph('Of another page', $other_page);
    $orphan = $this->createParagraph('Of nothing');
    $this->type($node, 'b2', 'Changed here');

    [$errors, $saved] = $this->savePage($node, self::layout(self::paragraphItem('b1', $foreign), self::paragraphItem('b2', $orphan)));
    $this->assertSame([], $errors);
    $this->assertEqualsCanonicalizing(['b1', 'b2'], array_keys($saved), 'Also without unsaved values.');
    $this->assertNotSame((int) $foreign->id(), $saved['b1']['id']);
    $this->assertNotSame($foreign->uuid(), $saved['b1']['uuid']);
    $this->assertNotSame((int) $orphan->id(), $saved['b2']['id']);

    $copy = $this->reloadParagraph($saved['b1']['id']);
    $this->assertSame('Of another page', self::paragraphText($copy), 'The copy has the values of the paragraph it was made from.');
    $this->assertSame((string) $node->id(), (string) $copy->get('parent_id')->value);
    $this->assertSame('Changed here', self::paragraphText($this->reloadParagraph($saved['b2']['id'])), 'With the unsaved values of the block.');

    $foreign = $this->reloadParagraph($foreign);
    $this->assertSame('Of another page', self::paragraphText($foreign));
    $this->assertSame((string) $other_page->id(), (string) $foreign->get('parent_id')->value, 'The other page keeps its paragraph.');
    $this->assertSame('Of nothing', self::paragraphText($this->reloadParagraph($orphan)));
    $this->assertSame(4, $this->paragraphCounts()[0]);

    // Now they are the page's own: saved again only when changed.
    [, $again] = $this->savePage($node, self::layout(self::paragraphItem('b1', $saved['b1']), self::paragraphItem('b2', $saved['b2'])));
    $this->assertSame([], $again);
    $this->assertSame(4, $this->paragraphCounts()[0]);
  }

  /**
   * The second of two blocks that name the same paragraph (a duplicated
   * block) gets a paragraph of its own.
   */
  public function testDuplicatedBlock(): void {
    $node = $this->createBuilderNode();
    $paragraph = $this->createParagraph('Original', $node);
    $reference = Paragraphs::reference($paragraph);
    $this->type($node, 'copy', 'Changed in the copy');

    [$errors, $saved, $data] = $this->savePage($node, self::layout(self::paragraphItem('b1', $reference), self::paragraphItem('copy', $reference)));
    $this->assertSame([], $errors);
    $this->assertSame(['copy'], array_keys($saved), 'The first block keeps the paragraph.');
    $this->assertSame($reference, $data['content'][0]['props']['paragraph']);
    $this->assertNotSame($reference['id'], $saved['copy']['id']);
    $this->assertSame('Changed in the copy', self::paragraphText($this->reloadParagraph($saved['copy']['id'])));
    $this->assertSame('Original', self::paragraphText($this->reloadParagraph($paragraph)), 'What is typed in one block does not change the other.');

    // Without a change the duplicate still gets its own paragraph.
    [, $saved] = $this->savePage($node, self::layout(self::paragraphItem('b1', $reference), self::paragraphItem('b3', $reference)));
    $this->assertSame(['b3'], array_keys($saved));
    $this->assertSame('Original', self::paragraphText($this->reloadParagraph($saved['b3']['id'])));
  }

  /**
   * A value that is not valid stops the save with its message: nothing is
   * saved, and what was typed stays.
   */
  public function testErrors(): void {
    $this->createParagraphType('required_block', 'Required block', TRUE);
    $node = $this->createBuilderNode();
    $existing = $this->createParagraph('Kept', $node);
    $this->type($node, 'good', 'Fine');
    $this->type($node, 'changed', 'Not saved either');
    $this->type($node, 'bad', NULL);
    $data = self::layout(
      self::paragraphItem('good'),
      self::paragraphItem('changed', $existing),
      self::paragraphItem('bad', NULL, 'required_block'),
    );
    $before = $data;
    $saved = [];
    $errors = $this->paragraphs->persist($data, $node, $saved);

    $this->assertCount(1, $errors);
    $this->assertStringStartsWith('Required block: ', $errors[0], 'The message names the paragraph type.');
    $this->assertStringNotContainsString('<', $errors[0]);
    $this->assertSame([], $saved);
    $this->assertSame($before, $data, 'The layout is not touched.');
    $this->assertSame([1, 1], $this->paragraphCounts(), 'Nothing is saved, the valid blocks included.');
    $this->assertSame('Kept', self::paragraphText($this->reloadParagraph($existing)));
    $this->assertTrue($this->hasDraft($node, 'good'));
    $this->assertTrue($this->hasDraft($node, 'bad'), 'What was typed is still there to be corrected.');

    // Corrected, everything is saved.
    $this->type($node, 'bad', 'Now filled');
    [$errors, $saved] = $this->savePage($node, $before);
    $this->assertSame([], $errors);
    $this->assertEqualsCanonicalizing(['good', 'changed', 'bad'], array_keys($saved));
    $this->assertSame('required_block', $this->reloadParagraph($saved['bad']['id'])->bundle());
  }

  /**
   * What is not a Paragraph block of a known type is not saved.
   */
  public function testIgnored(): void {
    $node = $this->createBuilderNode();
    $this->type($node, 'b1', 'Typed');
    $this->type($node, '', 'Typed without a block');
    $data = self::layout(
      self::paragraphItem('b1', NULL, 'missing_type'),
      // A component that happens to have the id of a draft.
      self::item('frame_builder_test_theme:probe', 'b1'),
      ['type' => 'drupal_paragraph:text_block', 'props' => []],
    );
    $saved = [];
    $this->assertSame([], $this->paragraphs->persist($data, $node, $saved));
    $this->assertSame([], $saved);
    $this->assertSame([0, 0], $this->paragraphCounts());

    $empty = [];
    $this->assertSame([], $this->paragraphs->persist($empty, $node, $saved), 'A request without a layout.');
    $none = ['content' => 'none'];
    $this->assertSame([], $this->paragraphs->persist($none, $node, $saved));
  }

  /**
   * A translation of the page gets its own revision of the paragraph: with
   * the translation of the paragraph when its type is translatable.
   */
  public function testTranslations(): void {
    $this->addLanguage('de');
    $node = $this->createBuilderNode();
    $german = $node->addTranslation('de', ['title' => 'Eine Seite']);
    $node->save();

    // A new block of the translation: a paragraph in its language.
    $this->type($german, 'new', 'Neu');
    $this->type($node, 'new', 'English draft of the same block');
    $data = self::layout(self::paragraphItem('new'));
    $saved = [];
    $this->assertSame([], $this->paragraphs->persist($data, $german, $saved));
    $paragraph = $this->reloadParagraph($saved['new']['id']);
    $this->assertSame('de', $paragraph->language()->getId());
    $this->assertSame('Neu', self::paragraphText($paragraph));
    $this->assertFalse($this->hasDraft($german, 'new'));
    $this->assertTrue($this->hasDraft($node, 'new'), 'The unsaved values of the other language stay.');

    // A type that is not translatable: another revision with the translated text.
    $shared = $this->createParagraph('Hello', $node);
    $english = Paragraphs::reference($shared);
    $this->type($german, 'b1', 'Hallo');
    $data = self::layout(self::paragraphItem('b1', $english));
    $saved = [];
    $this->assertSame([], $this->paragraphs->persist($data, $german, $saved));
    $this->assertSame($english['id'], $saved['b1']['id']);
    $this->assertGreaterThan($english['vid'], $saved['b1']['vid']);
    $this->assertSame('Hello', self::paragraphText($this->paragraphs->load($english, 'text_block')), 'The original keeps naming its revision.');
    $this->assertSame('Hallo', self::paragraphText($this->paragraphs->forDisplay(['id' => 'b1', 'paragraph' => $saved['b1']], 'text_block', $german)));

    // A translatable type: the revision holds the translation of the paragraph.
    $this->container->get('content_translation.manager')->setEnabled('paragraph', 'text_block', TRUE);
    $this->container->get('entity_type.bundle.info')->clearCachedBundles();
    $translatable = $this->createParagraph('Good day', $node);
    $english = Paragraphs::reference($translatable);
    $this->assertTrue($translatable->isTranslatable());
    $this->type($german, 'b2', 'Guten Tag');
    $data = self::layout(self::paragraphItem('b2', $english));
    $saved = [];
    $this->assertSame([], $this->paragraphs->persist($data, $german, $saved));
    $this->assertSame($english['id'], $saved['b2']['id']);
    $revision = $this->paragraphs->load($saved['b2'], 'text_block');
    $this->assertTrue($revision->hasTranslation('de'));
    $this->assertSame('Guten Tag', self::paragraphText($revision->getTranslation('de')));
    $this->assertSame('Good day', self::paragraphText($revision->getTranslation('en')), 'The original text is not replaced.');
    $this->assertSame('Guten Tag', self::paragraphText($this->paragraphs->forDisplay(['id' => 'b2', 'paragraph' => $saved['b2']], 'text_block', $german)));
    $this->assertSame('Good day', self::paragraphText($this->paragraphs->forDisplay(['id' => 'b2', 'paragraph' => $saved['b2']], 'text_block', $node)));
  }

  /**
   * An item that goes to the library gets paragraphs of the saved block's
   * own, with the values typed on the page, even when the page was never
   * saved.
   */
  public function testPersistForLibrary(): void {
    $library = $this->container->get('frame_builder.library');
    $node = $this->createBuilderNode();
    $of_page = $this->createParagraph('Of the page', $node);
    $this->type($node, 'typed', 'Only typed');
    $this->type($node, 'changed', 'Changed on the page');
    $item = self::item('frame_builder_test_theme:probe', 'outer', ['content' => [
      self::paragraphItem('typed', ['draft' => 'k']),
      self::paragraphItem('changed', $of_page),
      self::paragraphItem('empty'),
    ]]);
    $saved = $library->create('Promo', $item);
    $this->assertSame([], $this->paragraphs->persistForLibrary($item, $saved, $node));

    $typed = $item['props']['content'][0]['props']['paragraph'];
    $changed = $item['props']['content'][1]['props']['paragraph'];
    $this->assertArrayNotHasKey('draft', $typed);
    $this->assertArrayNotHasKey('paragraph', $item['props']['content'][2]['props']);
    $this->assertSame('Only typed', self::paragraphText($this->reloadParagraph($typed['id'])));
    $this->assertNotSame((int) $of_page->id(), $changed['id'], 'The library gets a paragraph of its own.');
    $this->assertSame('Changed on the page', self::paragraphText($this->reloadParagraph($changed['id'])));
    $this->assertSame('Of the page', self::paragraphText($this->reloadParagraph($of_page)), 'The paragraph of the page is not written to.');
    foreach ([$typed, $changed] as $reference) {
      $paragraph = $this->reloadParagraph($reference['id']);
      $this->assertSame('frame_saved', $paragraph->get('parent_type')->value);
      $this->assertSame((string) $saved->id(), (string) $paragraph->get('parent_id')->value);
      $this->assertSame(LayoutStorage::FIELD, $paragraph->get('parent_field_name')->value);
    }
    $this->assertFalse($this->hasDraft($node, 'typed'));
    $this->assertFalse($this->hasDraft($node, 'changed'));

    // An update: the saved block's own paragraph gets a revision when it
    // was changed, and is left alone when not.
    $library->setItem($saved, $item);
    $saved->save();
    $counts = $this->paragraphCounts();
    $this->type($node, 'typed', 'Typed again');
    $this->assertSame([], $this->paragraphs->persistForLibrary($item, $saved, $node));
    $this->assertSame($typed['id'], $item['props']['content'][0]['props']['paragraph']['id']);
    $this->assertGreaterThan($typed['vid'], $item['props']['content'][0]['props']['paragraph']['vid']);
    $this->assertSame($changed, $item['props']['content'][1]['props']['paragraph']);
    $this->assertSame([$counts[0], $counts[1] + 1], $this->paragraphCounts());
    $this->assertSame('Typed again', self::paragraphText($this->reloadParagraph($typed['id'])));
  }

  /**
   * Without a page there are no typed values: paragraphs of something else
   * are copied for the saved block, and errors stop the save.
   */
  public function testPersistForLibraryWithoutAPageAndErrors(): void {
    $this->createParagraphType('required_block', 'Required block', TRUE);
    $library = $this->container->get('frame_builder.library');
    $node = $this->createBuilderNode();
    $of_page = $this->createParagraph('Of the page', $node);

    $item = self::paragraphItem('b1', $of_page);
    $saved = $library->create('Notice', $item);
    $this->assertSame([], $this->paragraphs->persistForLibrary($item, $saved, NULL));
    $this->assertNotSame((int) $of_page->id(), $item['props']['paragraph']['id']);
    $copy = $this->reloadParagraph($item['props']['paragraph']['id']);
    $this->assertSame('Of the page', self::paragraphText($copy));
    $this->assertSame('frame_saved', $copy->get('parent_type')->value);

    // A copy of the saved block on a page that is saved: the page gets its own.
    $data = self::layout($item);
    $names = [];
    $this->assertSame([], $this->paragraphs->persist($data, $node, $names));
    $this->assertNotSame($item['props']['paragraph']['id'], $names['b1']['id'], 'A page and the library never write to the same paragraph.');
    $this->assertSame('node', $this->reloadParagraph($names['b1']['id'])->get('parent_type')->value);

    $this->type($node, 'bad', NULL);
    $bad = self::paragraphItem('bad', NULL, 'required_block');
    $counts = $this->paragraphCounts();
    $errors = $this->paragraphs->persistForLibrary($bad, $saved, $node);
    $this->assertCount(1, $errors);
    $this->assertStringStartsWith('Required block: ', $errors[0]);
    $this->assertSame($counts, $this->paragraphCounts());
    $this->assertTrue($this->hasDraft($node, 'bad'));
  }

}
