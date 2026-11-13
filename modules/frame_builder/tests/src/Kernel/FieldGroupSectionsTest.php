<?php

declare(strict_types=1);

namespace Drupal\Tests\frame_builder\Kernel;

use Drupal\frame_builder\Form\BuilderContentForm;
use PHPUnit\Framework\Attributes\Group;

/**
 * How the groups of the Field Group module become panels of the editor:
 * BuilderContentForm::groupSections(). It takes the groups as plain
 * objects, so the module is not needed.
 */
#[Group('frame_builder')]
class FieldGroupSectionsTest extends BuilderKernelTestBase {

  /**
   * A group as the Field Group module describes it.
   */
  protected static function group(string $type, array $children = [], string $parent = '', int $weight = 0): object {
    return (object) ['format_type' => $type, 'children' => $children, 'parent_name' => $parent, 'weight' => $weight];
  }

  /**
   * Without groups there are no panels.
   */
  public function testNoGroups(): void {
    $this->assertSame(['panels' => [], 'sections' => [], 'tabs' => []], BuilderContentForm::groupSections([]));
  }

  /**
   * Every top-level group is a panel, whatever its format, in the order of
   * the weights; what is in it, at any depth, belongs to it.
   */
  public function testTopLevelGroupsArePanels(): void {
    $groups = [
      'group_last' => static::group('fieldset', ['field_z'], '', 5),
      'group_first' => static::group('details', ['field_a', 'group_inner'], '', -1),
      'group_inner' => static::group('fieldset', ['field_b', 'group_deep'], 'group_first', 9),
      'group_deep' => static::group('html_element', ['field_c'], 'group_inner'),
      'group_middle' => static::group('html_element', [], '', 2),
    ];
    $layout = BuilderContentForm::groupSections($groups);

    $this->assertSame(['group_first', 'group_middle', 'group_last'], array_keys($layout['panels']));
    $this->assertSame($groups['group_first'], $layout['panels']['group_first']);
    $this->assertSame([], $layout['tabs']);
    $this->assertEquals([
      'group_first' => 'group_first',
      'field_a' => 'group_first',
      'group_inner' => 'group_first',
      'field_b' => 'group_first',
      'group_deep' => 'group_first',
      'field_c' => 'group_first',
      'group_middle' => 'group_middle',
      'group_last' => 'group_last',
      'field_z' => 'group_last',
    ], $layout['sections']);
  }

  /**
   * Of a top-level "Tabs" group the tabs are the panels, by weight; the
   * group itself is none. Tabs deeper in the form stay tabs.
   */
  public function testTabs(): void {
    $groups = [
      'group_tabs' => static::group('tabs', ['group_two', 'group_one', 'field_stray'], '', 1),
      'group_one' => static::group('tab', ['field_a'], 'group_tabs', 1),
      'group_two' => static::group('tab', ['field_b', 'group_inner_tabs'], 'group_tabs', 2),
      'group_inner_tabs' => static::group('tabs', ['group_inner_tab'], 'group_two'),
      'group_inner_tab' => static::group('tab', ['field_c'], 'group_inner_tabs'),
      'group_before' => static::group('details', ['field_d'], '', 0),
    ];
    $layout = BuilderContentForm::groupSections($groups);

    $this->assertSame(['group_before', 'group_one', 'group_two'], array_keys($layout['panels']));
    $this->assertSame(['group_tabs'], $layout['tabs']);
    $this->assertEquals([
      'group_before' => 'group_before',
      'field_d' => 'group_before',
      'group_one' => 'group_one',
      'field_a' => 'group_one',
      'group_two' => 'group_two',
      'field_b' => 'group_two',
      'group_inner_tabs' => 'group_two',
      'group_inner_tab' => 'group_two',
      'field_c' => 'group_two',
    ], $layout['sections']);
    // A field put straight into the tabs group is in no panel: it is with
    // the fields that have no group.
    $this->assertArrayNotHasKey('field_stray', $layout['sections']);
    $this->assertArrayNotHasKey('group_tabs', $layout['sections']);
  }

  /**
   * A "Details sidebar" group is in the sidebar of the edit page: it and
   * everything in it belong to the Page part, and it is no panel.
   */
  public function testSidebarGroupsBelongToThePage(): void {
    $layout = BuilderContentForm::groupSections([
      'group_side' => static::group('details_sidebar', ['field_a', 'group_in_side'], '', 0),
      'group_in_side' => static::group('fieldset', ['field_b'], 'group_side'),
      'group_main' => static::group('details', ['field_c', 'group_side_inside'], '', 1),
      // In a panel it is a group like any other.
      'group_side_inside' => static::group('details_sidebar', ['field_d'], 'group_main'),
    ]);
    $this->assertSame(['group_main'], array_keys($layout['panels']));
    $this->assertEquals([
      'group_side' => 'page',
      'field_a' => 'page',
      'group_in_side' => 'page',
      'field_b' => 'page',
      'group_main' => 'group_main',
      'field_c' => 'group_main',
      'group_side_inside' => 'group_main',
      'field_d' => 'group_main',
    ], $layout['sections']);
  }

  /**
   * Groups that are incomplete or wrongly nested do not break the form: a
   * group whose parent is gone is a panel, bare objects are accepted, and
   * groups that contain each other end.
   */
  public function testOddGroups(): void {
    $layout = BuilderContentForm::groupSections([
      'group_orphan' => static::group('fieldset', ['field_a'], 'group_gone', 3),
      'group_bare' => (object) [],
      'group_loop' => static::group('details', ['group_loop_a'], '', 1),
      'group_loop_a' => static::group('fieldset', ['group_loop_b'], 'group_loop'),
      'group_loop_b' => static::group('fieldset', ['group_loop_a', 'field_b'], 'group_loop_a'),
    ]);
    $this->assertSame(['group_bare', 'group_loop', 'group_orphan'], array_keys($layout['panels']));
    $this->assertSame('group_orphan', $layout['sections']['field_a']);
    $this->assertSame('group_loop', $layout['sections']['field_b']);
    $this->assertSame('group_loop', $layout['sections']['group_loop_b']);

    // Groups that are only each other's parents are in no panel.
    $layout = BuilderContentForm::groupSections([
      'group_x' => static::group('details', ['group_y'], 'group_y'),
      'group_y' => static::group('details', ['group_x'], 'group_x'),
    ]);
    $this->assertSame(['panels' => [], 'sections' => [], 'tabs' => []], $layout);
  }

}
