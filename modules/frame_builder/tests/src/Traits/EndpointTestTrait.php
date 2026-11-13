<?php

declare(strict_types=1);

namespace Drupal\Tests\frame_builder\Traits;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Url;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\NodeInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Helpers of the functional tests that talk to the routes of the builder:
 * URLs of its routes, plain HTTP requests with the session of the browser,
 * and what is stored after them.
 *
 * For classes that extend BuilderFunctionalTestBase.
 */
trait EndpointTestTrait {

  /**
   * Id of the component of the test theme.
   */
  protected const PROBE = 'frame_builder_test_theme:probe';

  /**
   * The URL of a route of the builder.
   *
   * @param string $route
   *   Route name without the "frame_builder." prefix: "save", "draft"...
   * @param \Drupal\node\NodeInterface|null $node
   *   The page, for the routes that have one.
   * @param array $query
   *   Query arguments.
   * @param string|null $langcode
   *   Language of the URL (its prefix), for a translation.
   */
  protected function builderUrl(string $route, ?NodeInterface $node = NULL, array $query = [], ?string $langcode = NULL): Url {
    $options = ['query' => $query];
    if ($langcode !== NULL) {
      $options['language'] = \Drupal::languageManager()->getLanguage($langcode);
    }
    return Url::fromRoute('frame_builder.' . $route, $node ? ['node' => $node->id()] : [], $options);
  }

  /**
   * Sends a request with the cookies of the browser session, without
   * following redirects.
   */
  protected function request(string $method, Url|string $url, array $options = []): ResponseInterface {
    $url = $url instanceof Url ? $url->setAbsolute()->toString() : $this->getAbsoluteUrl($url);
    return $this->getHttpClient()->request($method, $url, $options + [
      'cookies' => $this->getSessionCookies(),
      'http_errors' => FALSE,
      'allow_redirects' => FALSE,
    ]);
  }

  /**
   * Gets JSON from an endpoint of the builder.
   *
   * @return array
   *   The status code and the decoded body: [int, mixed].
   */
  protected function getJson(Url|string $url): array {
    $response = $this->request('GET', $url);
    return [$response->getStatusCode(), Json::decode((string) $response->getBody())];
  }

  /**
   * Saves a layout through the save endpoint, as the editor does.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The page.
   * @param array $data
   *   The layout.
   * @param array $extra
   *   More of the request body: ['publish' => TRUE], ['draft' => TRUE].
   * @param string|null $langcode
   *   The translation to save, by the language of the URL.
   *
   * @return array
   *   The status code and the decoded body: [int, mixed].
   */
  protected function saveThroughEndpoint(NodeInterface $node, array $data, array $extra = [], ?string $langcode = NULL): array {
    return $this->postJson($this->builderUrl('save', $node, [], $langcode), ['data' => $data] + $extra);
  }

  /**
   * A probe item that shows a title, as a layout item.
   */
  protected static function probe(string $id, string $title, array $props = []): array {
    return static::item(static::PROBE, $id, $props + ['title' => $title, 'shown' => TRUE]);
  }

  /**
   * The latest revision of a page as it is in storage now.
   *
   * @param int|string $nid
   *   Node id.
   * @param string|null $langcode
   *   A translation: its latest revision. The latest revision of the node
   *   otherwise.
   */
  protected function latestRevision(int|string $nid, ?string $langcode = NULL): NodeInterface {
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $storage->resetCache([$nid]);
    if ($langcode !== NULL) {
      $revision = $storage->loadRevision($storage->getLatestTranslationAffectedRevisionId($nid, $langcode));
      return $revision->getTranslation($langcode);
    }
    return $storage->loadRevision($storage->getLatestRevisionId($nid));
  }

  /**
   * The published (default) revision of a page as it is in storage now.
   */
  protected function defaultRevision(int|string $nid): NodeInterface {
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $storage->resetCache([$nid]);
    return $storage->load($nid);
  }

  /**
   * Number of revisions of a page.
   */
  protected function revisionCount(int|string $nid): int {
    return (int) \Drupal::entityTypeManager()->getStorage('node')->getQuery()
      ->allRevisions()
      ->accessCheck(FALSE)
      ->condition('nid', $nid)
      ->count()
      ->execute();
  }

  /**
   * The document stored in the layout field of exactly this revision and
   * translation.
   */
  protected function storedLayout(NodeInterface $node): ?array {
    return Json::decode((string) $node->get('frame_layout')->value);
  }

  /**
   * Titles of the probes in a layout document, in order, top level only.
   */
  protected static function probeTitles(?array $data): array {
    return array_map(static fn (array $item) => $item['props']['title'] ?? NULL, $data['content'] ?? []);
  }

  /**
   * Titles of the probes on the page the browser is on, in order.
   */
  protected function shownProbeTitles(): array {
    return array_map(static fn ($element): string => $element->getText(), $this->getSession()->getPage()->findAll('css', '.probe__title'));
  }

  /**
   * Keeps values of the node form in the builder as a draft of the user:
   * what the form frame does while the user types.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The page.
   * @param array $edit
   *   Form values, by the name of the input.
   * @param string $part
   *   The part of the form that has them: "fields" or "page".
   * @param string|null $langcode
   *   The translation, by the language of the URL.
   */
  protected function keepFieldDraft(NodeInterface $node, array $edit, string $part = 'fields', ?string $langcode = NULL): void {
    $this->drupalGet($this->builderUrl('content_form', $node, ['part' => $part], $langcode));
    $this->submitForm($edit, 'Keep as draft');
    $this->assertSession()->statusCodeEquals(200);
  }

  /**
   * Adds a one-line text field to a content type, with its widget in the
   * form and its formatter in the default display.
   */
  protected function addTextField(string $name, string $label, bool $required = FALSE, string $bundle = 'landing'): void {
    if (!FieldStorageConfig::loadByName('node', $name)) {
      FieldStorageConfig::create(['field_name' => $name, 'entity_type' => 'node', 'type' => 'string'])->save();
    }
    FieldConfig::create([
      'field_name' => $name,
      'entity_type' => 'node',
      'bundle' => $bundle,
      'label' => $label,
      'required' => $required,
    ])->save();
    $displays = \Drupal::service('entity_display.repository');
    $displays->getFormDisplay('node', $bundle)->setComponent($name, ['type' => 'string_textfield'])->save();
    $displays->getViewDisplay('node', $bundle)->setComponent($name, ['type' => 'string', 'label' => 'hidden'])->save();
  }

}
