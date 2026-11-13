<?php

declare(strict_types=1);

namespace Drupal\Tests\frame_builder\Functional;

use Drupal\node\Entity\NodeType;
use Drupal\Tests\frame_builder\Traits\EndpointTestTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * The autosave of the node form in the editor: the hidden "Keep as draft"
 * button keeps what is typed, for the user, without saving the page.
 */
#[Group('frame_builder')]
class ContentFormDraftTest extends BuilderFunctionalTestBase {

  use EndpointTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['menu_ui'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->addTextField('field_note', 'Note');
    $this->addTextField('field_must', 'Must', TRUE);
  }

  /**
   * Whether the form the browser is on says it has unsaved values.
   */
  protected function hasDraft(): bool {
    return $this->getDrupalSettings()['frameBuilderFrame']['draft'];
  }

  /**
   * Values are kept whatever state the form is in, nothing is saved; they
   * are put back when the form is opened again, per part and per user; a
   * save of a part forgets the drafts of that part.
   */
  public function testKeepRestoreAndForget(): void {
    $node = $this->createBuilderNode(['title' => 'Saved title', 'field_note' => 'Saved note']);
    $nid = $node->id();
    $fields = $this->builderUrl('content_form', $node, ['part' => 'fields']);
    $page = $this->builderUrl('content_form', $node, ['part' => 'page']);
    $assert = $this->assertSession();

    // The required field is empty: kept all the same, without a word.
    $this->drupalGet($fields);
    $this->assertFalse($this->hasDraft());
    $this->submitForm(['field_note[0][value]' => 'Typed note'], 'Keep as draft');
    $assert->statusCodeEquals(200);
    $assert->pageTextNotContains('field is required');
    $assert->elementNotExists('css', '[aria-invalid="true"]');
    $this->assertStringNotContainsString('saved=1', $this->getUrl());
    // The page is untouched.
    $this->assertSame(1, $this->revisionCount($nid));
    $this->assertSame('Saved note', $this->latestRevision($nid)->get('field_note')->value);

    // Opened again: the typed value is back and the form says so.
    $this->drupalGet($fields);
    $assert->fieldValueEquals('field_note[0][value]', 'Typed note');
    $assert->fieldValueEquals('field_must[0][value]', '');
    $this->assertTrue($this->hasDraft());
    // The other part has nothing unsaved.
    $this->drupalGet($page);
    $assert->fieldValueEquals('title[0][value]', 'Saved title');
    $this->assertFalse($this->hasDraft());

    // A later draft replaces the value; drafts of both parts live together.
    $this->drupalGet($fields);
    $this->submitForm(['field_note[0][value]' => 'Typed note, more'], 'Keep as draft');
    $this->drupalGet($page);
    $this->submitForm(['title[0][value]' => ''], 'Keep as draft');
    $assert->pageTextNotContains('field is required');
    $this->submitForm(['title[0][value]' => 'Typed title'], 'Keep as draft');
    $this->drupalGet($page);
    $assert->fieldValueEquals('title[0][value]', 'Typed title');
    $this->assertTrue($this->hasDraft());
    $this->drupalGet($fields);
    $assert->fieldValueEquals('field_note[0][value]', 'Typed note, more');
    $this->assertTrue($this->hasDraft());
    $this->assertSame(1, $this->revisionCount($nid));

    // Somebody else gets the saved values.
    $other = $this->drupalCreateUser(static::EDITOR_PERMISSIONS);
    $this->drupalLogin($other);
    $this->drupalGet($fields);
    $assert->fieldValueEquals('field_note[0][value]', 'Saved note');
    $this->assertFalse($this->hasDraft());
    $this->drupalGet($page);
    $assert->fieldValueEquals('title[0][value]', 'Saved title');
    $this->assertFalse($this->hasDraft());

    // A save that does not validate keeps the draft.
    $this->drupalLogin($this->editor);
    $this->drupalGet($fields);
    $this->submitForm([], 'Save');
    $assert->pageTextContains('Must field is required.');
    $this->drupalGet($fields);
    $this->assertTrue($this->hasDraft());

    // Saved: the drafted value is the value now, the drafts of this part
    // are forgotten, those of the other part are still unsaved.
    $this->submitForm(['field_must[0][value]' => 'Filled'], 'Save');
    $this->assertStringContainsString('saved=1', $this->getUrl());
    $assert->fieldValueEquals('field_note[0][value]', 'Typed note, more');
    $this->assertFalse($this->hasDraft());
    $latest = $this->latestRevision($nid);
    $this->assertSame('Typed note, more', $latest->get('field_note')->value);
    $this->assertSame('Saved title', $latest->label());
    $this->drupalGet($page);
    $assert->fieldValueEquals('title[0][value]', 'Typed title');
    $this->assertTrue($this->hasDraft());

    // A save of the layout leaves the drafts of the form alone.
    [$status] = $this->saveThroughEndpoint($node, static::layout(static::probe('p1', 'Layout')));
    $this->assertSame(200, $status);
    $this->assertSame('Saved title', $this->latestRevision($nid)->label());
    $this->drupalGet($page);
    $assert->fieldValueEquals('title[0][value]', 'Typed title');
    $this->assertTrue($this->hasDraft());

    $this->submitForm([], 'Save');
    $this->assertStringContainsString('saved=1', $this->getUrl());
    $this->assertFalse($this->hasDraft());
    $latest = $this->latestRevision($nid);
    $this->assertSame('Typed title', $latest->label());
    $this->assertSame('Typed note, more', $latest->get('field_note')->value);
    $this->assertSame(['Layout'], static::probeTitles($this->storedLayout($latest)));
  }

  /**
   * Keeping a draft does nothing but that: what modules do when the node
   * form is saved does not happen.
   */
  public function testNothingElseHappens(): void {
    NodeType::load('landing')->setThirdPartySetting('menu_ui', 'available_menus', ['main'])->save();
    $this->drupalLogin($this->drupalCreateUser(array_merge(static::EDITOR_PERMISSIONS, ['administer nodes', 'administer menu'])));
    $node = $this->createBuilderNode(['field_must' => 'Filled']);
    $nid = $node->id();
    $links = \Drupal::entityTypeManager()->getStorage('menu_link_content');
    $changed = $node->getChangedTime();

    $this->drupalGet($this->builderUrl('content_form', $node, ['part' => 'fields']));
    $this->submitForm(['menu[enabled]' => TRUE, 'menu[title]' => 'In the menu', 'field_note[0][value]' => 'Typed'], 'Keep as draft');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSame([], $links->loadByProperties(['link__uri' => "entity:node/$nid"]));
    // The form is shown again as it was sent, to be continued.
    $this->assertSession()->fieldValueEquals('menu[title]', 'In the menu');
    $this->assertSession()->fieldValueEquals('field_note[0][value]', 'Typed');

    // The published flag of the Page part: kept, not applied.
    $this->drupalGet($this->builderUrl('content_form', $node, ['part' => 'page']));
    $this->submitForm(['status[value]' => FALSE, 'revision_log[0][value]' => 'Not yet.'], 'Keep as draft');
    $latest = $this->latestRevision($nid);
    $this->assertTrue($latest->isPublished());
    $this->assertSame(1, $this->revisionCount($nid));
    $this->assertSame($changed, $latest->getChangedTime());
    $this->drupalGet($this->builderUrl('content_form', $node, ['part' => 'page']));
    $this->assertSession()->checkboxNotChecked('status[value]');
    $this->assertTrue($this->hasDraft());

    // Saved for real, the handlers of the modules run.
    $this->drupalGet($this->builderUrl('content_form', $node, ['part' => 'fields']));
    $this->submitForm(['menu[enabled]' => TRUE, 'menu[title]' => 'In the menu'], 'Save');
    $links->resetCache();
    $this->assertCount(1, $links->loadByProperties(['link__uri' => "entity:node/$nid"]));
    $this->assertSame('Typed', $this->latestRevision($nid)->get('field_note')->value);
  }

}
