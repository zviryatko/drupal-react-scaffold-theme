<?php

declare(strict_types=1);

namespace Drupal\Tests\frame_builder\Functional;

use Drupal\Tests\frame_builder\Traits\EndpointTestTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * The preview page: the page as visitors would see it, with what the user
 * has not saved yet.
 */
#[Group('frame_builder')]
class PreviewTest extends BuilderFunctionalTestBase {

  use EndpointTestTrait;

  /**
   * The notice of a preview of an unsaved layout.
   */
  protected const NOTICE = 'Preview of your unsaved layout. Visitors still see the saved page.';

  /**
   * The preview shows the unsaved layout of the user; the page itself and
   * the preview of others show the saved one.
   */
  public function testUnsavedLayout(): void {
    $node = $this->createBuilderNode();
    $preview = $this->builderUrl('preview', $node);

    // Nothing built and nothing unsaved: the normal display, as the page.
    $this->drupalGet($preview);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSame([], $this->shownProbeTitles());
    $this->assertSession()->pageTextNotContains(static::NOTICE);

    // A first layout that was never saved can be previewed.
    [$status] = $this->postJson($this->builderUrl('draft', $node), ['data' => static::layout(static::probe('p1', 'Never saved'))]);
    $this->assertSame(200, $status);
    $this->drupalGet($preview);
    $this->assertSame(['Never saved'], $this->shownProbeTitles());
    $this->assertSession()->pageTextContains(static::NOTICE);
    $this->drupalGet($node->toUrl());
    $this->assertSame([], $this->shownProbeTitles());

    [$status] = $this->saveThroughEndpoint($node, static::layout(static::probe('p1', 'Saved')));
    $this->assertSame(200, $status);
    // Saved, nothing unsaved: the preview is the page.
    $this->drupalGet($preview);
    $this->assertSame(['Saved'], $this->shownProbeTitles());
    $this->assertSession()->pageTextNotContains(static::NOTICE);
    $this->assertSession()->titleEquals('Builder: A page | Drupal');

    $unsaved = static::layout(
      static::probe('p1', 'Unsaved', ['tone' => 'loud', 'content' => [static::probe('p2', 'Unsaved inside')]]),
      static::probe('p3', 'Unsaved too'),
    );
    [$status] = $this->postJson($this->builderUrl('draft', $node), ['data' => $unsaved]);
    $this->assertSame(200, $status);
    $this->drupalGet($preview);
    $this->assertSame(['Unsaved', 'Unsaved inside', 'Unsaved too'], $this->shownProbeTitles());
    $this->assertSession()->elementExists('css', '.probe--loud');
    $this->assertSession()->pageTextContains(static::NOTICE);
    // Again: the preview is never served from a cache.
    [$status] = $this->postJson($this->builderUrl('draft', $node), ['data' => static::layout(static::probe('p1', 'Changed since'))]);
    $this->assertSame(200, $status);
    $this->drupalGet($preview);
    $this->assertSame(['Changed since'], $this->shownProbeTitles());

    // The page is the saved one, also right after the preview.
    $this->drupalGet($node->toUrl());
    $this->assertSame(['Saved'], $this->shownProbeTitles());
    $this->assertSession()->pageTextNotContains(static::NOTICE);

    // Somebody else previews the saved page.
    $this->drupalLogin($this->drupalCreateUser(static::EDITOR_PERMISSIONS));
    $this->drupalGet($preview);
    $this->assertSame(['Saved'], $this->shownProbeTitles());
    $this->assertSession()->pageTextNotContains(static::NOTICE);

    // After a discard the preview is the saved page again.
    $this->drupalLogin($this->editor);
    [$status] = $this->postJson($this->builderUrl('draft', $node), ['discard' => TRUE]);
    $this->assertSame(200, $status);
    $this->drupalGet($preview);
    $this->assertSame(['Saved'], $this->shownProbeTitles());
  }

  /**
   * Values typed into the node form and not saved are in the preview, where
   * a Field block shows the field; with a saved and with an unsaved layout.
   */
  public function testUnsavedFieldValues(): void {
    $this->addTextField('field_note', 'Note');
    $node = $this->createBuilderNode(['title' => 'Saved title', 'field_note' => 'Saved note']);
    $preview = $this->builderUrl('preview', $node);
    $fields = [
      static::item('drupal_field:title', 'f1', ['formatter' => 'string', 'label' => 'hidden']),
      static::item('drupal_field:field_note', 'f2', ['formatter' => 'string', 'label' => 'above']),
    ];
    [$status] = $this->saveThroughEndpoint($node, static::layout(...$fields));
    $this->assertSame(200, $status);
    $shown = fn (): array => array_map(
      static fn ($element): string => $element->getText(),
      $this->getSession()->getPage()->findAll('css', '.frame-builder-field'),
    );

    $this->drupalGet($preview);
    $this->assertSame(['Saved title', 'Note Saved note'], $shown());
    $this->assertSession()->elementExists('css', '.frame-builder-field--label-hidden');
    $this->assertSession()->elementExists('css', '.frame-builder-field--label-above');

    // One field of each part of the form.
    $this->keepFieldDraft($node, ['field_note[0][value]' => 'Typed note']);
    $this->keepFieldDraft($node, ['title[0][value]' => 'Typed title'], 'page');
    $this->drupalGet($preview);
    $this->assertSame(['Typed title', 'Note Typed note'], $shown());
    // The layout is the saved one: no notice about it.
    $this->assertSession()->pageTextNotContains(static::NOTICE);

    // The page, and the preview of somebody else, have the saved values.
    $this->drupalGet($node->toUrl());
    $this->assertSame(['Saved title', 'Note Saved note'], $shown());
    $this->assertSame('Saved note', $this->latestRevision($node->id())->get('field_note')->value);
    $other = $this->drupalCreateUser(static::EDITOR_PERMISSIONS);
    $this->drupalLogin($other);
    $this->drupalGet($preview);
    $this->assertSame(['Saved title', 'Note Saved note'], $shown());

    // Together with an unsaved layout.
    $this->drupalLogin($this->editor);
    [$status] = $this->postJson($this->builderUrl('draft', $node), ['data' => static::layout(static::probe('p1', 'Unsaved'), $fields[1])]);
    $this->assertSame(200, $status);
    $this->drupalGet($preview);
    $this->assertSame(['Unsaved'], $this->shownProbeTitles());
    $this->assertSame(['Note Typed note'], $shown());
    $this->assertSession()->pageTextContains(static::NOTICE);
  }

  /**
   * In the preview dialog of the editor ("?frame=1") the page comes with
   * the style sheet that hides the admin toolbar.
   */
  public function testInsideTheEditor(): void {
    $node = $this->createBuilderNode();
    $this->saveLayout($node, static::layout(static::probe('p1', 'Saved')));

    $this->drupalGet($this->builderUrl('preview', $node));
    $this->assertSession()->responseNotContains('css/preview-frame.css');
    $this->assertSession()->responseNotContains('js-static/frame-links.js');

    $this->drupalGet($this->builderUrl('preview', $node, ['frame' => 1]));
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSame(['Saved'], $this->shownProbeTitles());
    $this->assertSession()->responseContains('css/preview-frame.css');
    // Links of the previewed page open outside the dialog.
    $this->assertSession()->responseContains('js-static/frame-links.js');
  }

}
