<?php

declare(strict_types=1);

namespace Drupal\Tests\frame_builder\Kernel;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\frame_builder\Paragraphs;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * With the Paragraphs Library module a Paragraph block is saved as an item
 * of that library, and a page that uses it gets a "From library" paragraph.
 */
#[Group('frame_builder')]
#[RunTestsInSeparateProcesses]
class ParagraphsLibraryTest extends ParagraphsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['views', 'entity_usage', 'paragraphs_library'];

  /**
   * The service under test.
   */
  protected Paragraphs $paragraphs;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $this->requireModules('entity_usage', 'paragraphs_library');
    parent::setUp();
    $this->installEntitySchema('paragraphs_library_item');
    $this->installSchema('entity_usage', ['entity_usage']);
    $this->installConfig(['entity_usage']);
    $this->createFromLibraryType();
    $this->paragraphs = $this->container->get('frame_builder.paragraphs');
  }

  /**
   * Creates an item of the library with a paragraph of its own.
   */
  protected function createLibraryItem(string $label, string $text): ContentEntityInterface {
    $item = $this->container->get('entity_type.manager')->getStorage('paragraphs_library_item')->create([
      'label' => $label,
      'paragraphs' => $this->container->get('entity_type.manager')->getStorage('paragraph')->create(['type' => 'text_block', self::TEXT_FIELD => $text]),
    ]);
    $item->save();
    return $item;
  }

  /**
   * An item of the library as it is stored now.
   */
  protected function reloadLibraryItem(ContentEntityInterface $item): ContentEntityInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('paragraphs_library_item');
    $storage->resetCache();
    $this->container->get('entity_type.manager')->getStorage('paragraph')->resetCache();
    return $storage->load($item->id());
  }

  /**
   * Saves a block to the library and expects that to work.
   */
  protected function saveToLibrary(?ContentEntityInterface $item, string $label, array $block, $node, string $message = ''): ContentEntityInterface {
    $errors = [];
    $item = $this->paragraphs->saveToLibrary($item, $label, $block, $node, $message, $errors);
    $this->assertSame([], $errors);
    $this->assertNotNull($item);
    return $this->reloadLibraryItem($item);
  }

  /**
   * The library is there with the module and its "From library" type.
   */
  public function testLibraryAvailable(): void {
    $this->assertTrue($this->paragraphs->libraryAvailable());
    $item = $this->createLibraryItem('Notice', 'Text');
    $this->assertNotNull($this->paragraphs->libraryItem($item->uuid()));

    // A site that removed the type: paragraphs go to the builder's own library.
    $this->container->get('entity_type.manager')->getStorage('paragraphs_type')->load(Paragraphs::LIBRARY_TYPE)->delete();
    $this->assertFalse($this->paragraphs->libraryAvailable());
    $this->assertSame([], $this->paragraphs->libraryItems());
    $this->assertNull($this->paragraphs->libraryItem($item->uuid()));
    $this->assertTrue($this->paragraphs->available());
  }

  /**
   * Items are listed by name, found by UUID, and give their paragraph.
   */
  public function testLibraryItems(): void {
    $this->assertSame([], $this->paragraphs->libraryItems());
    $notice = $this->createLibraryItem('Notice', 'Notice text');
    $contact = $this->createLibraryItem('Contact strip', 'Contact text');
    $saved_block = $this->container->get('frame_builder.library')->create('Promo', self::item('frame_builder_test_theme:probe', 'p'));

    $this->assertSame(['Contact strip', 'Notice'], array_values(array_map(static fn ($item): string => (string) $item->label(), $this->paragraphs->libraryItems())));
    $this->assertSame($notice->id(), $this->paragraphs->libraryItem($notice->uuid())->id());
    $this->assertNull($this->paragraphs->libraryItem(''));
    $this->assertNull($this->paragraphs->libraryItem('0b6f0000-0000-4000-8000-000000000000'));
    $this->assertNull($this->paragraphs->libraryItem($saved_block->uuid()), 'A saved block of the builder is not an item of this library.');
    $this->assertNull($this->paragraphs->libraryItem((string) $contact->id()));

    $paragraph = $this->paragraphs->libraryParagraph($contact);
    $this->assertSame('text_block', $paragraph->bundle());
    $this->assertSame('Contact text', self::paragraphText($paragraph));
    $empty = $this->container->get('entity_type.manager')->getStorage('paragraphs_library_item')->create(['label' => 'Empty']);
    $this->assertNull($this->paragraphs->libraryParagraph($empty));
  }

  /**
   * "Save to library" makes an item with a paragraph of its own that has
   * what was typed in the form of the block.
   */
  public function testSaveToLibrary(): void {
    $author = $this->setUpCurrentUser(['name' => 'ann'], ['administer paragraphs library']);
    $node = $this->createBuilderNode();
    $of_page = $this->createParagraph('Saved on the page', $node);
    $drafts = $this->container->get('frame_builder.paragraph_drafts');
    $drafts->set($node, 'b1', [self::TEXT_FIELD => 'Typed on the page']);

    $item = $this->saveToLibrary(NULL, 'Notice', self::paragraphItem('b1', $of_page), $node, 'First version');
    $this->assertSame('Notice', $item->label());
    $this->assertSame('First version', $item->getRevisionLogMessage());
    $this->assertSame($author->id(), $item->getRevisionUserId());
    $paragraph = $this->paragraphs->libraryParagraph($item);
    $this->assertSame('Typed on the page', self::paragraphText($paragraph));
    $this->assertNotSame($of_page->id(), $paragraph->id(), 'The library gets a paragraph of its own.');
    $this->assertSame('paragraphs_library_item', $paragraph->get('parent_type')->value);
    $this->assertSame((string) $item->id(), (string) $paragraph->get('parent_id')->value);
    $this->assertSame('Saved on the page', self::paragraphText($this->reloadParagraph($of_page)), 'The paragraph of the page is not written to.');
    $this->assertNull($drafts->get($node, 'b1'), 'What went to the library is no longer unsaved on the page.');
    $this->assertSame($item->id(), $this->paragraphs->libraryItem($item->uuid())->id());

    // A block of a page that was never saved: only typed values.
    $drafts->set($node, 'new', [self::TEXT_FIELD => 'Only typed']);
    $typed = $this->saveToLibrary(NULL, 'Typed', self::paragraphItem('new', ['draft' => 'k']), $node);
    $this->assertSame('Only typed', self::paragraphText($this->paragraphs->libraryParagraph($typed)));

    // Without a page (and so without typed values): a copy of the paragraph.
    $copied = $this->saveToLibrary(NULL, 'Copied', self::paragraphItem('b1', $of_page), NULL);
    $this->assertSame('Saved on the page', self::paragraphText($this->paragraphs->libraryParagraph($copied)));
    $this->assertNotSame($of_page->id(), $this->paragraphs->libraryParagraph($copied)->id());
    $this->assertCount(3, $this->paragraphs->libraryItems());
  }

  /**
   * "Update the saved block" is a new revision of the item, with its log
   * message; an empty name keeps the name.
   */
  public function testUpdateLibraryItem(): void {
    $this->setUpCurrentUser(['name' => 'ann'], ['administer paragraphs library']);
    $node = $this->createBuilderNode();
    $drafts = $this->container->get('frame_builder.paragraph_drafts');
    $item = $this->createLibraryItem('Notice', 'First text');
    $first_revision = $item->getRevisionId();
    $own = $this->paragraphs->libraryParagraph($item);

    // A copy on a page names the paragraph of the item; the user changes it.
    $drafts->set($node, 'copy', [self::TEXT_FIELD => 'Second text']);
    $updated = $this->saveToLibrary($item, '', self::paragraphItem('copy', $own), $node, 'Better wording');
    $this->assertSame($item->id(), $updated->id());
    $this->assertGreaterThan($first_revision, $updated->getRevisionId());
    $this->assertSame('Notice', $updated->label(), 'An empty name keeps the name.');
    $this->assertSame('Better wording', $updated->getRevisionLogMessage());
    $paragraph = $this->paragraphs->libraryParagraph($updated);
    $this->assertSame($own->id(), $paragraph->id(), 'The item keeps its paragraph');
    $this->assertGreaterThan($own->getRevisionId(), $paragraph->getRevisionId(), 'in a new revision.');
    $this->assertSame('Second text', self::paragraphText($paragraph));
    $this->assertNull($drafts->get($node, 'copy'));

    $storage = $this->container->get('entity_type.manager')->getStorage('paragraphs_library_item');
    $this->assertSame('First text', self::paragraphText($this->paragraphs->libraryParagraph($storage->loadRevision($first_revision))), 'The older revision of the item has the paragraph as it was.');

    // Renamed, from a copy that the page saved as its own paragraph.
    $of_page = $this->createParagraph('Third text', $node);
    $renamed = $this->saveToLibrary($updated, 'Important notice', self::paragraphItem('copy', $of_page), $node);
    $this->assertSame('Important notice', $renamed->label());
    $this->assertSame('', (string) $renamed->getRevisionLogMessage());
    $this->assertSame('Third text', self::paragraphText($this->paragraphs->libraryParagraph($renamed)));
    $this->assertNotSame($of_page->id(), $this->paragraphs->libraryParagraph($renamed)->id(), 'A page and the library never share a paragraph.');
    $this->assertCount(1, $this->paragraphs->libraryItems());
  }

  /**
   * Only a filled-in, valid paragraph goes to the library.
   */
  public function testSaveToLibraryErrors(): void {
    $this->createParagraphType('required_block', 'Required block', TRUE);
    $node = $this->createBuilderNode();
    $item = $this->createLibraryItem('Notice', 'Text');
    $drafts = $this->container->get('frame_builder.paragraph_drafts');
    $drafts->set($node, 'bad', [self::TEXT_FIELD => []]);

    $cases = [
      'Only a paragraph can be saved to the library.' => [
        self::item('frame_builder_test_theme:probe', 'p'),
        self::paragraphItem('b1', NULL, 'missing_type'),
        // An item of the library is not put into the library again.
        self::paragraphItem('b1', NULL, Paragraphs::LIBRARY_TYPE),
        self::savedItem('s1', $item->uuid()),
        [],
      ],
      'The paragraph is empty: fill in its form first.' => [
        self::paragraphItem('b1'),
        self::paragraphItem('b1', ['id' => 9999, 'vid' => 9999, 'uuid' => 'gone']),
      ],
    ];
    foreach ($cases as $message => $blocks) {
      foreach ($blocks as $block) {
        $errors = [];
        $this->assertNull($this->paragraphs->saveToLibrary(NULL, 'Name', $block, $node, '', $errors));
        $this->assertSame([$message], $errors);
      }
    }
    $errors = [];
    $this->assertNull($this->paragraphs->saveToLibrary(NULL, 'Name', self::paragraphItem('bad', NULL, 'required_block'), $node, '', $errors));
    $this->assertCount(1, $errors);
    $this->assertStringStartsWith('Required block: ', $errors[0]);
    $this->assertNotNull($drafts->get($node, 'bad'), 'What was typed stays, to be corrected.');

    $this->assertCount(1, $this->paragraphs->libraryItems(), 'Nothing was added to the library.');
    $this->assertSame('Text', self::paragraphText($this->paragraphs->libraryParagraph($this->reloadLibraryItem($item))));
  }

  /**
   * A page that links to an item of the library gets a "From library"
   * paragraph of its own when it is saved.
   */
  public function testPersistMakesTheWrapper(): void {
    $node = $this->createBuilderNode();
    $notice = $this->createLibraryItem('Notice', 'Notice text');
    $other = $this->createLibraryItem('Other', 'Other text');
    $data = self::layout(self::savedItem('s1', $notice->uuid()), self::item('frame_builder_test_theme:probe', 'p', ['content' => [self::savedItem('s2', $other->uuid())]]));
    $saved = [];
    $this->assertSame([], $this->paragraphs->persist($data, $node, $saved));
    $this->assertEqualsCanonicalizing(['s1', 's2'], array_keys($saved));
    $this->assertSame($saved['s1'], $data['content'][0]['props']['paragraph'], 'The layout names the "From library" paragraph of the page.');
    $this->assertSame($saved['s2'], $data['content'][1]['props']['content'][0]['props']['paragraph']);

    $wrapper = $this->reloadParagraph($saved['s1']['id']);
    $this->assertSame(Paragraphs::LIBRARY_TYPE, $wrapper->bundle());
    $this->assertSame((string) $notice->id(), (string) $wrapper->get(Paragraphs::LIBRARY_FIELD)->target_id);
    $this->assertSame('node', $wrapper->get('parent_type')->value);
    $this->assertSame((string) $node->id(), (string) $wrapper->get('parent_id')->value);
    $this->saveLayout($node, $data);
    $this->assertEqualsCanonicalizing([$saved['s1']['id'], $saved['s2']['id']], array_map('intval', array_column($node->get(Paragraphs::FIELD)->getValue(), 'target_id')), 'The page lists its "From library" paragraphs.');

    // Saved again: the page has its paragraphs already.
    $again = [];
    $this->assertSame([], $this->paragraphs->persist($data, $node, $again));
    $this->assertSame([], $again);

    // The block now links to another item; a duplicated block; a paragraph
    // of another page.
    $other_page = $this->createBuilderNode();
    $foreign = [];
    $foreign_data = self::layout(self::savedItem('f1', $notice->uuid()));
    $this->paragraphs->persist($foreign_data, $other_page, $foreign);
    $data = self::layout(
      self::savedItem('s1', $other->uuid(), ['paragraph' => $saved['s1']]),
      self::savedItem('s2', $other->uuid(), ['paragraph' => $saved['s2']]),
      self::savedItem('s3', $other->uuid(), ['paragraph' => $saved['s2']]),
      self::savedItem('s4', $notice->uuid(), ['paragraph' => $foreign['f1']]),
    );
    $changed = [];
    $this->assertSame([], $this->paragraphs->persist($data, $node, $changed));
    $this->assertEqualsCanonicalizing(['s1', 's3', 's4'], array_keys($changed));
    $this->assertSame((string) $other->id(), (string) $this->reloadParagraph($changed['s1']['id'])->get(Paragraphs::LIBRARY_FIELD)->target_id);
    $this->assertNotSame($saved['s2']['id'], $changed['s3']['id']);
    $this->assertNotSame($foreign['f1']['id'], $changed['s4']['id']);
    $this->assertSame((string) $other_page->id(), (string) $this->reloadParagraph($foreign['f1']['id'])->get('parent_id')->value);

    // A saved block of the builder's own gets no such paragraph.
    $own = $this->container->get('frame_builder.library')->create('Promo', self::item('frame_builder_test_theme:probe', 'p'));
    $data = self::layout(self::savedItem('s9', $own->uuid()));
    $none = [];
    $this->assertSame([], $this->paragraphs->persist($data, $node, $none));
    $this->assertSame([], $none);
    $this->assertArrayNotHasKey('paragraph', $data['content'][0]['props']);
  }

  /**
   * The "From library" paragraph shown for a linked block is the page's own
   * once it has one.
   */
  public function testWrapperForDisplay(): void {
    $node = $this->createBuilderNode();
    $notice = $this->createLibraryItem('Notice', 'Notice text');
    $other = $this->createLibraryItem('Other', 'Other text');

    $wrapper = $this->paragraphs->wrapperForDisplay(['id' => 's1'], $notice);
    $this->assertTrue($wrapper->isNew(), 'Before the page is saved: a new one, not saved.');
    $this->assertSame(Paragraphs::LIBRARY_TYPE, $wrapper->bundle());
    $this->assertSame((string) $notice->id(), (string) $wrapper->get(Paragraphs::LIBRARY_FIELD)->target_id);

    $data = self::layout(self::savedItem('s1', $notice->uuid()));
    $saved = [];
    $this->paragraphs->persist($data, $node, $saved);
    $own = $this->paragraphs->wrapperForDisplay($data['content'][0]['props'], $notice);
    $this->assertFalse($own->isNew());
    $this->assertSame($saved['s1']['id'], (int) $own->id());

    $this->assertTrue($this->paragraphs->wrapperForDisplay($data['content'][0]['props'], $other)->isNew(), 'A paragraph that names another item is not used.');
    $text = $this->createParagraph('Not a wrapper', $node);
    $this->assertTrue($this->paragraphs->wrapperForDisplay(['id' => 's1', 'paragraph' => Paragraphs::reference($text)], $notice)->isNew());
    $this->assertSame(1, (int) $this->container->get('entity_type.manager')->getStorage('paragraph')->getQuery()->accessCheck(FALSE)->condition('type', Paragraphs::LIBRARY_TYPE)->count()->execute(), 'Showing saves nothing: only the paragraph the page saved exists.');
  }

  /**
   * A linked item of the library is shown as it is saved now, to those who
   * may see it.
   */
  public function testRender(): void {
    $renderer = $this->container->get('frame_builder.renderer');
    $node = $this->createBuilderNode();
    $notice = $this->createLibraryItem('Notice', 'Notice text');
    $block = self::savedItem('s1', $notice->uuid());

    $build = $renderer->buildItem($block, $node);
    $this->assertSame($notice->getCacheTags(), $build['#cache']['tags'], 'Pages that link to the item are invalidated with it.');
    $html = $this->html($build);
    $this->assertStringContainsString('Notice text', $html);
    $this->assertStringContainsString('paragraph--type--from-library', $html, 'Shown by a "From library" paragraph, as the module shows it.');
    $this->assertSame(0, (int) $this->container->get('entity_type.manager')->getStorage('paragraph')->getQuery()->accessCheck(FALSE)->condition('type', Paragraphs::LIBRARY_TYPE)->count()->execute(), 'Rendering saves nothing.');

    // After the page was saved the page's own paragraph shows it.
    $data = self::layout($block);
    $saved = [];
    $this->paragraphs->persist($data, $node, $saved);
    $this->assertStringContainsString('Notice text', $this->html($renderer->buildItem($data['content'][0], $node)));

    // The item is updated: the page follows without being saved.
    $this->container->get('frame_builder.paragraph_drafts')->set($node, 'copy', [self::TEXT_FIELD => 'Updated notice']);
    $this->saveToLibrary($notice, '', self::paragraphItem('copy', $this->paragraphs->libraryParagraph($notice)), $node);
    $html = $this->html($renderer->buildItem($data['content'][0], $node));
    $this->assertStringContainsString('Updated notice', $html);
    $this->assertStringNotContainsString('Notice text', $html);

    // With layout options of the place.
    $build = $renderer->buildItem(self::savedItem('s1', $notice->uuid(), ['_spacing' => ['margin' => ['size' => 'l']]]), $node);
    $this->assertSame('container', $build['#type']);
    $this->assertStringContainsString('Updated notice', $this->html($build));

    // An unpublished item is not shown to those who may not see it.
    $notice = $this->reloadLibraryItem($notice);
    $notice->setUnpublished()->save();
    $this->assertStringContainsString('Updated notice', $this->html($renderer->buildItem($block, $node)));
    $this->setUpCurrentUser([], ['access content']);
    $this->assertSame([], $renderer->buildItem($block, $node));
  }

  /**
   * Items of the library are components of the Library group; the "From
   * library" type is not a block to add by hand.
   */
  public function testCatalog(): void {
    $this->setUpCurrentUser(['name' => 'ann'], ['administer paragraphs library']);
    $catalog = $this->container->get('frame_builder.catalog');
    $node = $this->createBuilderNode();
    $this->container->get('frame_builder.paragraph_drafts')->set($node, 'b1', [self::TEXT_FIELD => 'Text']);
    $item = $this->saveToLibrary(NULL, 'Notice', self::paragraphItem('b1'), $node, 'First version');
    $made_in_drupal = $this->createLibraryItem('Contact strip', 'Text');
    $saved_block = $this->container->get('frame_builder.library')->create('Promo', self::item('frame_builder_test_theme:probe', 'p'));

    $component = $catalog->libraryComponent($item);
    $this->assertSame('drupal_saved:' . $item->uuid(), $component['id']);
    $this->assertSame('Notice', $component['label']);
    $this->assertSame('Library', $component['group']);
    $this->assertSame($item->uuid(), $component['saved']);
    $this->assertSame([], $component['slots']);
    $this->assertSame('_library', $component['fields'][0]['name']);
    $this->assertSame([
      'type' => 'Text block',
      'saved' => (string) $this->container->get('date.formatter')->format((int) $item->getChangedTime(), 'short'),
      'author' => 'ann',
      'message' => 'First version',
    ], $component['info']);

    $config = $catalog->editorConfig($node);
    foreach ([$item, $made_in_drupal, $saved_block] as $entity) {
      $listed = self::component($config, 'drupal_saved:' . $entity->uuid());
      $this->assertNotNull($listed, (string) $entity->label());
      $this->assertSame('Library', $listed['group']);
      $this->assertCount(1, array_keys(array_column($listed['fields'], 'name'), '_library', TRUE));
      $this->assertFalse(self::componentField($listed, '_library')['offer']);
    }
    $this->assertSame('Text block', self::component($config, 'drupal_saved:' . $made_in_drupal->uuid())['info']['type'], 'Items made in Content > Paragraphs are offered like the ones saved in the builder.');

    $from_library = self::component($config, 'drupal_paragraph:' . Paragraphs::LIBRARY_TYPE);
    $this->assertTrue($from_library['hidden'], 'Made by the builder itself: not in the Blocks panel, but blocks pages have keep working.');
    $this->assertFalse(self::componentField($from_library, '_library')['offer']);
    $text_block = self::component($config, 'drupal_paragraph:text_block');
    $this->assertArrayNotHasKey('hidden', $text_block);
    $this->assertTrue(self::componentField($text_block, '_library')['offer']);
  }

}
