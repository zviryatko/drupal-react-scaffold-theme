<?php

namespace Drupal\frame_builder\EventSubscriber;

use Drupal\Component\Serialization\Json;
use Drupal\Component\Uuid\Uuid;
use Drupal\Core\DefaultContent\ExportMetadata;
use Drupal\Core\DefaultContent\PreEntityImportEvent;
use Drupal\Core\DefaultContent\PreExportEvent;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\frame_builder\ComponentCatalog;
use Drupal\frame_builder\LayoutStorage;
use Drupal\frame_builder\Paragraphs;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Makes layouts part of Drupal's content export and import.
 *
 * Core exports content entities as YAML (`content:export`, the `content`
 * folder of a recipe) and names what an entity refers to by UUID, so the
 * export can be imported into another site. A layout is one field of a page,
 * and it names content by the ids of the site it was made on: the media
 * item of an image, the page a link goes to, the paragraph of a Paragraph
 * block. Here those become UUIDs on export, with the content they name as
 * dependencies of the page, and ids of the importing site on import.
 *
 * In the export the layout is the document itself, under `layout`, not the
 * JSON text the field stores: it reads and compares like the rest of the
 * file.
 */
class ContentExport implements EventSubscriberInterface {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityRepositoryInterface $entityRepository,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Core has these events from 11.2 on; naming a class does not load it.
    return [
      PreExportEvent::class => 'onExport',
      PreEntityImportEvent::class => 'onImport',
    ];
  }

  /**
   * Export: the layout with portable references.
   */
  public function onExport(PreExportEvent $event): void {
    $entity = $event->entity;
    if (!$entity->hasField(LayoutStorage::FIELD)) {
      return;
    }
    $event->setCallback(LayoutStorage::FIELD, function (FieldItemInterface $item, ExportMetadata $metadata): ?array {
      $data = Json::decode((string) $item->value);
      if (!is_array($data)) {
        return NULL;
      }
      return ['layout' => $this->portable($data, $metadata, $item->getEntity())];
    });
    // The list of the paragraphs of the layout is made again from the layout
    // on import.
    if ($entity->hasField(Paragraphs::FIELD)) {
      $event->setExportable(Paragraphs::FIELD, FALSE);
    }
  }

  /**
   * Import: the layout as the field stores it, with the ids of this site.
   */
  public function onImport(PreEntityImportEvent $event): void {
    $data = $event->data;
    foreach (array_merge(['default'], array_map(static fn ($langcode): string => "translations.$langcode", array_keys($data['translations'] ?? []))) as $where) {
      $values = &$data;
      foreach (explode('.', $where) as $key) {
        $values = &$values[$key];
      }
      if (!is_array($values[LayoutStorage::FIELD] ?? NULL)) {
        unset($values);
        continue;
      }
      $paragraphs = [];
      foreach ($values[LayoutStorage::FIELD] as $delta => $item) {
        if (is_array($item['layout'] ?? NULL)) {
          $values[LayoutStorage::FIELD][$delta] = ['value' => Json::encode($this->local($item['layout'], $paragraphs))];
        }
      }
      if ($paragraphs && ($event->metadata['entity_type'] ?? '') === 'node') {
        $values[Paragraphs::FIELD] = array_values($paragraphs);
      }
      unset($values);
    }
    $event->data = $data;
  }

  /**
   * A value of a layout with what it refers to named by UUID.
   */
  protected function portable(mixed $value, ExportMetadata $metadata, ContentEntityInterface $owner): mixed {
    if (is_string($value)) {
      // Links picked from the content of the site, also inside rich text.
      $value = preg_replace_callback('#\bentity:node/(\d+)(?![\w-])#', function (array $match) use ($metadata, $owner): string {
        $node = $this->entityTypeManager->getStorage('node')->load($match[1]);
        if (!$node) {
          return $match[0];
        }
        $this->depend($node, $metadata, $owner);
        return 'entity:node/' . $node->uuid();
      }, $value);
      // Media embedded in rich text are named by UUID already.
      if (str_contains($value, 'data-entity-uuid') && preg_match_all('#<drupal-media[^>]*\sdata-entity-uuid="([^"]+)"#', $value, $matches)) {
        foreach ($matches[1] as $uuid) {
          if ($this->entityTypeManager->hasDefinition('media') && ($media = $this->entityRepository->loadEntityByUuid('media', $uuid))) {
            $this->depend($media, $metadata, $owner);
          }
        }
      }
      return $value;
    }
    if (!is_array($value)) {
      return $value;
    }
    // A picked image: {"media": id, "url", "style", ...}.
    if (isset($value['media']) && is_numeric($value['media']) && $this->entityTypeManager->hasDefinition('media')) {
      if ($media = $this->entityTypeManager->getStorage('media')->load($value['media'])) {
        $this->depend($media, $metadata, $owner);
        $value = ['media_uuid' => $media->uuid()] + array_diff_key($value, ['media' => 1]);
      }
    }
    // The paragraph of a Paragraph block: {"id", "vid", "uuid"}.
    if (isset($value['id'], $value['vid'], $value['uuid']) && is_string($value['uuid']) && Uuid::isValid($value['uuid']) && $this->entityTypeManager->hasDefinition('paragraph')) {
      if ($paragraph = $this->entityRepository->loadEntityByUuid('paragraph', $value['uuid'])) {
        $this->depend($paragraph, $metadata, $owner);
      }
      return ['paragraph_uuid' => $value['uuid']];
    }
    // A linked block of the library is named by UUID already.
    if (is_string($value['type'] ?? NULL) && is_array($value['props'] ?? NULL) && str_starts_with($value['type'], ComponentCatalog::SAVED . ':')) {
      if ($saved = $this->entityRepository->loadEntityByUuid('frame_saved', substr($value['type'], strlen(ComponentCatalog::SAVED) + 1))) {
        $this->depend($saved, $metadata, $owner);
      }
    }
    foreach ($value as $key => $child) {
      $value[$key] = $this->portable($child, $metadata, $owner);
    }
    return $value;
  }

  /**
   * A value of an exported layout with the ids of this site.
   *
   * What is not here (yet) keeps its UUID: a page that links to itself gets
   * its id when it is saved (frame_builder_node_insert()).
   *
   * @param mixed $value
   *   The value.
   * @param array $paragraphs
   *   Collects the paragraphs the layout names, as values of the field that
   *   lists them.
   */
  protected function local(mixed $value, array &$paragraphs): mixed {
    if (is_string($value)) {
      return preg_replace_callback('#\bentity:node/([0-9a-f]{8}-[0-9a-f-]{27})#i', function (array $match): string {
        $node = $this->entityRepository->loadEntityByUuid('node', $match[1]);
        return $node ? 'entity:node/' . $node->id() : $match[0];
      }, $value);
    }
    if (!is_array($value)) {
      return $value;
    }
    if (is_string($value['media_uuid'] ?? NULL)) {
      $media = $this->entityTypeManager->hasDefinition('media') ? $this->entityRepository->loadEntityByUuid('media', $value['media_uuid']) : NULL;
      $value = ($media ? ['media' => (int) $media->id()] : []) + array_diff_key($value, ['media_uuid' => 1]);
    }
    if (is_string($value['paragraph_uuid'] ?? NULL)) {
      $paragraph = $this->entityTypeManager->hasDefinition('paragraph') ? $this->entityRepository->loadEntityByUuid('paragraph', $value['paragraph_uuid']) : NULL;
      if (!$paragraph) {
        return NULL;
      }
      $paragraphs[$paragraph->id()] = ['target_id' => $paragraph->id(), 'target_revision_id' => $paragraph->getRevisionId()];
      return Paragraphs::reference($paragraph);
    }
    foreach ($value as $key => $child) {
      $value[$key] = $this->local($child, $paragraphs);
    }
    return $value;
  }

  /**
   * Names an entity the layout refers to as a dependency of its page.
   */
  protected function depend(ContentEntityInterface $entity, ExportMetadata $metadata, ContentEntityInterface $owner): void {
    // A page that links to itself does not depend on itself.
    if ($entity->getEntityTypeId() !== $owner->getEntityTypeId() || $entity->id() !== $owner->id()) {
      $metadata->addDependency($entity);
    }
  }

}
