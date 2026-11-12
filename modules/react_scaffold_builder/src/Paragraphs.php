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

  /**
   * The paragraph type of the Paragraphs Library module that shows an item
   * of its library. The builder makes such paragraphs itself, for the pages
   * that use a paragraph of the library; it is not offered as a block.
   */
  public const LIBRARY_TYPE = 'from_library';

  /**
   * The field of that paragraph type that names the item of the library.
   */
  public const LIBRARY_FIELD = 'field_reusable_paragraph';

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
   * the page. A block that names a paragraph of something else (a copy of
   * a saved block) gets a paragraph of its own. The layout gets the saved
   * revisions.
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
   * Saves the paragraphs of an item that goes to the library: the library
   * gets paragraphs of its own, with the unsaved values the user has for
   * them on the page the item comes from. A paragraph that is the saved
   * block's already gets a new revision.
   *
   * Without this a block saved to the library before its page was saved
   * would have no paragraph at all: the values exist only as a draft, and
   * the page itself no longer has the block (it links to the saved one).
   *
   * @param array $item
   *   The item sent by the editor. Gets the saved revisions.
   * @param \Drupal\Core\Entity\ContentEntityInterface $saved
   *   The saved block, saved already: the paragraphs belong to it.
   * @param \Drupal\node\NodeInterface|null $node
   *   The page (translation) the item is on, when known.
   *
   * @return array
   *   Messages of values that are not valid. Nothing is saved when there
   *   are any.
   */
  public function persistForLibrary(array &$item, ContentEntityInterface $saved, ?NodeInterface $node): array {
    if (!$this->available()) {
      return [];
    }
    $pending = [];
    $errors = [];
    $this->collect([$item], $node, $pending, $errors, $saved);
    if ($errors) {
      return $errors;
    }
    $references = [];
    foreach ($pending as $block => $paragraph) {
      if (!$paragraph->isNew()) {
        $paragraph->setNewRevision(TRUE);
      }
      $paragraph->setParentEntity($saved, LayoutStorage::FIELD);
      $paragraph->save();
      $references[$block] = self::reference($paragraph);
      if ($node) {
        $this->drafts->forget($node, $block);
      }
    }
    $items = [$item];
    $this->replace($items, $references);
    $item = $items[0];
    return [];
  }

  /**
   * Finds the Paragraph blocks whose paragraph is to be saved and prepares
   * their paragraphs: the ones with unsaved values, and the ones whose
   * paragraph belongs to something else.
   *
   * A paragraph belongs to one owner, a page or a saved block. A block that
   * names the paragraph of another owner (a copy of a saved block, an item
   * that goes to the library) gets a duplicate; so does the second of two
   * blocks that name the same paragraph (a block that was duplicated).
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface|null $owner
   *   What the paragraphs will belong to; the page by default.
   */
  protected function collect(array $items, ?NodeInterface $node, array &$pending, array &$errors, ?ContentEntityInterface $owner = NULL, array &$seen = []): void {
    $owner ??= $node;
    $langcode = $node?->language()->getId() ?? $owner->language()->getId();
    foreach ($items as $item) {
      $props = is_array($item['props'] ?? NULL) ? $item['props'] : [];
      [$kind, $bundle] = ComponentCatalog::parse((string) ($item['type'] ?? ''));
      $block = (string) ($props['id'] ?? '');
      // A paragraph of the library that the page links to: the page gets a
      // "From library" paragraph of its own that names it.
      if ($kind === ComponentCatalog::SAVED && $block !== '' && $owner === $node && ($library = $this->libraryItem($bundle))) {
        $wrapper = $this->load($props['paragraph'] ?? NULL, self::LIBRARY_TYPE);
        $own = $wrapper && $this->belongsTo($wrapper, $owner) && !isset($seen[$wrapper->id()])
          && (string) $wrapper->get(self::LIBRARY_FIELD)->target_id === (string) $library->id();
        if ($own) {
          $seen[$wrapper->id()] = TRUE;
        }
        else {
          $pending[$block] = $this->wrapper($library);
        }
        continue;
      }
      if ($kind === ComponentCatalog::PARAGRAPH && $block !== '' && isset($this->types()[$bundle])) {
        $values = $node ? $this->drafts->get($node, $block) : NULL;
        $paragraph = $this->load($props['paragraph'] ?? NULL, $bundle);
        $foreign = $paragraph && (!$this->belongsTo($paragraph, $owner) || isset($seen[$paragraph->id()]));
        if ($paragraph && !$foreign) {
          $seen[$paragraph->id()] = TRUE;
        }
        if ($values !== NULL || $foreign) {
          $paragraph = $foreign ? $paragraph->createDuplicate() : $paragraph;
          $paragraph ??= $this->entityTypeManager->getStorage('paragraph')->create(['type' => $bundle, 'langcode' => $langcode]);
          if ($paragraph->hasTranslation($langcode)) {
            $paragraph = $paragraph->getTranslation($langcode);
          }
          elseif (!$paragraph->isNew() && $paragraph->isTranslatable()) {
            $paragraph = $paragraph->addTranslation($langcode, $paragraph->toArray());
          }
          if ($values !== NULL) {
            ParagraphDrafts::apply($paragraph, $values);
          }
          foreach ($paragraph->validate() as $violation) {
            $errors[] = trim($paragraph->type->entity?->label() . ': ' . strip_tags((string) $violation->getMessage()));
          }
          $pending[$block] = $paragraph;
        }
      }
      foreach ($props as $value) {
        if (LayoutMigrator::isItems($value)) {
          $this->collect($value, $node, $pending, $errors, $owner, $seen);
        }
      }
    }
  }

  /**
   * Whether a paragraph is the one of this page or saved block.
   */
  protected function belongsTo(ContentEntityInterface $paragraph, ContentEntityInterface $owner): bool {
    return !$owner->isNew() && $paragraph->get('parent_type')->value === $owner->getEntityTypeId()
      && (string) $paragraph->get('parent_id')->value === (string) $owner->id();
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
      $block = (string) ($item['props']['id'] ?? '');
      if (isset($saved[$block])) {
        $item['props']['paragraph'] = $saved[$block];
      }
      elseif (ComponentCatalog::parse((string) ($item['type'] ?? ''))[0] === ComponentCatalog::PARAGRAPH) {
        if (is_array($item['props']['paragraph'] ?? NULL)) {
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
   * Whether paragraphs are saved to the library of the Paragraphs Library
   * module: it is installed and has its "From library" paragraph type.
   */
  public function libraryAvailable(): bool {
    return $this->available()
      && $this->moduleHandler->moduleExists('paragraphs_library')
      && $this->entityTypeManager->hasDefinition('paragraphs_library_item')
      && isset($this->types()[self::LIBRARY_TYPE]);
  }

  /**
   * An item of the Paragraphs Library by UUID.
   */
  public function libraryItem(string $uuid): ?ContentEntityInterface {
    if ($uuid === '' || !$this->libraryAvailable()) {
      return NULL;
    }
    $found = $this->entityTypeManager->getStorage('paragraphs_library_item')->loadByProperties(['uuid' => $uuid]);
    return $found ? reset($found) : NULL;
  }

  /**
   * The items of the Paragraphs Library, by name.
   */
  public function libraryItems(): array {
    if (!$this->libraryAvailable()) {
      return [];
    }
    $storage = $this->entityTypeManager->getStorage('paragraphs_library_item');
    return $storage->loadMultiple($storage->getQuery()->accessCheck(FALSE)->sort('label')->execute());
  }

  /**
   * The paragraph an item of the library holds.
   */
  public function libraryParagraph(ContentEntityInterface $library): ?ContentEntityInterface {
    $paragraph = $library->get('paragraphs')->entity;
    return $paragraph instanceof ContentEntityInterface ? $paragraph : NULL;
  }

  /**
   * A new "From library" paragraph that names an item of the library.
   */
  protected function wrapper(ContentEntityInterface $library): ContentEntityInterface {
    return $this->entityTypeManager->getStorage('paragraph')->create([
      'type' => self::LIBRARY_TYPE,
      self::LIBRARY_FIELD => ['target_id' => $library->id()],
    ]);
  }

  /**
   * The "From library" paragraph to show for a block that links to an item
   * of the library: the page's own once the page was saved, else a new one.
   */
  public function wrapperForDisplay(array $props, ContentEntityInterface $library): ContentEntityInterface {
    $wrapper = $this->load($props['paragraph'] ?? NULL, self::LIBRARY_TYPE);
    if ($wrapper && (string) $wrapper->get(self::LIBRARY_FIELD)->target_id === (string) $library->id()) {
      return $wrapper;
    }
    return $this->wrapper($library);
  }

  /**
   * Saves a Paragraph block to the Paragraphs Library: a new item, or a new
   * revision of one, with a paragraph of its own that has the values the
   * user typed in the block's form.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface|null $library
   *   The item to update; NULL makes a new one.
   * @param string $label
   *   Its name; empty keeps the name it has.
   * @param array $block
   *   The Paragraph block sent by the editor.
   * @param \Drupal\node\NodeInterface|null $node
   *   The page (translation) the block is on, when known.
   * @param string $message
   *   The revision log message.
   * @param array $errors
   *   Filled with messages when nothing was saved.
   */
  public function saveToLibrary(?ContentEntityInterface $library, string $label, array $block, ?NodeInterface $node, string $message, array &$errors): ?ContentEntityInterface {
    $library ??= $this->entityTypeManager->getStorage('paragraphs_library_item')->create(['label' => $label]);
    [$kind, $bundle] = ComponentCatalog::parse((string) ($block['type'] ?? ''));
    $id = (string) ($block['props']['id'] ?? '');
    if ($kind !== ComponentCatalog::PARAGRAPH || $bundle === self::LIBRARY_TYPE || !isset($this->types()[$bundle])) {
      $errors[] = 'Only a paragraph can be saved to the library.';
      return NULL;
    }
    $pending = [];
    $this->collect([$block], $node, $pending, $errors, $library);
    if ($errors) {
      return NULL;
    }
    $paragraph = $pending[$id] ?? $this->load($block['props']['paragraph'] ?? NULL, $bundle);
    if (!$paragraph) {
      $errors[] = 'The paragraph is empty: fill in its form first.';
      return NULL;
    }
    if (isset($pending[$id]) && !$paragraph->isNew()) {
      $paragraph->setNewRevision(TRUE);
      $paragraph->setNeedsSave(TRUE);
    }
    $library->set('paragraphs', $paragraph);
    if ($label !== '') {
      $library->set('label', $label);
    }
    if (!$library->isNew()) {
      $library->setNewRevision(TRUE);
    }
    $library->setRevisionLogMessage($message);
    $library->setRevisionUserId(\Drupal::currentUser()->id());
    $library->setRevisionCreationTime(\Drupal::time()->getRequestTime());
    $library->save();
    if ($node) {
      $this->drafts->forget($node, $id);
    }
    return $library;
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
