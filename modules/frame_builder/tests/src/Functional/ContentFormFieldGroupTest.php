<?php

declare(strict_types=1);

namespace Drupal\Tests\frame_builder\Functional;

use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Extension\ExtensionDiscovery;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\frame_builder\Traits\EndpointTestTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * With the Field Group module the top-level groups of the node form are
 * panels of the editor.
 */
#[Group('frame_builder')]
class ContentFormFieldGroupTest extends BuilderFunctionalTestBase {

  use EndpointTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['field_group', 'field_ui'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $root = dirname((new \ReflectionClass(BrowserTestBase::class))->getFileName(), 5);
    if (!isset((new ExtensionDiscovery($root, FALSE))->scan('module')['field_group'])) {
      $this->markTestSkipped('The Field Group module is not in the codebase.');
    }
    parent::setUp();

    foreach (['a', 'b', 'c', 'd', 'side', 'loose'] as $name) {
      $this->addTextField("field_$name", "Field $name");
    }
    // A panel with a group inside, tabs, a group of the sidebar, a group
    // with nothing in it; "field_loose" is in no group.
    $this->createGroup('group_main', 'details', 'Main', 1, ['field_a', 'group_inner'], '', ['frame_builder_icon' => 'image']);
    $this->createGroup('group_inner', 'fieldset', 'Inner', 0, ['field_b'], 'group_main');
    $this->createGroup('group_tabs', 'tabs', 'Tabs', 2, ['group_tab_two', 'group_tab_one']);
    $this->createGroup('group_tab_one', 'tab', 'Tab one', 1, ['field_c'], 'group_tabs');
    $this->createGroup('group_tab_two', 'tab', 'Tab two', 2, ['field_d'], 'group_tabs');
    $this->createGroup('group_side', 'details_sidebar', 'Side', 3, ['field_side']);
    $this->createGroup('group_empty', 'details', 'Empty', 4, []);
  }

  /**
   * Adds a group to the form of the "landing" content type.
   */
  protected function createGroup(string $name, string $type, string $label, int $weight, array $children, string $parent = '', array $settings = []): void {
    $settings += \Drupal::service('plugin.manager.field_group.formatters')->getDefaultSettings($type, 'form');
    field_group_group_save((object) [
      'group_name' => $name,
      'entity_type' => 'node',
      'bundle' => 'landing',
      'mode' => 'default',
      'context' => 'form',
      'children' => $children,
      'parent_name' => $parent,
      'weight' => $weight,
      'label' => $label,
      'format_type' => $type,
      'format_settings' => $settings,
      'region' => 'content',
    ]);
  }

  /**
   * The editor gets a panel per top-level group that has fields, with its
   * label and icon; the tabs of a "Tabs" group are panels themselves.
   */
  public function testSectionsOfTheEditor(): void {
    $node = $this->createBuilderNode();
    $this->assertSame([
      ['name' => 'content'],
      ['name' => 'group_main', 'label' => 'Main', 'icon' => 'image'],
      ['name' => 'group_tab_one', 'label' => 'Tab one', 'icon' => ''],
      ['name' => 'group_tab_two', 'label' => 'Tab two', 'icon' => ''],
    ], $this->editorSettings($node)['sections']);

    // With every field in a group there is no "Content" panel.
    $display = EntityFormDisplay::load('node.landing.default');
    $display->removeComponent('field_loose')->save();
    $this->assertSame(['group_main', 'group_tab_one', 'group_tab_two'], array_column($this->editorSettings($node)['sections'], 'name'));

    // A panel without a field of the form is not offered.
    $display->removeComponent('field_c')->save();
    $this->assertSame(['group_main', 'group_tab_two'], array_column($this->editorSettings($node)['sections'], 'name'));
  }

  /**
   * In the form, a panel is a plain container marked with its name; groups
   * inside a panel stay groups; a sidebar group is in the Page part; an
   * empty group is nowhere. One save of a part saves all its panels.
   */
  public function testPanelsOfTheForm(): void {
    $node = $this->createBuilderNode(['title' => 'Grouped']);
    $nid = $node->id();
    $assert = $this->assertSession();
    $input = static fn (string $field): string => '//input[@name="field_' . $field . '[0][value]"]';
    $panel = static fn (string $name): string => '//div[@data-frame-builder-section="' . $name . '"]';

    $this->drupalGet($this->builderUrl('content_form', $node, ['part' => 'fields']));
    $assert->statusCodeEquals(200);
    // The panel of a group holds its field and the group nested in it.
    $assert->elementExists('xpath', $panel('group_main') . '[.' . $input('a') . '][.//fieldset[.//legend[contains(., "Inner")]]' . $input('b') . ']');
    // The group that is the panel is not printed: the editor has its
    // button, with the label.
    $assert->elementNotExists('xpath', '//details' . $input('a'));
    $assert->elementNotExists('xpath', '//summary[contains(., "Main")]');
    // The tabs are panels; there are no tabs in the form.
    $assert->elementExists('xpath', $panel('group_tab_one') . $input('c'));
    $assert->elementExists('xpath', $panel('group_tab_two') . $input('d'));
    $assert->elementNotExists('xpath', $panel('group_tab_one') . $input('d'));
    $assert->elementNotExists('xpath', $panel('group_tabs'));
    $assert->pageTextNotContains('Tab one');
    $assert->pageTextNotContains('Tab two');
    // Fields in no group are the "content" panel.
    $assert->elementExists('xpath', '//*[@data-frame-builder-section="content"]' . $input('loose'));
    $assert->elementNotExists('xpath', $panel('group_main') . $input('loose'));
    // The sidebar group and the empty group are not here.
    $assert->elementNotExists('xpath', $input('side'));
    $assert->pageTextNotContains('Side');
    $assert->pageTextNotContains('Empty');
    $assert->elementNotExists('xpath', $panel('group_empty'));
    $assert->elementNotExists('xpath', $panel('group_side'));
    $assert->fieldNotExists('title[0][value]');

    // One save for all the panels.
    $this->submitForm([
      'field_a[0][value]' => 'A',
      'field_b[0][value]' => 'B',
      'field_c[0][value]' => 'C',
      'field_d[0][value]' => 'D',
      'field_loose[0][value]' => 'Loose',
    ], 'Save');
    $this->assertStringContainsString('saved=1', $this->getUrl());
    $assert->elementExists('xpath', $panel('group_main') . $input('a') . '[@value="A"]');

    // The Page part has the sidebar group, as a group, next to the title.
    $this->drupalGet($this->builderUrl('content_form', $node, ['part' => 'page']));
    $assert->fieldExists('title[0][value]');
    $assert->elementExists('xpath', '//details[.//summary[contains(., "Side")]]' . $input('side'));
    foreach (['a', 'b', 'c', 'd', 'loose'] as $field) {
      $assert->elementNotExists('xpath', $input($field));
    }
    $assert->elementNotExists('xpath', $panel('group_main'));
    $assert->elementNotExists('xpath', $panel('group_tab_one'));
    $assert->pageTextNotContains('Inner');
    $assert->pageTextNotContains('Empty');
    $this->submitForm(['field_side[0][value]' => 'Aside', 'title[0][value]' => 'Grouped and saved'], 'Save');
    $this->assertStringContainsString('saved=1', $this->getUrl());

    $latest = $this->latestRevision($nid);
    $values = [];
    foreach (['a', 'b', 'c', 'd', 'loose', 'side'] as $field) {
      $values[$field] = $latest->get("field_$field")->value;
    }
    $this->assertSame(['a' => 'A', 'b' => 'B', 'c' => 'C', 'd' => 'D', 'loose' => 'Loose', 'side' => 'Aside'], $values);
    $this->assertSame('Grouped and saved', $latest->label());

    // Unsaved values of a field in a panel are kept like any other.
    $this->keepFieldDraft($node, ['field_c[0][value]' => 'C, typed']);
    $this->drupalGet($this->builderUrl('content_form', $node, ['part' => 'fields']));
    $assert->elementExists('xpath', $panel('group_tab_one') . $input('c') . '[@value="C, typed"]');
    $this->assertTrue($this->getDrupalSettings()['frameBuilderFrame']['draft']);
  }

  /**
   * "Manage form display" has the icon of the panel among the settings of
   * a group; it is stored with them and given to the editor.
   */
  public function testIconSetting(): void {
    $node = $this->createBuilderNode();
    $this->drupalLogin($this->drupalCreateUser(array_merge(static::EDITOR_PERMISSIONS, ['administer node form display', 'administer content types'])));
    $assert = $this->assertSession();
    $icon = 'fields[group_main][settings_edit_form][settings][frame_builder_icon]';

    $this->drupalGet('admin/structure/types/manage/landing/form-display');
    $assert->statusCodeEquals(200);
    // The setting is there while the settings of the group are edited.
    $assert->fieldNotExists($icon);
    $this->submitForm([], 'group_main_group_settings_edit');
    $assert->fieldValueEquals($icon, 'image');
    $assert->pageTextContains('Icon in the page builder');

    $this->submitForm([$icon => 'map'], 'group_main_plugin_settings_update');
    $this->submitForm([], 'Save');
    $assert->pageTextContains('Your settings have been saved.');

    \Drupal::entityTypeManager()->getStorage('entity_form_display')->resetCache();
    $group = EntityFormDisplay::load('node.landing.default')->getThirdPartySetting('field_group', 'group_main');
    $this->assertSame('map', $group['format_settings']['frame_builder_icon']);
    // The other settings of the group are kept.
    $this->assertSame('Main', $group['label']);
    $this->assertEqualsCanonicalizing(['field_a', 'group_inner'], $group['children']);

    $sections = array_column($this->editorSettings($node)['sections'], NULL, 'name');
    $this->assertSame('map', $sections['group_main']['icon']);

    // Emptied: the editor shows the initials of the label.
    $this->drupalGet('admin/structure/types/manage/landing/form-display');
    $this->submitForm([], 'group_main_group_settings_edit');
    $this->submitForm([$icon => ''], 'group_main_plugin_settings_update');
    $this->submitForm([], 'Save');
    $sections = array_column($this->editorSettings($node)['sections'], NULL, 'name');
    $this->assertSame('', $sections['group_main']['icon']);
    $this->assertSame('Main', $sections['group_main']['label']);
  }

}
