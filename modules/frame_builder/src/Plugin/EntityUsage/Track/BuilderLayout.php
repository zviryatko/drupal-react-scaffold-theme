<?php

namespace Drupal\frame_builder\Plugin\EntityUsage\Track;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\entity_usage\Attribute\EntityUsageTrack;
use Drupal\entity_usage\EntityUsageTrackBase;
use Drupal\frame_builder\LayoutReferences;
use Drupal\frame_builder\LayoutStorage;
use Drupal\frame_builder\Paragraphs;

/**
 * Tells the Entity Usage module what a page builder layout uses.
 *
 * Media picked as an image or a background, media embedded in rich text and
 * content picked as a link and blocks of the builder's library, per revision
 * and translation of the page; and the same for what a saved block uses. Only
 * discovered when Entity Usage is installed.
 */
#[EntityUsageTrack(
  id: 'frame_builder',
  label: new TranslatableMarkup('Page builder layout'),
  description: new TranslatableMarkup('Tracks media and linked content in layouts made with the Frame page builder.'),
  field_types: ['string_long'],
)]
class BuilderLayout extends EntityUsageTrackBase {

  /**
   * {@inheritdoc}
   *
   * Only the layout field. It is a base field, and those are left out by
   * default ("track_enabled_base_fields"): a layout is content, not one of
   * the base fields that setting is about, so it is tracked regardless.
   */
  public function getReferencingFields(EntityInterface $source_entity, array $field_types): array {
    if (!$source_entity instanceof FieldableEntityInterface || !$source_entity->hasField(LayoutStorage::FIELD)) {
      return [];
    }
    return [LayoutStorage::FIELD => $source_entity->getFieldDefinition(LayoutStorage::FIELD)];
  }

  /**
   * {@inheritdoc}
   */
  public function getTargetEntities(FieldItemInterface $item): array {
    $data = Json::decode((string) $item->value);
    if (!is_array($data)) {
      return [];
    }
    $found = LayoutReferences::find($data);
    return array_values(array_unique(array_merge(
      $this->checkAndPrepareEntityIds('media', $found['media'], 'id'),
      $this->checkAndPrepareEntityIds('media', $found['media_uuid'], 'uuid'),
      $this->checkAndPrepareEntityIds('node', $found['node'], 'id'),
      $this->checkAndPrepareEntityIds('frame_saved', $found['saved'], 'uuid'),
      $this->paragraphTargets($item),
    )));
  }

  /**
   * What the paragraphs of Paragraph blocks use.
   *
   * Entity Usage does not record paragraphs themselves: what they use counts
   * for the page they are on. That is the work of its plugin for Entity
   * Reference Revisions fields, which is asked here for the field that lists
   * the paragraphs of the layout (a base field, which it skips by default).
   */
  protected function paragraphTargets(FieldItemInterface $item): array {
    $entity = $item->getEntity();
    if (!$entity->hasField(Paragraphs::FIELD) || $entity->get(Paragraphs::FIELD)->isEmpty()) {
      return [];
    }
    $targets = [];
    try {
      $plugin = \Drupal::service('plugin.manager.entity_usage.track')->createInstance('entity_reference_revisions');
      foreach ($entity->get(Paragraphs::FIELD) as $reference) {
        $targets = array_merge($targets, $plugin->getTargetEntities($reference));
      }
    }
    catch (\Exception) {
      // That plugin is not there: the paragraphs' own references are not known.
    }
    return $targets;
  }

}
