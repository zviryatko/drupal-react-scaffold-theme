<?php

declare(strict_types=1);

namespace Drupal\Tests\frame_builder\Kernel;

use Drupal\Component\Serialization\Json;
use Drupal\frame_builder\Entity\SavedBlock;
use Drupal\frame_builder\LayoutMigrator;
use Drupal\frame_builder\LayoutReferences;
use Drupal\frame_builder\LayoutStorage;
use Drupal\frame_builder\Library;
use Drupal\Tests\frame_builder\Traits\ParagraphsTestTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The library of saved blocks: what a saved block consists of, its log, and
 * how pages use it, linked or as a copy.
 */
#[Group('frame_builder')]
#[RunTestsInSeparateProcesses]
class LibraryTest extends BuilderKernelTestBase {

  use ParagraphsTestTrait;

  /**
   * The component of the test theme.
   */
  protected const PROBE = 'frame_builder_test_theme:probe';

  /**
   * The service under test.
   */
  protected Library $library;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->library = $this->container->get('frame_builder.library');
  }

  /**
   * A probe with a title, as the editor sends it.
   */
  protected static function probe(string $id, string $title, array $props = []): array {
    return self::item(self::PROBE, $id, ['title' => $title, 'shown' => TRUE] + $props);
  }

  /**
   * A saved block is a name and a document with one item, in the format of
   * the layout of a page.
   */
  public function testCreate(): void {
    $saved = $this->library->create('Promo', self::probe('p1', 'Saved title', ['content' => [self::probe('p2', 'Inner')]]));
    $this->assertInstanceOf(SavedBlock::class, $saved);
    $this->assertFalse($saved->isNew());
    $this->assertSame('Promo', $saved->label());

    $data = Json::decode($saved->get(LayoutStorage::FIELD)->value);
    $this->assertSame(LayoutMigrator::VERSION, $data['version']);
    $this->assertCount(1, $data['content']);
    $this->assertSame(self::PROBE, $data['content'][0]['type']);
    $this->assertArrayHasKey('version', $data['content'][0], 'Items carry the version of their component, as on a page.');
    $this->assertSame('Inner', $data['content'][0]['props']['content'][0]['props']['title']);

    $item = $this->library->item($saved);
    $this->assertSame(self::PROBE, $item['type']);
    $this->assertSame('Saved title', $item['props']['title']);
    $this->assertSame('p2', $item['props']['content'][0]['props']['id']);
  }

  /**
   * In the library an item has no note where a copy came from and no link
   * to the saved block itself.
   */
  public function testItemIsCleaned(): void {
    $saved = $this->library->create('First', self::probe('p1', 'First'));
    $other = $this->library->create('Other', self::probe('o1', 'Other'));
    $uuid = $saved->uuid();

    $this->library->setItem($saved, self::probe('p1', 'Changed', [
      '_library' => ['uuid' => $uuid, 'label' => 'First', 'overridden' => TRUE],
      '_spacing' => ['padding' => ['size' => 'm']],
      'content' => [
        self::savedItem('self', $uuid),
        self::probe('p2', 'Inner', ['_library' => ['uuid' => $other->uuid()], 'content' => [self::savedItem('deep-self', $uuid), self::probe('p3', 'Deep')]]),
        self::savedItem('link', $other->uuid()),
      ],
    ]));
    $this->assertSame('First', $this->library->item($this->library->load($uuid))['props']['title'], 'setItem() does not save: the caller does.');
    $saved->save();

    $item = $this->library->item($this->library->load($uuid));
    $this->assertSame('Changed', $item['props']['title']);
    $this->assertArrayNotHasKey('_library', $item['props']);
    $this->assertSame(['padding' => ['size' => 'm']], $item['props']['_spacing'], 'The layout options of the block are kept.');
    $this->assertSame(['p2', 'link'], array_map(static fn (array $child): string => $child['props']['id'], $item['props']['content']), 'The link to the block itself is taken out, the link to another block stays.');
    $inner = $item['props']['content'][0];
    $this->assertArrayNotHasKey('_library', $inner['props']);
    $this->assertSame(['p3'], array_map(static fn (array $child): string => $child['props']['id'], $inner['props']['content']), 'However deep it is.');
    $this->assertSame('drupal_saved:' . $other->uuid(), $item['props']['content'][1]['type']);
  }

  /**
   * Saved blocks are listed by name and found by UUID.
   */
  public function testAllAndLoad(): void {
    $this->assertSame([], $this->library->all());
    $b = $this->library->create('Banner', self::probe('p', 'B'));
    $a = $this->library->create('Alert', self::probe('p', 'A'));
    $c = $this->library->create('Contact strip', self::probe('p', 'C'));

    $this->assertSame(['Alert', 'Banner', 'Contact strip'], array_values(array_map(static fn (SavedBlock $saved): string => (string) $saved->label(), $this->library->all())));
    $this->assertSame($b->id(), $this->library->load($b->uuid())->id());
    $this->assertNull($this->library->load(''));
    $this->assertNull($this->library->load('0b6f0000-0000-4000-8000-000000000000'));
    $this->assertNull($this->library->load((string) $a->id()), 'A saved block is named by UUID, not by id.');

    $c->delete();
    $this->assertNull($this->library->load($c->uuid()));
    $this->assertCount(2, $this->library->all());
  }

  /**
   * A saved block without a usable document has no item.
   */
  public function testItemOfABrokenBlock(): void {
    $saved = SavedBlock::create(['label' => 'Empty']);
    $saved->save();
    $this->assertNull($this->library->item($saved));
    foreach (['not json', '{"content": []}', '{"content": ["text"]}', '"text"'] as $value) {
      $saved->set(LayoutStorage::FIELD, $value);
      $this->assertNull($this->library->item($saved), $value);
    }
    $this->assertSame([], $this->container->get('frame_builder.renderer')->buildItem(self::savedItem('s1', $saved->uuid())));
    // A document saved before versions existed is read as the current one.
    $saved->set(LayoutStorage::FIELD, Json::encode(['content' => [['type' => self::PROBE, 'props' => ['id' => 'p', 'title' => 'Old']]]]));
    $this->assertSame('Old', $this->library->item($saved)['props']['title']);
  }

  /**
   * Every save is noted: who, when and with what message.
   */
  public function testRecordAndLog(): void {
    $author = $this->setUpCurrentUser(['name' => 'ann']);
    $saved = $this->library->create('Promo', self::probe('p', 'T'));
    $this->assertSame([], $this->library->log($saved));

    $this->library->record($saved, "  First save \n");
    $this->library->record($saved, '');
    $saved->save();
    $saved = $this->library->load($saved->uuid());
    $now = $this->container->get('datetime.time')->getRequestTime();
    $this->assertSame([
      ['time' => $now, 'uid' => (int) $author->id(), 'message' => 'First save'],
      ['time' => $now, 'uid' => (int) $author->id(), 'message' => ''],
    ], $this->library->log($saved));

    // Another user; a very long message is cut.
    $second = $this->setUpCurrentUser(['name' => 'bob']);
    $this->library->record($saved, str_repeat('é', 2500));
    $log = $this->library->log($saved);
    $this->assertCount(3, $log);
    $this->assertSame((int) $second->id(), $log[2]['uid']);
    $this->assertSame(2000, mb_strlen($log[2]['message']));

    // The log keeps the newest 50 entries.
    for ($i = 1; $i <= 60; $i++) {
      $this->library->record($saved, 'Save ' . $i);
    }
    $log = $this->library->log($saved);
    $this->assertCount(50, $log);
    $this->assertSame('Save 11', $log[0]['message']);
    $this->assertSame('Save 60', $log[49]['message']);

    // What is not a log is no log.
    $saved->set('log', 'not json');
    $this->assertSame([], $this->library->log($saved));
    $saved->set('log', Json::encode(['text', ['time' => 1, 'uid' => 1, 'message' => 'Kept']]));
    $this->assertSame([['time' => 1, 'uid' => 1, 'message' => 'Kept']], $this->library->log($saved));
  }

  /**
   * The editor is told when a saved block was last saved, by whom and why.
   */
  public function testInfo(): void {
    $format = fn (int $time): string => (string) $this->container->get('date.formatter')->format($time, 'short');
    $this->setUpCurrentUser(['name' => 'ann']);
    $saved = $this->library->create('Promo', self::probe('p', 'T'));
    // Saved before the log existed: the time the entity changed, nobody.
    $this->assertSame(['saved' => $format((int) $saved->getChangedTime()), 'author' => '', 'message' => '', 'revisions' => 0], $this->library->info($saved));

    $this->library->record($saved, 'Made it');
    $this->setUpCurrentUser(['name' => 'bob']);
    $this->library->record($saved, 'New wording');
    $info = $this->library->info($saved);
    $this->assertSame('bob', $info['author']);
    $this->assertSame('New wording', $info['message']);
    $this->assertSame(2, $info['revisions']);
    $this->assertSame($format($this->container->get('datetime.time')->getRequestTime()), $info['saved']);

    // A user who is gone leaves the rest.
    $saved->set('log', Json::encode([['time' => 86400 * 365, 'uid' => 9999, 'message' => 'By someone']]));
    $this->assertSame(['saved' => $format(86400 * 365), 'author' => '', 'message' => 'By someone', 'revisions' => 1], $this->library->info($saved));
  }

  /**
   * A linked block shows the saved block as it is saved now; a copy is the
   * page's own.
   */
  public function testLinkedAndCopy(): void {
    $layouts = $this->container->get('frame_builder.layouts');
    $renderer = $this->container->get('frame_builder.renderer');
    $saved = $this->library->create('Promo', self::probe('p1', 'Saved title'));
    $uuid = $saved->uuid();

    $linked = $this->createBuilderNode(['title' => 'Linked page']);
    $this->saveLayout($linked, self::layout(self::savedItem('s1', $uuid)));
    $copy = $this->createBuilderNode(['title' => 'Copy page']);
    $this->saveLayout($copy, self::layout(['props' => ['id' => 'c1', '_library' => ['uuid' => $uuid, 'label' => 'Promo']] + $this->library->item($saved)['props']] + $this->library->item($saved)));

    $this->assertSame([$uuid], LayoutReferences::find($layouts->load($linked))['saved']);
    $this->assertSame([], LayoutReferences::find($layouts->load($copy))['saved'], 'A copy does not link to the saved block.');
    $this->assertSame('drupal_saved:' . $uuid, $layouts->load($linked)['content'][0]['type']);
    $this->assertArrayNotHasKey('title', $layouts->load($linked)['content'][0]['props'], 'The page has the saved block, not its content.');
    $this->assertSame($uuid, $layouts->load($copy)['content'][0]['props']['_library']['uuid'], 'The copy remembers where it came from.');

    $render = fn ($node): string => $this->html($renderer->build($layouts->load($node), $node));
    $this->assertStringContainsString('Saved title', $render($linked));
    $this->assertStringContainsString('Saved title', $render($copy));

    // The saved block is updated: the linked page follows without a save.
    $this->library->setItem($saved, self::probe('p1', 'Updated title'));
    $saved->save();
    $html = $render($linked);
    $this->assertStringContainsString('Updated title', $html);
    $this->assertStringNotContainsString('Saved title', $html);
    $this->assertStringContainsString('Saved title', $render($copy), 'The copy no longer follows the saved block.');

    // Deleted: the linked page shows nothing in its place, the copy stays.
    $saved->delete();
    $this->assertSame([], $renderer->build($layouts->load($linked), $linked));
    $this->assertStringContainsString('Saved title', $render($copy));
  }

  /**
   * A saved block is rendered like the rest of the page, with its cache
   * tag, and in the context of the page it is on.
   */
  public function testRender(): void {
    $renderer = $this->container->get('frame_builder.renderer');
    $saved = $this->library->create('Promo', self::probe('p1', 'On [node:title]', ['tone' => 'loud', 'content' => [self::probe('p2', 'Inner title')]]));
    $node = $this->createBuilderNode(['title' => 'The first page']);

    $build = $renderer->buildItem(self::savedItem('s1', $saved->uuid()), $node);
    $this->assertSame(['frame_saved:' . $saved->id()], $build['#cache']['tags'], 'Pages that link to the block are invalidated with it.');
    $html = $this->html($build);
    $this->assertStringContainsString('On The first page', $html, 'Tokens show the values of the page the block is on.');
    $this->assertStringContainsString('probe--loud', $html);
    $this->assertMatchesRegularExpression('#probe__content.*Inner title#s', $html);
    $this->assertStringContainsString('On Another page', $this->html($renderer->buildItem(self::savedItem('s1', $saved->uuid()), $this->createBuilderNode(['title' => 'Another page']))));

    // The layout options of the place, per page.
    $build = $renderer->buildItem(self::savedItem('s1', $saved->uuid(), ['_spacing' => ['margin' => ['size' => 'l']]]), $node);
    $this->assertSame('container', $build['#type']);
    $this->assertStringContainsString('margin', $build['#attributes']['style']);
    $this->assertStringContainsString('2rem', $build['#attributes']['style']);
    $this->assertStringContainsString('On The first page', $this->html($build));

    $this->assertSame([], $renderer->buildItem(self::savedItem('s1', '0b6f0000-0000-4000-8000-000000000000'), $node), 'A saved block that is gone shows nothing.');
    $this->assertSame([], $renderer->buildItem(self::savedItem('s1', ''), $node));
  }

  /**
   * A saved block may contain other saved blocks; one that would contain
   * itself is shown once.
   */
  public function testNestedAndRecursive(): void {
    $renderer = $this->container->get('frame_builder.renderer');
    $inner = $this->library->create('Inner', self::probe('i1', 'Inner block'));
    $outer = $this->library->create('Outer', self::probe('o1', 'Outer block', ['content' => [self::savedItem('link', $inner->uuid())]]));

    $build = $renderer->buildItem(self::savedItem('s1', $outer->uuid()));
    $html = $this->html($build);
    $this->assertMatchesRegularExpression('#Outer block.*Inner block#s', $html);
    $this->assertEqualsCanonicalizing(['frame_saved:' . $inner->id(), 'frame_saved:' . $outer->id()], $this->renderedCacheTags($build, 'frame_saved:'));

    // The inner block now links back to the outer one.
    $this->library->setItem($inner, self::probe('i1', 'Inner block', ['content' => [self::savedItem('back', $outer->uuid())]]));
    $inner->save();
    $html = $this->html($renderer->buildItem(self::savedItem('s1', $outer->uuid())));
    $this->assertSame(1, substr_count($html, 'Outer block'));
    $this->assertSame(1, substr_count($html, 'Inner block'));
    // The same block twice on a page is not "inside itself".
    $html = $this->html($renderer->build(self::layout(self::savedItem('s1', $outer->uuid()), self::savedItem('s2', $outer->uuid()))));
    $this->assertSame(2, substr_count($html, 'Outer block'));
  }

  /**
   * The cache tags of a rendered build that start with a prefix.
   */
  protected function renderedCacheTags(array $build, string $prefix): array {
    $this->container->get('renderer')->renderInIsolation($build);
    return array_values(array_filter($build['#cache']['tags'] ?? [], static fn (string $tag): bool => str_starts_with($tag, $prefix)));
  }

  /**
   * Saved blocks are components of the Library group of the editor.
   */
  public function testCatalog(): void {
    $catalog = $this->container->get('frame_builder.catalog');
    $this->setUpCurrentUser(['name' => 'ann']);
    $saved = $this->library->create('Promo', self::probe('p1', 'Saved title'));
    $this->library->record($saved, 'Made it');
    $saved->save();
    $this->library->create('Alert', self::probe('p1', 'Alert'));

    $component = $catalog->savedComponent($saved);
    $this->assertSame('drupal_saved:' . $saved->uuid(), $component['id']);
    $this->assertSame('Promo', $component['label']);
    $this->assertSame('Library', $component['group']);
    $this->assertSame('library', $component['icon']);
    $this->assertSame($saved->uuid(), $component['saved']);
    $this->assertSame([], $component['slots'], 'What is in a linked block cannot be selected.');
    $this->assertSame('_library', $component['fields'][0]['name'], 'What the block is comes first: it has no fields of its own.');
    $this->assertSame('library', $component['fields'][0]['widget']);
    $this->assertContains('_spacing', array_column($component['fields'], 'name'), 'Spacing of a linked block is set where it is placed.');
    $this->assertSame(['author' => 'ann', 'message' => 'Made it', 'revisions' => 1], array_diff_key($component['info'], ['saved' => 1]));

    $config = $catalog->editorConfig($this->createBuilderNode());
    $ids = array_values(array_filter(array_column($config, 'id'), static fn (string $id): bool => str_starts_with($id, 'drupal_saved:')));
    $this->assertCount(2, $ids);
    $this->assertSame(['Alert', 'Promo'], array_map(static fn (string $id): string => self::component($config, $id)['label'], $ids), 'By name.');
    $listed = self::component($config, 'drupal_saved:' . $saved->uuid());
    $this->assertSame($component['info'], $listed['info']);
    $names = array_column($listed['fields'], 'name');
    $this->assertSame('_library', $names[0]);
    $this->assertCount(1, array_keys($names, '_library', TRUE), 'The Library box is there once.');
    $this->assertFalse(self::componentField($listed, '_library')['offer'], 'A saved block is not saved to the library again.');
    $this->assertContains('_spacing', $names);
  }

  /**
   * Without the Paragraphs module there are no Paragraph blocks, and what a
   * layout says about paragraphs is ignored.
   */
  public function testWithoutParagraphs(): void {
    $paragraphs = $this->container->get('frame_builder.paragraphs');
    $node = $this->createBuilderNode();
    $this->assertFalse($paragraphs->available());
    $this->assertFalse($paragraphs->libraryAvailable());
    $this->assertSame([], $paragraphs->types());
    $this->assertSame([], $paragraphs->libraryItems());
    $this->assertNull($paragraphs->libraryItem($this->library->create('Promo', self::probe('p', 'T'))->uuid()));
    $this->assertFalse($node->hasField('frame_paragraphs'));

    $reference = ['id' => 1, 'vid' => 1, 'uuid' => '0b6f0000-0000-4000-8000-000000000000'];
    $this->assertNull($paragraphs->load($reference, 'text_block'));
    $data = self::layout(self::paragraphItem('b1', $reference + ['draft' => 'k']));
    $this->assertSame([], $paragraphs->fieldValues($data));
    $this->container->get('frame_builder.paragraph_drafts')->set($node, 'b1', ['field_text' => 'Typed']);
    $before = $data;
    $saved = [];
    $this->assertSame([], $paragraphs->persist($data, $node, $saved));
    $this->assertSame($before, $data);
    $this->assertSame([], $saved);
    $item = $data['content'][0];
    $this->assertSame([], $paragraphs->persistForLibrary($item, $this->library->create('Other', $item), $node));
    $paragraphs->showDrafts();
    $this->assertNull($paragraphs->forDisplay($item['props'], 'text_block', $node));
    $this->assertSame([], $this->container->get('frame_builder.renderer')->buildItem($item, $node));

    $config = $this->container->get('frame_builder.catalog')->editorConfig($node);
    $this->assertSame([], array_filter(array_column($config, 'id'), static fn (string $id): bool => str_starts_with($id, 'drupal_paragraph:')));
    // The layout is stored as it is: the block comes back when the module does.
    $this->saveLayout($node, $data);
    $this->assertSame('drupal_paragraph:text_block', $this->container->get('frame_builder.layouts')->load($node)['content'][0]['type']);
  }

}
