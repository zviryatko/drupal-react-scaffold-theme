<?php

namespace Drupal\frame_builder;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Theme\ComponentPluginManager;
use Drupal\Core\Theme\ThemeInitializationInterface;
use Drupal\Core\Theme\ThemeManagerInterface;

/**
 * Brings a stored layout up to date with the components as they are now.
 *
 * Two kinds of version are stored:
 * - the layout has a "version": the format of the document itself, owned by
 *   this module (self::VERSION);
 * - every item has a "version": the schema version of its component when the
 *   item was saved. A component declares its current one, and how to get
 *   there, in its *.component.yml:
 *
 * @code
 * thirdPartySettings:
 *   frame_builder:
 *     version: 3
 *     migrations:
 *       2:                               # from version 1 to 2
 *         rename: { title: heading }     # props and slots
 *         values: { tone: { big: loud } }
 *         defaults: { tone: calm }       # where the prop has no value
 *         remove: [subtitle]
 *       3:
 *         rename: { body: content }
 * @endcode
 *
 * What cannot be said that way is done in code, by modules and by the site's
 * theme, in hook_frame_builder_item_migrate_alter().
 *
 * Layouts are migrated when they are read, so pages are always rendered and
 * edited with current data; the result is stored the next time the page is
 * saved. Migrations therefore have to stay for as long as old content does.
 */
class LayoutMigrator {

  /**
   * Version of the layout document format.
   *
   * 1: {"version", "root", "content": [{"type", "version", "props"}]}; a
   *    synced translation: {"version", "strings", "versions"}.
   */
  public const VERSION = 1;

  public function __construct(
    protected ComponentPluginManager $components,
    protected ModuleHandlerInterface $moduleHandler,
    protected ThemeManagerInterface $themeManager,
    protected ThemeInitializationInterface $themeInitialization,
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Current schema version of a component. 1 unless it declares another;
   * fields, views and blocks of Drupal are always 1.
   */
  public function componentVersion(string $type): int {
    return max(1, (int) ($this->settings($type)['version'] ?? 1));
  }

  /**
   * A layout with every item at the current version of its component.
   *
   * @param array $data
   *   The layout document.
   * @param bool $from_editor
   *   What a missing version means. FALSE (stored data): content saved before
   *   versions existed, which is version 1. TRUE (data sent by the editor):
   *   an item that was added just now, which is current.
   */
  public function migrate(array $data, bool $from_editor = FALSE): array {
    $version = (int) ($data['version'] ?? ($from_editor ? self::VERSION : 0));
    if ($version < self::VERSION) {
      $data = $this->migrateDocument($data, $version);
    }
    // A document of a newer module version is left as it is.
    $data['version'] = max($version, self::VERSION);
    if (is_array($data['content'] ?? NULL)) {
      $data['content'] = $this->migrateItems($data['content'], $from_editor);
    }
    return $data;
  }

  /**
   * Translated values of a synced translation, renamed and converted like
   * the props they belong to.
   *
   * @param array $strings
   *   Values keyed by "<item id>.<prop>".
   * @param array $versions
   *   Component version the values of an item were saved with, by item id.
   * @param array $items
   *   The (current) items of the original language: they tell the type of
   *   every id.
   *
   * @return array
   *   The values, keyed as the current components name their props.
   */
  public function migrateStrings(array $strings, array $versions, array $items): array {
    $types = [];
    $this->types($items, $types);
    $props = [];
    foreach ($strings as $key => $value) {
      // Item ids contain no dot of their own making, prop names never do.
      $at = strrpos((string) $key, '.');
      if ($at !== FALSE) {
        $props[substr($key, 0, $at)][substr($key, $at + 1)] = $value;
      }
    }
    $result = [];
    foreach ($props as $id => $values) {
      if (isset($types[$id])) {
        $item = $this->migrateItem(['type' => $types[$id], 'version' => (int) ($versions[$id] ?? 1), 'props' => $values], FALSE, FALSE);
        $values = $item['props'];
      }
      foreach ($values as $name => $value) {
        $result["$id.$name"] = $value;
      }
    }
    return $result;
  }

  /**
   * Current component versions of the items that have translated values.
   */
  public function stringVersions(array $strings, array $items): array {
    $types = [];
    $this->types($items, $types);
    $versions = [];
    foreach (array_keys($strings) as $key) {
      $id = substr($key, 0, (int) strrpos((string) $key, '.'));
      if (isset($types[$id])) {
        $versions[$id] = $this->componentVersion($types[$id]);
      }
    }
    return $versions;
  }

  /**
   * Whether a value is the content of a slot: a list of items.
   */
  public static function isItems(mixed $value): bool {
    if (!is_array($value) || !$value || !array_is_list($value)) {
      return FALSE;
    }
    foreach ($value as $child) {
      if (!is_array($child) || !is_string($child['type'] ?? NULL) || !is_array($child['props'] ?? NULL)) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Changes of the document format itself, one step per version.
   */
  protected function migrateDocument(array $data, int $from): array {
    // 0 → 1: versions were added; nothing else changed.
    return $data;
  }

  /**
   * Migrates a list of items and what is in their slots.
   */
  protected function migrateItems(array $items, bool $from_editor): array {
    foreach ($items as $key => $item) {
      if (is_array($item)) {
        $items[$key] = $this->migrateItem($item, $from_editor);
      }
    }
    return $items;
  }

  /**
   * Migrates one item, step by step, to the version of its component.
   */
  protected function migrateItem(array $item, bool $from_editor, bool $nested = TRUE): array {
    $type = (string) ($item['type'] ?? '');
    $current = $this->componentVersion($type);
    $version = (int) ($item['version'] ?? ($from_editor ? $current : 1));
    $props = is_array($item['props'] ?? NULL) ? $item['props'] : [];
    $steps = $this->settings($type)['migrations'] ?? [];
    for ($to = $version + 1; $to <= $current; $to++) {
      $props = $this->applyStep($props, is_array($steps[$to] ?? NULL) ? $steps[$to] : []);
      $context = ['type' => $type, 'from' => $to - 1, 'to' => $to];
      $this->moduleHandler->alter('frame_builder_item_migrate', $props, $context);
      // The theme that owns the components, whatever theme this request uses.
      if ($theme = $this->defaultTheme()) {
        $this->themeManager->alterForTheme($theme, 'frame_builder_item_migrate', $props, $context);
      }
    }
    if ($nested) {
      foreach ($props as $name => $value) {
        if (self::isItems($value)) {
          $props[$name] = $this->migrateItems($value, $from_editor);
        }
      }
    }
    // An item of a newer component (after a rollback) keeps its version.
    return ['type' => $type, 'version' => max($version, $current), 'props' => $props] + $item;
  }

  /**
   * Applies one declared step to the props of an item.
   */
  protected function applyStep(array $props, array $step): array {
    foreach (is_array($step['rename'] ?? NULL) ? $step['rename'] : [] as $from => $to) {
      if (array_key_exists($from, $props)) {
        if (!array_key_exists($to, $props)) {
          $props[$to] = $props[$from];
        }
        unset($props[$from]);
      }
    }
    foreach (is_array($step['values'] ?? NULL) ? $step['values'] : [] as $name => $map) {
      $value = $props[$name] ?? NULL;
      if (is_array($map) && (is_string($value) || is_int($value)) && array_key_exists($value, $map)) {
        $props[$name] = $map[$value];
      }
    }
    foreach (is_array($step['defaults'] ?? NULL) ? $step['defaults'] : [] as $name => $value) {
      if (!isset($props[$name]) || $props[$name] === '') {
        $props[$name] = $value;
      }
    }
    foreach ((array) ($step['remove'] ?? []) as $name) {
      unset($props[$name]);
    }
    return $props;
  }

  /**
   * Collects the type of every item, by item id.
   */
  protected function types(array $items, array &$types): void {
    foreach ($items as $item) {
      $props = is_array($item['props'] ?? NULL) ? $item['props'] : [];
      $types[(string) ($props['id'] ?? '')] = (string) ($item['type'] ?? '');
      foreach ($props as $value) {
        if (self::isItems($value)) {
          $this->types($value, $types);
        }
      }
    }
  }

  /**
   * What a component declares for the builder in its *.component.yml.
   */
  protected function settings(string $type): array {
    static $definitions;
    $definitions ??= $this->components->getDefinitions();
    $settings = $definitions[$type]['thirdPartySettings']['frame_builder'] ?? [];
    return is_array($settings) ? $settings : [];
  }

  /**
   * The site's default theme, NULL when it cannot be initialized.
   */
  protected function defaultTheme() {
    static $theme = FALSE;
    if ($theme === FALSE) {
      try {
        $theme = $this->themeInitialization->getActiveThemeByName((string) $this->configFactory->get('system.theme')->get('default'));
        // Its hooks, when this request runs with another theme (or none).
        $this->themeInitialization->loadActiveTheme($theme);
      }
      catch (\Exception) {
        $theme = NULL;
      }
    }
    return $theme;
  }

}
