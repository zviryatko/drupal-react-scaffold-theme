<?php

namespace Drupal\react_scaffold_builder;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\react_scaffold_builder\Entity\SavedBlock;

/**
 * The library of saved blocks: components, with everything in them, that
 * are used on more than one page.
 *
 * A page uses a saved block in one of two ways:
 * - linked: the layout has an item of the type "drupal_saved:<uuid>" and
 *   nothing of the block itself. The page shows the block as it is saved
 *   now; changing the saved block changes every such page.
 * - as a copy: the layout has the items themselves, and the first one
 *   remembers where it came from in its "_library" prop. The copy is the
 *   page's own and no longer follows the saved block.
 */
class Library {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected LayoutMigrator $migrator,
  ) {}

  /**
   * All saved blocks, by name.
   *
   * @return \Drupal\react_scaffold_builder\Entity\SavedBlock[]
   */
  public function all(): array {
    if (!$this->entityTypeManager->hasDefinition('react_scaffold_saved')) {
      return [];
    }
    try {
      $storage = $this->entityTypeManager->getStorage('react_scaffold_saved');
      return $storage->loadMultiple($storage->getQuery()->accessCheck(FALSE)->sort('label')->execute());
    }
    catch (\Exception) {
      // The table is not there yet: updates have not run.
      return [];
    }
  }

  /**
   * A saved block by UUID.
   */
  public function load(string $uuid): ?SavedBlock {
    if ($uuid === '' || !$this->entityTypeManager->hasDefinition('react_scaffold_saved')) {
      return NULL;
    }
    try {
      $found = $this->entityTypeManager->getStorage('react_scaffold_saved')->loadByProperties(['uuid' => $uuid]);
    }
    catch (\Exception) {
      return NULL;
    }
    return $found ? reset($found) : NULL;
  }

  /**
   * The item a saved block consists of, migrated to the current components.
   */
  public function item(SavedBlock $saved): ?array {
    $data = Json::decode((string) $saved->get(LayoutStorage::FIELD)->value);
    if (!is_array($data) || !is_array($data['content'][0] ?? NULL)) {
      return NULL;
    }
    return $this->migrator->migrate($data)['content'][0];
  }

  /**
   * Creates a saved block from an item sent by the editor.
   */
  public function create(string $label, array $item): SavedBlock {
    $saved = $this->entityTypeManager->getStorage('react_scaffold_saved')->create(['label' => $label]);
    $this->setItem($saved, $item);
    $saved->save();
    return $saved;
  }

  /**
   * Replaces what a saved block consists of. The caller saves.
   */
  public function setItem(SavedBlock $saved, array $item): void {
    $item = $this->clean($item, $saved->uuid());
    $data = $this->migrator->migrate(['content' => [$item]], TRUE);
    $saved->set(LayoutStorage::FIELD, Json::encode($data));
  }

  /**
   * An item as it is kept in the library: without the note where a copy came
   * from, without a place on a page, and without links to the block itself.
   */
  protected function clean(array $item, string $uuid): array {
    $props = is_array($item['props'] ?? NULL) ? $item['props'] : [];
    unset($props['_library']);
    foreach ($props as $name => $value) {
      if (LayoutMigrator::isItems($value)) {
        $children = [];
        foreach ($value as $child) {
          if (($child['type'] ?? '') !== ComponentCatalog::SAVED . ':' . $uuid) {
            $children[] = $this->clean($child, $uuid);
          }
        }
        $props[$name] = $children;
      }
    }
    return ['props' => $props] + $item;
  }

}
