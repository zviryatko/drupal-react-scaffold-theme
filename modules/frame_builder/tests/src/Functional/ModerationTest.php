<?php

declare(strict_types=1);

namespace Drupal\Tests\frame_builder\Functional;

use Drupal\Tests\content_moderation\Traits\ContentModerationTestTrait;
use Drupal\Tests\frame_builder\Traits\EndpointTestTrait;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * With core's Content Moderation, Publish and Draft of the builder are the
 * "published" and "draft" states of the workflow.
 */
#[Group('frame_builder')]
class ModerationTest extends BuilderFunctionalTestBase {

  use ContentModerationTestTrait;
  use EndpointTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['content_moderation'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $workflow = $this->createEditorialWorkflow();
    $this->addEntityTypeAndBundleToWorkflow($workflow, 'node', 'landing');
  }

  /**
   * A user of the builder with the given transitions of the workflow.
   */
  protected function moderator(string ...$transitions): UserInterface {
    $permissions = array_map(static fn (string $transition): string => "use editorial transition $transition", $transitions);
    return $this->drupalCreateUser(array_merge(static::EDITOR_PERMISSIONS, $permissions, ['view latest version', 'view any unpublished content']));
  }

  /**
   * What a visitor gets of the page: the status code and the titles of the
   * probes on it.
   */
  protected function asVisitor(string $path): array {
    $response = $this->getHttpClient()->request('GET', $this->getAbsoluteUrl($path), ['http_errors' => FALSE]);
    preg_match_all('#<h2 class="probe__title">([^<]*)</h2>#', (string) $response->getBody(), $matches);
    return [$response->getStatusCode(), $matches[1]];
  }

  /**
   * Draft and Publish move the page through the workflow; visitors see the
   * published revision, the editor works on the latest one.
   */
  public function testDraftAndPublishFollowTheWorkflow(): void {
    $publisher = $this->moderator('create_new_draft', 'publish');
    $this->drupalLogin($publisher);
    $node = $this->createBuilderNode(['uid' => $publisher->id()]);
    $nid = $node->id();
    $path = "node/$nid";
    // A new page of a moderated type is a draft.
    $this->assertSame('draft', $node->get('moderation_state')->value);
    $this->assertSame([403, []], $this->asVisitor($path));

    $actions = $this->editorSettings($node)['actions'];
    $this->assertTrue($actions['draft']);
    $this->assertTrue($actions['publish']);

    // Saved as it is: still a draft.
    [$status, $body] = $this->saveThroughEndpoint($node, static::layout(static::probe('p1', 'One')));
    $this->assertSame(200, $status);
    $this->assertSame('draft', $this->latestRevision($nid)->get('moderation_state')->value);
    $this->assertSame([403, []], $this->asVisitor($path));

    // Published.
    [$status, $body] = $this->saveThroughEndpoint($node, static::layout(static::probe('p1', 'Two')), ['publish' => TRUE]);
    $this->assertSame(200, $status);
    $latest = $this->latestRevision($nid);
    $this->assertSame('published', $latest->get('moderation_state')->value);
    $this->assertTrue($latest->isPublished());
    $this->assertTrue($latest->isDefaultRevision());
    $this->assertStringEndsWith("/node/$nid", $body['viewUrl']);
    $this->assertSame([200, ['Two']], $this->asVisitor($path));
    $published = $body['revision'];

    // Draft on a published page: a newer revision that visitors do not get.
    [$status, $body] = $this->saveThroughEndpoint($node, static::layout(static::probe('p1', 'Three')), ['draft' => TRUE]);
    $this->assertSame(200, $status);
    $latest = $this->latestRevision($nid);
    $this->assertSame($body['revision'], (int) $latest->getRevisionId());
    $this->assertSame('draft', $latest->get('moderation_state')->value);
    $this->assertFalse($latest->isDefaultRevision());
    $this->assertSame($published, (int) $this->defaultRevision($nid)->getRevisionId());
    $this->assertSame([200, ['Two']], $this->asVisitor($path));
    $this->assertSame(403, $this->asVisitor("$path/latest")[0]);
    // "View page" of the editor goes to the draft.
    $this->assertStringEndsWith("/node/$nid/latest", $body['viewUrl']);
    $this->drupalGet($body['viewUrl']);
    $this->assertSession()->elementTextEquals('css', '.probe__title', 'Three');

    $settings = $this->editorSettings($node);
    $this->assertSame($body['revision'], $settings['revision']);
    $this->assertSame(['Three'], static::probeTitles($settings['data']));
    $this->assertStringEndsWith("/node/$nid/latest", $settings['viewUrl']);

    // A save without a state keeps the draft a draft.
    [$status] = $this->saveThroughEndpoint($node, static::layout(static::probe('p1', 'Four')));
    $this->assertSame(200, $status);
    $this->assertSame('draft', $this->latestRevision($nid)->get('moderation_state')->value);
    $this->assertSame([200, ['Two']], $this->asVisitor($path));

    // Published again: the draft becomes the page.
    [$status, $body] = $this->saveThroughEndpoint($node, static::layout(static::probe('p1', 'Five')), ['publish' => TRUE]);
    $this->assertSame(200, $status);
    $this->assertTrue($this->latestRevision($nid)->isDefaultRevision());
    $this->assertStringEndsWith("/node/$nid", $body['viewUrl']);
    $this->assertSame([200, ['Five']], $this->asVisitor($path));
  }

  /**
   * The buttons follow the transitions the user may make; a transition the
   * user may not make is not made by asking for it.
   */
  public function testTransitionsOfTheUser(): void {
    $author = $this->moderator('create_new_draft');
    $publisher = $this->moderator('create_new_draft', 'publish');
    $reader = $this->moderator();
    $node = $this->createBuilderNode(['uid' => $author->id()]);
    $nid = $node->id();

    // An author may save drafts, not publish.
    $this->drupalLogin($author);
    $actions = $this->editorSettings($node)['actions'];
    $this->assertTrue($actions['draft']);
    $this->assertFalse($actions['publish']);
    [$status] = $this->saveThroughEndpoint($node, static::layout(static::probe('p1', 'By the author')), ['publish' => TRUE]);
    $this->assertSame(200, $status);
    $latest = $this->latestRevision($nid);
    $this->assertSame('draft', $latest->get('moderation_state')->value);
    $this->assertFalse($latest->isPublished());
    $this->assertSame(['By the author'], static::probeTitles($this->storedLayout($latest)));
    $this->assertSame(403, $this->asVisitor("node/$nid")[0]);

    $this->drupalLogin($publisher);
    [$status] = $this->saveThroughEndpoint($node, static::layout(static::probe('p1', 'Published')), ['publish' => TRUE]);
    $this->assertSame(200, $status);
    $this->assertSame([200, ['Published']], $this->asVisitor("node/$nid"));

    // The author works on a published page in a draft of it.
    $this->drupalLogin($author);
    [$status] = $this->saveThroughEndpoint($node, static::layout(static::probe('p1', 'A proposal')), ['draft' => TRUE]);
    $this->assertSame(200, $status);
    $this->assertSame([200, ['Published']], $this->asVisitor("node/$nid"));
    $this->assertSame(['A proposal'], static::probeTitles($this->editorSettings($node)['data']));

    // Without any transition of the workflow the page cannot be edited
    // (Content Moderation refuses it), so the builder is closed too.
    $this->drupalLogin($reader);
    $this->drupalGet($node->toUrl('edit-form'));
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalGet($this->builderUrl('editor_frame', $node));
    $this->assertSession()->statusCodeEquals(403);
    $count = $this->revisionCount($nid);
    [$status] = $this->saveThroughEndpoint($node, static::layout(static::probe('p1', 'By a reader')));
    $this->assertSame(403, $status);
    $this->assertSame($count, $this->revisionCount($nid));
  }

}
