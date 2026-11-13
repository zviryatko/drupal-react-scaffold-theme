<?php

declare(strict_types=1);

namespace Drupal\Tests\frame_builder_ai\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\frame_builder_ai\MetaTags;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The meta tags of a page (Metatag module) for the assistant: which it may
 * fill, what they are now, and its proposals as unsaved values.
 */
#[Group('frame_builder')]
#[RunTestsInSeparateProcesses]
class MetaTagsTest extends AssistantKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['token', 'metatag'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $this->skipWithoutModules('metatag', 'token');
    parent::setUp();
    $this->installConfig(['metatag']);
    FieldStorageConfig::create(['field_name' => 'field_meta', 'entity_type' => 'node', 'type' => 'metatag'])->save();
    FieldConfig::create(['field_name' => 'field_meta', 'entity_type' => 'node', 'bundle' => 'landing', 'label' => 'Meta tags'])->save();
  }

  /**
   * The service under test.
   */
  protected function metaTags(): MetaTags {
    return $this->container->get('frame_builder_ai.meta_tags');
  }

  /**
   * The unsaved meta tags of a page, decoded; NULL when there are none.
   */
  protected function draft(NodeInterface $node): ?array {
    $values = $this->container->get('frame_builder.field_drafts')->get($node);
    return isset($values['field_meta']) ? metatag_data_decode($values['field_meta'][0]['value']) : NULL;
  }

  /**
   * A page has meta tags when its content type has a Metatag field.
   */
  public function testField(): void {
    $this->assertSame('field_meta', $this->metaTags()->field($this->createBuilderNode()));
    $this->createBuilderType('plain');
    $plain = $this->createBuilderNode(['type' => 'plain']);
    $this->assertNull($this->metaTags()->field($plain));
    $this->assertSame([], $this->metaTags()->current($plain));
    $this->assertSame([], $this->metaTags()->propose($plain, ['title' => 'A title']));
    $this->assertSame([], $this->container->get('frame_builder.field_drafts')->get($plain), 'Nothing is kept for a page without meta tags.');
  }

  /**
   * The assistant is offered the tags of its list that the site has.
   */
  public function testOffered(): void {
    $offered = $this->metaTags()->offered();
    $this->assertSame(['title', 'description', 'abstract', 'keywords'], array_keys($offered));
    $this->assertSame(MetaTags::TAGS['title'][0], $offered['title']);
    $this->assertStringContainsString('about 150 characters', $offered['description']);

    $this->skipWithoutModules('metatag_open_graph', 'metatag_twitter_cards');
    $this->enableModules(['metatag_open_graph', 'metatag_twitter_cards']);
    $this->assertSame(array_keys(MetaTags::TAGS), array_keys($this->metaTags()->offered()), 'With Open Graph and Twitter Cards: their titles and descriptions too.');
    $this->assertArrayNotHasKey('robots', $this->metaTags()->offered());
    $this->assertArrayNotHasKey('og_image', $this->metaTags()->offered());
  }

  /**
   * The values set on the page itself, with what the user has not saved.
   */
  public function testCurrent(): void {
    $node = $this->createBuilderNode();
    $this->assertSame([], $this->metaTags()->current($node), 'Nothing set: the defaults of the site apply.');

    $node->set('field_meta', metatag_data_encode(['title' => 'Saved title', 'description' => 'Saved description', 'robots' => ['noindex', 'nofollow']]))->save();
    $this->assertEquals(['title' => 'Saved title', 'description' => 'Saved description'], $this->metaTags()->current($node), 'Values that are not text are left out.');

    $this->container->get('frame_builder.field_drafts')->merge($node, ['field_meta' => [['value' => metatag_data_encode(['title' => 'Unsaved title'])]]]);
    $this->assertSame(['title' => 'Unsaved title'], $this->metaTags()->current($node));
    $this->assertSame('Saved title', metatag_data_decode($node->get('field_meta')->value)['title'], 'The page itself is not changed.');
  }

  /**
   * Proposed values: only offered tags, as plain text of a limited length,
   * kept as unsaved values of the node form.
   */
  public function testPropose(): void {
    $node = $this->createBuilderNode();
    $saved = ['title' => 'Saved title', 'keywords' => 'old, words', 'robots' => 'noindex'];
    $node->set('field_meta', metatag_data_encode($saved))->save();

    $kept = $this->metaTags()->propose($node, [
      'title' => " A <b>cooking</b>\n class   in [site:name] ",
      'description' => str_repeat('word ', 100),
      'abstract' => ['not', 'text'],
      'robots' => 'index, follow',
      'canonical_url' => 'https://evil.example',
      'refresh' => '0; url=https://evil.example',
      'og_title' => 'The module is not installed',
      'made_up' => 'x',
    ]);
    $this->assertSame(['title', 'description'], array_keys($kept));
    $this->assertSame('A cooking class in [site:name]', $kept['title'], 'Plain text on one line; tokens stay.');
    $this->assertSame(320, mb_strlen($kept['description']));
    $this->assertEquals(
      ['title' => 'A cooking class in [site:name]', 'description' => $kept['description'], 'keywords' => 'old, words', 'robots' => 'noindex'],
      $this->draft($node),
      'The proposal over what the page has; tags the assistant may not set stay as they were.',
    );
    $this->container->get('entity_type.manager')->getStorage('node')->resetCache();
    $this->assertEquals($saved, metatag_data_decode(Node::load($node->id())->get('field_meta')->value), 'Nothing is saved.');

    // The next proposal builds on the unsaved values; an empty one clears a tag.
    $this->assertSame(['keywords' => '', 'abstract' => '12'], $this->metaTags()->propose($node, ['keywords' => '  ', 'abstract' => 12]));
    $draft = $this->draft($node);
    $this->assertArrayNotHasKey('keywords', $draft);
    $this->assertSame('12', $draft['abstract']);
    $this->assertSame('A cooking class in [site:name]', $draft['title']);

    $this->assertSame(121, mb_strlen(str_repeat('é', 121)));
    $this->assertSame(str_repeat('é', 120), $this->metaTags()->propose($node, ['title' => str_repeat('é', 121)])['title']);
    $this->assertSame(str_repeat('k', 255), $this->metaTags()->propose($node, ['keywords' => str_repeat('k', 300)])['keywords']);

    // Nothing usable: nothing is kept.
    $other = $this->createBuilderNode();
    $this->assertSame([], $this->metaTags()->propose($other, ['robots' => 'noindex', 'title' => ['a']]));
    $this->assertSame([], $this->metaTags()->propose($other, []));
    $this->assertNull($this->draft($other));
  }

  /**
   * The model is told the tags it may set and their values now, only for a
   * page that has meta tags.
   */
  public function testInstructions(): void {
    $assistant = $this->container->get('frame_builder_ai.assistant');
    $node = $this->createBuilderNode();
    $instructions = $assistant->instructions($node, [], []);
    $this->assertStringContainsString("# Meta tags of the page\n", $instructions);
    $this->assertStringContainsString("tokens\nwork in them:\n" . json_encode($this->metaTags()->offered(), JSON_UNESCAPED_SLASHES) . "\n", $instructions);
    $this->assertStringContainsString("Set on this page now (empty: the defaults of the site apply):\n{}\n", $instructions);
    $this->assertStringContainsString('add "metatags": {"<tag>": "<text>"} to your final answer', $instructions);

    $node->set('field_meta', metatag_data_encode(['title' => 'Saved title', 'robots' => 'noindex']))->save();
    $this->container->get('frame_builder.field_drafts')->merge($node, ['field_meta' => [['value' => metatag_data_encode(['title' => 'Saved title', 'description' => 'Unsaved "description"', 'robots' => 'noindex'])]]]);
    $instructions = $assistant->instructions($node, [], []);
    $this->assertStringContainsString("Set on this page now (empty: the defaults of the site apply):\n{\"title\":\"Saved title\",\"description\":\"Unsaved \\\"description\\\"\"}\n", $instructions, 'With unsaved values, and only the tags the model may set.');

    $this->createBuilderType('plain');
    $this->assertStringNotContainsString('# Meta tags of the page', $assistant->instructions($this->createBuilderNode(['type' => 'plain']), [], []));
  }

  /**
   * Meta tags in the answer of the model become unsaved values of the page
   * and are named in the result of the turn.
   */
  public function testRespond(): void {
    $this->useScriptedProvider();
    $assistant = $this->container->get('frame_builder_ai.assistant');
    $node = $this->createBuilderNode();
    $ask = [['role' => 'user', 'content' => 'Write meta tags']];

    $this->script(['message' => 'Meta tags written.', 'layout' => NULL, 'metatags' => ['title' => 'Cooking <i>class</i>', 'robots' => 'noindex']]);
    $result = $assistant->respond($node, $ask, []);
    $this->assertSame(['title' => 'Cooking class'], $result['metatags']);
    $this->assertNull($result['data'], 'Meta tags alone do not change the layout.');
    $this->assertSame(['title' => 'Cooking class'], $this->draft($node));

    $this->script(['message' => 'I tried.', 'layout' => NULL, 'metatags' => ['robots' => 'noindex']]);
    $this->assertNull($assistant->respond($node, $ask, [])['metatags'], 'Tags it may not set: nothing proposed.');
    $this->script(['message' => 'I tried.', 'layout' => NULL, 'metatags' => 'title: x']);
    $this->assertArrayNotHasKey('metatags', $assistant->respond($node, $ask, []));
    $this->script(['message' => 'No tags.', 'layout' => NULL]);
    $this->assertArrayNotHasKey('metatags', $assistant->respond($node, $ask, []));
    $this->assertSame(['title' => 'Cooking class'], $this->draft($node));

    $this->createBuilderType('plain');
    $plain = $this->createBuilderNode(['type' => 'plain']);
    $this->script(['message' => 'Done.', 'layout' => NULL, 'metatags' => ['title' => 'A title']]);
    $this->assertNull($assistant->respond($plain, $ask, [])['metatags'], 'A page without meta tags gets none.');
  }

}
