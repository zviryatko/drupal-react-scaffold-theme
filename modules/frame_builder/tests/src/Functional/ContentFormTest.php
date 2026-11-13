<?php

declare(strict_types=1);

namespace Drupal\Tests\frame_builder\Functional;

use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\Tests\frame_builder\Traits\EndpointTestTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * The node form in the editor: two frames, each with a part of the form.
 */
#[Group('frame_builder')]
class ContentFormTest extends BuilderFunctionalTestBase {

  use EndpointTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['menu_ui', 'path'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->addTextField('field_note', 'Note');
    $this->addTextField('field_must', 'Must', TRUE);
    NodeType::load('landing')->setThirdPartySetting('menu_ui', 'available_menus', ['main'])->save();
  }

  /**
   * The URL of a part of the form.
   */
  protected function part(NodeInterface $node, ?string $part, array $query = []) {
    return $this->builderUrl('content_form', $node, ($part === NULL ? [] : ['part' => $part]) + $query);
  }

  /**
   * The "fields" part has the fields of the content type and the menu
   * settings, the "page" part the title and what the edit page has in its
   * sidebar; each element is marked with its panel.
   */
  public function testPartsOfTheForm(): void {
    $this->drupalLogin($this->drupalCreateUser(array_merge(static::EDITOR_PERMISSIONS, ['administer nodes', 'administer menu', 'create url aliases'])));
    $node = $this->createBuilderNode(['field_note' => 'A note', 'field_must' => 'Filled']);
    $assert = $this->assertSession();

    // "fields" is also what an unknown or missing part is.
    foreach (['fields', NULL, 'nonsense'] as $part) {
      $this->drupalGet($this->part($node, $part));
      $assert->statusCodeEquals(200);
      $assert->fieldValueEquals('field_note[0][value]', 'A note');
      $assert->fieldValueEquals('field_must[0][value]', 'Filled');
      $assert->fieldExists('menu[enabled]');
      foreach (['title[0][value]', 'status[value]', 'path[0][alias]', 'revision_log[0][value]', 'uid[0][target_id]', 'created[0][value][date]', 'langcode[0][value]', 'frame_layout[0][value]'] as $name) {
        $assert->fieldNotExists($name);
      }
      // Marked with their panels; nothing of the other part.
      $assert->elementExists('xpath', '//*[@data-frame-builder-section="content"]//input[@name="field_note[0][value]"]');
      $assert->elementExists('xpath', '//*[@data-frame-builder-section="content"]//input[@name="field_must[0][value]"]');
      $assert->elementExists('xpath', '//*[@data-frame-builder-section="menu"]//input[@name="menu[enabled]"]');
      $assert->elementNotExists('css', '[data-frame-builder-section="page"]');
      $settings = $this->getDrupalSettings()['frameBuilderFrame'];
      $this->assertSame(['part', 'tokens', 'draft'], array_keys($settings));
      $this->assertSame('fields', $settings['part']);
      $this->assertStringEndsWith('/frame-builder/tokens', $settings['tokens']);
      $this->assertFalse($settings['draft']);
    }
    // One visible button, and the hidden one of the autosave.
    $assert->buttonExists('Save');
    $assert->elementAttributeContains('css', 'input[data-frame-builder-autosave]', 'value', 'Keep as draft');
    $assert->elementAttributeContains('css', 'input[data-frame-builder-autosave]', 'class', 'visually-hidden');
    $assert->buttonNotExists('Preview');
    $assert->linkNotExists('Delete');
    // A page with the form only, and the script that talks to the editor.
    $assert->responseContains('js-static/content-frame.js');
    $assert->elementNotExists('css', '.dialog-off-canvas-main-canvas');
    $assert->elementNotExists('css', 'h1');

    $this->drupalGet($this->part($node, 'page'));
    $assert->statusCodeEquals(200);
    $assert->fieldValueEquals('title[0][value]', 'A page');
    $assert->elementExists('xpath', '//*[@data-frame-builder-section="page"]//input[@name="title[0][value]"]');
    $assert->elementExists('xpath', '//*[@data-frame-builder-section="page"]//input[@name="status[value]"]');
    // With what the edit page has in its sidebar.
    foreach (['path[0][alias]', 'revision_log[0][value]', 'uid[0][target_id]', 'created[0][value][date]'] as $name) {
      $assert->fieldExists($name);
    }
    foreach (['field_note[0][value]', 'field_must[0][value]', 'menu[enabled]', 'langcode[0][value]', 'frame_layout[0][value]'] as $name) {
      $assert->fieldNotExists($name);
    }
    $assert->elementNotExists('css', '[data-frame-builder-section="content"]');
    $assert->elementNotExists('css', '[data-frame-builder-section="menu"]');
    $this->assertSame('page', $this->getDrupalSettings()['frameBuilderFrame']['part']);
    $assert->buttonExists('Save');
    $assert->elementExists('css', 'input[data-frame-builder-autosave]');
    $assert->buttonNotExists('Preview');

    // Without the rights, the elements are not there: the editor of the
    // tests may change neither menus nor the published flag.
    $this->drupalLogin($this->editor);
    $this->drupalGet($this->part($node, 'fields'));
    $assert->fieldNotExists('menu[enabled]');
    $assert->fieldExists('field_note[0][value]');
    $this->drupalGet($this->part($node, 'page'));
    $assert->fieldExists('title[0][value]');
    $assert->fieldNotExists('status[value]');
    $assert->fieldNotExists('path[0][alias]');
  }

  /**
   * Each part saves only its own fields, as a new revision on top of the
   * latest one: with both parts and the layout changed at the same time,
   * nothing is lost.
   */
  public function testEachPartSavesItsOwnFields(): void {
    $node = $this->createBuilderNode(['title' => 'First title', 'field_note' => 'First note', 'field_must' => 'Filled']);
    $nid = $node->id();

    // The Page part is opened first, and stays open.
    $this->drupalGet($this->part($node, 'page'));
    $page = $this->getSession()->getPage();
    $open_form = ['title[0][value]' => 'Second title', 'revision_log[0][value]' => '', 'op' => 'Save'];
    foreach (['form_build_id', 'form_token', 'form_id', 'changed'] as $name) {
      $open_form[$name] = $page->find('css', 'input[name="' . $name . '"]')->getValue();
    }

    // Meanwhile the layout is saved...
    [$status] = $this->saveThroughEndpoint($node, static::layout(static::probe('p1', 'Layout')));
    $this->assertSame(200, $status);
    $this->assertSame(2, $this->revisionCount($nid));

    // ...and the fields.
    $this->drupalGet($this->part($node, 'fields'));
    $this->submitForm(['field_note[0][value]' => 'Second note'], 'Save');
    $this->assertSession()->statusCodeEquals(200);
    // Back on the form, with the mark the frame reports to the editor.
    $this->assertStringContainsString("/node/$nid/builder/content", $this->getUrl());
    parse_str((string) parse_url($this->getUrl(), PHP_URL_QUERY), $query);
    $this->assertSame(['part' => 'fields', 'saved' => '1'], $query);
    $this->assertSession()->fieldValueEquals('field_note[0][value]', 'Second note');
    $this->assertSession()->elementNotExists('css', '[aria-invalid="true"]');

    $this->assertSame(3, $this->revisionCount($nid));
    $latest = $this->latestRevision($nid);
    $this->assertSame('Second note', $latest->get('field_note')->value);
    $this->assertSame('Filled', $latest->get('field_must')->value);
    $this->assertSame('First title', $latest->label());
    $this->assertSame(['Layout'], static::probeTitles($this->storedLayout($latest)));
    $this->assertSame((int) $this->editor->id(), (int) $latest->getRevisionUserId());
    $this->assertSame('Edited in the page builder.', $latest->getRevisionLogMessage());

    // The Page part, opened before both, is saved now: that the page was
    // changed since is no conflict here.
    $response = $this->request('POST', $this->part($node, 'page'), ['form_params' => $open_form]);
    $this->assertSame(303, $response->getStatusCode());
    $location = $response->getHeaderLine('Location');
    $this->assertStringContainsString("/node/$nid/builder/content", $location);
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
    $this->assertSame(['part' => 'page', 'saved' => '1'], $query);

    $this->assertSame(4, $this->revisionCount($nid));
    $latest = $this->latestRevision($nid);
    $this->assertSame('Second title', $latest->label());
    $this->assertSame('Second note', $latest->get('field_note')->value);
    $this->assertSame(['Layout'], static::probeTitles($this->storedLayout($latest)));

    // And the layout again: the fields stay.
    [$status] = $this->saveThroughEndpoint($node, static::layout(static::probe('p1', 'Layout, again')));
    $this->assertSame(200, $status);
    $latest = $this->latestRevision($nid);
    $this->assertSame('Second title', $latest->label());
    $this->assertSame('Second note', $latest->get('field_note')->value);
    $this->assertSame(['Layout, again'], static::probeTitles($this->storedLayout($latest)));

    // The page shows it all.
    $this->drupalGet($node->toUrl());
    $this->assertSession()->titleEquals('Second title | Drupal');
    $this->assertSame(['Layout, again'], $this->shownProbeTitles());

    // The form of a page that was reloaded after a save is the form.
    $this->drupalGet($this->part($node, 'page', ['saved' => 1]));
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->fieldValueEquals('title[0][value]', 'Second title');
  }

  /**
   * A part that does not validate is not saved and says why; a required
   * field of the other part does not stand in the way.
   */
  public function testValidation(): void {
    $node = $this->createBuilderNode(['title' => 'A title']);
    $nid = $node->id();
    $assert = $this->assertSession();

    // The required field is empty: the Fields part is refused.
    $this->drupalGet($this->part($node, 'fields'));
    $this->submitForm(['field_note[0][value]' => 'A note'], 'Save');
    $assert->statusCodeEquals(200);
    $assert->pageTextContains('Must field is required.');
    $assert->elementExists('css', 'input[name="field_must[0][value]"][aria-invalid="true"]');
    $this->assertStringNotContainsString('saved=1', $this->getUrl());
    // What was typed is still there.
    $assert->fieldValueEquals('field_note[0][value]', 'A note');
    $this->assertSame(1, $this->revisionCount($nid));

    // The Page part does not have that field and is saved.
    $this->drupalGet($this->part($node, 'page'));
    $this->submitForm(['title[0][value]' => 'Another title'], 'Save');
    $assert->pageTextNotContains('field is required');
    $this->assertStringContainsString('saved=1', $this->getUrl());
    $this->assertSame(2, $this->revisionCount($nid));
    $this->assertSame('Another title', $this->latestRevision($nid)->label());

    // Its own required field is checked.
    $this->submitForm(['title[0][value]' => ''], 'Save');
    $assert->pageTextContains('Title field is required.');
    $assert->elementExists('css', 'input[name="title[0][value]"][aria-invalid="true"]');
    $this->assertSame(2, $this->revisionCount($nid));

    // Filled in, the Fields part is saved.
    $this->drupalGet($this->part($node, 'fields'));
    $this->submitForm(['field_note[0][value]' => 'A note', 'field_must[0][value]' => 'Done'], 'Save');
    $this->assertStringContainsString('saved=1', $this->getUrl());
    $latest = $this->latestRevision($nid);
    $this->assertSame(['A note', 'Done', 'Another title'], [$latest->get('field_note')->value, $latest->get('field_must')->value, $latest->label()]);
  }

  /**
   * It is the node form: what modules add to it works. Menu settings are in
   * the Fields part, the URL alias and the revision log in the Page part.
   */
  public function testWhatModulesAddToTheForm(): void {
    $this->drupalLogin($this->drupalCreateUser(array_merge(static::EDITOR_PERMISSIONS, ['administer nodes', 'administer menu', 'create url aliases'])));
    $node = $this->createBuilderNode(['field_must' => 'Filled']);
    $nid = $node->id();
    $links = \Drupal::entityTypeManager()->getStorage('menu_link_content');

    $this->drupalGet($this->part($node, 'fields'));
    $this->submitForm(['menu[enabled]' => TRUE, 'menu[title]' => 'In the menu'], 'Save');
    $this->assertStringContainsString('saved=1', $this->getUrl());
    $found = $links->loadByProperties(['link__uri' => "entity:node/$nid"]);
    $this->assertCount(1, $found);
    $this->assertSame('In the menu', reset($found)->label());
    $this->assertSession()->checkboxChecked('menu[enabled]');

    $this->drupalGet($this->part($node, 'page'));
    $this->submitForm([
      'path[0][alias]' => '/a-fine-page',
      'revision_log[0][value]' => 'Gave it an address.',
      'status[value]' => FALSE,
    ], 'Save');
    $this->assertStringContainsString('saved=1', $this->getUrl());
    $latest = $this->latestRevision($nid);
    $this->assertSame('Gave it an address.', $latest->getRevisionLogMessage());
    $this->assertFalse($latest->isPublished());
    $aliases = \Drupal::entityTypeManager()->getStorage('path_alias')->loadByProperties(['path' => "/node/$nid"]);
    $this->assertSame(['/a-fine-page'], array_values(array_map(static fn ($alias) => $alias->getAlias(), $aliases)));
    // The menu link of the other part is still there, once.
    $links->resetCache();
    $this->assertCount(1, $links->loadByProperties(['link__uri' => "entity:node/$nid"]));
    $this->assertSession()->fieldValueEquals('path[0][alias]', '/a-fine-page');
  }

}
