<?php

namespace Drupal\frame_builder;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\node\NodeInterface;

/**
 * Unsaved values of the paragraphs of Paragraph blocks, per user.
 *
 * The form of a paragraph in the builder saves nothing: while the user
 * types, its values are kept here (ParagraphBlockForm::autosave()), by page,
 * language and id of the block. The canvas and the preview show the
 * paragraph with them. When the page is saved, the paragraph is created or
 * gets a new revision (Paragraphs::persist()) and the draft is dropped.
 */
class ParagraphDrafts {

  public function __construct(protected PrivateTempStoreFactory $tempStore) {}

  /**
   * Draft values of the paragraph of a block: field name => value.
   */
  public function get(NodeInterface $node, string $block): ?array {
    $values = $this->store()->get($this->key($node, $block));
    return is_array($values) ? $values : NULL;
  }

  /**
   * Keeps the values of the paragraph of a block.
   */
  public function set(NodeInterface $node, string $block, array $values): void {
    $this->store()->set($this->key($node, $block), $values);
    $index = $this->blocks($node);
    if (!in_array($block, $index, TRUE)) {
      $index[] = $block;
      $this->store()->set($this->key($node), $index);
    }
  }

  /**
   * Drops the draft of one block, or of all blocks of the page.
   */
  public function forget(NodeInterface $node, ?string $block = NULL): void {
    foreach ($block === NULL ? $this->blocks($node) : [$block] as $id) {
      $this->store()->delete($this->key($node, $id));
    }
    $left = $block === NULL ? [] : array_values(array_diff($this->blocks($node), [$block]));
    $left ? $this->store()->set($this->key($node), $left) : $this->store()->delete($this->key($node));
  }

  /**
   * Puts values on a paragraph. Values that do not fit are skipped.
   */
  public static function apply(ContentEntityInterface $paragraph, array $values): void {
    foreach ($values as $name => $value) {
      if ($paragraph->hasField($name)) {
        try {
          $paragraph->set($name, $value);
        }
        catch (\Throwable) {
          // A value that no longer fits the field.
        }
      }
    }
  }

  /**
   * Ids of the blocks of a page that have a draft.
   */
  protected function blocks(NodeInterface $node): array {
    $index = $this->store()->get($this->key($node));
    return is_array($index) ? $index : [];
  }

  protected function store() {
    return $this->tempStore->get('frame_builder');
  }

  protected function key(NodeInterface $node, ?string $block = NULL): string {
    return 'paragraphs:' . $node->id() . ':' . $node->language()->getId() . ($block === NULL ? '' : ':' . $block);
  }

}
