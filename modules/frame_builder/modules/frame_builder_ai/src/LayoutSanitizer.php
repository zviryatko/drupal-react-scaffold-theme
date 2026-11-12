<?php

namespace Drupal\frame_builder_ai;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldConfigInterface;
use Drupal\node\NodeInterface;
use Drupal\frame_builder\LayoutMigrator;
use Drupal\frame_builder\MediaItems;

/**
 * Turns the layout a model proposed into one the builder accepts.
 *
 * A model is asked for a layout, but what it returns is text: nothing in it
 * is trusted. Only what the editor could have produced by hand is kept:
 * - items of components that exist, with ids that are unique;
 * - for every prop, a value of the kind its field takes, and for choices one
 *   of the options;
 * - spacing, alignment and background colours from the theme's tokens.
 *
 * - images only as media items of the library that exist and that the user
 *   may see (the model finds them with its media search), never as a URL.
 *
 * - the content of a Paragraph block only as a copy of an existing paragraph
 *   the user may see.
 *
 * What a model cannot know or choose (the settings of a field formatter) is
 * never taken from it. When it keeps an item of the page (same
 * id and type), those values stay as they were; on a new item they are
 * empty.
 */
class LayoutSanitizer {

  /**
   * Widgets whose value is always carried over from the existing item.
   */
  protected const KEEP = ['formatter', 'library', 'datetime'];

  /**
   * Ids used so far in the layout being sanitized.
   */
  protected array $ids = [];

  protected ?NodeInterface $node = NULL;

  protected bool $final = FALSE;

  public function __construct(protected AccountInterface $currentUser, protected MediaItems $mediaItems, protected EntityTypeManagerInterface $entityTypeManager) {}

  /**
   * @param array $items
   *   The proposed items.
   * @param array $components
   *   The editor's component list (ComponentCatalog::editorConfig()).
   * @param array $current
   *   The items of the page as it is, to carry values over from.
   *
   * @param \Drupal\node\NodeInterface|null $node
   *   The page being built.
   * @param bool $final
   *   Whether this is the layout that goes to the editor (not a check of the
   *   model). Only then is the content of copied paragraphs kept for it.
   *
   * @return array
   *   Items the builder accepts.
   */
  public function sanitize(array $items, array $components, array $current, ?NodeInterface $node = NULL, bool $final = FALSE): array {
    $this->ids = [];
    $this->node = $node;
    $this->final = $final;
    $existing = [];
    $this->index($current, $existing);
    return $this->items($items, array_column($components, NULL, 'id'), $existing, 0);
  }

  /**
   * Items of the page by id.
   */
  protected function index(array $items, array &$found): void {
    foreach ($items as $item) {
      if (is_array($item) && is_array($item['props'] ?? NULL)) {
        $found[(string) ($item['props']['id'] ?? '')] = $item;
        foreach ($item['props'] as $value) {
          if (LayoutMigrator::isItems($value)) {
            $this->index($value, $found);
          }
        }
      }
    }
  }

  /**
   * A list of items.
   */
  protected function items(array $items, array $components, array $existing, int $depth): array {
    $result = [];
    // Deeper than any page needs: a runaway answer.
    if ($depth > 8) {
      return $result;
    }
    foreach (array_slice(array_values($items), 0, 200) as $item) {
      $type = is_array($item) ? (string) ($item['type'] ?? '') : '';
      if (!isset($components[$type])) {
        continue;
      }
      $given = is_array($item['props'] ?? NULL) ? $item['props'] : [];
      $id = is_string($given['id'] ?? NULL) && preg_match('/^[\w\/:.-]{1,120}$/', $given['id']) ? $given['id'] : '';
      $before = $id !== '' && ($existing[$id]['type'] ?? NULL) === $type ? $existing[$id] : NULL;
      if ($id === '' || isset($this->ids[$id])) {
        $id = str_replace(':', '/', $type) . '-' . bin2hex(random_bytes(6));
        $before = NULL;
      }
      $this->ids[$id] = TRUE;
      $props = ['id' => $id];
      foreach ($components[$type]['fields'] as $field) {
        $name = $field['name'];
        $widget = $field['widget'] ?? 'text';
        if (in_array($widget, self::KEEP, TRUE)) {
          // On a new item: what the editor would start it with.
          $value = $before ? ($before['props'][$name] ?? NULL) : ($field['default'] ?? NULL);
        }
        else {
          $value = ($field['widget'] ?? '') === 'paragraph'
            ? $this->paragraph($given[$name] ?? NULL, $field, $before['props'][$name] ?? NULL, $id)
            : $this->value($given[$name] ?? NULL, $field, $before['props'][$name] ?? NULL);
        }
        if ($value === NULL && !empty($field['required']) && isset($field['default'])) {
          $value = $widget === 'html' ? $this->html($field['default'], $field, NULL) : $field['default'];
        }
        if ($value !== NULL) {
          $props[$name] = $value;
        }
      }
      foreach ($components[$type]['slots'] as $slot) {
        $children = $given[$slot['name']] ?? [];
        $props[$slot['name']] = is_array($children) ? $this->items($children, $components, $existing, $depth + 1) : [];
      }
      // The version of a kept item stays; a new one gets the current one.
      $result[] = ['type' => $type] + ($before && isset($before['version']) ? ['version' => $before['version']] : []) + ['props' => $props];
    }
    return $result;
  }

  /**
   * One value, as the field of that prop takes it; NULL when it does not.
   */
  protected function value(mixed $value, array $field, mixed $before): mixed {
    if ($value === NULL) {
      return NULL;
    }
    $options = array_column($field['options'] ?? [], 'value');
    $sizes = array_column($field['sizes'] ?? [], 'value');
    switch ($field['widget'] ?? 'text') {
      case 'text':
      case 'textarea':
      case 'url':
        return is_scalar($value) ? mb_substr((string) $value, 0, 5000) : NULL;

      case 'html':
        return $this->html($value, $field, $before);

      case 'select':
      case 'icon':
        return is_scalar($value) && in_array((string) $value, array_map('strval', $options), TRUE) ? $value : NULL;

      case 'color':
        return is_string($value) && in_array($value, array_column($field['colors'] ?? [], 'value'), TRUE) ? $value : NULL;

      case 'boolean':
        return is_bool($value) ? $value : (is_scalar($value) ? filter_var($value, FILTER_VALIDATE_BOOLEAN) : NULL);

      case 'number':
        return is_numeric($value) ? $value + 0 : NULL;

      case 'length':
        return $this->length($value, $sizes, FALSE);

      case 'align':
        return in_array($value, ['start', 'center', 'end'], TRUE) ? $value : NULL;

      case 'spacing':
        if (!is_array($value)) {
          return NULL;
        }
        $spacing = [];
        foreach (['margin', 'padding'] as $property) {
          $length = $this->length($value[$property] ?? NULL, $sizes, $property === 'margin');
          if ($length) {
            $sides = $value[$property]['sides'] ?? 'all';
            $spacing[$property] = $length + ['sides' => in_array($sides, ['all', 'horizontal', 'vertical'], TRUE) ? $sides : 'all'];
          }
        }
        return $spacing ?: NULL;

      case 'textstyle':
        if (!is_array($value)) {
          return NULL;
        }
        $text = $this->length($value, $sizes, FALSE) ?? [];
        foreach (['weight' => ['normal', 'medium', 'bold'], 'transform' => ['uppercase', 'lowercase', 'capitalize'], 'leading' => ['tight', 'normal', 'loose']] as $key => $allowed) {
          if (in_array($value[$key] ?? NULL, $allowed, TRUE)) {
            $text[$key] = $value[$key];
          }
        }
        if (!empty($value['italic'])) {
          $text['italic'] = TRUE;
        }
        if (is_string($value['color'] ?? NULL) && in_array($value['color'], array_column($field['colors'] ?? [], 'value'), TRUE)) {
          $text['color'] = $value['color'];
        }
        return $text ?: NULL;

      case 'image':
        return $this->image($value, $before, $field['imageStyles'] ?? []);

      case 'position':
        if (!is_array($value)) {
          return NULL;
        }
        if (($value['mode'] ?? '') === 'relative' && preg_match('/^(top|middle|bottom)-(left|center|right)$/', (string) ($value['place'] ?? ''))) {
          return ['mode' => 'relative', 'place' => $value['place']];
        }
        if (($value['mode'] ?? '') === 'absolute' && in_array($value['corner'] ?? '', ['top-left', 'top-right', 'bottom-left', 'bottom-right'], TRUE)) {
          return ['mode' => 'absolute', 'corner' => $value['corner']]
            + (($offset = $this->length($value['offset'] ?? NULL, $sizes, FALSE)) ? ['offset' => $offset] : [])
            + (!empty($value['overlap']) ? ['overlap' => TRUE] : []);
        }
        return NULL;

      case 'background':
        // A colour of the palette and an image of the media library.
        $background = is_array($before) ? array_intersect_key($before, ['image' => 1, 'size' => 1, 'x' => 1, 'y' => 1]) : [];
        if (is_array($value) && array_key_exists('image', $value)) {
          $image = $this->image($value['image'], $background['image'] ?? NULL, $field['imageStyles'] ?? []);
          $background = $image ? ['image' => $image] + $background : array_diff_key($background, ['image' => 1]);
          if ($image && in_array($value['size'] ?? '', ['cover', 'contain', 'fill', 'auto'], TRUE)) {
            $background['size'] = $value['size'];
          }
        }
        $color = is_array($value) ? ($value['color'] ?? NULL) : NULL;
        if (is_string($color) && in_array($color, array_column($field['colors'] ?? [], 'value'), TRUE)) {
          $background['color'] = $color;
        }
        return $background ?: NULL;
    }
    return NULL;
  }

  /**
   * The paragraph of a Paragraph block.
   *
   * A kept block keeps its paragraph. A new block can start as a copy of an
   * existing paragraph of the same type that the user may see ({"copy":
   * <id>}, found with the paragraph search): its values become the unsaved
   * values of the block, and a paragraph of its own is created when the page
   * is saved. The paragraph that was copied is never shared or changed.
   */
  protected function paragraph(mixed $value, array $field, mixed $before, string $block): ?array {
    $copy = is_array($value) ? ($value['copy'] ?? NULL) : NULL;
    if (!is_numeric($copy) || !$this->entityTypeManager->hasDefinition('paragraph')) {
      return is_array($before) ? $before : NULL;
    }
    $source = $this->entityTypeManager->getStorage('paragraph')->load((int) $copy);
    if (!$source || $source->bundle() !== ($field['bundle'] ?? '') || !$source->access('view', $this->currentUser)) {
      return is_array($before) ? $before : NULL;
    }
    if (!$this->final || !$this->node) {
      // For a check of the model: what the copy would show.
      return ['id' => (int) $source->id(), 'vid' => (int) $source->getRevisionId(), 'uuid' => $source->uuid()];
    }
    $values = [];
    foreach ($source->getFieldDefinitions() as $name => $definition) {
      if ($definition instanceof FieldConfigInterface) {
        $values[$name] = $source->get($name)->getValue();
      }
    }
    \Drupal::service('frame_builder.paragraph_drafts')->set($this->node, $block, $values);
    return ['draft' => \Drupal::time()->getRequestTime()];
  }

  /**
   * An image: a media item of the library that exists, is an image and that
   * the user may see. Never a URL from the model.
   *
   * @param mixed $value
   *   {"media": <id>} or the id.
   * @param mixed $before
   *   The image the item had: kept when the model names the same one (with
   *   its image style, loading and link) or names none.
   * @param array $styles
   *   The image styles the field offers, as options.
   */
  protected function image(mixed $value, mixed $before, array $styles = []): ?array {
    $id = is_array($value) ? ($value['media'] ?? NULL) : $value;
    if ($value === NULL || (is_array($before) && (int) ($before['media'] ?? 0) === (int) $id)) {
      return is_array($before) ? $before : NULL;
    }
    if (!is_numeric($id) || !$this->entityTypeManager->hasDefinition('media')) {
      return NULL;
    }
    $media = $this->entityTypeManager->getStorage('media')->load((int) $id);
    $described = $media && $media->access('view', $this->currentUser) ? $this->mediaItems->describe($media) : NULL;
    if (!$described) {
      return NULL;
    }
    $image = ['url' => $described['url'], 'media' => $described['id'], 'alt' => $described['alt']];
    // Not the original file, which can be very large: an image style the
    // theme offers. A responsive one first, else a large one, else any.
    $offered = array_filter(array_map('strval', array_column($styles, 'value')));
    $style = current(array_filter($offered, static fn (string $id): bool => str_starts_with($id, 'responsive:')))
      ?: current(array_filter($offered, static fn (string $id): bool => str_contains($id, 'large')))
      ?: current($offered);
    return $image + ($style ? ['style' => $style] : []);
  }

  /**
   * A length: a step of the theme's scale or a custom CSS length.
   */
  protected function length(mixed $value, array $sizes, bool $auto): ?array {
    if (is_string($value)) {
      $value = in_array($value, $sizes, TRUE) || ($auto && $value === 'auto') ? ['size' => $value] : ['size' => 'custom', 'custom' => $value];
    }
    if (!is_array($value)) {
      return NULL;
    }
    $size = (string) ($value['size'] ?? '');
    if (in_array($size, $sizes, TRUE) || ($auto && $size === 'auto')) {
      return ['size' => $size];
    }
    $custom = trim((string) ($value['custom'] ?? ''));
    return $size === 'custom' && preg_match('/^-?\d+(\.\d+)?(px|rem|em|%|vw|vh|ch)$/', $custom) ? ['size' => 'custom', 'custom' => $custom] : NULL;
  }

  /**
   * Rich text: in a text format the user may use. Drupal filters it with
   * that format when the page is rendered, like any text typed by the user.
   */
  protected function html(mixed $value, array $field, mixed $before): ?array {
    $text = is_array($value) ? ($value['value'] ?? NULL) : $value;
    if (!is_string($text) || trim($text) === '') {
      return NULL;
    }
    $formats = filter_formats($this->currentUser);
    $format = $field['format'] ?? (is_array($before) ? ($before['format'] ?? NULL) : NULL);
    if (!is_string($format) || !isset($formats[$format])) {
      // The first format with an editor, as for text typed in the builder.
      $format = filter_default_format($this->currentUser);
      foreach (array_keys($formats) as $candidate) {
        if (\Drupal::moduleHandler()->moduleExists('editor') && \Drupal::entityTypeManager()->getStorage('editor')->load($candidate)) {
          $format = $candidate;
          break;
        }
      }
    }
    return ['format' => $format, 'value' => mb_substr($text, 0, 20000)];
  }

}
