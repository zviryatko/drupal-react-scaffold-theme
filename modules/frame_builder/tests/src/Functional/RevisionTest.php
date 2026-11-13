<?php

declare(strict_types=1);

namespace Drupal\Tests\frame_builder\Functional;

use Drupal\Tests\content_moderation\Traits\ContentModerationTestTrait;
use Drupal\Tests\frame_builder\Traits\EndpointTestTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * The layout is a part of the revisions of a page.
 */
#[Group('frame_builder')]
class RevisionTest extends BuilderFunctionalTestBase {

  use ContentModerationTestTrait;
  use EndpointTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['content_moderation'];

  /**
   * Reverting a revision with Drupal's own form brings its layout back: on
   * the page and in the editor.
   */
  public function testRevertBringsALayoutBack(): void {
    $node = $this->createBuilderNode();
    $nid = $node->id();
    [$status, $first] = $this->saveThroughEndpoint($node, static::layout(static::probe('p1', 'First'), static::probe('p2', 'And more')));
    $this->assertSame(200, $status);
    [$status, $second] = $this->saveThroughEndpoint($node, static::layout(static::probe('p1', 'Second')));
    $this->assertSame(200, $status);
    $this->assertSame(3, $this->revisionCount($nid));

    // The revisions of the builder are listed with their log message.
    $this->drupalGet("node/$nid/revisions");
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Edited with the page builder.');

    $this->drupalGet($node->toUrl());
    $this->assertSame(['Second'], $this->shownProbeTitles());
    // An old revision is shown with its own layout.
    $this->drupalGet("node/$nid/revisions/{$first['revision']}/view");
    $this->assertSame(['First', 'And more'], $this->shownProbeTitles());

    // A draft of the editor, kept before the revert.
    [$status] = $this->postJson($this->builderUrl('draft', $node), ['data' => static::layout(static::probe('p1', 'Unsaved')), 'revision' => $second['revision']]);
    $this->assertSame(200, $status);

    $this->drupalGet("node/$nid/revisions/{$first['revision']}/revert");
    $this->submitForm([], 'Revert');
    $this->assertSame(4, $this->revisionCount($nid));
    $reverted = $this->latestRevision($nid);
    $this->assertGreaterThan($second['revision'], (int) $reverted->getRevisionId());
    $this->assertSame(['First', 'And more'], static::probeTitles($this->storedLayout($reverted)));

    $this->drupalGet($node->toUrl());
    $this->assertSame(['First', 'And more'], $this->shownProbeTitles());

    $settings = $this->editorSettings($node);
    $this->assertSame((int) $reverted->getRevisionId(), $settings['revision']);
    $this->assertSame(['First', 'And more'], static::probeTitles($settings['data']));
    // The draft was of the revision before the revert.
    $this->assertTrue($settings['draft']['stale']);

    // The next save continues from the reverted layout's revision.
    [$status, $third] = $this->saveThroughEndpoint($node, static::layout(static::probe('p1', 'Third')));
    $this->assertSame(200, $status);
    $this->assertGreaterThan((int) $reverted->getRevisionId(), $third['revision']);
    $older = \Drupal::entityTypeManager()->getStorage('node')->loadRevision($second['revision']);
    $this->assertSame(['Second'], static::probeTitles($this->storedLayout($older)));
  }

  /**
   * With content moderation the latest revision can be a draft that is
   * newer than the published page: that one is edited, in every part of
   * the builder, while visitors keep the published page.
   */
  public function testTheLatestRevisionIsEdited(): void {
    $workflow = $this->createEditorialWorkflow();
    $this->addEntityTypeAndBundleToWorkflow($workflow, 'node', 'landing');
    $this->drupalLogin($this->drupalCreateUser(array_merge(static::EDITOR_PERMISSIONS, [
      'use editorial transition create_new_draft',
      'use editorial transition publish',
      'view latest version',
      'view any unpublished content',
    ])));

    $node = $this->createBuilderNode(['title' => 'Published title', 'moderation_state' => 'published']);
    $nid = $node->id();
    $this->saveLayout($node, static::layout(static::probe('p1', 'Published')));
    $published = (int) $node->getRevisionId();
    // A newer draft, made outside the builder.
    $draft = \Drupal::entityTypeManager()->getStorage('node')->createRevision($node, FALSE);
    $draft->set('moderation_state', 'draft');
    $draft->setTitle('Draft title');
    $this->saveLayout($draft, static::layout(static::probe('p1', 'Forward draft')));
    $forward = (int) $draft->getRevisionId();
    $this->assertGreaterThan($published, $forward);
    $this->assertSame($published, (int) $this->defaultRevision($nid)->getRevisionId());

    // The editor opens the draft.
    $settings = $this->editorSettings($node);
    $this->assertSame($forward, $settings['revision']);
    $this->assertSame('Draft title', $settings['title']);
    $this->assertSame(['Forward draft'], static::probeTitles($settings['data']));
    $this->assertStringEndsWith("/node/$nid/latest", $settings['viewUrl']);

    // So do the node form, the preview and the render endpoint.
    $this->drupalGet($this->builderUrl('content_form', $node, ['part' => 'page']));
    $this->assertSession()->fieldValueEquals('title[0][value]', 'Draft title');
    $this->drupalGet($this->builderUrl('preview', $node));
    $this->assertSession()->elementTextEquals('css', '.probe__title', 'Forward draft');
    [$status, $body] = $this->postJson($this->builderUrl('render'), [
      'type' => 'drupal_field:title',
      'node' => $nid,
      'props' => ['id' => 'f1', 'formatter' => 'string', 'label' => 'hidden'],
    ]);
    $this->assertSame(200, $status);
    $this->assertStringContainsString('Draft title', $body['html']);

    // A draft kept now is of the latest revision.
    [$status] = $this->postJson($this->builderUrl('draft', $node), ['data' => static::layout(static::probe('p1', 'Unsaved'))]);
    $this->assertSame(200, $status);
    $kept = $this->editorSettings($node)['draft'];
    $this->assertSame($forward, $kept['revision']);
    $this->assertFalse($kept['stale']);

    // A save is a revision on top of the draft, not of the published page.
    [$status, $body] = $this->saveThroughEndpoint($node, static::layout(static::probe('p1', 'Draft, edited')));
    $this->assertSame(200, $status);
    $this->assertGreaterThan($forward, $body['revision']);
    $this->assertStringEndsWith("/node/$nid/latest", $body['viewUrl']);
    $latest = $this->latestRevision($nid);
    $this->assertSame('Draft title', $latest->label());
    $this->assertSame('draft', $latest->get('moderation_state')->value);
    $this->assertFalse($latest->isDefaultRevision());

    // Visitors still get the published page.
    $default = $this->defaultRevision($nid);
    $this->assertSame($published, (int) $default->getRevisionId());
    $this->assertSame(['Published'], static::probeTitles($this->storedLayout($default)));
    $this->drupalLogout();
    $this->drupalGet($node->toUrl());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->elementTextEquals('css', '.probe__title', 'Published');
    $this->assertSession()->pageTextContains('Published title');
  }

}
