<?php

declare(strict_types=1);

namespace Drupal\Tests\frame_builder\Traits;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\Core\Url;
use Drupal\frame_builder\Paragraphs;
use Drupal\node\NodeInterface;

/**
 * What the tests of Paragraph blocks and of the library need: a paragraph
 * type with a text field, paragraphs, the "From library" type of the
 * Paragraphs Library, and layout items that name them.
 *
 * The classes of Paragraphs are named by string: the trait is loaded also on
 * a site without the module, where the tests are skipped.
 */
trait ParagraphsTestTrait {

  /**
   * The text field of the paragraph types of the tests.
   */
  protected const TEXT_FIELD = 'field_text';

  /**
   * Whether a module is in the code base, installed or not.
   *
   * Asked before the site of a test exists, to skip the tests of an
   * integration whose module is missing.
   */
  protected static function moduleInCodeBase(string $module): bool {
    // The bootstrap of the tests registers the namespace of every module it
    // finds, so that their classes load before a module is installed.
    foreach (\Composer\Autoload\ClassLoader::getRegisteredLoaders() as $loader) {
      if (isset($loader->getPrefixesPsr4()['Drupal\\' . $module . '\\'])) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Skips the test unless every one of the modules is in the code base.
   */
  protected function requireModules(string ...$modules): void {
    foreach ($modules as $module) {
      if (!self::moduleInCodeBase($module)) {
        $this->markTestSkipped(sprintf('Needs the %s module.', $module));
      }
    }
  }

  /**
   * Creates a paragraph type with one text field, shown and edited.
   *
   * @param string $id
   *   Machine name.
   * @param string $label
   *   Name.
   * @param bool $required
   *   Whether the text is required.
   * @param string $description
   *   Description of the type.
   */
  protected function createParagraphType(string $id = 'text_block', string $label = 'Text block', bool $required = FALSE, string $description = ''): void {
    \Drupal::entityTypeManager()->getStorage('paragraphs_type')->create(['id' => $id, 'label' => $label, 'description' => $description])->save();
    if (!FieldStorageConfig::loadByName('paragraph', static::TEXT_FIELD)) {
      FieldStorageConfig::create(['entity_type' => 'paragraph', 'field_name' => static::TEXT_FIELD, 'type' => 'string'])->save();
    }
    FieldConfig::create(['entity_type' => 'paragraph', 'bundle' => $id, 'field_name' => static::TEXT_FIELD, 'label' => 'Text', 'required' => $required])->save();
    EntityViewDisplay::create(['targetEntityType' => 'paragraph', 'bundle' => $id, 'mode' => 'default', 'status' => TRUE])
      ->setComponent(static::TEXT_FIELD, ['type' => 'string', 'label' => 'hidden'])
      ->save();
    EntityFormDisplay::create(['targetEntityType' => 'paragraph', 'bundle' => $id, 'mode' => 'default', 'status' => TRUE])
      ->setComponent(static::TEXT_FIELD, ['type' => 'string_textfield'])
      ->save();
  }

  /**
   * Creates the "From library" paragraph type as the Paragraphs Library
   * module installs it: a reference to an item of the library, shown as the
   * item.
   */
  protected function createFromLibraryType(): void {
    \Drupal::entityTypeManager()->getStorage('paragraphs_type')->create(['id' => Paragraphs::LIBRARY_TYPE, 'label' => 'From library'])->save();
    FieldStorageConfig::create([
      'entity_type' => 'paragraph',
      'field_name' => Paragraphs::LIBRARY_FIELD,
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'paragraphs_library_item'],
    ])->save();
    FieldConfig::create(['entity_type' => 'paragraph', 'bundle' => Paragraphs::LIBRARY_TYPE, 'field_name' => Paragraphs::LIBRARY_FIELD, 'label' => 'Reusable paragraph', 'required' => TRUE])->save();
    EntityViewDisplay::create(['targetEntityType' => 'paragraph', 'bundle' => Paragraphs::LIBRARY_TYPE, 'mode' => 'default', 'status' => TRUE])
      ->setComponent(Paragraphs::LIBRARY_FIELD, ['type' => 'entity_reference_entity_view', 'label' => 'hidden', 'settings' => ['view_mode' => 'default']])
      ->save();
    EntityViewDisplay::create(['targetEntityType' => 'paragraphs_library_item', 'bundle' => 'paragraphs_library_item', 'mode' => 'default', 'status' => TRUE])
      ->setComponent('paragraphs', ['type' => 'entity_reference_revisions_entity_view', 'label' => 'hidden', 'settings' => ['view_mode' => 'default']])
      ->save();
  }

  /**
   * Creates and saves a paragraph.
   *
   * @param string $text
   *   Its text.
   * @param \Drupal\Core\Entity\ContentEntityInterface|null $owner
   *   The page or saved block it belongs to, if any.
   * @param string $bundle
   *   Its type.
   * @param string $field
   *   The field of the owner that lists it.
   */
  protected function createParagraph(string $text, ?ContentEntityInterface $owner = NULL, string $bundle = 'text_block', string $field = Paragraphs::FIELD): ContentEntityInterface {
    $paragraph = \Drupal::entityTypeManager()->getStorage('paragraph')->create(['type' => $bundle, static::TEXT_FIELD => $text]);
    if ($owner) {
      $paragraph->setParentEntity($owner, $field);
    }
    $paragraph->save();
    return $paragraph;
  }

  /**
   * Saves a new revision of a paragraph with another text.
   */
  protected function reviseParagraph(ContentEntityInterface $paragraph, string $text): ContentEntityInterface {
    $paragraph = $this->reloadParagraph($paragraph);
    $paragraph->setNewRevision(TRUE);
    $paragraph->set(static::TEXT_FIELD, $text);
    $paragraph->save();
    return $paragraph;
  }

  /**
   * A paragraph as it is stored now.
   */
  protected function reloadParagraph(ContentEntityInterface|int|string $paragraph): ?ContentEntityInterface {
    $storage = \Drupal::entityTypeManager()->getStorage('paragraph');
    $id = $paragraph instanceof ContentEntityInterface ? $paragraph->id() : $paragraph;
    $storage->resetCache([$id]);
    return $storage->load($id);
  }

  /**
   * The text of a paragraph.
   */
  protected static function paragraphText(ContentEntityInterface $paragraph): ?string {
    return $paragraph->get(static::TEXT_FIELD)->value;
  }

  /**
   * Number of paragraphs of the site, and of their revisions.
   *
   * @return int[]
   *   [paragraphs, revisions].
   */
  protected function paragraphCounts(): array {
    $storage = \Drupal::entityTypeManager()->getStorage('paragraph');
    return [
      (int) $storage->getQuery()->accessCheck(FALSE)->count()->execute(),
      (int) $storage->getQuery()->accessCheck(FALSE)->allRevisions()->count()->execute(),
    ];
  }

  /**
   * A Paragraph block, as the editor sends it.
   *
   * @param string $id
   *   Id of the block.
   * @param \Drupal\Core\Entity\ContentEntityInterface|array|null $paragraph
   *   The paragraph the block names, a reference, or nothing: a block whose
   *   form was not saved yet.
   * @param string $bundle
   *   The paragraph type.
   * @param array $props
   *   More props.
   */
  protected static function paragraphItem(string $id, ContentEntityInterface|array|null $paragraph = NULL, string $bundle = 'text_block', array $props = []): array {
    if ($paragraph instanceof ContentEntityInterface) {
      $paragraph = Paragraphs::reference($paragraph);
    }
    return ['type' => 'drupal_paragraph:' . $bundle, 'props' => ['id' => $id] + ($paragraph === NULL ? [] : ['paragraph' => $paragraph]) + $props];
  }

  /**
   * A block that links to a saved block or to an item of the library.
   */
  protected static function savedItem(string $id, string $uuid, array $props = []): array {
    return ['type' => 'drupal_saved:' . $uuid, 'props' => ['id' => $id] + $props];
  }

  /**
   * The component of the editor's list with an id, NULL when not offered.
   */
  protected static function component(array $config, string $id): ?array {
    foreach ($config as $component) {
      if ($component['id'] === $id) {
        return $component;
      }
    }
    return NULL;
  }

  /**
   * The field of a component of the editor's list, by name.
   */
  protected static function componentField(array $component, string $name): ?array {
    foreach ($component['fields'] as $field) {
      if (($field['name'] ?? NULL) === $name) {
        return $field;
      }
    }
    return NULL;
  }

  /**
   * The URL of the form of the paragraph of a block (functional tests).
   *
   * @param \Drupal\node\NodeInterface $node
   *   The page.
   * @param string $block
   *   Id of the block.
   * @param array|null $reference
   *   The paragraph the block names, if any: 'id' and 'vid'.
   * @param string $bundle
   *   The paragraph type.
   */
  protected function paragraphFormUrl(NodeInterface $node, string $block, ?array $reference = NULL, string $bundle = 'text_block'): Url {
    $query = ['type' => $bundle, 'block' => $block, 'key' => 'key-' . $block];
    if ($reference) {
      $query += ['id' => $reference['id'], 'vid' => $reference['vid']];
    }
    return Url::fromRoute('frame_builder.paragraph_form', ['node' => $node->id()], ['query' => $query]);
  }

  /**
   * Types a text in the form of the paragraph of a block and lets the form
   * keep it, as the script of the form does in the background (functional
   * tests).
   */
  protected function typeInParagraphForm(NodeInterface $node, string $block, string $text, ?array $reference = NULL, string $bundle = 'text_block'): void {
    $this->drupalGet($this->paragraphFormUrl($node, $block, $reference, $bundle));
    $this->assertSession()->statusCodeEquals(200);
    $this->submitForm([static::TEXT_FIELD . '[0][value]' => $text], 'Keep as draft');
    $this->assertSession()->statusCodeEquals(200);
  }

  /**
   * Renders a render array to HTML.
   */
  protected function html(array $build): string {
    return (string) \Drupal::service('renderer')->renderInIsolation($build);
  }

}
