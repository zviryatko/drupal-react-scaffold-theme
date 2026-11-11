<?php

namespace Drupal\react_scaffold_builder;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\node\NodeInterface;

/**
 * Paragraphs in a layout.
 *
 * With the Paragraphs module, every paragraph type is a block of the
 * builder. The content of such a block is a paragraph entity, edited with
 * its own Drupal form; the layout only says which one, and where:
 *
 * @code
 * {"type": "drupal_paragraph:text", "props": {"id": "...",
 *   "paragraph": {"id": 12, "vid": 57, "uuid": "..."}, "_spacing": {...}}}
 * @endcode
 *
 * "vid" pins the revision. The form of the paragraph in the builder saves
 * nothing (ParagraphDrafts); when the page is saved, a changed paragraph
 * gets a new revision and the layout names it (persist()). Old revisions of
 * the page keep showing the paragraph as it was.
 *
 * The page also lists its paragraphs in an Entity Reference Revisions field
 * (self::FIELD), so that they belong to the page the way Paragraphs expects:
 * they are deleted with it and are not seen as orphans.
 */
class Paragraphs {

  /**
   * The Entity Reference Revisions field of nodes that lists the paragraphs
   * of the layout.
   */
  public const FIELD = 'react_scaffold_paragraphs';

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ModuleHandlerInterface $moduleHandler,
    protected ParagraphDrafts $drafts,
  ) {}

  /**
   * Whether paragraphs are shown with the unsaved values of the current
   * user: in the editor and its preview, never on the page itself.
   */
  protected bool $editing = FALSE;

  /**
   * Switches showing unsaved values on for the rest of the request.
   */
  public function showDrafts(): void {
    $this->editing = TRUE;
  }

  /**
   * The paragraph of a block as it is to be shown: the one the layout names,
   * in the language of the page, and in the editor with the user's unsaved
   * values. NULL when there is nothing to show.
   *
   * @param array $props
   *   The props of the block: 'id' and 'paragraph'.
   */
  public function forDisplay(array $props, string $bundle, ?ContentEntityInterface $page): ?ContentEntityInterface {
    $paragraph = $this->load($props['paragraph'] ?? NULL, $bundle);
    $langcode = $page?->language()->getId();
    $values = $this->editing && $page instanceof NodeInterface ? $this->drafts->get($page, (string) ($props['id'] ?? '')) : NULL;
    if (!$paragraph && $values === NULL) {
      return NULL;
    }
    if (!$paragraph && $this->available()) {
      $paragraph = $this->entityTypeManager->getStorage('paragraph')->create(['type' => $bundle, 'langcode' => $langcode]);
    }
    if ($paragraph && $langcode && $paragraph->hasTranslation($langcode)) {
      $paragraph = $paragraph->getTranslation($langcode);
    }
    if ($paragraph && $values !== NULL) {
      // The loaded entity is shared within the request: never change it.
      $paragraph = clone $paragraph;
      ParagraphDrafts::apply($paragraph, $values);
    }
    return $paragraph;
  }

  /**
   * Saves the paragraphs of a layout that have unsaved values: a new
   * paragraph, or a new revision of the one the block names, belonging to
   * the page. The layout gets the saved revisions.
   *
   * @param array $data
   *   The layout sent by the editor.
   * @param \Drupal\node\NodeInterface $node
   *   The page (translation) being saved.
   * @param array $saved
   *   Filled with what each block now names: block id => reference.
   *
   * @return array
   *   Messages of values that are not valid. Nothing is saved when there
   *   are any.
   */
  public function persist(array &$data, NodeInterface $node, array &$saved): array {
    if (!$this->available() || !is_array($data['content'] ?? NULL)) {
      return [];
    }
    $pending = [];
    $errors = [];
    $this->collect($data['content'], $node, $pending, $errors);
    if ($errors) {
      return $errors;
    }
    foreach ($pending as $block => $paragraph) {
      if (!$paragraph->isNew()) {
        $paragraph->setNewRevision(TRUE);
      }
      $paragraph->setParentEntity($node, self::FIELD);
      $paragraph->save();
      $saved[$block] = self::reference($paragraph);
      $this->drafts->forget($node, $block);
    }
    $this->replace($data['content'], $saved);
    return [];
  }

  /**
   * Finds the Paragraph blocks with unsaved values and prepares their
   * paragraphs.
   */
  protected function collect(array $items, NodeInterface $node, array &$pending, array &$errors): void {
    $langcode = $node->language()->getId();
    foreach ($items as $item) {
      $props = is_array($item['props'] ?? NULL) ? $item['props'] : [];
      [$kind, $bundle] = ComponentCatalog::parse((string) ($item['type'] ?? ''));
      $block = (string) ($props['id'] ?? '');
      if ($kind === ComponentCatalog::PARAGRAPH && ($values = $this->drafts->get($node, $block)) !== NULL && isset($this->types()[$bundle])) {
        $paragraph = $this->load($props['paragraph'] ?? NULL, $bundle)
          ?? $this->entityTypeManager->getStorage('paragraph')->create(['type' => $bundle, 'langcode' => $langcode]);
        if ($paragraph->hasTranslation($langcode)) {
          $paragraph = $paragraph->getTranslation($langcode);
        }
        elseif (!$paragraph->isNew() && $paragraph->isTranslatable()) {
          $paragraph = $paragraph->addTranslation($langcode, $paragraph->toArray());
        }
        ParagraphDrafts::apply($paragraph, $values);
        foreach ($paragraph->validate() as $violation) {
          $errors[] = trim($paragraph->type->entity?->label() . ': ' . strip_tags((string) $violation->getMessage()));
        }
        $pending[$block] = $paragraph;
      }
      foreach ($props as $value) {
        if (LayoutMigrator::isItems($value)) {
          $this->collect($value, $node, $pending, $errors);
        }
      }
    }
  }

  /**
   * Puts the saved revisions in the layout, and takes the editor's marks of
   * unsaved values out of it.
   */
  protected function replace(array &$items, array $saved): void {
    foreach ($items as &$item) {
      if (!is_array($item['props'] ?? NULL)) {
        continue;
      }
      if (ComponentCatalog::parse((string) ($item['type'] ?? ''))[0] === ComponentCatalog::PARAGRAPH) {
        $block = (string) ($item['props']['id'] ?? '');
        if (isset($saved[$block])) {
          $item['props']['paragraph'] = $saved[$block];
        }
        elseif (is_array($item['props']['paragraph'] ?? NULL)) {
          unset($item['props']['paragraph']['draft']);
          if (empty($item['props']['paragraph']['id'])) {
            unset($item['props']['paragraph']);
          }
        }
      }
      foreach ($item['props'] as &$value) {
        if (LayoutMigrator::isItems($value)) {
          $this->replace($value, $saved);
        }
      }
    }
  }

  /**
   * Whether paragraphs can be used: the modules are installed.
   */
  public function available(): bool {
    return $this->moduleHandler->moduleExists('paragraphs')
      && $this->moduleHandler->moduleExists('entity_reference_revisions')
      && $this->entityTypeManager->hasDefinition('paragraph');
  }

  /**
   * Labels of the paragraph types, by id.
   */
  public function types(): array {
    if (!$this->available()) {
      return [];
    }
    $types = [];
    foreach ($this->entityTypeManager->getStorage('paragraphs_type')->loadMultiple() as $id => $type) {
      $types[$id] = ['label' => (string) $type->label(), 'description' => (string) $type->getDescription()];
    }
    uasort($types, static fn (array $a, array $b): int => strnatcasecmp($a['label'], $b['label']));
    return $types;
  }

  /**
   * The paragraph a layout item names, in the revision it names.
   *
   * @param mixed $reference
   *   ['id' => ..., 'vid' => ..., 'uuid' => ...] as stored in the layout.
   * @param string $bundle
   *   The paragraph type the item is of. Another type is not loaded.
   */
  public function load(mixed $reference, string $bundle): ?ContentEntityInterface {
    if (!is_array($reference) || !$this->available()) {
      return NULL;
    }
    $storage = $this->entityTypeManager->getStorage('paragraph');
    $paragraph = !empty($reference['vid']) ? $storage->loadRevision((int) $reference['vid']) : NULL;
    // The revision is gone (revisions of the page were deleted): the
    // paragraph as it is now.
    if (!$paragraph && !empty($reference['id'])) {
      $paragraph = $storage->load((int) $reference['id']);
    }
    if (!$paragraph || $paragraph->bundle() !== $bundle || (!empty($reference['id']) && (int) $paragraph->id() !== (int) $reference['id'])) {
      return NULL;
    }
    return $paragraph;
  }

  /**
   * What a layout item stores about a paragraph.
   */
  public static function reference(ContentEntityInterface $paragraph): array {
    return ['id' => (int) $paragraph->id(), 'vid' => (int) $paragraph->getRevisionId(), 'uuid' => $paragraph->uuid()];
  }

  /**
   * The paragraphs a stored document names, as values of the reference field.
   */
  public function fieldValues(array $data): array {
    $values = [];
    foreach (LayoutReferences::find($data)['paragraph'] as $reference) {
      if ($this->available() && $this->entityTypeManager->getStorage('paragraph')->loadRevision($reference['vid'])) {
        $values[$reference['vid']] = ['target_id' => $reference['id'], 'target_revision_id' => $reference['vid']];
      }
    }
    return array_values($values);
  }

}
