<?php

declare(strict_types=1);

namespace Drupal\Tests\frame_builder\Functional;

use Drupal\Core\Url;
use Drupal\filter\Entity\FilterFormat;
use Drupal\frame_builder\Entity\SavedBlock;
use Drupal\node\NodeInterface;
use Drupal\Tests\frame_builder\Traits\ParagraphsTestTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * The library of saved blocks through its endpoint and its list page: save,
 * read, update, rename, delete, and how pages follow.
 */
#[Group('frame_builder')]
class LibraryEndpointTest extends BuilderFunctionalTestBase {

  use ParagraphsTestTrait;

  /**
   * The component of the test theme.
   */
  protected const PROBE = 'frame_builder_test_theme:probe';

  /**
   * A probe that shows its title, as the editor sends it.
   */
  protected static function probe(string $id, string $title, array $props = []): array {
    return self::item(self::PROBE, $id, ['title' => $title, 'shown' => TRUE] + $props);
  }

  /**
   * The endpoint.
   */
  protected function libraryUrl(array $query = []): Url {
    return Url::fromRoute('frame_builder.library', [], ['query' => $query]);
  }

  /**
   * Reads from the endpoint.
   *
   * @return array
   *   The status code and the decoded body.
   */
  protected function getJson(Url $url): array {
    $body = $this->drupalGet($url);
    return [$this->getSession()->getStatusCode(), json_decode($body, TRUE)];
  }

  /**
   * A saved block as it is stored now.
   */
  protected function loadSaved(string $uuid): ?SavedBlock {
    \Drupal::entityTypeManager()->getStorage('frame_saved')->resetCache();
    return \Drupal::service('frame_builder.library')->load($uuid);
  }

  /**
   * Saves the layout of a page through the save endpoint and expects that
   * to work.
   */
  protected function savePage(NodeInterface $node, array $data): void {
    [$status] = $this->postJson(Url::fromRoute('frame_builder.save', ['node' => $node->id()]), ['data' => $data, 'publish' => TRUE]);
    $this->assertSame(200, $status);
  }

  /**
   * A block is saved under a name, and read back to make a copy of it.
   */
  public function testSaveAndRead(): void {
    $item = self::probe('p1', 'Saved title', ['_library' => ['uuid' => 'x', 'label' => 'Old'], 'content' => [self::probe('p2', 'Inner')]]);
    [$status, $body] = $this->postJson($this->libraryUrl(), ['label' => '  Promo ', 'item' => $item, 'message' => 'Made it']);
    $this->assertSame(200, $status);
    $uuid = $body['uuid'];

    // The answer is the editor's description of the block as a component.
    $component = $body['component'];
    $this->assertSame('drupal_saved:' . $uuid, $component['id']);
    $this->assertSame('Promo', $component['label']);
    $this->assertSame('Library', $component['group']);
    $this->assertSame($uuid, $component['saved']);
    $this->assertSame((string) $this->editor->getDisplayName(), $component['info']['author']);
    $this->assertSame('Made it', $component['info']['message']);
    $this->assertSame(1, $component['info']['revisions']);
    $this->assertNotSame('', $component['info']['saved']);

    $saved = $this->loadSaved($uuid);
    $this->assertSame('Promo', $saved->label());
    $stored = \Drupal::service('frame_builder.library')->item($saved);
    $this->assertSame('Saved title', $stored['props']['title']);
    $this->assertArrayNotHasKey('_library', $stored['props']);

    [$status, $body] = $this->getJson($this->libraryUrl(['uuid' => $uuid]));
    $this->assertSame(200, $status);
    $this->assertSame('Promo', $body['label']);
    $this->assertSame(self::PROBE, $body['item']['type']);
    $this->assertSame('Saved title', $body['item']['props']['title']);
    $this->assertSame('Inner', $body['item']['props']['content'][0]['props']['title']);

    // The editor's list of components has it, for every page.
    $ids = array_column($this->editorSettings($this->createBuilderNode())['components'], 'id');
    $this->assertContains('drupal_saved:' . $uuid, $ids);
  }

  /**
   * A request that names nothing to save, or a block that is gone, is
   * refused.
   */
  public function testRefused(): void {
    $item = self::probe('p1', 'Title');
    foreach ([
      ['item' => $item],
      ['label' => '   ', 'item' => $item],
      ['label' => 'Name'],
      ['label' => 'Name', 'item' => 'text'],
      ['label' => 'Name', 'item' => ['props' => ['id' => 'p1']]],
      [],
    ] as $input) {
      [$status, $body] = $this->postJson($this->libraryUrl(), $input);
      $this->assertSame(400, $status, json_encode($input));
      $this->assertSame(['A name and a block are needed.'], $body['errors']);
    }
    $gone = '0b6f0000-0000-4000-8000-000000000000';
    foreach ([['uuid' => $gone, 'item' => $item], ['uuid' => $gone, 'delete' => TRUE], ['uuid' => $gone, 'label' => 'Name']] as $input) {
      [$status, $body] = $this->postJson($this->libraryUrl(), $input);
      $this->assertSame(404, $status);
      $this->assertSame(['This saved block no longer exists.'], $body['errors']);
    }
    [$status, $body] = $this->getJson($this->libraryUrl(['uuid' => $gone]));
    $this->assertSame(404, $status);
    $this->assertSame(['This saved block no longer exists.'], $body['errors']);
    [$status] = $this->getJson($this->libraryUrl());
    $this->assertSame(404, $status);
    $this->assertSame([], \Drupal::service('frame_builder.library')->all(), 'Nothing was saved.');
  }

  /**
   * A saved block is replaced, renamed and deleted; every save is logged.
   */
  public function testUpdateRenameDelete(): void {
    [, $body] = $this->postJson($this->libraryUrl(), ['label' => 'Promo', 'item' => self::probe('p1', 'First'), 'message' => 'Made it']);
    $uuid = $body['uuid'];
    $id = $this->loadSaved($uuid)->id();

    // "Update the saved block", by another editor.
    $second = $this->drupalCreateUser(static::EDITOR_PERMISSIONS);
    $this->drupalLogin($second);
    [$status, $body] = $this->postJson($this->libraryUrl(), ['uuid' => $uuid, 'item' => self::probe('p1', 'Second', ['_library' => ['uuid' => $uuid, 'overridden' => TRUE]]), 'message' => 'New wording']);
    $this->assertSame(200, $status);
    $this->assertSame($uuid, $body['uuid']);
    $this->assertSame('Promo', $body['component']['label'], 'Without a name the name stays.');
    $this->assertSame(['author' => (string) $second->getDisplayName(), 'message' => 'New wording', 'revisions' => 2], array_diff_key($body['component']['info'], ['saved' => 1]));
    $saved = $this->loadSaved($uuid);
    $this->assertSame($id, $saved->id(), 'The same saved block.');
    $item = \Drupal::service('frame_builder.library')->item($saved);
    $this->assertSame('Second', $item['props']['title']);
    $this->assertArrayNotHasKey('_library', $item['props']);
    $this->assertSame(['Made it', 'New wording'], array_column(\Drupal::service('frame_builder.library')->log($saved), 'message'));
    $this->assertSame([(int) $this->editor->id(), (int) $second->id()], array_column(\Drupal::service('frame_builder.library')->log($saved), 'uid'));

    // Renamed only: what it consists of stays. A name is cut to its length.
    [$status, $body] = $this->postJson($this->libraryUrl(), ['uuid' => $uuid, 'label' => str_repeat('n', 300)]);
    $this->assertSame(200, $status);
    $saved = $this->loadSaved($uuid);
    $this->assertSame(str_repeat('n', 255), $saved->label());
    $this->assertSame('Second', \Drupal::service('frame_builder.library')->item($saved)['props']['title']);
    $this->assertSame(3, $body['component']['info']['revisions']);

    [$status, $body] = $this->postJson($this->libraryUrl(), ['uuid' => $uuid, 'delete' => TRUE]);
    $this->assertSame(200, $status);
    $this->assertSame(['deleted' => TRUE], $body);
    $this->assertNull($this->loadSaved($uuid));
    [$status] = $this->getJson($this->libraryUrl(['uuid' => $uuid]));
    $this->assertSame(404, $status);
  }

  /**
   * The endpoint is for users of the builder, and writes need the token of
   * the session.
   */
  public function testPermissionAndCsrf(): void {
    $input = ['label' => 'Promo', 'item' => self::probe('p1', 'Title')];
    [$status] = $this->postJson($this->libraryUrl(), $input, 'not-the-token');
    $this->assertSame(403, $status);
    $this->assertSame([], \Drupal::service('frame_builder.library')->all());

    [, $body] = $this->postJson($this->libraryUrl(), $input);
    $uuid = $body['uuid'];
    [$status] = $this->postJson($this->libraryUrl(), ['uuid' => $uuid, 'delete' => TRUE], 'not-the-token');
    $this->assertSame(403, $status);

    // Everyone with the builder permission may read, save, update, delete.
    $this->drupalLogin($this->drupalCreateUser(['use frame builder']));
    [$status] = $this->getJson($this->libraryUrl(['uuid' => $uuid]));
    $this->assertSame(200, $status);
    [$status] = $this->postJson($this->libraryUrl(), ['uuid' => $uuid, 'label' => 'Renamed']);
    $this->assertSame(200, $status);
    $this->assertSame('Renamed', $this->loadSaved($uuid)->label());

    // Without it: nothing.
    $this->drupalLogin($this->drupalCreateUser(['access content', 'edit any landing content']));
    [$status] = $this->getJson($this->libraryUrl(['uuid' => $uuid]));
    $this->assertSame(403, $status);
    foreach ([$input, ['uuid' => $uuid, 'label' => 'Taken'], ['uuid' => $uuid, 'delete' => TRUE]] as $body) {
      [$status] = $this->postJson($this->libraryUrl(), $body);
      $this->assertSame(403, $status);
    }
    $this->drupalLogout();
    [$status] = $this->getJson($this->libraryUrl(['uuid' => $uuid]));
    $this->assertSame(403, $status);
    $this->assertSame('Renamed', $this->loadSaved($uuid)->label());
    $this->assertCount(1, \Drupal::service('frame_builder.library')->all());
  }

  /**
   * A page that links to a saved block shows it as it is saved now; a page
   * with a copy keeps its own.
   */
  public function testLinkedAndCopiedOnPages(): void {
    [, $body] = $this->postJson($this->libraryUrl(), ['label' => 'Promo', 'item' => self::probe('p1', 'Saved title')]);
    $uuid = $body['uuid'];

    $linked = $this->createBuilderNode(['title' => 'Linked page']);
    $this->savePage($linked, self::layout(self::savedItem('s1', $uuid)));
    // "Edit a copy here": the item of the saved block, which remembers
    // where it came from.
    [, $read] = $this->getJson($this->libraryUrl(['uuid' => $uuid]));
    $copy = $read['item'];
    $copy['props']['id'] = 'c1';
    $copy['props']['_library'] = ['uuid' => $uuid, 'label' => $read['label']];
    $copied = $this->createBuilderNode(['title' => 'Copy page']);
    $this->savePage($copied, self::layout($copy));

    foreach ([$linked, $copied] as $node) {
      $this->drupalGet($node->toUrl());
      $this->assertSession()->elementTextContains('css', '.probe__title', 'Saved title');
    }

    // Updated from the copy, with other words: every page that links to
    // the saved block changes without being saved again.
    $copy['props']['title'] = 'Updated title';
    [$status] = $this->postJson($this->libraryUrl(), ['uuid' => $uuid, 'item' => $copy, 'message' => 'Reworded']);
    $this->assertSame(200, $status);
    $this->drupalGet($linked->toUrl());
    $this->assertSession()->elementTextContains('css', '.probe__title', 'Updated title');
    $this->assertSession()->pageTextNotContains('Saved title');
    $this->drupalGet($copied->toUrl());
    $this->assertSession()->elementTextContains('css', '.probe__title', 'Saved title');

    // Also for visitors, whose pages are cached.
    $this->drupalLogout();
    $this->drupalGet($linked->toUrl());
    $this->assertSession()->pageTextContains('Updated title');
    $this->drupalLogin($this->editor);
    [$status] = $this->postJson($this->libraryUrl(), ['uuid' => $uuid, 'item' => self::probe('p1', 'Third title')]);
    $this->assertSame(200, $status);
    $this->drupalLogout();
    $this->drupalGet($linked->toUrl());
    $this->assertSession()->pageTextContains('Third title');
    $this->assertSession()->pageTextNotContains('Updated title');

    // Deleted: pages that link to it show nothing in its place; copies stay.
    $this->drupalLogin($this->editor);
    [$status] = $this->postJson($this->libraryUrl(), ['uuid' => $uuid, 'delete' => TRUE]);
    $this->assertSame(200, $status);
    $this->drupalGet($linked->toUrl());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->elementNotExists('css', '.probe');
    $this->drupalGet($copied->toUrl());
    $this->assertSession()->elementTextContains('css', '.probe__title', 'Saved title');
  }

  /**
   * A text format the user may not use is accepted only for text the saved
   * block already has.
   */
  public function testTextFormats(): void {
    FilterFormat::create(['format' => 'rich', 'name' => 'Rich'])->save();
    $text = static fn (string $value): array => self::item('frame:text', 't1', ['text' => ['format' => 'rich', 'value' => $value]]);

    [$status, $body] = $this->postJson($this->libraryUrl(), ['label' => 'Text', 'item' => $text('<p>Mine</p>')]);
    $this->assertSame(422, $status);
    $this->assertSame(['You may not use the "rich" text format.'], $body['errors']);
    $this->assertSame([], \Drupal::service('frame_builder.library')->all());
    // Also deep inside the block.
    [$status] = $this->postJson($this->libraryUrl(), ['label' => 'Text', 'item' => self::probe('p1', 'Around', ['content' => [$text('<p>Mine</p>')]])]);
    $this->assertSame(422, $status);

    // Saved by someone who may use the format.
    $saved = \Drupal::service('frame_builder.library')->create('Text', self::probe('p1', 'Around', ['content' => [$text('<p>Theirs</p>')]]));
    $uuid = $saved->uuid();
    [$status] = $this->postJson($this->libraryUrl(), ['uuid' => $uuid, 'item' => self::probe('p1', 'Changed around', ['content' => [$text('<p>Theirs</p>')]])]);
    $this->assertSame(200, $status, 'The text that is there already may stay, as a locked field of a form.');
    $this->assertSame('Changed around', \Drupal::service('frame_builder.library')->item($this->loadSaved($uuid))['props']['title']);
    [$status] = $this->postJson($this->libraryUrl(), ['uuid' => $uuid, 'item' => self::probe('p1', 'Changed around', ['content' => [$text('<p>Changed</p>')]])]);
    $this->assertSame(422, $status, 'It may not be changed.');
    $this->assertSame('<p>Theirs</p>', \Drupal::service('frame_builder.library')->item($this->loadSaved($uuid))['props']['content'][0]['props']['text']['value']);

    // A format the user may use.
    [$status] = $this->postJson($this->libraryUrl(), ['label' => 'Plain', 'item' => self::item('frame:text', 't1', ['text' => ['format' => 'plain_text', 'value' => 'Plain']])]);
    $this->assertSame(200, $status);
  }

  /**
   * Content > Page builder library lists the saved blocks, with renaming
   * and deleting.
   */
  public function testListPage(): void {
    $list = Url::fromRoute('entity.frame_saved.collection');
    $this->assertSame('admin/content/page-builder-library', $list->getInternalPath());
    $this->drupalGet($list);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Nothing is saved yet.');

    [, $body] = $this->postJson($this->libraryUrl(), ['label' => 'Promo', 'item' => self::probe('p1', 'Outer', ['content' => [
      self::probe('p2', 'Inner', ['content' => [self::probe('p3', 'Deep')]]),
      self::probe('p4', 'Second inner'),
    ]])]);
    $uuid = $body['uuid'];
    $this->postJson($this->libraryUrl(), ['label' => 'Single', 'item' => self::probe('p1', 'Alone')]);

    $this->drupalGet($list);
    $this->assertSession()->pageTextNotContains('Nothing is saved yet.');
    $this->assertSession()->elementsCount('css', 'table tbody tr', 2);
    $row = $this->assertSession()->elementExists('xpath', '//table/tbody/tr[td[1][normalize-space()="Promo"]]');
    $cells = array_map(static fn ($cell): string => trim($cell->getText()), $row->findAll('css', 'td'));
    $this->assertSame(['Promo', self::PROBE, '4'], array_slice($cells, 0, 3), 'The name, the component and the number of blocks in it.');
    $this->assertNotSame('', $cells[3], 'When it changed.');
    $this->assertNotNull($row->findLink('Edit'));
    $this->assertNotNull($row->findLink('Delete'));
    $single = $this->assertSession()->elementExists('xpath', '//table/tbody/tr[td[1][normalize-space()="Single"]]');
    $this->assertSame('1', trim($single->find('css', 'td:nth-child(3)')->getText()));

    // Edit: rename. What the block consists of is made in the builder.
    $row->clickLink('Edit');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->fieldValueEquals('label[0][value]', 'Promo');
    $this->assertSession()->fieldNotExists('frame_layout[0][value]');
    $this->assertSession()->fieldNotExists('log[0][value]');
    $this->submitForm(['label[0][value]' => 'Promo of the spring'], 'Save');
    $saved = $this->loadSaved($uuid);
    $this->assertSame('Promo of the spring', $saved->label());
    $this->assertSame('Outer', \Drupal::service('frame_builder.library')->item($saved)['props']['title']);

    // Delete, after a confirmation.
    $this->drupalGet($saved->toUrl('delete-form'));
    $this->assertSession()->statusCodeEquals(200);
    $this->assertNotNull($this->loadSaved($uuid));
    $this->submitForm([], 'Delete');
    $this->assertNull($this->loadSaved($uuid));
    $this->drupalGet($list);
    $this->assertSession()->elementsCount('css', 'table tbody tr', 1);
    $this->assertSession()->pageTextNotContains('Promo of the spring');

    // Only for users of the builder.
    $left = current(\Drupal::service('frame_builder.library')->all());
    $this->drupalLogin($this->drupalCreateUser(['access content', 'access administration pages']));
    foreach ([$list, $left->toUrl('edit-form'), $left->toUrl('delete-form')] as $url) {
      $this->drupalGet($url);
      $this->assertSession()->statusCodeEquals(403);
    }
  }

}
