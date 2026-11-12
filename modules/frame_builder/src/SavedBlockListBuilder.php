<?php

namespace Drupal\frame_builder;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;

/**
 * The library of saved blocks as a list: what each one is, when it was
 * changed, and renaming and deleting. Saved blocks are made and changed in
 * the page builder, from the Library section of a component.
 */
class SavedBlockListBuilder extends EntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader() {
    return [
      'label' => $this->t('Name'),
      'component' => $this->t('Component'),
      'blocks' => $this->t('Blocks in it'),
      'changed' => $this->t('Changed'),
    ] + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity) {
    $data = Json::decode((string) $entity->get(LayoutStorage::FIELD)->value);
    $item = is_array($data['content'][0] ?? NULL) ? $data['content'][0] : [];
    return [
      'label' => $entity->label(),
      'component' => (string) ($item['type'] ?? ''),
      'blocks' => $item ? $this->count([$item]) : 0,
      'changed' => \Drupal::service('date.formatter')->format($entity->getChangedTime(), 'short'),
    ] + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function render() {
    $build = parent::render();
    $build['table']['#empty'] = $this->t('Nothing is saved yet. In the page builder, select a component and use "Save to library" in its Library section.');
    return $build;
  }

  /**
   * Number of items, nested ones included.
   */
  protected function count(array $items): int {
    $count = 0;
    foreach ($items as $item) {
      $count++;
      foreach (is_array($item['props'] ?? NULL) ? $item['props'] : [] as $value) {
        if (LayoutMigrator::isItems($value)) {
          $count += $this->count($value);
        }
      }
    }
    return $count;
  }

}
