<?php

declare(strict_types=1);

namespace Drupal\Tests\frame_builder\Functional;

use Drupal\Core\Url;
use Drupal\frame_builder\Entity\SavedBlock;
use Drupal\frame_builder\Paragraphs;
use Drupal\node\NodeInterface;
use Drupal\Tests\frame_builder\Traits\ParagraphsTestTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * The form of a paragraph in the editor: it keeps what is typed and saves
 * nothing; the paragraph is saved with the page.
 */
#[Group('frame_builder')]
class ParagraphFormTest extends BuilderFunctionalTestBase {

  use ParagraphsTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['paragraphs'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $this->requireModules('entity_reference_revisions', 'paragraphs');
    parent::setUp();
    $this->createParagraphType();
    $this->createParagraphType('required_block', 'Required block', TRUE);
  }

  /**
   * Saves the layout of a page through the save endpoint.
   *
   * @return array
   *   The status code and the decoded body.
   */
  protected function savePage(NodeInterface $node, array $data): array {
    return $this->postJson(Url::fromRoute('frame_builder.save', ['node' => $node->id()]), ['data' => $data, 'publish' => TRUE]);
  }

  /**
   * The page as it is stored now.
   */
  protected function reloadNode(NodeInterface $node): NodeInterface {
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $storage->resetCache([$node->id()]);
    return $storage->load($node->id());
  }

  /**
   * The form is for those who may use the editor of the page, and for a
   * paragraph type that exists.
   */
  public function testAccess(): void {
    $node = $this->createBuilderNode();
    $this->drupalGet($this->paragraphFormUrl($node, 'b1'));
    $this->assertSession()->statusCodeEquals(200);

    $this->drupalGet($this->paragraphFormUrl($node, 'b1', NULL, 'missing_type'));
    $this->assertSession()->statusCodeEquals(404);
    $this->drupalGet(Url::fromRoute('frame_builder.paragraph_form', ['node' => $node->id()], ['query' => ['block' => 'b1']]));
    $this->assertSession()->statusCodeEquals(404);

    // May edit the page, may not use the builder.
    $this->drupalLogin($this->drupalCreateUser(['access content', 'edit any landing content']));
    $this->drupalGet($this->paragraphFormUrl($node, 'b1'));
    $this->assertSession()->statusCodeEquals(403);
    // May use the builder, may not edit the page.
    $this->drupalLogin($this->drupalCreateUser(['access content', 'use frame builder']));
    $this->drupalGet($this->paragraphFormUrl($node, 'b1'));
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalLogout();
    $this->drupalGet($this->paragraphFormUrl($node, 'b1'));
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * The form is the paragraph's own form display without a save button,
   * with the values of the revision the block names.
   */
  public function testForm(): void {
    $node = $this->createBuilderNode();
    $paragraph = $this->createParagraph('First text', $node);
    $first = Paragraphs::reference($paragraph);
    $second = Paragraphs::reference($this->reviseParagraph($paragraph, 'Second text'));

    // A new block: an empty form.
    $this->drupalGet($this->paragraphFormUrl($node, 'new'));
    $this->assertSession()->fieldValueEquals(self::TEXT_FIELD . '[0][value]', '');
    $this->assertSession()->buttonExists('Keep as draft');
    $this->assertSession()->elementsCount('css', 'form input[type="submit"]', 1);
    $this->assertSession()->elementExists('css', 'input[data-frame-builder-autosave].visually-hidden');
    $this->assertSame('key-new', $this->getDrupalSettings()['frameBuilderParagraph']['key'], 'The key is passed back to the editor.');
    $this->assertSession()->elementNotExists('css', '#toolbar-administration, .region-header');

    // The paragraph of the block, in the revision the layout names.
    $this->drupalGet($this->paragraphFormUrl($node, 'b1', $second));
    $this->assertSession()->fieldValueEquals(self::TEXT_FIELD . '[0][value]', 'Second text');
    $this->drupalGet($this->paragraphFormUrl($node, 'b1', $first));
    $this->assertSession()->fieldValueEquals(self::TEXT_FIELD . '[0][value]', 'First text');

    // A paragraph of another type than the block is not the block's.
    $this->drupalGet($this->paragraphFormUrl($node, 'b1', $second, 'required_block'));
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->fieldValueEquals(self::TEXT_FIELD . '[0][value]', '');
  }

  /**
   * What is typed is kept for the user, page and block, and put back in
   * the form; no paragraph is made or changed.
   */
  public function testAutosaveKeepsADraft(): void {
    $node = $this->createBuilderNode();
    $other_page = $this->createBuilderNode();
    $paragraph = $this->createParagraph('Saved text', $node);
    $reference = Paragraphs::reference($paragraph);

    $this->typeInParagraphForm($node, 'new', 'Typed in a new block');
    $this->assertSession()->fieldValueEquals(self::TEXT_FIELD . '[0][value]', 'Typed in a new block');
    $this->typeInParagraphForm($node, 'b1', 'Typed over', $reference);
    $this->assertSame([1, 1], $this->paragraphCounts(), 'Nothing is saved: no paragraph, no revision.');
    $this->assertSame('Saved text', self::paragraphText($this->reloadParagraph($paragraph)));
    $this->assertSame($node->getRevisionId(), $this->reloadNode($node)->getRevisionId(), 'The page is not saved either.');

    // After a reload the form has what was typed.
    $this->drupalGet($this->paragraphFormUrl($node, 'new'));
    $this->assertSession()->fieldValueEquals(self::TEXT_FIELD . '[0][value]', 'Typed in a new block');
    $this->drupalGet($this->paragraphFormUrl($node, 'b1', $reference));
    $this->assertSession()->fieldValueEquals(self::TEXT_FIELD . '[0][value]', 'Typed over');
    // Typed again: the newest values count.
    $this->typeInParagraphForm($node, 'new', 'Typed again');
    $this->drupalGet($this->paragraphFormUrl($node, 'new'));
    $this->assertSession()->fieldValueEquals(self::TEXT_FIELD . '[0][value]', 'Typed again');

    // Another block, another page and another user do not get it.
    $this->drupalGet($this->paragraphFormUrl($node, 'b2', $reference));
    $this->assertSession()->fieldValueEquals(self::TEXT_FIELD . '[0][value]', 'Saved text');
    $this->drupalGet($this->paragraphFormUrl($other_page, 'new'));
    $this->assertSession()->fieldValueEquals(self::TEXT_FIELD . '[0][value]', '');
    $this->drupalLogin($this->drupalCreateUser(static::EDITOR_PERMISSIONS));
    $this->drupalGet($this->paragraphFormUrl($node, 'new'));
    $this->assertSession()->fieldValueEquals(self::TEXT_FIELD . '[0][value]', '');
    $this->drupalGet($this->paragraphFormUrl($node, 'b1', $reference));
    $this->assertSession()->fieldValueEquals(self::TEXT_FIELD . '[0][value]', 'Saved text');
  }

  /**
   * A draft is kept whatever state the form is in: values are checked when
   * the page is saved, and stop the save then.
   */
  public function testInvalidValuesAreKeptAndStopTheSave(): void {
    $node = $this->createBuilderNode();
    $revision = $node->getRevisionId();
    $this->typeInParagraphForm($node, 'good', 'Fine');
    $this->typeInParagraphForm($node, 'bad', '', NULL, 'required_block');
    $this->assertSession()->pageTextNotContains('field is required');
    $this->assertSession()->elementNotExists('css', '.messages--error, [data-drupal-messages] [role="alert"]');

    $data = self::layout(self::paragraphItem('good', ['draft' => 'k']), self::paragraphItem('bad', ['draft' => 'k'], 'required_block'));
    [$status, $body] = $this->savePage($node, $data);
    $this->assertSame(422, $status);
    $this->assertCount(1, $body['errors']);
    $this->assertStringStartsWith('Required block: ', $body['errors'][0]);
    $this->assertSame([0, 0], $this->paragraphCounts(), 'No paragraph is saved, the valid one included.');
    $this->assertSame($revision, $this->reloadNode($node)->getRevisionId(), 'The page is not saved.');
    $this->drupalGet($this->paragraphFormUrl($node, 'good'));
    $this->assertSession()->fieldValueEquals(self::TEXT_FIELD . '[0][value]', 'Fine');

    // Corrected: the page and both paragraphs are saved.
    $this->typeInParagraphForm($node, 'bad', 'Filled in', NULL, 'required_block');
    [$status, $body] = $this->savePage($node, $data);
    $this->assertSame(200, $status);
    $this->assertEqualsCanonicalizing(['good', 'bad'], array_keys($body['paragraphs']));
    $this->assertSame('required_block', $this->reloadParagraph($body['paragraphs']['bad']['id'])->bundle());
  }

  /**
   * Saving the page makes the paragraph, or a new revision of it, and tells
   * the editor what the block names now.
   */
  public function testSave(): void {
    $node = $this->createBuilderNode();
    $this->typeInParagraphForm($node, 'b1', 'First text');
    [$status, $body] = $this->savePage($node, self::layout(self::paragraphItem('b1', ['draft' => 'k'])));
    $this->assertSame(200, $status);
    $first = $body['paragraphs']['b1'];
    $this->assertSame(['id', 'vid', 'uuid'], array_keys($first));
    $first_revision = $body['revision'];

    $paragraph = $this->reloadParagraph($first['id']);
    $this->assertSame('First text', self::paragraphText($paragraph));
    $this->assertSame('node', $paragraph->get('parent_type')->value);
    $this->assertSame((string) $node->id(), (string) $paragraph->get('parent_id')->value);
    $stored = $this->reloadNode($node);
    $this->assertSame([$first['id']], array_map('intval', array_column($stored->get(Paragraphs::FIELD)->getValue(), 'target_id')), 'The page lists its paragraph.');
    $layout = \Drupal::service('frame_builder.layouts')->load($stored);
    $this->assertSame($first, $layout['content'][0]['props']['paragraph'], 'The layout names the saved revision.');

    $this->drupalGet($node->toUrl());
    $this->assertSession()->pageTextContains('First text');
    $this->assertSession()->elementExists('css', '.paragraph--type--text-block');
    // What was typed is no longer unsaved: a new block with the same id starts empty.
    $this->drupalGet($this->paragraphFormUrl($node, 'b1'));
    $this->assertSession()->fieldValueEquals(self::TEXT_FIELD . '[0][value]', '');

    // Saved without a change: the block names the same paragraph.
    [$status, $body] = $this->savePage($node, self::layout(self::paragraphItem('b1', $first)));
    $this->assertSame(200, $status);
    $this->assertNull($body['paragraphs']);

    // Changed in the form: a new revision of the same paragraph.
    $this->typeInParagraphForm($node, 'b1', 'Second text', $first);
    $this->drupalGet($node->toUrl());
    $this->assertSession()->pageTextContains('First text');
    $this->assertSession()->pageTextNotContains('Second text');
    [$status, $body] = $this->savePage($node, self::layout(self::paragraphItem('b1', $first + ['draft' => 'k'])));
    $this->assertSame(200, $status);
    $second = $body['paragraphs']['b1'];
    $this->assertSame($first['id'], $second['id']);
    $this->assertGreaterThan($first['vid'], $second['vid']);
    $this->drupalGet($node->toUrl());
    $this->assertSession()->pageTextContains('Second text');
    $this->assertSession()->pageTextNotContains('First text');

    // The older revision of the page shows the paragraph as it was.
    $this->drupalGet(Url::fromRoute('entity.node.revision', ['node' => $node->id(), 'node_revision' => $first_revision]));
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('First text');
    $this->assertSession()->pageTextNotContains('Second text');

    // The block is removed: the page no longer lists the paragraph.
    [$status] = $this->savePage($node, self::layout(self::item('frame_builder_test_theme:probe', 'p')));
    $this->assertSame(200, $status);
    $this->assertTrue($this->reloadNode($node)->get(Paragraphs::FIELD)->isEmpty());
    $this->drupalGet($node->toUrl());
    $this->assertSession()->pageTextNotContains('Second text');
  }

  /**
   * The preview of the editor shows what was typed; the page does not.
   */
  public function testPreviewShowsTheDraft(): void {
    $node = $this->createBuilderNode();
    $this->typeInParagraphForm($node, 'b1', 'Only typed');
    [$status] = $this->postJson(Url::fromRoute('frame_builder.draft', ['node' => $node->id()]), ['data' => self::layout(self::paragraphItem('b1', ['draft' => 'k']))]);
    $this->assertSame(200, $status);
    $this->drupalGet(Url::fromRoute('frame_builder.preview', ['node' => $node->id()]));
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Only typed');
    $this->drupalGet($node->toUrl());
    $this->assertSession()->pageTextNotContains('Only typed');
  }

  /**
   * Without the Paragraphs Library module a Paragraph block saved to the
   * library is a saved block of the builder with a paragraph of its own,
   * also when the page was never saved.
   */
  public function testSaveToTheLibraryOfTheBuilder(): void {
    $node = $this->createBuilderNode();
    $this->typeInParagraphForm($node, 'b1', 'Typed for the library');
    $library = Url::fromRoute('frame_builder.library');
    [$status, $body] = $this->postJson($library, ['label' => 'Notice', 'item' => self::paragraphItem('b1', ['draft' => 'k']), 'node' => $node->id(), 'message' => 'First']);
    $this->assertSame(200, $status);
    $this->assertSame('drupal_saved:' . $body['uuid'], $body['component']['id']);

    $saved = \Drupal::service('frame_builder.library')->load($body['uuid']);
    $this->assertInstanceOf(SavedBlock::class, $saved);
    $item = \Drupal::service('frame_builder.library')->item($saved);
    $this->assertSame('drupal_paragraph:text_block', $item['type']);
    $paragraph = $this->reloadParagraph($item['props']['paragraph']['id']);
    $this->assertSame('Typed for the library', self::paragraphText($paragraph));
    $this->assertSame('frame_saved', $paragraph->get('parent_type')->value);
    $this->assertSame((string) $saved->id(), (string) $paragraph->get('parent_id')->value);
    $this->drupalGet($this->paragraphFormUrl($node, 'b1'));
    // What went to the library is no longer unsaved on the page.
    $this->assertSession()->fieldValueEquals(self::TEXT_FIELD . '[0][value]', '');

    // The page links to it and shows it.
    [$status, $page] = $this->savePage($node, self::layout(self::savedItem('s1', $body['uuid'])));
    $this->assertSame(200, $status);
    $this->assertNull($page['paragraphs'], 'A link to a saved block gives the page no paragraph.');
    $this->drupalGet($node->toUrl());
    $this->assertSession()->pageTextContains('Typed for the library');

    // An update from a copy on another page: a revision of the saved
    // block's paragraph, visible on the page that links to it.
    $other = $this->createBuilderNode();
    $this->typeInParagraphForm($other, 'copy', 'Updated in the library', $item['props']['paragraph']);
    [$status] = $this->postJson($library, ['uuid' => $body['uuid'], 'item' => self::paragraphItem('copy', $item['props']['paragraph'] + ['draft' => 'k']), 'node' => $other->id(), 'message' => 'Second']);
    $this->assertSame(200, $status);
    \Drupal::entityTypeManager()->getStorage('frame_saved')->resetCache();
    $updated = \Drupal::service('frame_builder.library')->item(\Drupal::service('frame_builder.library')->load($body['uuid']));
    $this->assertSame($item['props']['paragraph']['id'], $updated['props']['paragraph']['id']);
    $this->assertGreaterThan($item['props']['paragraph']['vid'], $updated['props']['paragraph']['vid']);
    $this->drupalGet($node->toUrl());
    $this->assertSession()->pageTextContains('Updated in the library');
    $this->assertSession()->pageTextNotContains('Typed for the library');

    // A value that is not valid: nothing is added to the library.
    $this->typeInParagraphForm($node, 'bad', '', NULL, 'required_block');
    [$status, $body] = $this->postJson($library, ['label' => 'Bad', 'item' => self::paragraphItem('bad', NULL, 'required_block'), 'node' => $node->id()]);
    $this->assertSame(422, $status);
    $this->assertStringStartsWith('Required block: ', $body['errors'][0]);
    \Drupal::entityTypeManager()->getStorage('frame_saved')->resetCache();
    $this->assertCount(1, \Drupal::service('frame_builder.library')->all());
  }

}
