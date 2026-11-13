<?php

declare(strict_types=1);

namespace Drupal\Tests\frame_builder\Kernel;

use Drupal\frame_builder\LayoutMigrator;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Reading and writing the layout of a page.
 */
#[Group('frame_builder')]
#[RunTestsInSeparateProcesses]
class LayoutStorageTest extends BuilderKernelTestBase {

  /**
   * A layout survives a save and a load, on the right revision.
   */
  public function testStoreAndLoad(): void {
    $layouts = $this->container->get('frame_builder.layouts');
    $node = $this->createBuilderNode();
    $this->assertTrue($layouts->isEnabled($node));
    $this->assertSame([], $layouts->load($node), 'A page without a layout stays without one.');

    $this->saveLayout($node, self::layout(self::item('frame:heading', 'h1', ['text' => 'Hello', 'level' => 'h2'])));
    $first = $node->getRevisionId();
    $loaded = $layouts->load(Node::load($node->id()));
    $this->assertSame('frame:heading', $loaded['content'][0]['type']);
    $this->assertSame('Hello', $loaded['content'][0]['props']['text']);

    $node->setNewRevision(TRUE);
    $this->saveLayout($node, self::layout(self::item('frame:heading', 'h1', ['text' => 'Changed', 'level' => 'h2'])));
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $this->assertSame('Hello', $layouts->load($storage->loadRevision($first))['content'][0]['props']['text']);
    $this->assertSame('Changed', $layouts->load($storage->load($node->id()))['content'][0]['props']['text']);
  }

  /**
   * The builder is off for types that do not use it and without Frame.
   */
  public function testIsEnabled(): void {
    $layouts = $this->container->get('frame_builder.layouts');
    $this->createBuilderType('plain', 'synced', FALSE);
    $this->assertFalse($layouts->isEnabled($this->createBuilderNode(['type' => 'plain'])));
    $node = $this->createBuilderNode();
    $this->assertTrue($layouts->isEnabled($node));
    $this->container->get('theme_installer')->install(['stark']);
    $this->config('system.theme')->set('default', 'stark')->save();
    $this->assertFalse($layouts->isEnabled($node), 'A default theme that is not based on Frame switches the builder off.');
  }

  /**
   * The stored document carries the version of the format and, per item,
   * the version of its component; the editor's new items are current.
   */
  public function testStoreAddsVersions(): void {
    $layouts = $this->container->get('frame_builder.layouts');
    $node = $this->createBuilderNode();
    $layouts->store($node, self::layout(
      self::item('frame:heading', 'h1', ['text' => 'Hello']),
      self::item('frame_builder_test_theme:mig-card', 'c1', ['title' => 'Named as the editor sent it']),
    ));
    $stored = json_decode($node->get('frame_layout')->value, TRUE);
    $this->assertSame(LayoutMigrator::VERSION, $stored['version']);
    $this->assertSame(['props' => []], $stored['root']);
    $this->assertSame(['type' => 'frame:heading', 'version' => 1, 'props' => ['id' => 'h1', 'text' => 'Hello']], $stored['content'][0]);
    $this->assertSame(3, $stored['content'][1]['version']);
    $this->assertSame(['id' => 'c1', 'title' => 'Named as the editor sent it'], $stored['content'][1]['props']);
  }

  /**
   * store() only sets the field: nothing is written until the node is saved.
   */
  public function testStoreDoesNotSave(): void {
    $layouts = $this->container->get('frame_builder.layouts');
    $node = $this->saveLayout($this->createBuilderNode(), self::layout(self::item('frame:heading', 'h1', ['text' => 'Saved'])));
    $revision = $node->getRevisionId();
    $layouts->store($node, self::layout(self::item('frame:heading', 'h1', ['text' => 'Not saved'])));
    $this->assertSame('Not saved', $layouts->load($node)['content'][0]['props']['text']);
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $storage->resetCache();
    $fresh = $storage->load($node->id());
    $this->assertSame($revision, $fresh->getRevisionId());
    $this->assertSame('Saved', $layouts->load($fresh)['content'][0]['props']['text']);
  }

  /**
   * A layout stored before a component changed is migrated when it is read;
   * what is stored stays as it is until the page is saved.
   */
  public function testLoadMigratesStoredItems(): void {
    $layouts = $this->container->get('frame_builder.layouts');
    $raw = json_encode(['content' => [['type' => 'frame_builder_test_theme:mig-card', 'props' => ['id' => 'c1', 'title' => 'Old', 'body' => 'Text']]]]);
    $node = $this->createBuilderNode(['frame_layout' => $raw]);
    $loaded = $layouts->load(Node::load($node->id()));
    $this->assertSame(LayoutMigrator::VERSION, $loaded['version']);
    $this->assertSame(3, $loaded['content'][0]['version']);
    $this->assertSame('Old', $loaded['content'][0]['props']['heading']);
    $this->assertSame('Text', $loaded['content'][0]['props']['summary']);
    $this->assertArrayNotHasKey('title', $loaded['content'][0]['props']);
    $this->assertSame($raw, Node::load($node->id())->get('frame_layout')->value);
  }

  /**
   * A layout without items is stored and read as one without items.
   */
  public function testEmptyLayout(): void {
    $layouts = $this->container->get('frame_builder.layouts');
    $node = $this->saveLayout($this->createBuilderNode(), self::layout(self::item('frame:heading', 'h1', ['text' => 'Hello'])));
    $node->setNewRevision(TRUE);
    $this->saveLayout($node, self::layout());
    $loaded = $layouts->load(Node::load($node->id()));
    $this->assertSame([], $loaded['content']);
    $this->assertSame(LayoutMigrator::VERSION, $loaded['version']);

    $node->setNewRevision(TRUE);
    $this->saveLayout($node, []);
    $this->assertSame(['version' => LayoutMigrator::VERSION], json_decode(Node::load($node->id())->get('frame_layout')->value, TRUE));
    $this->assertArrayNotHasKey('content', $layouts->load(Node::load($node->id())), 'No content is invented.');
  }

  /**
   * A stored value that is not a layout document reads as no layout.
   */
  #[DataProvider('brokenValues')]
  public function testLoadOfBrokenValue(?string $value): void {
    $layouts = $this->container->get('frame_builder.layouts');
    $node = $this->createBuilderNode(['frame_layout' => $value]);
    $this->assertSame([], $layouts->load(Node::load($node->id())));
  }

  /**
   * Data of testLoadOfBrokenValue().
   */
  public static function brokenValues(): array {
    return [
      'nothing' => [NULL],
      'empty string' => [''],
      'not JSON' => ['{"content": [{"type": '],
      'text' => ['Hello'],
      'JSON string' => ['"content"'],
      'JSON number' => ['5'],
      'JSON null' => ['null'],
      'JSON true' => ['true'],
      'empty object' => ['{}'],
      'empty list' => ['[]'],
    ];
  }

  /**
   * A preview layout is shown instead of the stored one, for that page
   * only, until it is cleared; nothing is stored.
   */
  public function testPreview(): void {
    $layouts = $this->container->get('frame_builder.layouts');
    $node = $this->saveLayout($this->createBuilderNode(), self::layout(self::item('frame:heading', 'h1', ['text' => 'Stored'])));
    $other = $this->saveLayout($this->createBuilderNode(), self::layout(self::item('frame:heading', 'h1', ['text' => 'Other'])));
    $empty = $this->createBuilderNode();
    $raw = $node->get('frame_layout')->value;

    $layouts->setPreview($node, self::layout(
      self::item('frame:heading', 'h1', ['text' => 'Unsaved']),
      self::item('frame:heading', 'h2', ['text' => 'Added']),
    ));
    $shown = $layouts->load($node);
    $this->assertSame(['Unsaved', 'Added'], array_map(static fn (array $item): string => $item['props']['text'], $shown['content']));
    $this->assertSame('Unsaved', $layouts->load(Node::load($node->id()))['content'][0]['props']['text'], 'The preview is for the page, not for one object of it.');
    $this->assertSame($raw, $node->get('frame_layout')->value, 'Nothing is set on the node.');
    $this->assertSame('Other', $layouts->load($other)['content'][0]['props']['text']);

    $layouts->setPreview($empty, self::layout(self::item('frame:heading', 'h1', ['text' => 'First layout'])));
    $this->assertSame('First layout', $layouts->load($empty)['content'][0]['props']['text'], 'A page without a layout can be previewed with one.');
    $layouts->setPreview($other, self::layout());
    $this->assertSame([], $layouts->load($other)['content'], 'An empty preview hides the stored layout.');

    $layouts->clearPreview($node);
    $this->assertSame('Stored', $layouts->load($node)['content'][0]['props']['text']);
    $this->assertSame('First layout', $layouts->load($empty)['content'][0]['props']['text'], 'Clearing one preview keeps the others.');
    $layouts->clearPreview($empty);
    $this->assertSame([], $layouts->load($empty));
    $layouts->clearPreview($empty);
    $this->assertSame([], $layouts->load($empty), 'Clearing a preview that is not set does nothing.');
  }

  /**
   * A preview is migrated like stored content: it may be a draft kept
   * before a component changed.
   */
  public function testPreviewIsMigrated(): void {
    $layouts = $this->container->get('frame_builder.layouts');
    $node = $this->createBuilderNode();
    $layouts->setPreview($node, ['content' => [
      ['type' => 'frame_builder_test_theme:mig-card', 'version' => 1, 'props' => ['id' => 'c1', 'title' => 'Old draft']],
      ['type' => 'frame_builder_test_theme:mig-card', 'version' => 3, 'props' => ['id' => 'c2', 'heading' => 'Current draft']],
    ]]);
    $shown = $layouts->load($node);
    $this->assertSame(LayoutMigrator::VERSION, $shown['version']);
    $this->assertSame('Old draft', $shown['content'][0]['props']['heading']);
    $this->assertSame(3, $shown['content'][0]['version']);
    $this->assertSame(['id' => 'c2', 'heading' => 'Current draft'], $shown['content'][1]['props']);
  }

  /**
   * The translation mode is "independent" only when the content type says
   * so; anything else is "synced".
   */
  #[DataProvider('modes')]
  public function testMode(mixed $setting, string $expected): void {
    $layouts = $this->container->get('frame_builder.layouts');
    $type = NodeType::create(['type' => 'other', 'name' => 'Other']);
    $type->setThirdPartySetting('frame_builder', 'enabled', TRUE);
    if ($setting !== NULL) {
      $type->setThirdPartySetting('frame_builder', 'translation_mode', $setting);
    }
    $type->save();
    $node = $this->createBuilderNode(['type' => 'other']);
    $this->assertSame($expected, $layouts->mode($node));
    $this->assertFalse($layouts->isSyncedTranslation($node), 'The original language is never a synced translation.');
  }

  /**
   * Data of testMode(): the setting of the content type, the mode.
   */
  public static function modes(): array {
    return [
      'synced' => ['synced', 'synced'],
      'independent' => ['independent', 'independent'],
      'not set' => [NULL, 'synced'],
      'empty' => ['', 'synced'],
      'unknown' => ['mirrored', 'synced'],
      'other case' => ['Independent', 'synced'],
    ];
  }

  /**
   * A node whose content type is gone is synced and not built with the
   * builder.
   */
  public function testNodeWithoutType(): void {
    $layouts = $this->container->get('frame_builder.layouts');
    $node = Node::create(['type' => 'missing', 'title' => 'No type']);
    $this->assertSame('synced', $layouts->mode($node));
    $this->assertFalse($layouts->isEnabled($node));
  }

}
