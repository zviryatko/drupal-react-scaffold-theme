<?php

declare(strict_types=1);

namespace Drupal\Tests\frame_builder\Kernel;

use Drupal\frame_builder\LayoutStorage;
use Drupal\Tests\frame_builder\Traits\LayoutTranslationTestTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Layouts of translations in independent mode: every translation has its
 * own layout, a copy of the original that changes freely.
 */
#[Group('frame_builder')]
#[RunTestsInSeparateProcesses]
class LayoutTranslationIndependentTest extends BuilderKernelTestBase {

  use LayoutTranslationTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['language', 'content_translation'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->setUpTranslation('independent');
  }

  /**
   * The service under test.
   */
  protected function layouts(): LayoutStorage {
    return $this->container->get('frame_builder.layouts');
  }

  /**
   * Ids of the top-level items of a layout.
   */
  protected static function ids(array $data): array {
    return array_map(static fn (array $item): string => $item['props']['id'], $data['content'] ?? []);
  }

  /**
   * No translation of an independent content type is a synced one.
   */
  public function testIsNotSyncedTranslation(): void {
    $node = $this->saveLayout($this->createBuilderNode(), self::layout(self::item('frame:heading', 'h1', ['text' => 'Welcome'])));
    $translation = $this->translate($node);
    $translation->save();
    $this->assertSame('independent', $this->layouts()->mode($translation));
    $this->assertFalse($this->layouts()->isSyncedTranslation($translation));
    $this->assertFalse($this->layouts()->isSyncedTranslation($node));
  }

  /**
   * A new translation starts with the copy of the layout Drupal makes.
   */
  public function testTranslationStartsAsACopy(): void {
    $node = $this->saveLayout($this->createBuilderNode(), self::layout(
      self::item('frame:heading', 'h1', ['text' => 'Welcome', 'level' => 'h2']),
      self::item('frame:button', 'b1', ['label' => 'Read more', 'url' => '/en/more']),
    ));
    $translation = $this->translate($node);
    $translation->save();
    $this->assertSame($this->layouts()->load($this->reload($node)), $this->layouts()->load($this->reload($node, 'de')));
  }

  /**
   * A translation stores a whole layout of its own: structure, settings and
   * texts differ from the original, which is not touched.
   */
  public function testTranslationChangesFreely(): void {
    $node = $this->saveLayout($this->createBuilderNode(), self::layout(
      self::item('frame:heading', 'h1', ['text' => 'Welcome', 'level' => 'h2']),
      self::item('frame:button', 'b1', ['label' => 'Read more', 'url' => '/en/more']),
    ));
    $translation = $this->translate($node);
    $this->saveLayout($translation, self::layout(
      self::item('frame:button', 'b1', ['label' => 'Weiterlesen', 'url' => '/de/mehr']),
      self::item('frame:heading', 'h1', ['text' => 'Willkommen', 'level' => 'h4']),
      self::item('frame:heading', 'extra', ['text' => 'Nur auf Deutsch']),
    ));

    $stored = $this->stored($this->reload($node, 'de'));
    $this->assertArrayNotHasKey('strings', $stored);
    $this->assertArrayNotHasKey('versions', $stored);
    $this->assertCount(3, $stored['content']);

    $german = $this->layouts()->load($this->reload($node, 'de'));
    $this->assertSame(['b1', 'h1', 'extra'], self::ids($german));
    $this->assertSame('h4', $german['content'][1]['props']['level'], 'Settings that are not text are its own too.');
    $this->assertSame('Willkommen', $german['content'][1]['props']['text']);

    $english = $this->layouts()->load($this->reload($node));
    $this->assertSame(['h1', 'b1'], self::ids($english));
    $this->assertSame('Welcome', $english['content'][0]['props']['text']);
    $this->assertSame('h2', $english['content'][0]['props']['level']);
  }

  /**
   * Changes of the original after the translation was made do not reach it.
   */
  public function testOriginalChangesDoNotReachTheTranslation(): void {
    $node = $this->saveLayout($this->createBuilderNode(), self::layout(self::item('frame:heading', 'h1', ['text' => 'Welcome'])));
    $translation = $this->translate($node);
    $translation->save();

    $node = $this->reload($node);
    $node->setNewRevision(TRUE);
    $this->saveLayout($node, self::layout(
      self::item('frame:heading', 'h1', ['text' => 'Welcome back']),
      self::item('frame:heading', 'h2', ['text' => 'Added']),
    ));
    $german = $this->layouts()->load($this->reload($node, 'de'));
    $this->assertSame(['h1'], self::ids($german));
    $this->assertSame('Welcome', $german['content'][0]['props']['text']);

    $node = $this->reload($node);
    $node->setNewRevision(TRUE);
    $this->saveLayout($node, self::layout());
    $this->assertSame(['h1'], self::ids($this->layouts()->load($this->reload($node, 'de'))), 'Emptying the original keeps the layout of the translation.');
  }

  /**
   * A translation without a layout has none, whatever the original has, and
   * can get one while the original has none.
   */
  public function testLayoutOnOneLanguageOnly(): void {
    $node = $this->saveLayout($this->createBuilderNode(), self::layout(self::item('frame:heading', 'h1', ['text' => 'Welcome'])));
    $translation = $this->translate($node);
    $translation->set('frame_layout', NULL)->save();
    $this->assertSame([], $this->layouts()->load($this->reload($node, 'de')));
    $this->assertSame(['h1'], self::ids($this->layouts()->load($this->reload($node))));

    $other = $this->createBuilderNode();
    $translation = $this->translate($other);
    $this->saveLayout($translation, self::layout(self::item('frame:heading', 'de1', ['text' => 'Nur Deutsch'])));
    $this->assertSame(['de1'], self::ids($this->layouts()->load($this->reload($other, 'de'))));
    $this->assertSame([], $this->layouts()->load($this->reload($other)));
  }

  /**
   * The source of the structure is of no use to an independent translation.
   */
  public function testSourceIsIgnored(): void {
    $node = $this->saveLayout($this->createBuilderNode(), self::layout(self::item('frame:heading', 'h1', ['text' => 'Welcome'])));
    $translation = $this->translate($node);
    $this->saveLayout($translation, self::layout(self::item('frame:heading', 'de1', ['text' => 'Deutsch'])));
    $translation = $this->reload($node, 'de');
    $source = $this->reload($node);

    $this->assertSame(['de1'], self::ids($this->layouts()->load($translation, $source)));
    $this->layouts()->store($translation, self::layout(self::item('frame:heading', 'de2', ['text' => 'Anders'])), $source);
    $stored = $this->stored($translation);
    $this->assertArrayNotHasKey('strings', $stored);
    $this->assertSame('de2', $stored['content'][0]['props']['id']);
  }

  /**
   * Every revision keeps the layout of each language.
   */
  public function testRevisions(): void {
    $node = $this->saveLayout($this->createBuilderNode(), self::layout(self::item('frame:heading', 'h1', ['text' => 'Welcome'])));
    $translation = $this->translate($node);
    $this->saveLayout($translation, self::layout(self::item('frame:heading', 'de1', ['text' => 'Erste Fassung'])));
    $first = $this->reload($node)->getRevisionId();

    $translation = $this->reload($node, 'de');
    $translation->setNewRevision(TRUE);
    $this->saveLayout($translation, self::layout(self::item('frame:heading', 'de1', ['text' => 'Zweite Fassung'])));
    $this->assertNotSame($first, $this->reload($node)->getRevisionId());

    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $this->assertSame('Erste Fassung', $this->layouts()->load($storage->loadRevision($first)->getTranslation('de'))['content'][0]['props']['text']);
    $this->assertSame('Zweite Fassung', $this->layouts()->load($this->reload($node, 'de'))['content'][0]['props']['text']);
    $this->assertSame('Welcome', $this->layouts()->load($this->reload($node))['content'][0]['props']['text']);
  }

  /**
   * A preview is for one language of a page.
   */
  public function testPreviewPerLanguage(): void {
    $node = $this->saveLayout($this->createBuilderNode(), self::layout(self::item('frame:heading', 'h1', ['text' => 'Welcome'])));
    $translation = $this->translate($node);
    $translation->save();
    $this->layouts()->setPreview($node, self::layout(self::item('frame:heading', 'p', ['text' => 'Preview'])));
    $this->assertSame('Preview', $this->layouts()->load($node)['content'][0]['props']['text']);
    $this->assertSame('Welcome', $this->layouts()->load($translation)['content'][0]['props']['text']);
  }

}
