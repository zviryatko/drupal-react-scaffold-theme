<?php

declare(strict_types=1);

namespace Drupal\Tests\frame_builder\Functional;

use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field\Entity\FieldConfig;
use Drupal\editor\Entity\Editor;
use Drupal\filter\Entity\FilterFormat;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\frame_builder\Traits\EndpointTestTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * What the editor page gives its script: drupalSettings.frameBuilder.
 */
#[Group('frame_builder')]
class EditorSettingsTest extends BuilderFunctionalTestBase {

  use EndpointTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['editor', 'ckeditor5', 'menu_ui'];

  /**
   * The page: its id, language, title, revision, layout, where it is viewed,
   * the endpoints of the editor and the token for them.
   */
  public function testPageAndEndpoints(): void {
    $node = $this->createBuilderNode(['title' => 'Spring offer']);
    $nid = (int) $node->id();
    $settings = $this->editorSettings($node);

    $this->assertSame($nid, $settings['node']);
    $this->assertSame('en', $settings['langcode']);
    $this->assertSame('Spring offer', $settings['title']);
    $this->assertSame((int) $node->getRevisionId(), $settings['revision']);
    $this->assertSame($node->toUrl()->toString(), $settings['viewUrl']);
    // Nothing built yet, nothing left unsaved.
    $this->assertNull($settings['data']);
    $this->assertNull($settings['draft']);

    $expected = [
      'render' => '/frame-builder/render',
      'save' => "/frame-builder/node/$nid/save",
      'urls' => '/frame-builder/urls',
      'media' => '/frame-builder/media',
      'tokens' => '/frame-builder/tokens',
      'content' => "/node/$nid/builder/content?part=fields",
      'page' => "/node/$nid/builder/content?part=page",
      'formatter' => "/node/$nid/builder/formatter",
      // Core has HTMX: the settings of a formatter open inline.
      'formatterForm' => "/node/$nid/builder/formatter-form",
      'draft' => "/frame-builder/node/$nid/draft",
      'library' => '/frame-builder/library',
      'paragraph' => "/node/$nid/builder/paragraph",
      'preview' => "/node/$nid/builder/preview",
    ];
    ksort($expected);
    $endpoints = $settings['endpoints'];
    ksort($endpoints);
    // Without the Media Library module there is no library to open.
    $this->assertSame(array_keys($expected), array_keys($endpoints));
    foreach ($expected as $name => $path) {
      $this->assertStringEndsWith($path, $endpoints[$name], $name);
    }

    // The token is the one the endpoints accept.
    $this->assertSame($this->csrfToken(), $settings['csrfToken']);
    [$status, $body] = $this->postJson($endpoints['save'], ['data' => static::layout(static::probe('p1', 'Hello'))], $settings['csrfToken']);
    $this->assertSame(200, $status);

    // The saved layout and its revision are what the editor opens next.
    $settings = $this->editorSettings($node);
    $this->assertSame($body['revision'], $settings['revision']);
    $this->assertGreaterThan((int) $node->getRevisionId(), $settings['revision']);
    $this->assertSame(['Hello'], static::probeTitles($settings['data']));
    $this->assertSame(static::PROBE, $settings['data']['content'][0]['type']);
    $this->assertSame('p1', $settings['data']['content'][0]['props']['id']);

    // The components of the theme and the fields of the page are offered.
    $components = array_column($settings['components'], NULL, 'id');
    $this->assertArrayHasKey(static::PROBE, $components);
    $this->assertSame('Test', $components[static::PROBE]['group']);
    $this->assertArrayHasKey('drupal_field:title', $components);
    $this->assertArrayNotHasKey('drupal_field:frame_layout', $components);
  }

  /**
   * Publish, Draft and Delete are offered to those who may do them.
   */
  public function testActionsFollowPermissions(): void {
    $node = $this->createBuilderNode();
    // The editor of the tests may delete, but not change the published flag.
    $actions = $this->editorSettings($node)['actions'];
    $this->assertFalse($actions['publish']);
    $this->assertFalse($actions['draft']);
    $this->assertSame($node->toUrl('delete-form')->toString(), $actions['delete']);

    $this->drupalLogin($this->drupalCreateUser(['use frame builder', 'access content', 'edit any landing content', 'administer nodes']));
    $actions = $this->editorSettings($node)['actions'];
    $this->assertTrue($actions['publish']);
    $this->assertTrue($actions['draft']);
    // Deleting is a right of its own.
    $this->assertNull($actions['delete']);

    $this->drupalLogin($this->drupalCreateUser(['use frame builder', 'access content', 'edit any landing content']));
    $actions = $this->editorSettings($node)['actions'];
    $this->assertSame(['draft' => FALSE, 'publish' => FALSE, 'delete' => NULL], $actions);
  }

  /**
   * The text formats the user may use, which of them have an editor, and
   * the one new text starts with.
   */
  public function testTextFormats(): void {
    $node = $this->createBuilderNode();
    $settings = $this->editorSettings($node);
    $this->assertSame([['id' => 'plain_text', 'label' => 'Plain text', 'editor' => FALSE]], $settings['formats']);
    $this->assertSame('plain_text', $settings['defaultFormat']);

    FilterFormat::create(['format' => 'rich', 'name' => 'Rich', 'weight' => -5])->save();
    FilterFormat::create(['format' => 'markup', 'name' => 'Markup', 'weight' => -1])->save();
    FilterFormat::create(['format' => 'secret', 'name' => 'Secret', 'weight' => -9])->save();
    Editor::create([
      'format' => 'rich',
      'editor' => 'ckeditor5',
      'image_upload' => ['status' => FALSE],
      'settings' => ['toolbar' => ['items' => ['bold', 'italic']], 'plugins' => []],
    ])->save();
    $this->drupalLogin($this->drupalCreateUser(array_merge(static::EDITOR_PERMISSIONS, ['use text format rich', 'use text format markup'])));

    $settings = $this->editorSettings($node);
    $this->assertSame([
      ['id' => 'rich', 'label' => 'Rich', 'editor' => TRUE],
      ['id' => 'markup', 'label' => 'Markup', 'editor' => FALSE],
      ['id' => 'plain_text', 'label' => 'Plain text', 'editor' => FALSE],
    ], $settings['formats']);
    $this->assertSame('rich', $settings['defaultFormat']);
    // The configuration of CKEditor 5 for the format comes with the page.
    $this->assertArrayHasKey('rich', $this->getDrupalSettings()['editor']['formats']);
    $this->assertArrayNotHasKey('secret', $this->getDrupalSettings()['editor']['formats']);
  }

  /**
   * The widths of the preview are the breakpoints of the theme, taken from
   * the base theme when the theme declares none.
   */
  public function testViewportsFromBreakpoints(): void {
    $node = $this->createBuilderNode();
    // Without the Breakpoint module there are none.
    $this->assertSame([], $this->editorSettings($node)['viewports']);

    $this->container->get('module_installer')->install(['breakpoint']);
    $this->rebuildContainer();
    // Frame: no minimum width is a phone, 48em and 75em in pixels.
    $this->assertSame([
      ['label' => 'Mobile', 'width' => 375],
      ['label' => 'Tablet', 'width' => 768],
      ['label' => 'Desktop', 'width' => 1200],
    ], $this->editorSettings($node)['viewports']);
  }

  /**
   * The panels of the node form: "content" when the content type has fields
   * of its own to edit, and "menu" for a content type with menus and a user
   * who may edit them.
   */
  public function testSections(): void {
    $node = $this->createBuilderNode();
    $with_menu = $this->drupalCreateUser(array_merge(static::EDITOR_PERMISSIONS, ['administer menu']));

    // The content type has no menu to put its pages in.
    $this->drupalLogin($with_menu);
    $this->assertSame([], $this->editorSettings($node)['sections'], 'A content type with nothing but its title has no Content panel: the title is in the page settings.');

    NodeType::load('landing')->setThirdPartySetting('menu_ui', 'available_menus', ['main'])->save();
    $this->assertSame([['name' => 'menu']], $this->editorSettings($node)['sections']);

    // A field of the content type, with a widget in the form.
    FieldStorageConfig::create(['field_name' => 'field_note', 'entity_type' => 'node', 'type' => 'string'])->save();
    FieldConfig::create(['field_name' => 'field_note', 'entity_type' => 'node', 'bundle' => 'landing', 'label' => 'Note'])->save();
    \Drupal::service('entity_display.repository')->getFormDisplay('node', 'landing')->setComponent('field_note', ['type' => 'string_textfield'])->save();
    $this->assertSame([['name' => 'content'], ['name' => 'menu']], $this->editorSettings($node)['sections']);

    // The editor of the tests may not edit menus.
    $this->drupalLogin($this->editor);
    $this->assertSame([['name' => 'content']], $this->editorSettings($node)['sections']);

    // A field without a widget is not one to edit.
    \Drupal::service('entity_display.repository')->getFormDisplay('node', 'landing')->removeComponent('field_note')->save();
    $this->assertSame([], $this->editorSettings($node)['sections']);
  }

  /**
   * A page that is not translated says so: nothing is locked, no notice.
   */
  public function testTranslationInfoOfAnUntranslatedPage(): void {
    $node = $this->createBuilderNode();
    $this->assertSame(
      ['mode' => 'synced', 'language' => 'English', 'locked' => FALSE, 'notice' => NULL, 'short' => NULL],
      $this->editorSettings($node)['translation'],
    );
    $this->createBuilderType('free', 'independent');
    $this->drupalLogin($this->drupalCreateUser(['use frame builder', 'access content', 'edit any free content']));
    $free = $this->createBuilderNode(['type' => 'free']);
    $this->assertSame('independent', $this->editorSettings($free)['translation']['mode']);
  }

}
