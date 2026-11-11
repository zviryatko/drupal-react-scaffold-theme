<?php

namespace Drupal\react_scaffold_builder;

use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\node\NodeInterface;

/**
 * Unsaved field values of the node form in the builder, per user.
 *
 * BuilderContentForm autosaves what is typed into it here (private
 * tempstore: per user, expires by itself). The values are put on a copy of
 * the node for the preview of the builder and for the form itself after a
 * reload, so nothing is written to the node until it is saved.
 */
class FieldDrafts {

  public function __construct(protected PrivateTempStoreFactory $tempStore) {}

  /**
   * Draft values of a translation: field name => field value.
   */
  public function get(NodeInterface $node): array {
    $values = $this->tempStore->get('react_scaffold_builder')->get($this->key($node));
    return is_array($values) ? $values : [];
  }

  /**
   * Adds or replaces draft values of some fields.
   */
  public function merge(NodeInterface $node, array $values): void {
    $this->tempStore->get('react_scaffold_builder')->set($this->key($node), $values + $this->get($node));
  }

  /**
   * Drops the drafts of the given fields (they were saved), or all of them.
   */
  public function forget(NodeInterface $node, ?array $fields = NULL): void {
    $values = $fields === NULL ? [] : array_diff_key($this->get($node), array_flip($fields));
    $store = $this->tempStore->get('react_scaffold_builder');
    $values ? $store->set($this->key($node), $values) : $store->delete($this->key($node));
  }

  /**
   * A copy of the node with the draft values on it; the node itself when
   * there are none.
   */
  public function apply(NodeInterface $node): NodeInterface {
    $values = $this->get($node);
    if (!$values) {
      return $node;
    }
    // The loaded node is shared within the request: never change it.
    $copy = clone $node;
    foreach ($values as $name => $value) {
      if ($copy->hasField($name)) {
        try {
          $copy->set($name, $value);
        }
        catch (\Throwable) {
          // A value that no longer fits the field.
        }
      }
    }
    return $copy;
  }

  /**
   * Key of the draft: one per node and language.
   */
  protected function key(NodeInterface $node): string {
    return 'fields:' . $node->id() . ':' . $node->language()->getId();
  }

}
