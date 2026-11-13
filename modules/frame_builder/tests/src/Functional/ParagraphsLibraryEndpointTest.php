<?php

declare(strict_types=1);

namespace Drupal\Tests\frame_builder\Functional;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Url;
use Drupal\frame_builder\Paragraphs;
use Drupal\Tests\frame_builder\Traits\ParagraphsTestTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * With the Paragraphs Library module the library endpoint saves Paragraph
 * blocks as items of that library, with the permissions of that module.
 */
#[Group('frame_builder')]
class ParagraphsLibraryEndpointTest extends BuilderFunctionalTestBase {

  use ParagraphsTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['paragraphs', 'paragraphs_library'];

  /**
   * {@inheritdoc}
   */
  protected const EDITOR_PERMISSIONS = [
    'use frame builder',
    'access content',
    'create landing content',
    'edit any landing content',
    'create paragraph library item',
    'edit paragraph library item',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $this->requireModules('entity_reference_revisions', 'paragraphs', 'entity_usage', 'paragraphs_library');
    parent::setUp();
    $this->createParagraphType();
  }

  /**
   * The endpoint.
   */
  protected function libraryUrl(array $query = []): Url {
    return Url::fromRoute('frame_builder.library', [], ['query' => $query]);
  }

  /**
   * An item of the Paragraphs Library as it is stored now.
   */
  protected function loadLibraryItem(string $uuid): ?ContentEntityInterface {
    \Drupal::entityTypeManager()->getStorage('paragraphs_library_item')->resetCache();
    \Drupal::entityTypeManager()->getStorage('paragraph')->resetCache();
    $found = \Drupal::entityTypeManager()->getStorage('paragraphs_library_item')->loadByProperties(['uuid' => $uuid]);
    return $found ? reset($found) : NULL;
  }

  /**
   * The text of the paragraph an item of the library holds.
   */
  protected function libraryText(string $uuid): ?string {
    return self::paragraphText($this->loadLibraryItem($uuid)->get('paragraphs')->entity);
  }

  /**
   * "Save to library" of a Paragraph block makes an item of the Paragraphs
   * Library; it is read, updated with a log message, and deleted.
   */
  public function testSaveReadUpdateDelete(): void {
    $this->assertTrue(\Drupal::service('frame_builder.paragraphs')->libraryAvailable(), 'The module brings its "From library" paragraph type.');
    $node = $this->createBuilderNode();
    $this->typeInParagraphForm($node, 'b1', 'Typed notice');
    [$status, $body] = $this->postJson($this->libraryUrl(), ['label' => 'Notice', 'item' => self::paragraphItem('b1', ['draft' => 'k']), 'node' => $node->id(), 'message' => 'First version']);
    $this->assertSame(200, $status);
    $uuid = $body['uuid'];
    $this->assertSame('drupal_saved:' . $uuid, $body['component']['id']);
    $this->assertSame('Notice', $body['component']['label']);
    $this->assertSame('Text block', $body['component']['info']['type']);
    $this->assertSame('First version', $body['component']['info']['message']);
    $this->assertSame((string) $this->editor->getDisplayName(), $body['component']['info']['author']);

    $item = $this->loadLibraryItem($uuid);
    $this->assertNotNull($item, 'It is an item of Content > Paragraphs');
    $this->assertSame([], \Drupal::service('frame_builder.library')->all(), 'not a saved block of the builder.');
    $this->assertSame('Typed notice', $this->libraryText($uuid));
    $this->assertSame('paragraphs_library_item', $item->get('paragraphs')->entity->get('parent_type')->value);
    $first_revision = $item->getRevisionId();

    // Read, to make a copy: a Paragraph block that names the item's paragraph.
    $read = json_decode($this->drupalGet($this->libraryUrl(['uuid' => $uuid])), TRUE);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSame('Notice', $read['label']);
    $this->assertSame('drupal_paragraph:text_block', $read['item']['type']);
    $this->assertSame(Paragraphs::reference($item->get('paragraphs')->entity), $read['item']['props']['paragraph']);

    // Updated from the copy on another page.
    $other = $this->createBuilderNode();
    $this->typeInParagraphForm($other, 'copy', 'Better notice', $read['item']['props']['paragraph']);
    $copy = self::paragraphItem('copy', $read['item']['props']['paragraph'] + ['draft' => 'k'], 'text_block', ['_library' => ['uuid' => $uuid, 'label' => 'Notice', 'overridden' => TRUE]]);
    [$status, $body] = $this->postJson($this->libraryUrl(), ['uuid' => $uuid, 'item' => $copy, 'node' => $other->id(), 'message' => 'Better wording']);
    $this->assertSame(200, $status);
    $this->assertSame($uuid, $body['uuid']);
    $this->assertSame('Better wording', $body['component']['info']['message']);
    $item = $this->loadLibraryItem($uuid);
    $this->assertGreaterThan($first_revision, $item->getRevisionId(), 'A new revision of the item.');
    $this->assertSame('Notice', $item->label());
    $this->assertSame('Better notice', $this->libraryText($uuid));
    $this->assertSame('Better wording', $item->getRevisionLogMessage());
    $old = \Drupal::entityTypeManager()->getStorage('paragraphs_library_item')->loadRevision($first_revision);
    $this->assertSame('Typed notice', self::paragraphText($old->get('paragraphs')->entity));

    // Deleting needs the permission to administer the library.
    [$status, $body] = $this->postJson($this->libraryUrl(), ['uuid' => $uuid, 'delete' => TRUE]);
    $this->assertSame(403, $status);
    $this->assertSame(['You may not delete items of the paragraphs library.'], $body['errors']);
    $this->assertNotNull($this->loadLibraryItem($uuid));
    $this->drupalLogin($this->drupalCreateUser(['use frame builder', 'administer paragraphs library']));
    [$status, $body] = $this->postJson($this->libraryUrl(), ['uuid' => $uuid, 'delete' => TRUE]);
    $this->assertSame(200, $status);
    $this->assertSame(['deleted' => TRUE], $body);
    $this->assertNull($this->loadLibraryItem($uuid));
  }

  /**
   * Saving and updating need the permissions of the Paragraphs Library; a
   * name and a filled-in paragraph are needed; other components go to the
   * builder's own library.
   */
  public function testPermissionsAndInput(): void {
    $node = $this->createBuilderNode();
    $this->typeInParagraphForm($node, 'b1', 'Typed notice');
    $block = self::paragraphItem('b1', ['draft' => 'k']);

    [$status, $body] = $this->postJson($this->libraryUrl(), ['label' => ' ', 'item' => $block, 'node' => $node->id()]);
    $this->assertSame(400, $status);
    $this->assertSame(['A name and a paragraph are needed.'], $body['errors']);
    [$status, $body] = $this->postJson($this->libraryUrl(), ['label' => 'Empty', 'item' => self::paragraphItem('never-filled'), 'node' => $node->id()]);
    $this->assertSame(422, $status);
    $this->assertSame(['The paragraph is empty: fill in its form first.'], $body['errors']);
    // Without the page the typed values are not known.
    [$status] = $this->postJson($this->libraryUrl(), ['label' => 'No page', 'item' => $block]);
    $this->assertSame(422, $status);
    $this->assertSame([], \Drupal::service('frame_builder.paragraphs')->libraryItems());

    [$status, $body] = $this->postJson($this->libraryUrl(), ['label' => 'Notice', 'item' => $block, 'node' => $node->id()]);
    $this->assertSame(200, $status);
    $uuid = $body['uuid'];
    [$status, $body] = $this->postJson($this->libraryUrl(), ['uuid' => $uuid, 'label' => 'Renamed']);
    $this->assertSame(400, $status, 'An update needs the paragraph.');

    // A user of the builder without the permissions of the library.
    $plain = $this->drupalCreateUser(['use frame builder', 'access content', 'edit any landing content']);
    $this->drupalLogin($plain);
    $this->typeInParagraphForm($node, 'b2', 'Typed by another');
    [$status, $body] = $this->postJson($this->libraryUrl(), ['label' => 'Mine', 'item' => self::paragraphItem('b2'), 'node' => $node->id()]);
    $this->assertSame(403, $status);
    $this->assertSame(['You may not change the paragraphs library.'], $body['errors']);
    $copy = self::paragraphItem('b2', Paragraphs::reference($this->loadLibraryItem($uuid)->get('paragraphs')->entity));
    [$status, $body] = $this->postJson($this->libraryUrl(), ['uuid' => $uuid, 'item' => $copy, 'node' => $node->id()]);
    $this->assertSame(403, $status);
    $this->assertSame('Typed notice', $this->libraryText($uuid));
    $this->assertCount(1, \Drupal::service('frame_builder.paragraphs')->libraryItems());
    $this->drupalGet($this->paragraphFormUrl($node, 'b2'));
    $this->assertSession()->fieldValueEquals(self::TEXT_FIELD . '[0][value]', 'Typed by another');
    // They may read it, to use it.
    $this->drupalGet($this->libraryUrl(['uuid' => $uuid]));
    $this->assertSession()->statusCodeEquals(200);

    // Another component is a saved block of the builder, for everyone with
    // the builder permission.
    [$status, $body] = $this->postJson($this->libraryUrl(), ['label' => 'Probe', 'item' => self::item('frame_builder_test_theme:probe', 'p1', ['title' => 'T'])]);
    $this->assertSame(200, $status);
    \Drupal::entityTypeManager()->getStorage('frame_saved')->resetCache();
    $this->assertNotNull(\Drupal::service('frame_builder.library')->load($body['uuid']));
    $this->assertNull($this->loadLibraryItem($body['uuid']));
  }

  /**
   * A page that links to an item of the library gets its own "From library"
   * paragraph on save and shows the item as it is saved now.
   */
  public function testPageUsesAnItem(): void {
    $node = $this->createBuilderNode();
    $this->typeInParagraphForm($node, 'b1', 'Notice of the library');
    [, $body] = $this->postJson($this->libraryUrl(), ['label' => 'Notice', 'item' => self::paragraphItem('b1'), 'node' => $node->id()]);
    $uuid = $body['uuid'];
    $this->assertContains('drupal_saved:' . $uuid, array_column($this->editorSettings($node)['components'], 'id'));
    $settings = $this->editorSettings($node);
    $hidden = array_column(array_filter($settings['components'], static fn (array $component): bool => !empty($component['hidden'])), 'id');
    $this->assertSame(['drupal_paragraph:' . Paragraphs::LIBRARY_TYPE], array_values($hidden), '"From library" is not a block to add by hand.');

    $save = Url::fromRoute('frame_builder.save', ['node' => $node->id()]);
    [$status, $saved] = $this->postJson($save, ['data' => self::layout(self::savedItem('s1', $uuid)), 'publish' => TRUE]);
    $this->assertSame(200, $status);
    $this->assertSame(['s1'], array_keys($saved['paragraphs']), 'The editor is told the paragraph of the page.');
    $wrapper = $this->reloadParagraph($saved['paragraphs']['s1']['id']);
    $this->assertSame(Paragraphs::LIBRARY_TYPE, $wrapper->bundle());
    $this->assertSame((string) $this->loadLibraryItem($uuid)->id(), (string) $wrapper->get(Paragraphs::LIBRARY_FIELD)->target_id);
    $this->assertSame((string) $node->id(), (string) $wrapper->get('parent_id')->value);
    $this->drupalGet($node->toUrl());
    $this->assertSession()->pageTextContains('Notice of the library');
    $this->assertSession()->elementExists('css', '.paragraph--type--from-library .paragraph--type--text-block');

    // Saved again with what the editor was told: the same paragraph.
    [$status, $again] = $this->postJson($save, ['data' => self::layout(self::savedItem('s1', $uuid, ['paragraph' => $saved['paragraphs']['s1']])), 'publish' => TRUE]);
    $this->assertSame(200, $status);
    $this->assertNull($again['paragraphs']);

    // The item is updated: the page follows without being saved.
    $own = Paragraphs::reference($this->loadLibraryItem($uuid)->get('paragraphs')->entity);
    $this->typeInParagraphForm($node, 'copy', 'Notice, reworded', $own);
    [$status] = $this->postJson($this->libraryUrl(), ['uuid' => $uuid, 'item' => self::paragraphItem('copy', $own), 'node' => $node->id()]);
    $this->assertSame(200, $status);
    $this->drupalGet($node->toUrl());
    $this->assertSession()->pageTextContains('Notice, reworded');
    $this->assertSession()->pageTextNotContains('Notice of the library');

    // "Edit a copy here", then saved: the page gets a paragraph of its own
    // and no longer follows the item.
    $read = json_decode($this->drupalGet($this->libraryUrl(['uuid' => $uuid])), TRUE);
    $now = $read['item']['props']['paragraph'];
    $this->assertSame($own['id'], $now['id']);
    $this->assertGreaterThan($own['vid'], $now['vid']);
    [$status, $copied] = $this->postJson($save, ['data' => self::layout(self::paragraphItem('c1', $now, 'text_block', ['_library' => ['uuid' => $uuid, 'label' => 'Notice']])), 'publish' => TRUE]);
    $this->assertSame(200, $status);
    $this->assertNotSame($own['id'], $copied['paragraphs']['c1']['id'], 'A page and the library never share a paragraph.');
    $this->assertSame('node', $this->reloadParagraph($copied['paragraphs']['c1']['id'])->get('parent_type')->value);
    $this->typeInParagraphForm($node, 'copy2', 'Third wording', $now);
    [$status] = $this->postJson($this->libraryUrl(), ['uuid' => $uuid, 'item' => self::paragraphItem('copy2', $now), 'node' => $node->id()]);
    $this->assertSame(200, $status);
    $this->assertSame('Third wording', $this->libraryText($uuid));
    $this->drupalGet($node->toUrl());
    $this->assertSession()->pageTextContains('Notice, reworded');
    $this->assertSession()->pageTextNotContains('Third wording');
  }

}
