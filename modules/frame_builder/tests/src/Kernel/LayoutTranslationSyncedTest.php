<?php

declare(strict_types=1);

namespace Drupal\Tests\frame_builder\Kernel;

use Drupal\frame_builder\LayoutMigrator;
use Drupal\frame_builder\LayoutStorage;
use Drupal\node\NodeInterface;
use Drupal\Tests\frame_builder\Traits\LayoutTranslationTestTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Layouts of translations in synced mode: the structure of the original
 * language, the texts and links of the translation.
 */
#[Group('frame_builder')]
#[RunTestsInSeparateProcesses]
class LayoutTranslationSyncedTest extends BuilderKernelTestBase {

  use LayoutTranslationTestTrait;

  protected const CARD = 'frame_builder_test_theme:mig-card';
  protected const NOTE = 'frame_builder_test_theme:mig-note';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['language', 'content_translation'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->setUpTranslation('synced');
  }

  /**
   * The service under test.
   */
  protected function layouts(): LayoutStorage {
    return $this->container->get('frame_builder.layouts');
  }

  /**
   * The layout of the original language the tests start with.
   */
  protected static function original(): array {
    return self::layout(
      self::item('frame:heading', 'h1', ['text' => 'Welcome', 'eyebrow' => 'News', 'level' => 'h2']),
      self::item('frame:columns', 'cols', [
        'layout' => '2',
        'content' => [
          self::item('frame:button', 'b1', ['label' => 'Read more', 'url' => '/en/more', 'variant' => 'primary']),
          self::item('frame:text', 't1', ['text' => ['format' => 'basic_html', 'value' => '<p>Hello</p>']]),
        ],
      ]),
    );
  }

  /**
   * A page with the original layout and a saved German translation of it.
   *
   * @return \Drupal\node\NodeInterface[]
   *   The original and the translation.
   */
  protected function translatedPage(?array $layout = NULL): array {
    $node = $this->saveLayout($this->createBuilderNode(), $layout ?? self::original());
    $translation = $this->translate($node);
    $translation->save();
    return [$node, $translation];
  }

  /**
   * Stores the layout the editor sends for the translation and saves.
   */
  protected function saveTranslated(NodeInterface $translation, array $data, ?NodeInterface $source = NULL): NodeInterface {
    $this->layouts()->store($translation, $data, $source);
    $translation->save();
    return $translation;
  }

  /**
   * Items of a layout by id, at any depth.
   */
  protected static function byId(array $items, array &$found = []): array {
    foreach ($items as $item) {
      $found[$item['props']['id']] = $item;
      foreach ($item['props'] as $value) {
        if (LayoutMigrator::isItems($value)) {
          self::byId($value, $found);
        }
      }
    }
    return $found;
  }

  /**
   * Only a translation of a synced content type is a synced translation.
   */
  public function testIsSyncedTranslation(): void {
    [$node, $translation] = $this->translatedPage();
    $this->assertSame('synced', $this->layouts()->mode($translation));
    $this->assertFalse($this->layouts()->isSyncedTranslation($node));
    $this->assertTrue($this->layouts()->isSyncedTranslation($translation));
    $this->assertFalse($this->layouts()->isSyncedTranslation($translation->getUntranslated()));
  }

  /**
   * A translation that changed nothing shows the layout of the original,
   * whether it has the copy Drupal made or no value at all.
   */
  public function testNewTranslationShowsTheOriginal(): void {
    [$node, $translation] = $this->translatedPage();
    $this->assertSame($node->get('frame_layout')->value, $translation->get('frame_layout')->value, 'Drupal copied the document of the original.');
    $expected = $this->layouts()->load($node);
    $this->assertSame('Welcome', $expected['content'][0]['props']['text']);
    $this->assertSame($expected, $this->layouts()->load($this->reload($node, 'de')));

    $translation->set('frame_layout', NULL)->save();
    $this->assertSame($expected, $this->layouts()->load($this->reload($node, 'de')));
  }

  /**
   * A translation stores only the texts and links it changes, with the
   * component versions they were saved for, and shows them in the structure
   * of the original.
   */
  public function testOnlyChangedStringsAreStored(): void {
    [$node, $translation] = $this->translatedPage();
    $submitted = self::layout(
      // The text is translated, the eyebrow is sent back as it is, and the
      // level is not a text.
      self::item('frame:heading', 'h1', ['text' => 'Willkommen', 'eyebrow' => 'News', 'level' => 'h4']),
      self::item('frame:columns', 'cols', [
        'layout' => '3',
        'content' => [
          self::item('frame:button', 'b1', ['label' => 'Weiterlesen', 'url' => '/de/mehr', 'variant' => 'outline']),
          self::item('frame:text', 't1', ['text' => ['format' => 'basic_html', 'value' => '<p>Hallo</p>']]),
        ],
      ]),
    );
    $this->saveTranslated($translation, $submitted);

    $this->assertSame([
      'version' => LayoutMigrator::VERSION,
      'strings' => [
        'h1.text' => 'Willkommen',
        'b1.label' => 'Weiterlesen',
        'b1.url' => '/de/mehr',
        't1.text' => ['format' => 'basic_html', 'value' => '<p>Hallo</p>'],
      ],
      'versions' => ['h1' => 1, 'b1' => 1, 't1' => 1],
    ], $this->stored($this->reload($node, 'de')));

    $loaded = $this->layouts()->load($this->reload($node, 'de'));
    $this->assertSame(LayoutMigrator::VERSION, $loaded['version']);
    $this->assertSame(['props' => []], $loaded['root']);
    $items = self::byId($loaded['content']);
    $this->assertSame(['h1', 'cols', 'b1', 't1'], array_keys($items));
    $this->assertSame(['id' => 'h1', 'text' => 'Willkommen', 'eyebrow' => 'News', 'level' => 'h2'], $items['h1']['props']);
    $this->assertSame('2', $items['cols']['props']['layout'], 'What is not text or a link stays as on the original.');
    $this->assertSame(['id' => 'b1', 'label' => 'Weiterlesen', 'url' => '/de/mehr', 'variant' => 'primary'], $items['b1']['props']);
    $this->assertSame(['format' => 'basic_html', 'value' => '<p>Hallo</p>'], $items['t1']['props']['text']);

    $original = self::byId($this->layouts()->load($this->reload($node))['content']);
    $this->assertSame('Welcome', $original['h1']['props']['text'], 'The original is not touched.');
    $this->assertSame('/en/more', $original['b1']['props']['url']);
    $this->assertArrayNotHasKey('strings', $this->stored($this->reload($node)));
  }

  /**
   * A translation cannot change the structure, whatever is sent for it:
   * added, removed, moved and retyped items are ignored.
   */
  public function testStructureOfTheTranslationIsIgnored(): void {
    [$node, $translation] = $this->translatedPage();
    $this->saveTranslated($translation, self::layout(
      self::item('frame:heading', 'added', ['text' => 'Neu']),
      // Moved out of the columns, with another type.
      self::item('frame:heading', 'b1', ['label' => 'Weiterlesen', 'text' => 'Kein Knopf']),
      self::item('frame:heading', 'h1', ['text' => 'Willkommen']),
    ));

    $stored = $this->stored($this->reload($node, 'de'));
    $this->assertSame(['h1.text' => 'Willkommen', 'b1.label' => 'Weiterlesen'], $stored['strings']);
    $this->assertArrayNotHasKey('content', $stored);

    $items = self::byId($this->layouts()->load($this->reload($node, 'de'))['content']);
    $this->assertSame(['h1', 'cols', 'b1', 't1'], array_keys($items));
    $this->assertSame('frame:button', $items['b1']['type']);
    $this->assertSame('Weiterlesen', $items['b1']['props']['label']);
    $this->assertSame('/en/more', $items['b1']['props']['url'], 'A prop that was not sent keeps the value of the original.');
    $this->assertArrayNotHasKey('text', $items['b1']['props']);
    $this->assertSame(['format' => 'basic_html', 'value' => '<p>Hello</p>'], $items['t1']['props']['text'], 'An item that was not sent keeps the texts of the original.');
  }

  /**
   * A value equal to the original is not a translation: it is not stored,
   * and one that goes back to the original is dropped.
   */
  public function testValueEqualToTheOriginalIsNotStored(): void {
    [$node, $translation] = $this->translatedPage();
    $this->saveTranslated($translation, self::original());
    $this->assertSame(['version' => LayoutMigrator::VERSION, 'strings' => [], 'versions' => []], $this->stored($this->reload($node, 'de')));

    $changed = self::original();
    $changed['content'][0]['props']['text'] = 'Willkommen';
    $translation = $this->saveTranslated($this->reload($node, 'de'), $changed);
    $this->assertSame(['h1.text' => 'Willkommen'], $this->stored($translation)['strings']);

    $translation = $this->saveTranslated($this->reload($node, 'de'), self::original());
    $this->assertSame([], $this->stored($translation)['strings']);
    $this->assertSame('Welcome', $this->layouts()->load($this->reload($node, 'de'))['content'][0]['props']['text']);
  }

  /**
   * A text emptied on the translation stays empty there; a text the
   * original does not have can be given.
   */
  public function testEmptiedAndAddedTexts(): void {
    [$node, $translation] = $this->translatedPage(self::layout(
      self::item('frame:heading', 'h1', ['text' => 'Welcome', 'eyebrow' => 'News']),
      self::item('frame:heading', 'h2', ['text' => 'Second']),
    ));
    $this->saveTranslated($translation, self::layout(
      self::item('frame:heading', 'h1', ['text' => 'Willkommen', 'eyebrow' => '']),
      self::item('frame:heading', 'h2', ['text' => 'Second', 'eyebrow' => 'Nur hier']),
    ));
    $this->assertSame(['h1.text' => 'Willkommen', 'h1.eyebrow' => '', 'h2.eyebrow' => 'Nur hier'], $this->stored($this->reload($node, 'de'))['strings']);
    $items = self::byId($this->layouts()->load($this->reload($node, 'de'))['content']);
    $this->assertSame('', $items['h1']['props']['eyebrow']);
    $this->assertSame('Nur hier', $items['h2']['props']['eyebrow']);
    $this->assertSame('Second', $items['h2']['props']['text']);
  }

  /**
   * The title of a block of Drupal is a text of the translation.
   */
  public function testBlockTitleIsTranslated(): void {
    [$node, $translation] = $this->translatedPage(self::layout(
      self::item('drupal_block:system_powered_by_block', 'blk', ['title' => 'Powered by', 'label_display' => TRUE]),
    ));
    $this->saveTranslated($translation, self::layout(
      self::item('drupal_block:system_powered_by_block', 'blk', ['title' => 'Läuft mit', 'label_display' => FALSE]),
    ));
    $this->assertSame(['blk.title' => 'Läuft mit'], $this->stored($this->reload($node, 'de'))['strings']);
    $props = $this->layouts()->load($this->reload($node, 'de'))['content'][0]['props'];
    $this->assertSame('Läuft mit', $props['title']);
    $this->assertTrue($props['label_display']);
  }

  /**
   * Changes of the structure of the original after the translation was
   * saved apply to the translation, which keeps its texts.
   */
  public function testOriginalChangesAfterTheTranslation(): void {
    [$node, $translation] = $this->translatedPage();
    $translated = self::original();
    $translated['content'][0]['props']['text'] = 'Willkommen';
    $translated['content'][1]['props']['content'][0]['props']['label'] = 'Weiterlesen';
    $translated['content'][1]['props']['content'][1]['props']['text']['value'] = '<p>Hallo</p>';
    $this->saveTranslated($translation, $translated);

    // The original: the button moves out of the columns and gets another
    // link, the text block is removed, the heading gets another text and
    // eyebrow, and a heading is added.
    $node = $this->reload($node);
    $node->setNewRevision(TRUE);
    $this->saveLayout($node, self::layout(
      self::item('frame:heading', 'new', ['text' => 'Just added']),
      self::item('frame:button', 'b1', ['label' => 'Read on', 'url' => '/en/other', 'variant' => 'text']),
      self::item('frame:heading', 'h1', ['text' => 'Welcome back', 'eyebrow' => 'Latest', 'level' => 'h3']),
      self::item('frame:columns', 'cols', ['layout' => '4', 'content' => []]),
    ));

    $loaded = $this->layouts()->load($this->reload($node, 'de'));
    $this->assertSame(['new', 'b1', 'h1', 'cols'], array_map(static fn (array $item): string => $item['props']['id'], $loaded['content']));
    $items = self::byId($loaded['content']);
    $this->assertSame('Just added', $items['new']['props']['text'], 'A new item shows the text of the original.');
    $this->assertSame('Weiterlesen', $items['b1']['props']['label'], 'A moved item keeps its translation.');
    $this->assertSame('/en/other', $items['b1']['props']['url'], 'An untranslated value follows the original.');
    $this->assertSame('text', $items['b1']['props']['variant']);
    $this->assertSame('Willkommen', $items['h1']['props']['text'], 'A translated value stays when the original text changes.');
    $this->assertSame('Latest', $items['h1']['props']['eyebrow']);
    $this->assertSame('h3', $items['h1']['props']['level']);
    $this->assertSame([], $items['cols']['props']['content']);
    $this->assertArrayNotHasKey('t1', $items, 'An item removed from the original is gone.');

    $stored = $this->stored($this->reload($node, 'de'));
    $this->assertSame('<p>Hallo</p>', $stored['strings']['t1.text']['value'], 'The texts of a removed item are kept until the translation is saved.');

    // Saving the translation as it is shown keeps the texts in use only.
    $this->saveTranslated($this->reload($node, 'de'), $loaded);
    $stored = $this->stored($this->reload($node, 'de'));
    $this->assertSame(['b1.label' => 'Weiterlesen', 'h1.text' => 'Willkommen'], $stored['strings']);
    $this->assertSame(['b1' => 1, 'h1' => 1], $stored['versions']);
  }

  /**
   * Every revision of a translation shows the structure of the original in
   * that revision.
   */
  public function testRevisions(): void {
    [$node, $translation] = $this->translatedPage();
    $translated = self::original();
    $translated['content'][0]['props']['text'] = 'Willkommen';
    $this->saveTranslated($translation, $translated);
    $first = $this->reload($node)->getRevisionId();

    $node = $this->reload($node);
    $node->setNewRevision(TRUE);
    $this->saveLayout($node, self::layout(self::item('frame:heading', 'h1', ['text' => 'Welcome', 'level' => 'h1'])));

    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $old = $this->layouts()->load($storage->loadRevision($first)->getTranslation('de'));
    $this->assertSame(['h1', 'cols'], array_map(static fn (array $item): string => $item['props']['id'], $old['content']));
    $this->assertSame('Willkommen', $old['content'][0]['props']['text']);
    $new = $this->layouts()->load($this->reload($node, 'de'));
    $this->assertSame(['h1'], array_map(static fn (array $item): string => $item['props']['id'], $new['content']));
    $this->assertSame('Willkommen', $new['content'][0]['props']['text']);
    $this->assertSame('h1', $new['content'][0]['props']['level']);
  }

  /**
   * The structure is taken from the given source instead of the original in
   * the revision of the translation, when reading and when storing.
   */
  public function testSource(): void {
    [$node, $translation] = $this->translatedPage(self::layout(
      self::item('frame:heading', 'h1', ['text' => 'First']),
    ));
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $first = $node->getRevisionId();
    $node = $this->reload($node);
    $node->setNewRevision(TRUE);
    $this->saveLayout($node, self::layout(
      self::item('frame:heading', 'h1', ['text' => 'Second']),
      self::item('frame:heading', 'h2', ['text' => 'Added later']),
    ));
    $translation = $this->reload($node, 'de');
    $source = $storage->loadRevision($first);

    $this->assertSame(['Second', 'Added later'], array_column(array_column($this->layouts()->load($translation)['content'], 'props'), 'text'));
    $this->assertSame(['First'], array_column(array_column($this->layouts()->load($translation, $source)['content'], 'props'), 'text'));

    // The same submission is a translation or not, by what it is compared to.
    $submitted = self::layout(
      self::item('frame:heading', 'h1', ['text' => 'Second']),
      self::item('frame:heading', 'h2', ['text' => 'Später']),
    );
    $this->layouts()->store($translation, $submitted);
    $this->assertSame(['h2.text' => 'Später'], $this->stored($translation)['strings']);
    $this->layouts()->store($translation, $submitted, $source);
    $this->assertSame(['h1.text' => 'Second'], $this->stored($translation)['strings'], 'Compared to the source: its text differs and it has no second heading.');

    // The stored texts are put into whatever structure is asked for.
    $this->assertSame(['Second'], array_column(array_column($this->layouts()->load($translation, $source)['content'], 'props'), 'text'));
    $this->assertSame(['Second', 'Added later'], array_column(array_column($this->layouts()->load($translation)['content'], 'props'), 'text'));
  }

  /**
   * The source is not used for the original language.
   */
  public function testSourceIsIgnoredForTheOriginal(): void {
    [$node] = $this->translatedPage(self::layout(self::item('frame:heading', 'h1', ['text' => 'Mine'])));
    $other = $this->saveLayout($this->createBuilderNode(), self::layout(self::item('frame:heading', 'x', ['text' => 'Other page'])));
    $this->assertSame('Mine', $this->layouts()->load($node, $other)['content'][0]['props']['text']);
    $this->layouts()->store($node, self::layout(self::item('frame:heading', 'h1', ['text' => 'Changed'])), $other);
    $stored = $this->stored($node);
    $this->assertArrayNotHasKey('strings', $stored);
    $this->assertSame('Changed', $stored['content'][0]['props']['text']);
  }

  /**
   * Translated values follow props that were renamed since they were saved.
   */
  public function testStringsFollowRenamedProps(): void {
    // Saved when the card was at version 1: "title", "body" and "children".
    $node = $this->createBuilderNode(['frame_layout' => json_encode(['content' => [
      ['type' => self::CARD, 'props' => ['id' => 'c1', 'title' => 'Title', 'body' => 'Body', 'url' => '/en', 'children' => [
        ['type' => self::CARD, 'props' => ['id' => 'c2', 'title' => 'Nested']],
      ]]],
    ]])]);
    $translation = $this->translate($node);
    $translation->set('frame_layout', json_encode([
      'version' => 1,
      'strings' => ['c1.title' => 'Titel', 'c1.url' => '/de', 'c2.title' => 'Verschachtelt'],
      'versions' => ['c1' => 1, 'c2' => 1],
    ]));
    $translation->save();

    $loaded = $this->layouts()->load($this->reload($node, 'de'));
    $card = $loaded['content'][0];
    $this->assertSame(3, $card['version']);
    $this->assertSame('Titel', $card['props']['heading']);
    $this->assertSame('Body', $card['props']['summary'], 'An untranslated prop has the migrated value of the original.');
    $this->assertSame('/de', $card['props']['url']);
    $this->assertArrayNotHasKey('title', $card['props']);
    $this->assertSame('Verschachtelt', $card['props']['items'][0]['props']['heading']);

    // The next save keeps the values under the current names and versions.
    $this->saveTranslated($this->reload($node, 'de'), $loaded);
    $stored = $this->stored($this->reload($node, 'de'));
    $this->assertSame(['c1.heading' => 'Titel', 'c1.url' => '/de', 'c2.heading' => 'Verschachtelt'], $stored['strings']);
    $this->assertSame(['c1' => 3, 'c2' => 3], $stored['versions']);
    $this->assertSame('Titel', $this->layouts()->load($this->reload($node, 'de'))['content'][0]['props']['heading']);
  }

  /**
   * Values saved before versions were kept are of version 1; values saved
   * for the current version are used as they are.
   */
  public function testStringsWithoutAndWithCurrentVersions(): void {
    $node = $this->saveLayout($this->createBuilderNode(), self::layout(
      self::item(self::CARD, 'c1', ['heading' => 'Title', 'summary' => 'Body']),
    ));
    $translation = $this->translate($node);
    $translation->set('frame_layout', json_encode(['strings' => ['c1.title' => 'Alter Titel', 'c1.summary' => 'Text']]));
    $translation->save();
    $props = $this->layouts()->load($this->reload($node, 'de'))['content'][0]['props'];
    $this->assertSame('Alter Titel', $props['heading']);
    $this->assertSame('Text', $props['summary']);

    $translation = $this->reload($node, 'de');
    $translation->set('frame_layout', json_encode(['version' => 1, 'strings' => ['c1.title' => 'Kein Prop mehr', 'c1.summary' => 'Text'], 'versions' => ['c1' => 3]]));
    $translation->save();
    $props = $this->layouts()->load($this->reload($node, 'de'))['content'][0]['props'];
    $this->assertSame('Title', $props['heading'], 'A value of the current version under an old name is not a value of the prop.');
    $this->assertSame('Text', $props['summary']);
    $this->assertArrayNotHasKey('title', $props);
  }

  /**
   * A default a migration step gives to a prop is for items without a
   * value: a translation that did not translate the prop shows the original.
   */
  public function testMigrationDefaultDoesNotReplaceTheOriginal(): void {
    $node = $this->createBuilderNode(['frame_layout' => json_encode(['content' => [
      ['type' => self::NOTE, 'props' => ['id' => 'n1', 'label' => 'Important', 'text' => 'Text']],
    ]])]);
    $translation = $this->translate($node);
    $translation->set('frame_layout', json_encode(['version' => 1, 'strings' => ['n1.text' => 'Hallo'], 'versions' => ['n1' => 1]]));
    $translation->save();
    $props = $this->layouts()->load($this->reload($node, 'de'))['content'][0]['props'];
    $this->assertSame('Hallo', $props['text']);
    $this->assertSame('Important', $props['label']);
  }

  /**
   * Texts that differ only as text ("10" and "10.0") are different texts.
   */
  public function testNumericLookingTextIsATranslation(): void {
    [$node, $translation] = $this->translatedPage(self::layout(self::item('frame:heading', 'h1', ['text' => '10'])));
    $this->saveTranslated($translation, self::layout(self::item('frame:heading', 'h1', ['text' => '10.0'])));
    $this->assertSame(['h1.text' => '10.0'], $this->stored($this->reload($node, 'de'))['strings']);
    $this->assertSame('10.0', $this->layouts()->load($this->reload($node, 'de'))['content'][0]['props']['text']);
  }

  /**
   * Without a layout on the original a translation has none, whatever is
   * stored for it.
   */
  public function testOriginalWithoutLayout(): void {
    $node = $this->createBuilderNode();
    $translation = $this->translate($node);
    $translation->save();
    $this->assertSame([], $this->layouts()->load($translation));

    $this->saveTranslated($translation, self::layout(self::item('frame:heading', 'h1', ['text' => 'Nur Deutsch'])));
    $this->assertSame(['version' => LayoutMigrator::VERSION, 'strings' => [], 'versions' => []], $this->stored($this->reload($node, 'de')));
    $this->assertSame([], $this->layouts()->load($this->reload($node, 'de')));
    $this->assertSame([], $this->layouts()->load($this->reload($node)));
  }

  /**
   * Stored values that are not a map of strings are ignored.
   */
  public function testBrokenStrings(): void {
    [$node, $translation] = $this->translatedPage();
    $expected = $this->layouts()->load($node);
    foreach (['not json', '{"strings": "text"}', '{"strings": null, "versions": "x"}', '[]'] as $value) {
      $translation->set('frame_layout', $value)->save();
      $this->assertSame($expected, $this->layouts()->load($this->reload($node, 'de')), $value);
    }
    $translation->set('frame_layout', '{"strings": {"h1.text": "Willkommen", "h1": "No prop", "h1.level": "h4"}, "versions": "x"}')->save();
    $props = $this->layouts()->load($this->reload($node, 'de'))['content'][0]['props'];
    $this->assertSame('Willkommen', $props['text']);
    $this->assertSame('h2', $props['level'], 'A stored value of a prop that is not text is not applied.');
  }

  /**
   * A preview is for one language of a page.
   */
  public function testPreviewPerLanguage(): void {
    [$node, $translation] = $this->translatedPage();
    $this->layouts()->setPreview($translation, self::layout(self::item('frame:heading', 'p', ['text' => 'Vorschau'])));
    $this->assertSame('Vorschau', $this->layouts()->load($translation)['content'][0]['props']['text']);
    $this->assertSame('Welcome', $this->layouts()->load($node)['content'][0]['props']['text']);

    $this->layouts()->setPreview($node, self::layout(self::item('frame:heading', 'p', ['text' => 'Preview'])));
    $this->layouts()->clearPreview($translation);
    $this->assertSame('Preview', $this->layouts()->load($node)['content'][0]['props']['text']);
    $this->assertSame('Welcome', $this->layouts()->load($translation)['content'][0]['props']['text'], 'The translation takes the stored original, not its preview.');
  }

}
