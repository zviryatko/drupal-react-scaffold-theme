<?php

declare(strict_types=1);

namespace Drupal\Tests\frame_builder\Functional;

use Drupal\Core\Serialization\Yaml;
use Drupal\Core\Url;
use Drupal\Tests\frame_builder\Traits\EndpointTestTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * Who gets the editor, and where.
 */
#[Group('frame_builder')]
class EditorAccessTest extends BuilderFunctionalTestBase {

  use EndpointTestTrait;

  /**
   * Every route of the module: how it is requested with nothing but a page,
   * and what a user who may build that page gets then. NULL: not checked
   * here.
   */
  protected const ROUTES = [
    'frame_builder.editor' => ['GET', 200],
    'frame_builder.editor_frame' => ['GET', 200],
    'frame_builder.content_form' => ['GET', 200],
    // No layout in the request.
    'frame_builder.save' => ['POST', 400],
    // No component named.
    'frame_builder.render' => ['POST', 404],
    'frame_builder.urls' => ['GET', 200],
    'frame_builder.media' => ['GET', 200],
    'frame_builder.tokens' => ['GET', 200],
    // Needs the Media Library module: SuggestionEndpointsTest.
    'frame_builder.media_frame' => ['GET', NULL],
    'frame_builder.formatter_settings' => ['GET', 200],
    // Nothing to keep: the draft is discarded.
    'frame_builder.draft' => ['POST', 200],
    'frame_builder.preview' => ['GET', 200],
    'frame_builder.formatter_form' => ['GET', 200],
    // No saved block named.
    'frame_builder.library' => ['GET', 404],
    // No paragraph type named.
    'frame_builder.paragraph_form' => ['GET', 404],
    // A settings page of the site: not for editors (see the end of testEveryRoute()).
    'frame_builder.components' => ['GET', 403],
  ];

  /**
   * The builder is the edit page of a page of a builder content type.
   */
  public function testEditPageIsTheBuilder(): void {
    $node = $this->createBuilderNode();
    $this->drupalGet($node->toUrl('edit-form'));
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->elementExists('css', 'iframe.frame-builder-frame');

    $settings = $this->editorSettings($node);
    $this->assertSame((int) $node->id(), $settings['node']);
    $this->assertSame([], $settings['sections'], 'Nothing but a title: no Content panel.');
    $this->assertContains('frame_builder_test_theme:probe', array_column($settings['components'], 'id'));
  }

  /**
   * Without the permission the edit page is the normal form and the editor
   * is refused.
   */
  public function testWithoutPermission(): void {
    $node = $this->createBuilderNode();
    $this->drupalLogin($this->drupalCreateUser(['access content', 'edit any landing content']));
    $this->drupalGet($node->toUrl('edit-form'));
    $this->assertSession()->elementNotExists('css', 'iframe.frame-builder-frame');
    $this->assertSession()->fieldExists('title[0][value]');
    $this->drupalGet(Url::fromRoute('frame_builder.editor_frame', ['node' => $node->id()]));
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * The edit page and the builder page hold the editor in a frame that
   * points to the editor of the same page; the title names the page.
   */
  public function testHostPage(): void {
    $node = $this->createBuilderNode(['title' => 'Summer sale']);
    $frame = Url::fromRoute('frame_builder.editor_frame', ['node' => $node->id()])->toString();
    foreach ([$node->toUrl('edit-form'), $this->builderUrl('editor', $node)] as $url) {
      $this->drupalGet($url);
      $this->assertSession()->statusCodeEquals(200);
      $this->assertSession()->elementAttributeContains('css', 'iframe.frame-builder-frame', 'src', $frame);
    }
    $this->assertSession()->titleEquals('Builder: Summer sale | Drupal');
    // The frame itself has the root element the editor mounts in.
    $this->drupalGet($frame);
    $this->assertSession()->elementExists('css', '#frame-builder');
    $this->assertSession()->titleEquals('Builder: Summer sale | Drupal');
  }

  /**
   * A user who may use the builder but not edit the page gets neither the
   * edit page nor the builder nor its save.
   */
  public function testNodeAccessDenied(): void {
    $node = $this->createBuilderNode();
    $this->drupalLogin($this->drupalCreateUser(['use frame builder', 'access content']));
    $this->drupalGet($node->toUrl('edit-form'));
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalGet($this->builderUrl('editor', $node));
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalGet($this->builderUrl('editor_frame', $node));
    $this->assertSession()->statusCodeEquals(403);

    [$status] = $this->saveThroughEndpoint($node, static::layout(static::probe('p1', 'Not mine')));
    $this->assertSame(403, $status);
    $this->assertSame(1, $this->revisionCount($node->id()));
    $this->assertNull($this->latestRevision($node->id())->get('frame_layout')->value);

    // Own pages only: the page of somebody else stays closed.
    $author = $this->drupalCreateUser(['use frame builder', 'access content', 'edit own landing content']);
    $this->drupalLogin($author);
    $own = $this->createBuilderNode(['uid' => $author->id()]);
    $this->drupalGet($this->builderUrl('editor_frame', $own));
    $this->assertSession()->statusCodeEquals(200);
    $this->drupalGet($this->builderUrl('editor_frame', $node));
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * A content type that does not use the builder is edited with the normal
   * form; the routes of the builder are refused for its pages.
   */
  public function testTypeNotEnabled(): void {
    $this->createBuilderType('plain', 'synced', FALSE);
    $this->drupalLogin($this->drupalCreateUser(['use frame builder', 'access content', 'edit any plain content']));
    $node = $this->createBuilderNode(['type' => 'plain']);

    $this->drupalGet($node->toUrl('edit-form'));
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->elementNotExists('css', 'iframe.frame-builder-frame');
    $this->assertSession()->fieldExists('title[0][value]');
    // The normal form still saves.
    $this->submitForm(['title[0][value]' => 'Plain and changed'], 'Save');
    $this->assertSame('Plain and changed', $this->latestRevision($node->id())->label());

    foreach (['editor', 'editor_frame', 'content_form', 'preview', 'formatter_settings'] as $route) {
      $this->drupalGet($this->builderUrl($route, $node));
      $this->assertSession()->statusCodeEquals(403);
    }
    [$status] = $this->saveThroughEndpoint($node, static::layout(static::probe('p1', 'No builder here')));
    $this->assertSame(403, $status);
    [$status] = $this->postJson($this->builderUrl('draft', $node), ['data' => static::layout()]);
    $this->assertSame(403, $status);
  }

  /**
   * With a default theme that is not based on Frame the builder is off: the
   * edit page is the normal form and the builder is refused.
   */
  public function testThemeNotBasedOnFrame(): void {
    $node = $this->createBuilderNode();
    $this->container->get('theme_installer')->install(['stark']);
    $this->config('system.theme')->set('default', 'stark')->save();

    $this->drupalGet($node->toUrl('edit-form'));
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->elementNotExists('css', 'iframe.frame-builder-frame');
    $this->assertSession()->fieldExists('title[0][value]');
    $this->submitForm(['title[0][value]' => 'Edited without the builder'], 'Save');
    $this->assertSame('Edited without the builder', $this->latestRevision($node->id())->label());

    $this->drupalGet($this->builderUrl('editor_frame', $node));
    $this->assertSession()->statusCodeEquals(403);
    [$status] = $this->saveThroughEndpoint($node, static::layout(static::probe('p1', 'No theme')));
    $this->assertSame(403, $status);

    // Back on a subtheme of Frame the builder is back.
    $this->config('system.theme')->set('default', 'frame_builder_test_theme')->save();
    $this->drupalGet($node->toUrl('edit-form'));
    $this->assertSession()->elementExists('css', 'iframe.frame-builder-frame');
  }

  /**
   * Every route of frame_builder.routing.yml, for every kind of user:
   * visitors and users without the permission get nothing, a user without
   * access to the page gets only what is not about a page, an editor gets
   * what the route answers to a request with nothing in it.
   */
  public function testEveryRoute(): void {
    $routes = Yaml::decode(file_get_contents(dirname(__DIR__, 3) . '/frame_builder.routing.yml'));
    $this->assertSame([], array_diff(array_keys($routes), array_keys(static::ROUTES)), 'Every route of the module has its access tested.');
    $this->assertSame([], array_diff(array_keys(static::ROUTES), array_keys($routes)), 'Every tested route exists.');

    $node = $this->createBuilderNode();
    $this->createBuilderType('plain', 'synced', FALSE);
    $plain = $this->createBuilderNode(['type' => 'plain']);
    $missing = (int) $plain->id() + 100;

    $send = function (string $name, int|string $nid, ?string $method = NULL) use ($routes): int {
      $path = str_replace('{node}', (string) $nid, $routes[$name]['path']);
      $method ??= static::ROUTES[$name][0];
      $options = $method === 'POST' ? ['body' => '{}', 'headers' => ['Content-Type' => 'application/json', 'X-CSRF-Token' => $this->csrfToken()]] : [];
      return $this->request($method, $path, $options)->getStatusCode();
    };
    $of_a_page = static fn (string $name): bool => str_contains($routes[$name]['path'], '{node}');

    // An editor: of the page, of a page of another content type, of no page.
    foreach (static::ROUTES as $name => [, $expected]) {
      if ($expected !== NULL) {
        $this->assertSame($expected, $send($name, $node->id()), "$name for an editor");
      }
      if ($of_a_page($name)) {
        $this->assertSame(403, $send($name, $plain->id()), "$name for a page of a content type without the builder");
        $this->assertSame(404, $send($name, $missing), "$name for a page that does not exist");
        $this->assertSame(404, $send($name, 'abc'), "$name for something that is not a node id");
      }
      // Routes that change something take POST only, and only with the token.
      $methods = $routes[$name]['methods'] ?? ['GET', 'POST'];
      if (!in_array('GET', $methods, TRUE)) {
        $this->assertSame(405, $send($name, $node->id(), 'GET'), "$name with GET");
        $path = str_replace('{node}', (string) $node->id(), $routes[$name]['path']);
        $no_token = $this->request('POST', $path, ['body' => '{}', 'headers' => ['Content-Type' => 'application/json']]);
        $this->assertSame(403, $no_token->getStatusCode(), "$name without a CSRF token");
      }
    }

    // The permission without access to the page.
    $this->drupalLogin($this->drupalCreateUser(['use frame builder', 'access content']));
    foreach (static::ROUTES as $name => [, $expected]) {
      $status = $send($name, $node->id());
      if ($of_a_page($name)) {
        $this->assertSame(403, $status, "$name without access to the page");
      }
      elseif ($expected !== NULL) {
        $this->assertSame($expected, $status, "$name with the permission only");
      }
    }

    // Access to the page without the permission.
    $this->drupalLogin($this->drupalCreateUser(['access content', 'edit any landing content', 'administer nodes']));
    foreach (array_keys(static::ROUTES) as $name) {
      $this->assertSame(403, $send($name, $node->id()), "$name without the permission");
    }

    // Visitors.
    $this->drupalLogout();
    foreach (array_keys(static::ROUTES) as $name) {
      $this->assertSame(403, $send($name, $node->id()), "$name for a visitor");
    }
    $this->drupalGet($node->toUrl('edit-form'));
    $this->assertSession()->statusCodeEquals(403);

    // The settings page of the components: for who administers the site.
    $this->drupalLogin($this->drupalCreateUser(['administer site configuration']));
    $this->assertSame(200, $send('frame_builder.components', $node->id()));
  }

  /**
   * Without the Media Library module there is no library to show in a
   * frame: the route answers that, not with an error of the site.
   */
  public function testMediaFrameWithoutTheMediaLibrary(): void {
    $this->assertFalse(\Drupal::moduleHandler()->moduleExists('media_library'));
    $this->assertSame(404, $this->request('GET', $this->builderUrl('media_frame'))->getStatusCode());
  }

}
