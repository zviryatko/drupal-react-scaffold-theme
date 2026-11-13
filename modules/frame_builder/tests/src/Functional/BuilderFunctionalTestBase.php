<?php

declare(strict_types=1);

namespace Drupal\Tests\frame_builder\Functional;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\frame_builder\Traits\BuilderTestTrait;
use Drupal\user\UserInterface;

/**
 * Base of the functional tests of the page builder: real requests to its
 * pages and JSON endpoints.
 *
 * The default theme is the test subtheme of Frame; the "landing" content
 * type uses the builder; $this->editor may use it.
 */
abstract class BuilderFunctionalTestBase extends BrowserTestBase {

  use BuilderTestTrait;

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'frame_builder_test_theme';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['node', 'frame_builder'];

  /**
   * Permissions of the editor of the tests.
   */
  protected const EDITOR_PERMISSIONS = [
    'use frame builder',
    'access content',
    'create landing content',
    'edit any landing content',
    'delete any landing content',
    'view landing revisions',
    'revert landing revisions',
    'view own unpublished content',
  ];

  /**
   * A user who may build pages.
   */
  protected UserInterface $editor;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->createBuilderType();
    $this->editor = $this->drupalCreateUser(static::EDITOR_PERMISSIONS);
    $this->drupalLogin($this->editor);
  }

  /**
   * The settings the editor page gives its JavaScript.
   */
  protected function editorSettings(NodeInterface $node): array {
    $this->drupalGet(Url::fromRoute('frame_builder.editor_frame', ['node' => $node->id()]));
    $this->assertSession()->statusCodeEquals(200);
    return $this->getDrupalSettings()['frameBuilder'];
  }

  /**
   * Posts JSON to an endpoint of the builder, as the editor does.
   *
   * @return array
   *   The status code and the decoded body: [int, mixed].
   */
  protected function postJson(Url|string $url, array $body, ?string $token = NULL): array {
    $url = $url instanceof Url ? $url->setAbsolute()->toString() : $this->getAbsoluteUrl($url);
    $token ??= $this->csrfToken();
    $response = $this->getHttpClient()->request('POST', $url, [
      'body' => Json::encode($body),
      'headers' => ['Content-Type' => 'application/json', 'X-CSRF-Token' => $token],
      'cookies' => $this->getSessionCookies(),
      'http_errors' => FALSE,
    ]);
    return [$response->getStatusCode(), Json::decode((string) $response->getBody())];
  }

  /**
   * The CSRF token of the session, as scripts get it.
   */
  protected function csrfToken(): string {
    return $this->drupalGet('session/token');
  }

}
