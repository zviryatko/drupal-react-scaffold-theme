<?php

namespace Drupal\react_scaffold_builder;

use Drupal\Component\Serialization\Json;
use Drupal\node\NodeInterface;

/**
 * Reads and writes the layout of a node, per translation.
 *
 * Two translation modes, set per content type:
 * - synced: one structure for all languages. It is stored on the original
 *   language. A translation stores only the text and links it changes, keyed
 *   by "<item id>.<prop>", so adding, moving or removing components on the
 *   original applies everywhere.
 * - independent: every translation has its own layout. Drupal copies it from
 *   the original when the translation is created, after that nothing is
 *   shared.
 */
class LayoutStorage {

  public const FIELD = 'react_scaffold_layout';

  /**
   * Layouts to show instead of the stored ones during this request, keyed by
   * "<node id>:<langcode>". Used by the preview of unsaved changes.
   */
  protected array $previews = [];

  public function __construct(protected ComponentCatalog $catalog, protected LayoutMigrator $migrator, protected Paragraphs $paragraphs) {}

  /**
   * Shows the given layout for a translation for the rest of the request.
   */
  public function setPreview(NodeInterface $node, array $data): void {
    $this->previews[$node->id() . ':' . $node->language()->getId()] = $this->migrator->migrate($data);
  }

  /**
   * Stops showing a layout set with setPreview().
   */
  public function clearPreview(NodeInterface $node): void {
    unset($this->previews[$node->id() . ':' . $node->language()->getId()]);
  }

  /**
   * Whether the node is built with the builder: its content type uses it and
   * the site's theme supports it.
   */
  public function isEnabled(NodeInterface $node): bool {
    return (bool) $node->type->entity?->getThirdPartySetting('react_scaffold_builder', 'enabled', FALSE)
      // Without a theme based on React Scaffold there is nothing to build with.
      && \Drupal::service('react_scaffold_builder.theme_kit')->isSupportedTheme();
  }

  /**
   * Translation mode of the content type: "synced" or "independent".
   */
  public function mode(NodeInterface $node): string {
    $mode = $node->type->entity?->getThirdPartySetting('react_scaffold_builder', 'translation_mode', 'synced');
    return $mode === 'independent' ? 'independent' : 'synced';
  }

  /**
   * Whether this translation only overrides text of the original.
   */
  public function isSyncedTranslation(NodeInterface $node): bool {
    return $this->mode($node) === 'synced' && !$node->isDefaultTranslation();
  }

  /**
   * The layout to show for this translation, with every item migrated to the
   * current version of its component (LayoutMigrator).
   *
   * @param \Drupal\node\NodeInterface $node
   *   The translation.
   * @param \Drupal\node\NodeInterface|null $source
   *   The original language to take the structure from in synced mode. By
   *   default the one in the same revision as $node, which is right for
   *   viewing. The editor passes the latest revision of the original: the
   *   latest revision of a translation holds an older copy of it.
   */
  public function load(NodeInterface $node, ?NodeInterface $source = NULL): array {
    $preview = $this->previews[$node->id() . ':' . $node->language()->getId()] ?? NULL;
    if ($preview !== NULL) {
      return $preview;
    }
    if (!$this->isSyncedTranslation($node)) {
      $data = $this->decode($node);
      // A page without a layout stays without one.
      return $data ? $this->migrator->migrate($data) : $data;
    }
    $data = $this->decode($source ?? $node->getUntranslated());
    if (!$data) {
      return $data;
    }
    $data = $this->migrator->migrate($data);
    $own = $this->decode($node);
    $strings = is_array($own['strings'] ?? NULL) ? $own['strings'] : [];
    if ($strings && !empty($data['content'])) {
      // The values follow the props they were saved for, if those were renamed since.
      $strings = $this->migrator->migrateStrings($strings, is_array($own['versions'] ?? NULL) ? $own['versions'] : [], $data['content']);
      $data['content'] = $this->applyStrings($data['content'], $strings);
    }
    return $data;
  }

  /**
   * Sets the layout sent by the editor on the node. The caller saves.
   *
   * The stored document carries the version of the layout format and, per
   * item, the version of its component (LayoutMigrator).
   *
   * @param \Drupal\node\NodeInterface $node
   *   The translation being saved.
   * @param array $data
   *   The layout from the editor.
   * @param \Drupal\node\NodeInterface|null $source
   *   See load().
   */
  public function store(NodeInterface $node, array $data, ?NodeInterface $source = NULL): void {
    $data = $this->migrator->migrate($data, TRUE);
    if ($this->isSyncedTranslation($node)) {
      $original = $this->migrator->migrate($this->decode($source ?? $node->getUntranslated()));
      $submitted = [];
      $this->collect($data['content'] ?? [], $submitted);
      $strings = [];
      $this->diff($original['content'] ?? [], $submitted, $strings);
      $data = [
        'version' => LayoutMigrator::VERSION,
        'strings' => $strings,
        // The component version the values were saved for, by item id.
        'versions' => $this->migrator->stringVersions($strings, $original['content'] ?? []),
      ];
    }
    $node->set(self::FIELD, Json::encode($data));
    // The paragraphs this translation names belong to the page.
    if ($node->hasField(Paragraphs::FIELD)) {
      $node->set(Paragraphs::FIELD, $this->paragraphs->fieldValues($data));
    }
  }

  /**
   * The stored document of exactly this translation.
   */
  protected function decode(NodeInterface $node): array {
    $data = Json::decode((string) $node->get(self::FIELD)->value);
    return is_array($data) ? $data : [];
  }

  /**
   * Puts translated values into a copy of the original items.
   */
  protected function applyStrings(array $items, array $strings): array {
    foreach ($items as &$item) {
      $type = (string) ($item['type'] ?? '');
      $id = (string) ($item['props']['id'] ?? '');
      foreach ($this->catalog->translatableProps($type) as $name) {
        if (array_key_exists("$id.$name", $strings)) {
          $item['props'][$name] = $strings["$id.$name"];
        }
      }
      foreach ($this->catalog->slots($type) as $slot) {
        if (is_array($item['props'][$slot] ?? NULL)) {
          $item['props'][$slot] = $this->applyStrings($item['props'][$slot], $strings);
        }
      }
    }
    return $items;
  }

  /**
   * Props of every submitted item, keyed by item id.
   */
  protected function collect(array $items, array &$found): void {
    foreach ($items as $item) {
      $props = is_array($item['props'] ?? NULL) ? $item['props'] : [];
      $found[(string) ($props['id'] ?? '')] = $props;
      foreach ($this->catalog->slots((string) ($item['type'] ?? '')) as $slot) {
        if (is_array($props[$slot] ?? NULL)) {
          $this->collect($props[$slot], $found);
        }
      }
    }
  }

  /**
   * Translatable values that differ from the original, as "<id>.<prop>".
   *
   * Only the original's items are walked: a translation cannot change the
   * structure, whatever the request contains.
   */
  protected function diff(array $items, array $submitted, array &$strings): void {
    foreach ($items as $item) {
      $type = (string) ($item['type'] ?? '');
      $id = (string) ($item['props']['id'] ?? '');
      foreach ($this->catalog->translatableProps($type) as $name) {
        if (isset($submitted[$id]) && array_key_exists($name, $submitted[$id]) && $submitted[$id][$name] != ($item['props'][$name] ?? NULL)) {
          $strings["$id.$name"] = $submitted[$id][$name];
        }
      }
      foreach ($this->catalog->slots($type) as $slot) {
        if (is_array($item['props'][$slot] ?? NULL)) {
          $this->diff($item['props'][$slot], $submitted, $strings);
        }
      }
    }
  }

}
