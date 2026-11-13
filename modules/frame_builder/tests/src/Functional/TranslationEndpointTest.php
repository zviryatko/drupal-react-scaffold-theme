<?php

declare(strict_types=1);

namespace Drupal\Tests\frame_builder\Functional;

use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\NodeInterface;
use Drupal\Tests\frame_builder\Traits\EndpointTestTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * Translations of a page through the editor and its endpoints, in the two
 * translation modes of a content type.
 */
#[Group('frame_builder')]
class TranslationEndpointTest extends BuilderFunctionalTestBase {

  use EndpointTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['language', 'content_translation'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    ConfigurableLanguage::createFromLangcode('fr')->save();
    // "landing" is synced; "free" has independent translations.
    $this->createBuilderType('free', 'independent');
    foreach (['landing', 'free'] as $type) {
      \Drupal::service('content_translation.manager')->setEnabled('node', $type, TRUE);
    }
    $this->rebuildAll();
    $this->drupalLogin($this->drupalCreateUser(array_merge(static::EDITOR_PERMISSIONS, [
      'edit any free content',
      'translate landing node',
      'translate free node',
      'create content translations',
      'update content translations',
    ])));
  }

  /**
   * Creates the French translation of a page with Drupal's translation
   * form, as an editor does.
   */
  protected function translate(NodeInterface $node, string $title): void {
    $this->drupalGet('fr/node/' . $node->id() . '/translations/add/en/fr');
    $this->assertSession()->statusCodeEquals(200);
    $this->submitForm(['title[0][value]' => $title], 'edit-submit');
    $this->assertTrue($this->latestRevision($node->id())->hasTranslation('fr'));
  }

  /**
   * The editor settings of a translation of a page.
   */
  protected function settingsOf(NodeInterface $node, string $langcode): array {
    $this->drupalGet($this->builderUrl('editor_frame', $node, [], $langcode));
    $this->assertSession()->statusCodeEquals(200);
    return $this->getDrupalSettings()['frameBuilder'];
  }

  /**
   * Synced: a translation has the structure of the original and its own
   * texts. Its editor is locked to texts, and whatever a save of it sends,
   * only texts are stored.
   */
  public function testSyncedTranslation(): void {
    $node = $this->createBuilderNode(['title' => 'A page']);
    $nid = $node->id();
    $original = static::layout(
      static::probe('p1', 'Hello', ['tone' => 'calm', 'count' => 3, 'content' => [static::probe('p2', 'Inside')]]),
      static::probe('p3', 'Same in both'),
    );
    [$status] = $this->saveThroughEndpoint($node, $original);
    $this->assertSame(200, $status);

    // Before there is a translation nothing is said about translations.
    $info = $this->settingsOf($node, 'en')['translation'];
    $this->assertNull($info['short']);

    $this->translate($node, 'Une page');

    // The editor of the translation: the original structure, locked.
    $settings = $this->settingsOf($node, 'fr');
    $this->assertSame('fr', $settings['langcode']);
    $this->assertSame('Une page', $settings['title']);
    $this->assertStringEndsWith("/fr/node/$nid", $settings['viewUrl']);
    $this->assertSame(['Hello', 'Same in both'], static::probeTitles($settings['data']));
    $info = $settings['translation'];
    $this->assertSame('synced', $info['mode']);
    $this->assertSame('French', $info['language']);
    $this->assertTrue($info['locked']);
    $this->assertSame('French translation · text and links only', $info['short']);
    $this->assertStringContainsString('the structure comes from the English page', $info['notice']);
    // Its endpoints are those of the translation.
    foreach (['save', 'draft', 'content', 'page', 'preview'] as $name) {
      $this->assertStringContainsString('/fr/', $settings['endpoints'][$name], $name);
    }

    // A save of the translation: texts changed, and things a locked editor
    // would not send: another tone and count, an item removed, one added.
    $sent = static::layout(
      static::probe('p1', 'Bonjour', ['tone' => 'loud', 'count' => 9, 'content' => [static::probe('p2', 'Dedans')]]),
      static::probe('p9', 'Ajouté'),
    );
    [$status, $body] = $this->saveThroughEndpoint($node, $sent, [], 'fr');
    $this->assertSame(200, $status);
    $this->assertStringEndsWith("/fr/node/$nid", $body['viewUrl']);

    // Only the texts that differ are stored, by item and prop.
    $french = $this->latestRevision($nid, 'fr');
    $stored = $this->storedLayout($french);
    $this->assertSame(['p1.title' => 'Bonjour', 'p2.title' => 'Dedans'], $stored['strings']);
    $this->assertArrayNotHasKey('content', $stored);
    $this->assertSame('Une page', $french->label());
    $this->assertSame('Edited with the page builder.', $french->getRevisionLogMessage());
    // The original is what it was.
    $english = $this->latestRevision($nid)->getTranslation('en');
    $this->assertSame(['Hello', 'Same in both'], static::probeTitles($this->storedLayout($english)));
    $this->assertSame('A page', $english->label());

    // The page of each language.
    $this->drupalGet("fr/node/$nid");
    $this->assertSame(['Bonjour', 'Dedans', 'Same in both'], $this->shownProbeTitles());
    $this->assertSession()->elementExists('css', '.probe--calm[data-count="3"]');
    $this->assertSession()->elementNotExists('css', '.probe--loud');
    $this->drupalGet("node/$nid");
    $this->assertSame(['Hello', 'Inside', 'Same in both'], $this->shownProbeTitles());

    // The editor of the translation opens with its texts in the structure.
    $data = $this->settingsOf($node, 'fr')['data'];
    $this->assertSame(['Bonjour', 'Same in both'], static::probeTitles($data));
    $this->assertSame('calm', $data['content'][0]['props']['tone']);
    $this->assertSame('Dedans', $data['content'][0]['props']['content'][0]['props']['title']);

    // The structure changes on the original: the translation follows and
    // keeps its texts; texts changed on the original stay translated.
    $changed = static::layout(
      static::probe('p4', 'New on top'),
      static::probe('p1', 'Hello again', ['tone' => 'loud', 'count' => 5, 'content' => [static::probe('p2', 'Inside')]]),
    );
    [$status] = $this->saveThroughEndpoint($node, $changed, [], 'en');
    $this->assertSame(200, $status);
    $this->drupalGet("node/$nid");
    $this->assertSame(['New on top', 'Hello again', 'Inside'], $this->shownProbeTitles());
    $this->drupalGet("fr/node/$nid");
    $this->assertSame(['New on top', 'Bonjour', 'Dedans'], $this->shownProbeTitles());
    $this->assertSession()->elementExists('css', '.probe--loud[data-count="5"]');
    // The original says its structure is shared, and is not locked.
    $info = $this->settingsOf($node, 'en')['translation'];
    $this->assertFalse($info['locked']);
    $this->assertSame('English · structure shared with translations', $info['short']);
    $this->assertStringContainsString('applies to every language', $info['notice']);
    // Saving the original did not bring back an older copy of the translation.
    $this->assertSame(['p1.title' => 'Bonjour', 'p2.title' => 'Dedans'], $this->storedLayout($this->latestRevision($nid, 'fr'))['strings']);
    $this->assertSame(['New on top', 'Bonjour'], static::probeTitles($this->settingsOf($node, 'fr')['data']));

    // A text set back to the original's is no longer an override.
    $sent = static::layout(
      static::probe('p4', 'Nouveau'),
      static::probe('p1', 'Hello again', ['content' => [static::probe('p2', 'Dedans')]]),
    );
    [$status] = $this->saveThroughEndpoint($node, $sent, [], 'fr');
    $this->assertSame(200, $status);
    $this->assertSame(['p4.title' => 'Nouveau', 'p2.title' => 'Dedans'], $this->storedLayout($this->latestRevision($nid, 'fr'))['strings']);
    $this->drupalGet("fr/node/$nid");
    $this->assertSame(['Nouveau', 'Hello again', 'Dedans'], $this->shownProbeTitles());
    $this->drupalGet("node/$nid");
    $this->assertSame(['New on top', 'Hello again', 'Inside'], $this->shownProbeTitles());
  }

  /**
   * Independent: a translation starts as a copy of the original and is
   * then a layout of its own.
   */
  public function testIndependentTranslation(): void {
    $node = $this->createBuilderNode(['type' => 'free', 'title' => 'A page']);
    $nid = $node->id();
    [$status] = $this->saveThroughEndpoint($node, static::layout(static::probe('p1', 'Hello'), static::probe('p2', 'World')));
    $this->assertSame(200, $status);
    $this->translate($node, 'Une page');

    // The copy made when the translation was created.
    $settings = $this->settingsOf($node, 'fr');
    $this->assertSame(['Hello', 'World'], static::probeTitles($settings['data']));
    $info = $settings['translation'];
    $this->assertSame('independent', $info['mode']);
    $this->assertFalse($info['locked']);
    $this->assertSame('French · independent copy, not synced', $info['short']);
    $this->assertStringContainsString('has its own layout', $info['notice']);
    $this->drupalGet("fr/node/$nid");
    $this->assertSame(['Hello', 'World'], $this->shownProbeTitles());

    // The translation changes its structure freely.
    $french = static::layout(static::probe('p7', 'Tout autre', ['tone' => 'loud']));
    [$status] = $this->saveThroughEndpoint($node, $french, [], 'fr');
    $this->assertSame(200, $status);
    $stored = $this->storedLayout($this->latestRevision($nid, 'fr'));
    $this->assertSame(['Tout autre'], static::probeTitles($stored));
    $this->assertArrayNotHasKey('strings', $stored);
    $this->drupalGet("fr/node/$nid");
    $this->assertSame(['Tout autre'], $this->shownProbeTitles());
    $this->assertSession()->elementExists('css', '.probe--loud');
    $this->drupalGet("node/$nid");
    $this->assertSame(['Hello', 'World'], $this->shownProbeTitles());

    // And the original: nothing is shared.
    [$status] = $this->saveThroughEndpoint($node, static::layout(static::probe('p1', 'Hello, changed')), [], 'en');
    $this->assertSame(200, $status);
    $this->drupalGet("node/$nid");
    $this->assertSame(['Hello, changed'], $this->shownProbeTitles());
    $this->drupalGet("fr/node/$nid");
    $this->assertSame(['Tout autre'], $this->shownProbeTitles());
    $this->assertSame(['Tout autre'], static::probeTitles($this->settingsOf($node, 'fr')['data']));
    $settings = $this->settingsOf($node, 'en');
    $this->assertSame(['Hello, changed'], static::probeTitles($settings['data']));
    $this->assertFalse($settings['translation']['locked']);
    $this->assertSame('English · translations are independent copies', $settings['translation']['short']);
    $this->assertStringContainsString('Changes here are not applied to them', $settings['translation']['notice']);
  }

  /**
   * The editor of the original says that the page has translations as soon
   * as it has one.
   */
  public function testTheOriginalKnowsANewTranslation(): void {
    $node = $this->createBuilderNode(['title' => 'A page']);
    [$status] = $this->saveThroughEndpoint($node, static::layout(static::probe('p1', 'Hello')));
    $this->assertSame(200, $status);
    $this->translate($node, 'Une page');
    $info = $this->settingsOf($node, 'en')['translation'];
    $this->assertSame('English · structure shared with translations', $info['short']);
    $this->assertNotNull($info['notice']);
  }

  /**
   * Unsaved work, the preview, the node form and the fields on the canvas
   * are of the translation being edited.
   */
  public function testTheOtherEndpointsFollowTheLanguage(): void {
    $node = $this->createBuilderNode(['title' => 'A page']);
    $nid = $node->id();
    [$status] = $this->saveThroughEndpoint($node, static::layout(static::probe('p1', 'Hello')));
    $this->assertSame(200, $status);
    $this->translate($node, 'Une page');

    // A draft of the translation is not one of the original.
    [$status] = $this->postJson($this->builderUrl('draft', $node, [], 'fr'), ['data' => static::layout(static::probe('p1', 'Brouillon'))]);
    $this->assertSame(200, $status);
    $this->assertSame(['Brouillon'], static::probeTitles($this->settingsOf($node, 'fr')['draft']['data']));
    $this->assertNull($this->settingsOf($node, 'en')['draft']);
    $this->drupalGet($this->builderUrl('preview', $node, [], 'fr'));
    $this->assertSame(['Brouillon'], $this->shownProbeTitles());
    $this->drupalGet($this->builderUrl('preview', $node, [], 'en'));
    $this->assertSame(['Hello'], $this->shownProbeTitles());
    // The draft is unsaved: saving the original leaves it, stale or not.
    [$status] = $this->postJson($this->builderUrl('draft', $node, [], 'fr'), ['discard' => TRUE]);
    $this->assertSame(200, $status);

    // The node form edits the translation.
    $this->drupalGet($this->builderUrl('content_form', $node, ['part' => 'page'], 'fr'));
    $this->assertSession()->fieldValueEquals('title[0][value]', 'Une page');
    $this->submitForm(['title[0][value]' => 'Une belle page'], 'edit-submit');
    $this->assertStringContainsString('saved=1', $this->getUrl());
    $this->assertStringContainsString('/fr/', $this->getUrl());
    $latest = $this->latestRevision($nid, 'fr');
    $this->assertSame('Une belle page', $latest->label());
    $this->assertSame('A page', $latest->getTranslation('en')->label());

    // A Field block on the canvas shows the field of the language asked for.
    $field = ['type' => 'drupal_field:title', 'node' => $nid, 'props' => ['id' => 'f1', 'formatter' => 'string']];
    [$status, $body] = $this->postJson($this->builderUrl('render'), $field + ['langcode' => 'fr']);
    $this->assertSame(200, $status);
    $this->assertStringContainsString('Une belle page', $body['html']);
    [$status, $body] = $this->postJson($this->builderUrl('render'), $field + ['langcode' => 'en']);
    $this->assertStringContainsString('A page', $body['html']);
    $this->assertStringNotContainsString('Une belle page', $body['html']);

    // Links to content go to the page in the language being edited.
    [$status, $found] = $this->getJson($this->builderUrl('urls', NULL, ['q' => 'page', 'langcode' => 'fr']));
    $this->assertSame(200, $status);
    $this->assertSame([['label' => 'Une belle page', 'value' => "entity:node/$nid", 'url' => $found[0]['url'], 'meta' => 'Landing']], $found);
    $this->assertStringEndsWith("/fr/node/$nid", $found[0]['url']);
  }

}
