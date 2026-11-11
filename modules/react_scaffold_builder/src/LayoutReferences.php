<?php

namespace Drupal\react_scaffold_builder;

use Drupal\Component\Utility\Html;

/**
 * Finds the content a layout refers to: media items and linked pages.
 *
 * It reads the stored document as it is, without the components' schemas,
 * so it works whatever theme is active and for components that are gone.
 */
class LayoutReferences {

  /**
   * What a stored layout document refers to.
   *
   * @param array $data
   *   A layout, or the translated values of a synced translation.
   *
   * @return array
   *   - media: ids of media items picked as an image or a background;
   *   - media_uuid: UUIDs of media items embedded in rich text;
   *   - node: ids of content picked as a link;
   *   - saved: UUIDs of the blocks of the library the layout links to;
   *   - paragraph: ['id' => ..., 'vid' => ...] of the paragraphs of Paragraph
   *     blocks.
   */
  public static function find(array $data): array {
    $found = ['media' => [], 'media_uuid' => [], 'node' => [], 'saved' => [], 'paragraph' => []];
    self::walk($data, $found);
    $paragraphs = array_values($found['paragraph']);
    return ['paragraph' => $paragraphs] + array_map(static fn (array $ids): array => array_keys($ids), $found);
  }

  /**
   * Looks at one value and everything in it.
   */
  protected static function walk(mixed $value, array &$found): void {
    if (is_string($value)) {
      // A link picked from the site's content.
      if (preg_match('#^entity:node/(\d+)#', $value, $match)) {
        $found['node'][(int) $match[1]] = TRUE;
      }
      return;
    }
    if (!is_array($value)) {
      return;
    }
    // A linked block of the library: an item of the type "drupal_saved:<uuid>".
    if (is_string($value['type'] ?? NULL) && is_array($value['props'] ?? NULL) && str_starts_with($value['type'], ComponentCatalog::SAVED . ':')) {
      $found['saved'][substr($value['type'], strlen(ComponentCatalog::SAVED) + 1)] = TRUE;
    }
    // The paragraph of a Paragraph block: {"id", "vid", "uuid"}.
    if (isset($value['id'], $value['vid'], $value['uuid']) && is_numeric($value['id']) && is_numeric($value['vid']) && is_string($value['uuid'])) {
      $found['paragraph'][(int) $value['vid']] = ['id' => (int) $value['id'], 'vid' => (int) $value['vid']];
    }
    // A picked image: {"media": id, "url", "style", ...}.
    if (isset($value['media']) && is_numeric($value['media']) && (int) $value['media'] > 0) {
      $found['media'][(int) $value['media']] = TRUE;
    }
    // Rich text: {"format", "value"}.
    if (isset($value['format']) && is_string($value['value'] ?? NULL) && str_contains($value['value'], '<')) {
      self::html($value['value'], $found);
    }
    foreach ($value as $child) {
      self::walk($child, $found);
    }
  }

  /**
   * Media embedded in, and content linked from, a piece of rich text.
   */
  protected static function html(string $html, array &$found): void {
    $xpath = new \DOMXPath(Html::load($html));
    foreach ($xpath->query('//drupal-media[@data-entity-uuid]') as $element) {
      if ($uuid = $element->getAttribute('data-entity-uuid')) {
        $found['media_uuid'][$uuid] = TRUE;
      }
    }
    foreach ($xpath->query('//a[@href]') as $element) {
      if (preg_match('#^(?:entity:node/|/node/)(\d+)$#', $element->getAttribute('href'), $match)) {
        $found['node'][(int) $match[1]] = TRUE;
      }
    }
  }

}
