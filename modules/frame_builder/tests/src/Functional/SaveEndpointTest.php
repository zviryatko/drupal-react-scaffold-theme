<?php

declare(strict_types=1);

namespace Drupal\Tests\frame_builder\Functional;

use Drupal\filter\Entity\FilterFormat;
use Drupal\Tests\frame_builder\Traits\EndpointTestTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * Saving a layout: the frame_builder.save endpoint.
 */
#[Group('frame_builder')]
class SaveEndpointTest extends BuilderFunctionalTestBase {

  use EndpointTestTrait;

  /**
   * Id of the test component with a formatted text.
   */
  protected const TEXT = 'frame_builder_test_theme:fn-text';

  /**
   * Every save is a new revision by the user, with a log message; the
   * answer names it; the page then shows the layout.
   */
  public function testSaveStoresTheLayoutAsANewRevision(): void {
    $node = $this->createBuilderNode(['title' => 'A saved page']);
    $nid = $node->id();
    $first = (int) $node->getRevisionId();
    // Before anything is built the page is the normal display.
    $this->drupalGet($node->toUrl());
    $this->assertSession()->elementNotExists('css', '.probe');

    $layout = static::layout(
      static::probe('p1', 'Hello', ['tone' => 'loud', 'count' => 7, 'content' => [static::probe('p2', 'Inside')]]),
      static::probe('p3', 'Second'),
    );
    [$status, $body] = $this->saveThroughEndpoint($node, $layout);
    $this->assertSame(200, $status);
    $this->assertSame(['viewUrl', 'revision', 'paragraphs'], array_keys($body));
    $this->assertSame($node->toUrl()->toString(), $body['viewUrl']);
    $this->assertGreaterThan($first, $body['revision']);
    $this->assertNull($body['paragraphs']);

    $saved = $this->latestRevision($nid);
    $this->assertSame($body['revision'], (int) $saved->getRevisionId());
    $this->assertTrue($saved->isDefaultRevision());
    $this->assertSame((int) $this->editor->id(), (int) $saved->getRevisionUserId());
    $this->assertSame('Edited with the page builder.', $saved->getRevisionLogMessage());
    // Saving does not change whether the page is published, nor its title.
    $this->assertTrue($saved->isPublished());
    $this->assertSame('A saved page', $saved->label());
    $stored = $this->storedLayout($saved);
    $this->assertArrayHasKey('version', $stored);
    $this->assertSame(['Hello', 'Second'], static::probeTitles($stored));
    $this->assertSame('loud', $stored['content'][0]['props']['tone']);
    $this->assertSame('Inside', $stored['content'][0]['props']['content'][0]['props']['title']);
    $this->assertSame(2, $this->revisionCount($nid));

    // The page is the layout.
    $this->drupalGet($node->toUrl());
    $this->assertSession()->elementsCount('css', '.probe', 3);
    $this->assertSession()->elementTextEquals('css', '.probe--loud[data-count="7"] > .probe__title', 'Hello');
    $this->assertSession()->elementTextEquals('css', '.probe--loud > .probe__content .probe__title', 'Inside');

    // Saving again is one more revision; the older one keeps its layout.
    $second_user = $this->drupalCreateUser(static::EDITOR_PERMISSIONS);
    $this->drupalLogin($second_user);
    [$status, $again] = $this->saveThroughEndpoint($node, static::layout(static::probe('p3', 'Only this')));
    $this->assertSame(200, $status);
    $this->assertGreaterThan($body['revision'], $again['revision']);
    $this->assertSame(3, $this->revisionCount($nid));
    $latest = $this->latestRevision($nid);
    $this->assertSame((int) $second_user->id(), (int) $latest->getRevisionUserId());
    $this->assertSame(['Only this'], static::probeTitles($this->storedLayout($latest)));
    $older = \Drupal::entityTypeManager()->getStorage('node')->loadRevision($body['revision']);
    $this->assertSame(['Hello', 'Second'], static::probeTitles($this->storedLayout($older)));
    $this->assertSame((int) $this->editor->id(), (int) $older->getRevisionUserId());
    $this->drupalGet($node->toUrl());
    $this->assertSession()->elementsCount('css', '.probe', 1);
    $this->assertSession()->elementTextEquals('css', '.probe__title', 'Only this');

    // An emptied layout brings the normal display back.
    [$status] = $this->saveThroughEndpoint($node, static::layout());
    $this->assertSame(200, $status);
    $this->drupalGet($node->toUrl());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->elementNotExists('css', '.probe');
  }

  /**
   * "publish" and "draft" of the request set the published flag, for a user
   * who may; visitors see a page only when it is published.
   */
  public function testPublishAndDraft(): void {
    $node = $this->createBuilderNode(['status' => 0, 'uid' => $this->editor->id()]);
    $nid = $node->id();
    $layout = static::layout(static::probe('p1', 'For visitors'));
    $as_visitor = function () use ($node): int {
      return $this->getHttpClient()->request('GET', $node->toUrl()->setAbsolute()->toString(), ['http_errors' => FALSE])->getStatusCode();
    };

    // The editor of the tests may not publish: the layout is saved, the
    // page stays as it was.
    [$status] = $this->saveThroughEndpoint($node, $layout, ['publish' => TRUE]);
    $this->assertSame(200, $status);
    $this->assertFalse($this->latestRevision($nid)->isPublished());
    $this->assertSame(['For visitors'], static::probeTitles($this->storedLayout($this->latestRevision($nid))));
    $this->assertSame(403, $as_visitor());

    $publisher = $this->drupalCreateUser(array_merge(static::EDITOR_PERMISSIONS, ['administer nodes']));
    $this->drupalLogin($publisher);
    // Without either, the state is kept.
    [$status] = $this->saveThroughEndpoint($node, $layout);
    $this->assertSame(200, $status);
    $this->assertFalse($this->latestRevision($nid)->isPublished());

    [$status] = $this->saveThroughEndpoint($node, $layout, ['publish' => TRUE]);
    $this->assertSame(200, $status);
    $this->assertTrue($this->latestRevision($nid)->isPublished());
    $this->assertSame(200, $as_visitor());
    $this->drupalLogout();
    $this->drupalGet($node->toUrl());
    $this->assertSession()->elementTextEquals('css', '.probe__title', 'For visitors');

    $this->drupalLogin($publisher);
    [$status] = $this->saveThroughEndpoint($node, static::layout(static::probe('p1', 'Taken back')), ['draft' => TRUE]);
    $this->assertSame(200, $status);
    $this->assertFalse($this->latestRevision($nid)->isPublished());
    $this->assertSame(403, $as_visitor());

    // "draft" wins when both are sent.
    [$status] = $this->saveThroughEndpoint($node, $layout, ['publish' => TRUE]);
    $this->assertSame(200, $status);
    [$status] = $this->saveThroughEndpoint($node, $layout, ['publish' => TRUE, 'draft' => TRUE]);
    $this->assertSame(200, $status);
    $this->assertFalse($this->latestRevision($nid)->isPublished());

    // The editor without the right cannot take a page back either.
    [$status] = $this->saveThroughEndpoint($node, $layout, ['publish' => TRUE]);
    $this->assertSame(200, $status);
    $this->drupalLogin($this->editor);
    [$status] = $this->saveThroughEndpoint($node, $layout, ['draft' => TRUE]);
    $this->assertSame(200, $status);
    $this->assertTrue($this->latestRevision($nid)->isPublished());
  }

  /**
   * Requests that are not a save are refused and change nothing: no layout,
   * no or a wrong CSRF token, GET, a text format the user may not use, a
   * page that does not validate.
   */
  public function testRefusedRequests(): void {
    $node = $this->createBuilderNode();
    $nid = $node->id();
    $url = $this->builderUrl('save', $node);
    $layout = static::layout(static::probe('p1', 'Refused'));
    $json = ['Content-Type' => 'application/json'];

    foreach ([[], ['data' => 'a string'], ['data' => NULL], ['layout' => $layout]] as $body) {
      [$status, $answer] = $this->postJson($url, $body);
      $this->assertSame(400, $status);
      $this->assertSame(['errors' => ['No layout received.']], $answer);
    }
    $response = $this->request('POST', $url, ['body' => 'not json', 'headers' => $json + ['X-CSRF-Token' => $this->csrfToken()]]);
    $this->assertSame(400, $response->getStatusCode());

    // The token of the session is required.
    $body = json_encode(['data' => $layout]);
    $this->assertSame(403, $this->request('POST', $url, ['body' => $body, 'headers' => $json])->getStatusCode());
    [$status] = $this->postJson($url, ['data' => $layout], 'not-the-token');
    $this->assertSame(403, $status);
    $token = $this->csrfToken();
    // The token belongs to the session: it is worth nothing without it.
    $without_session = $this->getHttpClient()->request('POST', $url->setAbsolute()->toString(), [
      'body' => $body,
      'headers' => $json + ['X-CSRF-Token' => $token],
      'http_errors' => FALSE,
    ]);
    $this->assertSame(403, $without_session->getStatusCode());

    // Only POST.
    $this->assertSame(405, $this->request('GET', $url)->getStatusCode());
    $this->assertSame(405, $this->request('PUT', $url, ['body' => $body, 'headers' => $json + ['X-CSRF-Token' => $token]])->getStatusCode());

    $this->assertSame(1, $this->revisionCount($nid));
    $this->assertNull($this->latestRevision($nid)->get('frame_layout')->value);

    // A page that does not validate is not saved; the errors name the field.
    $invalid = $this->createBuilderNode(['title' => str_repeat('Long ', 60)]);
    [$status, $answer] = $this->saveThroughEndpoint($invalid, $layout);
    $this->assertSame(422, $status);
    $this->assertCount(1, $answer['errors']);
    $this->assertStringStartsWith('title', $answer['errors'][0]);
    $this->assertSame(1, $this->revisionCount($invalid->id()));
  }

  /**
   * Formatted text is accepted in the formats the user may use; a format
   * the user may not use only for text that was already saved with it.
   */
  public function testTextFormatsOfTheLayout(): void {
    FilterFormat::create(['format' => 'full', 'name' => 'Full', 'filters' => []])->save();
    $node = $this->createBuilderNode();
    $nid = $node->id();
    $text = static fn (string $id, string $html, string $format): array => static::item(static::TEXT, $id, ['text' => ['value' => $html, 'format' => $format]]);

    // The editor of the tests has the fallback format only.
    [$status, $answer] = $this->saveThroughEndpoint($node, static::layout($text('t1', '<p>Mine <script>alert(1)</script></p>', 'full')));
    $this->assertSame(422, $status);
    $this->assertSame(['errors' => ['You may not use the "full" text format.']], $answer);
    $this->assertSame(1, $this->revisionCount($nid));
    // Nested in a slot it is found as well.
    [$status] = $this->saveThroughEndpoint($node, static::layout(static::probe('p1', 'Box', ['content' => [$text('t1', '<p>Mine</p>', 'full')]])));
    $this->assertSame(422, $status);
    // A format that does not exist is not one the user may use.
    [$status] = $this->saveThroughEndpoint($node, static::layout($text('t1', '<p>Mine</p>', 'nonsense')));
    $this->assertSame(422, $status);

    [$status] = $this->saveThroughEndpoint($node, static::layout($text('t1', '<p>Plain <em>text</em></p>', 'plain_text')));
    $this->assertSame(200, $status);
    // The fallback format escapes markup.
    $this->drupalGet($node->toUrl());
    $this->assertSession()->elementNotExists('css', '.fn-text em');
    $this->assertSession()->elementTextContains('css', '.fn-text', '<em>text</em>');

    // Somebody who may use the format saves text with it.
    $this->drupalLogin($this->drupalCreateUser(array_merge(static::EDITOR_PERMISSIONS, ['use text format full'])));
    [$status] = $this->saveThroughEndpoint($node, static::layout($text('t1', '<p>Full <em>text</em></p>', 'full')));
    $this->assertSame(200, $status);
    $this->drupalGet($node->toUrl());
    $this->assertSession()->elementTextEquals('css', '.fn-text em', 'text');

    // The editor keeps that text when saving the rest of the page, and
    // cannot change it or add more in that format.
    $this->drupalLogin($this->editor);
    $kept = $text('t1', '<p>Full <em>text</em></p>', 'full');
    [$status] = $this->saveThroughEndpoint($node, static::layout($kept, static::probe('p1', 'Added')));
    $this->assertSame(200, $status);
    $count = $this->revisionCount($nid);
    [$status] = $this->saveThroughEndpoint($node, static::layout($text('t1', '<p>Full <em>changed</em></p>', 'full')));
    $this->assertSame(422, $status);
    [$status] = $this->saveThroughEndpoint($node, static::layout($kept, $text('t2', '<p>More</p>', 'full')));
    $this->assertSame(422, $status);
    $this->assertSame($count, $this->revisionCount($nid));
  }

}
