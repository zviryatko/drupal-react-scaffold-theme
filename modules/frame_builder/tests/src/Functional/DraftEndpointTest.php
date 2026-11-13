<?php

declare(strict_types=1);

namespace Drupal\Tests\frame_builder\Functional;

use Drupal\Tests\frame_builder\Traits\EndpointTestTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * Unsaved state of the editor: the frame_builder.draft endpoint.
 */
#[Group('frame_builder')]
class DraftEndpointTest extends BuilderFunctionalTestBase {

  use EndpointTestTrait;

  /**
   * An unsaved layout is kept for its user without touching the page, and
   * is given back to the editor when it is opened again.
   */
  public function testKeepAndRestore(): void {
    $node = $this->createBuilderNode();
    $nid = $node->id();
    [$status] = $this->saveThroughEndpoint($node, static::layout(static::probe('p1', 'Saved')));
    $this->assertSame(200, $status);
    $revision = (int) $this->latestRevision($nid)->getRevisionId();
    $count = $this->revisionCount($nid);
    $url = $this->builderUrl('draft', $node);

    $before = time();
    [$status, $body] = $this->postJson($url, ['data' => static::layout(static::probe('p1', 'Unsaved'), static::probe('p2', 'New')), 'revision' => $revision]);
    $this->assertSame(200, $status);
    $this->assertSame(['time'], array_keys($body));
    $this->assertGreaterThanOrEqual($before - 5, $body['time']);
    $this->assertLessThanOrEqual(time() + 5, $body['time']);

    // Nothing is written to the page.
    $this->assertSame($count, $this->revisionCount($nid));
    $this->assertSame(['Saved'], static::probeTitles($this->storedLayout($this->latestRevision($nid))));
    $this->drupalGet($node->toUrl());
    $this->assertSession()->elementTextEquals('css', '.probe__title', 'Saved');

    // The editor gets the saved layout and, next to it, the draft.
    $settings = $this->editorSettings($node);
    $this->assertSame(['Saved'], static::probeTitles($settings['data']));
    $draft = $settings['draft'];
    $this->assertSame(['Unsaved', 'New'], static::probeTitles($draft['data']));
    $this->assertSame(static::PROBE, $draft['data']['content'][0]['type']);
    $this->assertSame($revision, $draft['revision']);
    $this->assertSame($body['time'], $draft['time']);
    $this->assertSame(\Drupal::service('date.formatter')->format($body['time'], 'short'), $draft['label']);
    $this->assertFalse($draft['stale']);

    // Keeping again replaces it. Without a revision in the request the
    // draft is of the revision that is the latest now.
    [$status] = $this->postJson($url, ['data' => static::layout(static::probe('p1', 'Unsaved again'))]);
    $this->assertSame(200, $status);
    $draft = $this->editorSettings($node)['draft'];
    $this->assertSame(['Unsaved again'], static::probeTitles($draft['data']));
    $this->assertSame($revision, $draft['revision']);

    // A draft belongs to its user.
    $other = $this->drupalCreateUser(static::EDITOR_PERMISSIONS);
    $this->drupalLogin($other);
    $this->assertNull($this->editorSettings($node)['draft']);
    [$status] = $this->postJson($url, ['data' => static::layout(static::probe('p9', 'Of the other'))]);
    $this->assertSame(200, $status);
    $this->assertSame(['Of the other'], static::probeTitles($this->editorSettings($node)['draft']['data']));
    $this->drupalLogin($this->editor);
    $this->assertSame(['Unsaved again'], static::probeTitles($this->editorSettings($node)['draft']['data']));

    // And to its page.
    $second = $this->createBuilderNode();
    $this->assertNull($this->editorSettings($second)['draft']);
  }

  /**
   * A draft kept before somebody else saved the page is marked stale; the
   * own save, a discard, and a request without a layout drop the draft.
   */
  public function testStaleAndDiscard(): void {
    $node = $this->createBuilderNode();
    $nid = $node->id();
    $url = $this->builderUrl('draft', $node);
    $other = $this->drupalCreateUser(static::EDITOR_PERMISSIONS);

    [$status] = $this->postJson($url, ['data' => static::layout(static::probe('p1', 'Mine, unsaved')), 'revision' => (int) $node->getRevisionId()]);
    $this->assertSame(200, $status);
    $this->assertFalse($this->editorSettings($node)['draft']['stale']);

    // Somebody else saves the page.
    $this->drupalLogin($other);
    [$status, $saved] = $this->saveThroughEndpoint($node, static::layout(static::probe('p1', 'Theirs, saved')));
    $this->assertSame(200, $status);
    $this->drupalLogin($this->editor);
    $settings = $this->editorSettings($node);
    $this->assertSame($saved['revision'], $settings['revision']);
    $this->assertSame(['Theirs, saved'], static::probeTitles($settings['data']));
    $this->assertSame(['Mine, unsaved'], static::probeTitles($settings['draft']['data']));
    $this->assertSame((int) $node->getRevisionId(), $settings['draft']['revision']);
    $this->assertTrue($settings['draft']['stale']);

    // Discarded: gone, the page is as it was saved.
    [$status, $body] = $this->postJson($url, ['discard' => TRUE]);
    $this->assertSame(200, $status);
    $this->assertSame(['discarded' => TRUE], $body);
    $settings = $this->editorSettings($node);
    $this->assertNull($settings['draft']);
    $this->assertSame(['Theirs, saved'], static::probeTitles($settings['data']));
    // Discarding nothing is fine.
    [$status, $body] = $this->postJson($url, ['discard' => TRUE]);
    $this->assertSame([200, ['discarded' => TRUE]], [$status, $body]);

    // "discard" wins over a layout in the same request.
    [$status] = $this->postJson($url, ['data' => static::layout(static::probe('p1', 'Kept')), 'discard' => TRUE]);
    $this->assertSame(200, $status);
    $this->assertNull($this->editorSettings($node)['draft']);

    // A request without a layout drops the kept one.
    [$status] = $this->postJson($url, ['data' => static::layout(static::probe('p1', 'Kept'))]);
    $this->assertSame(200, $status);
    $this->assertNotNull($this->editorSettings($node)['draft']);
    [$status, $body] = $this->postJson($url, ['data' => 'not a layout']);
    $this->assertSame([200, ['discarded' => TRUE]], [$status, $body]);
    $this->assertNull($this->editorSettings($node)['draft']);

    // Saving the page drops the draft of the one who saved, and makes the
    // draft of the other stale.
    $this->drupalLogin($other);
    [$status] = $this->postJson($url, ['data' => static::layout(static::probe('p1', 'Theirs, unsaved'))]);
    $this->assertSame(200, $status);
    $this->drupalLogin($this->editor);
    [$status] = $this->postJson($url, ['data' => static::layout(static::probe('p1', 'Mine, unsaved'))]);
    $this->assertSame(200, $status);
    [$status] = $this->saveThroughEndpoint($node, static::layout(static::probe('p1', 'Mine, saved')));
    $this->assertSame(200, $status);
    $this->assertNull($this->editorSettings($node)['draft']);
    $this->drupalLogin($other);
    $draft = $this->editorSettings($node)['draft'];
    $this->assertSame(['Theirs, unsaved'], static::probeTitles($draft['data']));
    $this->assertTrue($draft['stale']);
  }

  /**
   * Discarding also forgets what was typed into the node form; a request
   * without a layout does not.
   */
  public function testDiscardForgetsFieldValues(): void {
    $node = $this->createBuilderNode(['title' => 'Saved title']);
    $url = $this->builderUrl('draft', $node);
    $page = $this->builderUrl('content_form', $node, ['part' => 'page']);

    $this->keepFieldDraft($node, ['title[0][value]' => 'Typed title'], 'page');
    [$status] = $this->postJson($url, ['data' => static::layout(static::probe('p1', 'Unsaved'))]);
    $this->assertSame(200, $status);

    // The layout is dropped, the typed title stays.
    [$status] = $this->postJson($url, []);
    $this->assertSame(200, $status);
    $this->assertNull($this->editorSettings($node)['draft']);
    $this->drupalGet($page);
    $this->assertSession()->fieldValueEquals('title[0][value]', 'Typed title');

    [$status] = $this->postJson($url, ['discard' => TRUE]);
    $this->assertSame(200, $status);
    $this->drupalGet($page);
    $this->assertSession()->fieldValueEquals('title[0][value]', 'Saved title');
    $this->assertFalse($this->getDrupalSettings()['frameBuilderFrame']['draft']);
  }

  /**
   * The endpoint takes POST with the token of the session only.
   */
  public function testRefusedRequests(): void {
    $node = $this->createBuilderNode();
    $url = $this->builderUrl('draft', $node);
    $body = ['data' => static::layout(static::probe('p1', 'Unsaved'))];

    [$status] = $this->postJson($url, $body, 'not-the-token');
    $this->assertSame(403, $status);
    $response = $this->request('POST', $url, ['body' => json_encode($body), 'headers' => ['Content-Type' => 'application/json']]);
    $this->assertSame(403, $response->getStatusCode());
    $this->assertSame(405, $this->request('GET', $url)->getStatusCode());
    $this->assertNull($this->editorSettings($node)['draft']);
  }

}
