<?php

declare(strict_types=1);

namespace Drupal\Tests\frame_builder\Traits;

use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;

/**
 * What every test of the page builder needs: a content type that uses the
 * builder, pages of it, and layouts.
 */
trait BuilderTestTrait {

  /**
   * Creates a content type built with the page builder.
   *
   * @param string $type
   *   Machine name.
   * @param string $translation_mode
   *   "synced" or "independent".
   * @param bool $enabled
   *   Whether the builder is switched on for the type.
   */
  protected function createBuilderType(string $type = 'landing', string $translation_mode = 'synced', bool $enabled = TRUE): NodeType {
    $node_type = NodeType::create(['type' => $type, 'name' => ucfirst($type), 'new_revision' => TRUE]);
    $node_type->setThirdPartySetting('frame_builder', 'enabled', $enabled);
    $node_type->setThirdPartySetting('frame_builder', 'translation_mode', $translation_mode);
    $node_type->save();
    return $node_type;
  }

  /**
   * Creates and saves a page of a builder content type.
   */
  protected function createBuilderNode(array $values = []): NodeInterface {
    $node = Node::create($values + ['type' => 'landing', 'title' => 'A page', 'status' => 1]);
    $node->save();
    return $node;
  }

  /**
   * An item of a layout, as the editor sends it.
   *
   * @param string $type
   *   Component id: "frame:heading", "drupal_field:title"...
   * @param string $id
   *   Id of the item, unique in the layout.
   * @param array $props
   *   Its props; slots are lists of items.
   */
  protected static function item(string $type, string $id, array $props = []): array {
    return ['type' => $type, 'props' => ['id' => $id] + $props];
  }

  /**
   * A layout document with the given items.
   */
  protected static function layout(array ...$items): array {
    return ['root' => ['props' => []], 'content' => array_values($items)];
  }

  /**
   * Stores a layout on a node and saves it, as the editor's save does.
   */
  protected function saveLayout(NodeInterface $node, array $data): NodeInterface {
    \Drupal::service('frame_builder.layouts')->store($node, $data);
    $node->save();
    return $node;
  }

}
