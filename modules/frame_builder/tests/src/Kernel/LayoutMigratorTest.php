<?php

declare(strict_types=1);

namespace Drupal\Tests\frame_builder\Kernel;

use Drupal\frame_builder\LayoutMigrator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Bringing stored layouts up to date with the components as they are now.
 *
 * Uses the "mig-*" components of the test theme:
 * - mig-card, version 3: step 2 renames "title" to "heading", maps the tones
 *   "big" and 7, defaults the tone to "calm" and removes "subtitle"; step 3
 *   renames "body" to "summary" and the slot "children" to "items";
 * - mig-note, version 2: step 2 defaults "label" to "Note";
 * - mig-hook, version 3: no declared steps, migrated by the hooks of the
 *   frame_builder_migrate_test module and of the test theme;
 * - mig-zero: declares version 0.
 */
#[Group('frame_builder')]
#[RunTestsInSeparateProcesses]
class LayoutMigratorTest extends BuilderKernelTestBase {

  protected const CARD = 'frame_builder_test_theme:mig-card';
  protected const NOTE = 'frame_builder_test_theme:mig-note';
  protected const HOOK = 'frame_builder_test_theme:mig-hook';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['frame_builder_migrate_test'];

  /**
   * The service under test.
   */
  protected function migrator(): LayoutMigrator {
    return $this->container->get('frame_builder.migrator');
  }

  /**
   * Migrates one stored item and returns it.
   */
  protected function migrateItem(array $item, bool $from_editor = FALSE): array {
    return $this->migrator()->migrate(['content' => [$item]], $from_editor)['content'][0];
  }

  /**
   * A component is at the version it declares, and at 1 when it declares
   * none, less than 1, or is not an SDC at all.
   */
  #[DataProvider('componentVersions')]
  public function testComponentVersion(string $type, int $expected): void {
    $this->assertSame($expected, $this->migrator()->componentVersion($type));
  }

  /**
   * Data of testComponentVersion().
   */
  public static function componentVersions(): array {
    return [
      'declared' => [self::CARD, 3],
      'declared, one step' => [self::NOTE, 2],
      'declared without steps' => [self::HOOK, 3],
      'below one' => ['frame_builder_test_theme:mig-zero', 1],
      'not declared' => ['frame_builder_test_theme:probe', 1],
      'base theme component' => ['frame:heading', 1],
      'field of Drupal' => ['drupal_field:title', 1],
      'block of Drupal' => ['drupal_block:system_branding_block', 1],
      'component that is gone' => ['gone:component', 1],
      'no type' => ['', 1],
    ];
  }

  /**
   * Version 2 of the format: the Size of an item was "width" (at most) and
   * "height" (at least). Old names become the new ones wherever they are,
   * whatever the document says its version is, and only once.
   */
  public function testSizeOfVersionOne(): void {
    $migrator = $this->migrator();
    $old = ['width' => ['size' => 'm'], 'height' => ['size' => 'custom', 'custom' => '10vh']];
    $new = ['max_width' => ['size' => 'm'], 'min_height' => ['size' => 'custom', 'custom' => '10vh']];
    $layout = self::layout(self::item('frame:columns', 'c', ['_size' => ['width' => ['size' => 's']], 'content' => [
      self::item('frame:heading', 'h', ['text' => 'A', '_size' => $old]),
    ]]));
    foreach ([['version' => 1] + $layout, $layout, ['version' => 2] + $layout] as $document) {
      $migrated = $migrator->migrate($document);
      $this->assertSame(['max_width' => ['size' => 's']], $migrated['content'][0]['props']['_size']);
      $this->assertSame($new, $migrated['content'][0]['props']['content'][0]['props']['_size']);
      $this->assertSame($migrated, $migrator->migrate($migrated), 'A second time changes nothing.');
    }
    // What is set in the new way stays, also next to an old name.
    $mixed = $migrator->migrate(self::layout(self::item('frame:heading', 'h', ['_size' => ['exact_width' => ['size' => 's'], 'max_width' => ['size' => 'm'], 'width' => ['size' => 'l']]])), TRUE);
    $this->assertSame(['exact_width' => ['size' => 's'], 'max_width' => ['size' => 'm']], $mixed['content'][0]['props']['_size']);
  }

  /**
   * The document gets the version of the format; a newer one is kept.
   */
  public function testDocumentVersion(): void {
    $migrator = $this->migrator();
    $this->assertSame(2, LayoutMigrator::VERSION);
    $this->assertSame(LayoutMigrator::VERSION, $migrator->migrate(self::layout())['version'], 'A stored document without a version.');
    $this->assertSame(LayoutMigrator::VERSION, $migrator->migrate(self::layout(), TRUE)['version'], 'A document of the editor without a version.');
    $this->assertSame(LayoutMigrator::VERSION, $migrator->migrate(['version' => 0] + self::layout())['version']);
    $this->assertSame(LayoutMigrator::VERSION + 4, $migrator->migrate(['version' => LayoutMigrator::VERSION + 4] + self::layout())['version'], 'A document of a newer module keeps its version.');

    $root = ['props' => ['title' => 'Kept']];
    $migrated = $migrator->migrate(['root' => $root, 'content' => [], 'zones' => ['kept']]);
    $this->assertSame($root, $migrated['root']);
    $this->assertSame([], $migrated['content']);
    $this->assertSame(['kept'], $migrated['zones'], 'Parts of the document the migrator does not know stay.');

    $this->assertSame(['version' => LayoutMigrator::VERSION], $migrator->migrate([]), 'No content is invented for a document without it.');
    $this->assertSame('not a list', $migrator->migrate(['content' => 'not a list'])['content']);
  }

  /**
   * A stored item without a version is version 1: every step is applied.
   */
  public function testStoredItemWithoutVersionIsMigratedFromOne(): void {
    $item = $this->migrateItem(self::item(self::CARD, 'c1', [
      'title' => 'Old title',
      'subtitle' => 'Dropped',
      'body' => 'Old body',
      'tone' => 'big',
      'url' => '/kept',
    ]));
    $this->assertSame(self::CARD, $item['type']);
    $this->assertSame(3, $item['version']);
    $this->assertEquals([
      'id' => 'c1',
      'heading' => 'Old title',
      'summary' => 'Old body',
      'tone' => 'loud',
      'url' => '/kept',
    ], $item['props']);
  }

  /**
   * An item the editor sends without a version was added just now: it is
   * current and nothing is migrated.
   */
  public function testEditorItemWithoutVersionIsCurrent(): void {
    $props = ['id' => 'c1', 'title' => 'Not a prop any more', 'subtitle' => 'Stays', 'tone' => 'big'];
    $item = $this->migrateItem(['type' => self::CARD, 'props' => $props], TRUE);
    $this->assertSame(3, $item['version']);
    $this->assertSame($props, $item['props']);
  }

  /**
   * An item the editor sends back with the version it was loaded with is
   * migrated from that version.
   */
  public function testEditorItemWithVersionIsMigrated(): void {
    $item = $this->migrateItem(['type' => self::CARD, 'version' => 1, 'props' => ['id' => 'c1', 'title' => 'Old']], TRUE);
    $this->assertSame(3, $item['version']);
    $this->assertSame('Old', $item['props']['heading']);
    $this->assertArrayNotHasKey('title', $item['props']);
  }

  /**
   * Only the steps after the version of the item are applied.
   */
  public function testStepsStartAfterTheItemVersion(): void {
    $item = $this->migrateItem(['type' => self::CARD, 'version' => 2, 'props' => [
      'id' => 'c1',
      'title' => 'Step 2 is not applied',
      'subtitle' => 'Stays',
      'tone' => 'big',
      'body' => 'Step 3 is',
    ]]);
    $this->assertSame(3, $item['version']);
    $this->assertEquals([
      'id' => 'c1',
      'title' => 'Step 2 is not applied',
      'subtitle' => 'Stays',
      'tone' => 'big',
      'summary' => 'Step 3 is',
    ], $item['props']);

    $current = ['id' => 'c1', 'title' => 'Untouched', 'body' => 'Untouched', 'tone' => 'big'];
    $this->assertSame($current, $this->migrateItem(['type' => self::CARD, 'version' => 3, 'props' => $current])['props']);
  }

  /**
   * An item saved with a newer component (the code was rolled back) keeps
   * its version and its props.
   */
  public function testNewerItemIsKept(): void {
    $props = ['id' => 'c1', 'title' => 'From the future', 'body' => 'Kept'];
    $item = $this->migrateItem(['type' => self::CARD, 'version' => 9, 'props' => $props]);
    $this->assertSame(9, $item['version']);
    $this->assertSame($props, $item['props']);
  }

  /**
   * What a declared step does to the props of a version 1 item.
   */
  #[DataProvider('steps')]
  public function testDeclaredStep(array $props, array $expected): void {
    $item = $this->migrateItem(['type' => self::CARD, 'props' => $props]);
    $actual = $item['props'];
    ksort($actual);
    ksort($expected);
    $this->assertSame($expected, $actual);
  }

  /**
   * Data of testDeclaredStep(): props before, props after.
   */
  public static function steps(): array {
    return [
      'rename' => [
        ['title' => 'A', 'tone' => 'loud'],
        ['tone' => 'loud', 'heading' => 'A'],
      ],
      'rename keeps a value the new name already has' => [
        ['title' => 'Old', 'heading' => 'New', 'tone' => 'loud'],
        ['heading' => 'New', 'tone' => 'loud'],
      ],
      'rename moves an empty value too' => [
        ['title' => '', 'tone' => 'loud'],
        ['tone' => 'loud', 'heading' => ''],
      ],
      'value mapped' => [
        ['tone' => 'big'],
        ['tone' => 'loud'],
      ],
      'integer value mapped' => [
        ['tone' => 7],
        ['tone' => 'calm'],
      ],
      'value that is not in the map stays' => [
        ['tone' => 'odd'],
        ['tone' => 'odd'],
      ],
      'value that is not a string or integer stays' => [
        ['tone' => ['big']],
        ['tone' => ['big']],
      ],
      'default for a missing prop' => [
        ['heading' => 'A'],
        ['heading' => 'A', 'tone' => 'calm'],
      ],
      'default for an empty prop' => [
        ['tone' => ''],
        ['tone' => 'calm'],
      ],
      'default for a null prop' => [
        ['tone' => NULL],
        ['tone' => 'calm'],
      ],
      'no default over a value' => [
        ['tone' => 'loud'],
        ['tone' => 'loud'],
      ],
      'no default over false' => [
        ['tone' => FALSE],
        ['tone' => FALSE],
      ],
      'remove' => [
        ['subtitle' => 'Gone', 'tone' => 'loud'],
        ['tone' => 'loud'],
      ],
      'steps follow each other' => [
        ['title' => 'A', 'body' => 'B', 'subtitle' => 'C', 'tone' => 'big'],
        ['tone' => 'loud', 'heading' => 'A', 'summary' => 'B'],
      ],
      'props the steps do not name stay' => [
        ['_spacing' => ['top' => 'm'], 'unknown' => 1, 'tone' => 'loud'],
        ['_spacing' => ['top' => 'm'], 'unknown' => 1, 'tone' => 'loud'],
      ],
    ];
  }

  /**
   * Items in slots are migrated, at any depth, also in a renamed slot.
   */
  public function testNestedItems(): void {
    $data = $this->migrator()->migrate(self::layout(
      self::item('frame:columns', 'cols', [
        'layout' => '2',
        'content' => [
          self::item(self::CARD, 'outer', [
            'title' => 'Outer',
            'children' => [
              self::item(self::CARD, 'inner', ['title' => 'Inner', 'body' => 'Deep']),
              self::item('frame:heading', 'h', ['text' => 'Plain']),
            ],
          ]),
        ],
      ]),
    ));
    $columns = $data['content'][0];
    $this->assertSame(1, $columns['version']);
    $outer = $columns['props']['content'][0];
    $this->assertSame(3, $outer['version']);
    $this->assertSame('Outer', $outer['props']['heading']);
    $this->assertArrayNotHasKey('children', $outer['props'], 'The slot was renamed.');
    $inner = $outer['props']['items'][0];
    $this->assertSame(3, $inner['version']);
    $this->assertSame('Inner', $inner['props']['heading']);
    $this->assertSame('Deep', $inner['props']['summary']);
    $this->assertSame('calm', $inner['props']['tone']);
    $this->assertSame(['type' => 'frame:heading', 'version' => 1, 'props' => ['id' => 'h', 'text' => 'Plain']], $outer['props']['items'][1]);
  }

  /**
   * In a document of the editor, nested items without a version are current
   * and those with a version are migrated.
   */
  public function testNestedItemsFromEditor(): void {
    $data = $this->migrator()->migrate(self::layout(
      self::item('frame:columns', 'cols', [
        'content' => [
          self::item(self::CARD, 'new', ['title' => 'Added now']),
          ['type' => self::CARD, 'version' => 2, 'props' => ['id' => 'old', 'body' => 'Loaded as version 2']],
        ],
      ]),
    ), TRUE);
    [$new, $old] = $data['content'][0]['props']['content'];
    $this->assertSame(3, $new['version']);
    $this->assertSame('Added now', $new['props']['title']);
    $this->assertSame(3, $old['version']);
    $this->assertSame('Loaded as version 2', $old['props']['summary']);
  }

  /**
   * An item of a component that does not exist (any more) keeps its type
   * and props; what is in it is still migrated.
   */
  public function testUnknownComponentIsKept(): void {
    $props = [
      'id' => 'gone',
      'title' => 'Kept as it is',
      'subtitle' => 'Kept too',
      'settings' => ['a' => 1, 'list' => ['x', 'y']],
    ];
    $item = $this->migrateItem(['type' => 'gone:component', 'props' => $props, 'readOnly' => TRUE]);
    $this->assertSame('gone:component', $item['type']);
    $this->assertSame(1, $item['version']);
    $this->assertSame($props, $item['props']);
    $this->assertTrue($item['readOnly'], 'Keys of the item other than type, version and props stay.');

    $item = $this->migrateItem(['type' => 'gone:component', 'version' => 4, 'props' => $props], TRUE);
    $this->assertSame(4, $item['version']);
    $this->assertSame($props, $item['props']);

    $item = $this->migrateItem(self::item('gone:component', 'gone', [
      'area' => [self::item(self::CARD, 'c1', ['title' => 'Inside'])],
    ]));
    $this->assertSame('Inside', $item['props']['area'][0]['props']['heading']);
    $this->assertSame(3, $item['props']['area'][0]['version']);
  }

  /**
   * Malformed entries of the content do not break the migration.
   */
  public function testMalformedItems(): void {
    $data = $this->migrator()->migrate(['content' => [
      'a string',
      ['type' => self::CARD],
      ['props' => ['id' => 'typeless']],
      ['type' => self::CARD, 'props' => 'not props'],
    ]]);
    $this->assertSame('a string', $data['content'][0], 'What is not an item is left alone.');
    $this->assertSame(['type' => self::CARD, 'version' => 3, 'props' => ['tone' => 'calm']], $data['content'][1]);
    $this->assertSame(['type' => '', 'version' => 1, 'props' => ['id' => 'typeless']], $data['content'][2]);
    $this->assertSame(['tone' => 'calm'], $data['content'][3]['props']);
  }

  /**
   * Migrating twice changes nothing more.
   */
  public function testMigrationIsRepeatable(): void {
    $once = $this->migrator()->migrate(self::layout(
      self::item(self::CARD, 'c1', ['title' => 'A', 'tone' => 'big', 'children' => [self::item(self::NOTE, 'n1', ['text' => 'T'])]]),
      self::item(self::HOOK, 'k1', ['name' => 'Ada Lovelace']),
    ));
    $this->assertSame($once, $this->migrator()->migrate($once));
    $this->assertSame($once, $this->migrator()->migrate($once, TRUE));
  }

  /**
   * Modules and the default theme migrate in code, once per version step,
   * after the declared changes: modules first, then the theme.
   */
  public function testHooks(): void {
    $item = $this->migrateItem(self::item(self::HOOK, 'k1', ['name' => 'Ada Lovelace']));
    $this->assertSame(3, $item['version']);
    $this->assertSame('Ada', $item['props']['first']);
    $this->assertSame('Lovelace', $item['props']['last']);
    $this->assertArrayNotHasKey('name', $item['props']);
    $this->assertSame(['module:1>2', 'theme:1>2', 'module:2>3', 'theme:2>3'], $item['props']['_steps']);

    $item = $this->migrateItem(['type' => self::HOOK, 'version' => 2, 'props' => ['id' => 'k2', 'name' => 'Not split']]);
    $this->assertSame(['module:2>3', 'theme:2>3'], $item['props']['_steps']);
    $this->assertSame('Not split', $item['props']['name']);

    $item = $this->migrateItem(['type' => self::HOOK, 'version' => 3, 'props' => ['id' => 'k3']]);
    $this->assertArrayNotHasKey('_steps', $item['props'], 'A current item is not given to the hooks.');
    $item = $this->migrateItem(self::item(self::HOOK, 'k4'), TRUE);
    $this->assertArrayNotHasKey('_steps', $item['props'], 'An item added in the editor is not given to the hooks.');
  }

  /**
   * The hooks of the default theme run whatever theme the request uses.
   */
  public function testThemeHookRunsWithAnotherActiveTheme(): void {
    $this->container->get('theme_installer')->install(['stark']);
    $this->container->get('theme.manager')->setActiveTheme($this->container->get('theme.initialization')->initTheme('stark'));
    $this->assertSame('stark', $this->container->get('theme.manager')->getActiveTheme()->getName());
    $item = $this->migrateItem(self::item(self::HOOK, 'k1', ['name' => 'Ada Lovelace']));
    $this->assertSame(['module:1>2', 'theme:1>2', 'module:2>3', 'theme:2>3'], $item['props']['_steps']);
  }

  /**
   * Translated values follow the props they were saved for.
   */
  public function testMigrateStrings(): void {
    $items = $this->migrator()->migrate(self::layout(
      self::item(self::CARD, 'c1', ['title' => 'Title', 'children' => [self::item(self::CARD, 'c2', ['title' => 'Nested'])]]),
      self::item('frame:heading', 'h1', ['text' => 'Heading']),
    ))['content'];
    $strings = [
      'c1.title' => 'Titel',
      'c1.body' => 'Text',
      'c2.title' => 'Verschachtelt',
      'h1.text' => 'Überschrift',
      'gone.title' => 'Its item was removed',
    ];

    $migrated = $this->migrator()->migrateStrings($strings, ['c1' => 1, 'c2' => 1, 'h1' => 1], $items);
    $this->assertSame('Titel', $migrated['c1.heading']);
    $this->assertSame('Text', $migrated['c1.summary']);
    $this->assertSame('Verschachtelt', $migrated['c2.heading'], 'The type of a nested item is known.');
    $this->assertSame('Überschrift', $migrated['h1.text']);
    $this->assertSame('Its item was removed', $migrated['gone.title'], 'Values of an item that is gone are kept as they are.');
    $this->assertArrayNotHasKey('c1.title', $migrated);
    $this->assertArrayNotHasKey('c1.body', $migrated);
    $this->assertArrayNotHasKey('c2.title', $migrated);
    $this->assertArrayNotHasKey('c1.id', $migrated);
    $this->assertArrayNotHasKey('c1.version', $migrated);

    $migrated = $this->migrator()->migrateStrings($strings, [], $items);
    $this->assertSame('Titel', $migrated['c1.heading'], 'Values saved before versions were kept are version 1.');

    $migrated = $this->migrator()->migrateStrings($strings, ['c1' => 2, 'c2' => 3], $items);
    $this->assertSame('Titel', $migrated['c1.title'], 'Step 2 is not applied to values of version 2.');
    $this->assertSame('Text', $migrated['c1.summary']);
    $this->assertSame('Verschachtelt', $migrated['c2.title'], 'Current values are not changed.');
    $this->assertSame(array_keys($strings), array_keys($this->migrator()->migrateStrings($strings, ['c1' => 3, 'c2' => 3, 'h1' => 1], $items)));
  }

  /**
   * Translated values are converted like the props, ids may contain dots and
   * keys without a prop are dropped.
   */
  public function testMigrateStringsValuesAndKeys(): void {
    $items = [
      ['type' => self::CARD, 'version' => 3, 'props' => ['id' => 'a.b']],
      ['type' => self::HOOK, 'version' => 3, 'props' => ['id' => 'k1']],
    ];
    $migrated = $this->migrator()->migrateStrings([
      'a.b.title' => 'Dotted id',
      'a.b.tone' => 'big',
      'k1.name' => 'Ada Lovelace',
      'no-prop' => 'Dropped',
    ], [], $items);
    $this->assertSame('Dotted id', $migrated['a.b.heading']);
    $this->assertSame('loud', $migrated['a.b.tone']);
    $this->assertSame('Ada', $migrated['k1.first'], 'Hooks see the translated values.');
    $this->assertSame('Lovelace', $migrated['k1.last']);
    $this->assertArrayNotHasKey('no-prop', $migrated);
    $this->assertArrayNotHasKey('k1.name', $migrated);

    $this->assertSame([], $this->migrator()->migrateStrings([], ['c1' => 1], $items));
    $this->assertSame(['x.title' => 'T'], $this->migrator()->migrateStrings(['x.title' => 'T'], ['x' => 1], []));
  }

  /**
   * A step's default is for an item without a value, not for a translation
   * that did not translate the prop.
   */
  public function testMigrateStringsAddsNoDefaults(): void {
    $items = [['type' => self::NOTE, 'version' => 2, 'props' => ['id' => 'n1', 'label' => 'Important', 'text' => 'Text']]];
    $this->assertSame(['n1.text' => 'Hallo'], $this->migrator()->migrateStrings(['n1.text' => 'Hallo'], ['n1' => 1], $items));
  }

  /**
   * The versions kept with translated values: the current one of every item
   * that has a value.
   */
  public function testStringVersions(): void {
    $items = [
      ['type' => self::CARD, 'version' => 3, 'props' => ['id' => 'c1', 'items' => [
        ['type' => self::NOTE, 'version' => 2, 'props' => ['id' => 'n1']],
        ['type' => self::NOTE, 'version' => 2, 'props' => ['id' => 'n2']],
      ]]],
      ['type' => 'frame:heading', 'version' => 1, 'props' => ['id' => 'h.1']],
      ['type' => 'gone:component', 'version' => 5, 'props' => ['id' => 'g1']],
    ];
    $versions = $this->migrator()->stringVersions([
      'c1.heading' => 'A',
      'c1.summary' => 'B',
      'n1.text' => 'C',
      'h.1.text' => 'D',
      'g1.text' => 'E',
      'removed.text' => 'F',
    ], $items);
    $this->assertSame(['c1' => 3, 'n1' => 2, 'h.1' => 1, 'g1' => 1], $versions);
    $this->assertSame([], $this->migrator()->stringVersions([], $items));
  }

  /**
   * What counts as the content of a slot.
   */
  #[DataProvider('itemLists')]
  public function testIsItems(mixed $value, bool $expected): void {
    $this->assertSame($expected, LayoutMigrator::isItems($value));
  }

  /**
   * Data of testIsItems().
   */
  public static function itemLists(): array {
    $item = ['type' => 'frame:heading', 'props' => ['id' => 'h']];
    return [
      'one item' => [[$item], TRUE],
      'items' => [[$item, ['type' => '', 'props' => []]], TRUE],
      'empty list' => [[], FALSE],
      'string' => ['frame:heading', FALSE],
      'null' => [NULL, FALSE],
      'an item, not a list' => [$item, FALSE],
      'keyed list' => [['a' => $item], FALSE],
      'list that does not start at 0' => [[1 => $item], FALSE],
      'list of strings' => [['a', 'b'], FALSE],
      'item without props' => [[['type' => 'frame:heading']], FALSE],
      'item without type' => [[['props' => []]], FALSE],
      'type is not a string' => [[['type' => 1, 'props' => []]], FALSE],
      'props are not an array' => [[['type' => 'frame:heading', 'props' => 'x']], FALSE],
      'one of them is not an item' => [[$item, 'x'], FALSE],
      'picked image' => [['media' => 3, 'url' => '/a.png'], FALSE],
    ];
  }

}
