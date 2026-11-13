<?php

declare(strict_types=1);

namespace Drupal\Tests\frame_builder\Kernel;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\Render\Markup;
use Drupal\filter\Entity\FilterFormat;
use Drupal\frame_builder\LayoutRenderer;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\NodeInterface;
use Drupal\Tests\frame_builder\Traits\RenderingTestTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The values of props on their way to a template: tokens, links and
 * formatted text.
 */
#[Group('frame_builder')]
#[RunTestsInSeparateProcesses]
class LayoutRendererValuesTest extends BuilderKernelTestBase {

  use RenderingTestTrait;

  protected const KINDS = 'frame_builder_test_theme:cat-kinds';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['language'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['language']);
    $this->config('system.site')->set('name', 'Test site')->save();
    FilterFormat::create([
      'format' => 'cat_basic',
      'name' => 'Basic',
      'filters' => [
        'filter_html' => ['status' => TRUE, 'settings' => ['allowed_html' => '<p> <strong> <a href>']],
      ],
    ])->save();
  }

  /**
   * The renderer of layouts.
   */
  protected function layouts(): LayoutRenderer {
    return $this->container->get('frame_builder.renderer');
  }

  /**
   * The props a component is rendered with for the given stored values.
   */
  protected function props(string $type, array $values, ?NodeInterface $node = NULL): array {
    return $this->layouts()->buildItem(self::item($type, 'item', $values), $node)['#props'];
  }

  /**
   * Tokens in a text prop are replaced with values of the page and of the
   * site; a token of a kind Drupal knows that has no value is removed.
   */
  public function testTokensInText(): void {
    $node = $this->createBuilderNode(['title' => 'Fish & chips']);
    $props = $this->props('frame:heading', ['text' => 'About [node:title] on [site:name][node:gone]', 'eyebrow' => 'No. [node:nid]'], $node);
    $this->assertSame('About Fish & chips on Test site', $props['text'], 'A plain string: the template escapes it.');
    $this->assertSame('No. ' . $node->id(), $props['eyebrow']);

    $html = $this->renderHtml($this->layouts()->buildItem(self::item('frame:heading', 'h1', ['text' => 'About [node:title]']), $node));
    $this->assertStringContainsString('<h2 class="heading-block__text">About Fish &amp; chips</h2>', $html);
  }

  /**
   * Without a page only the tokens of the site have a value.
   */
  public function testTokensWithoutAPage(): void {
    $this->assertSame('On Test site: ', $this->props('frame:heading', ['text' => 'On [site:name]: [node:title]'])['text']);
  }

  /**
   * Text in brackets that is no token is text, also when it has the form of
   * one: only tokens of a kind Drupal knows are taken out.
   */
  public function testBracketsThatAreNoTokens(): void {
    $node = $this->createBuilderNode();
    $this->assertSame('Price [incl. VAT] and a[0]', $this->props('frame:heading', ['text' => 'Price [incl. VAT] and a[0]'], $node)['text']);
    $this->assertSame('See [note: below] and [gone:token]', $this->props('frame:heading', ['text' => 'See [note: below] and [gone:token]'], $node)['text']);
  }

  /**
   * A text that is only a token without a value is an empty prop: it is
   * not passed.
   */
  public function testTokenWithoutValueLeavesThePropOut(): void {
    $node = $this->createBuilderNode();
    $props = $this->props('frame:heading', ['text' => 'Kept', 'eyebrow' => '[node:gone]'], $node);
    $this->assertArrayNotHasKey('eyebrow', $props);
  }

  /**
   * What a token depends on reaches the page with the component.
   */
  public function testTokensBubbleCacheMetadata(): void {
    $node = $this->createBuilderNode();
    $build = $this->layouts()->buildItem(self::item('frame:heading', 'h1', ['text' => '[node:title] on [site:name]']), $node);
    $this->assertContains('node:' . $node->id(), $build['#cache']['tags']);
    $this->assertContains('config:system.site', $build['#cache']['tags']);
    $plain = $this->layouts()->buildItem(self::item('frame:heading', 'h2', ['text' => 'No tokens']), $node);
    $this->assertNotContains('config:system.site', $plain['#cache']['tags'] ?? []);
  }

  /**
   * Tokens have the values of the translation the layout belongs to.
   */
  public function testTokensInTheLanguageOfThePage(): void {
    ConfigurableLanguage::createFromLangcode('uk')->save();
    $node = $this->createBuilderNode(['title' => 'Fish']);
    $translation = $node->addTranslation('uk', ['title' => 'Риба']);
    $node->save();
    $item = ['text' => 'About [node:title]'];
    $this->assertSame('About Fish', $this->props('frame:heading', $item, $node)['text']);
    $this->assertSame('About Риба', $this->props('frame:heading', $item, $translation)['text']);
  }

  /**
   * The value of a token cannot bring markup into a text prop.
   */
  public function testTextIsEscaped(): void {
    $node = $this->createBuilderNode(['title' => '<script>alert(1)</script>']);
    $html = $this->renderHtml($this->layouts()->buildItem(self::item('frame:heading', 'h1', [
      'text' => '[node:title] <img src=x onerror=alert(2)>',
      'eyebrow' => '"><b>bold</b>',
    ]), $node));
    $this->assertStringNotContainsString('<script>', $html);
    $this->assertStringNotContainsString('<img', $html);
    $this->assertStringNotContainsString('<b>', $html);
    $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; &lt;img src=x onerror=alert(2)&gt;', $html);
  }

  /**
   * A link is used as typed, without a dangerous protocol.
   */
  #[DataProvider('urls')]
  public function testUrl(string $stored, ?string $expected): void {
    $props = $this->props('frame:button', ['label' => 'Go', 'url' => $stored], $this->createBuilderNode());
    $this->assertSame($expected, $props['url'] ?? NULL);
  }

  /**
   * Stored links and the URL the component receives.
   */
  public static function urls(): array {
    return [
      'a path' => ['/about', '/about'],
      'a path with a query and a fragment' => ['/about?a=1&b=2#team', '/about?a=1&b=2#team'],
      'an address' => ['https://example.com/a?b=c', 'https://example.com/a?b=c'],
      'mail' => ['mailto:someone@example.com', 'mailto:someone@example.com'],
      'a fragment' => ['#top', '#top'],
      'a script' => ['javascript:alert(1)', 'alert(1)'],
      'a script in capitals' => ['JaVaScRiPt:alert(1)', 'alert(1)'],
      'data' => ['data:text/html,<script>alert(1)</script>', 'text/html,<script>alert(1)</script>'],
      'content that cannot be linked' => ['entity:gone_type/1', NULL],
      'empty' => ['', NULL],
    ];
  }

  /**
   * Content picked in the editor is stored as "entity:node/N" and printed
   * as the URL of that content.
   */
  public function testEntityUrl(): void {
    $node = $this->createBuilderNode();
    $target = $this->createBuilderNode(['title' => 'Target']);
    $build = $this->layouts()->buildItem(self::item('frame:button', 'b1', ['label' => 'Go', 'url' => 'entity:node/' . $target->id()]), $node);
    $this->assertSame($target->toUrl()->toString(), $build['#props']['url']);
    $this->assertSame('/node/' . $target->id(), $build['#props']['url']);
    $this->assertSame('/node/' . $target->id(), $this->props('frame:button', ['label' => 'Go', 'url' => 'entity:node/' . $target->id()])['url'], 'Also without a page.');
    $this->assertStringContainsString('href="/node/' . $target->id() . '"', $this->renderHtml($build));
  }

  /**
   * A link to content is in the language of the page it is on, so one link
   * serves every translation.
   */
  public function testEntityUrlInTheLanguageOfThePage(): void {
    ConfigurableLanguage::createFromLangcode('uk')->save();
    $this->config('language.negotiation')->set('url.prefixes', ['en' => '', 'uk' => 'uk'])->save();
    $this->config('language.types')->set('negotiation.language_interface.enabled', ['language-url' => 0])->save();
    $this->container->get('kernel')->rebuildContainer();
    $target = $this->createBuilderNode(['title' => 'Target']);
    $node = $this->createBuilderNode(['title' => 'Fish']);
    $translation = $node->addTranslation('uk', ['title' => 'Риба']);
    $node->save();
    $values = ['label' => 'Go', 'url' => 'entity:node/' . $target->id()];
    $this->assertSame('/node/' . $target->id(), $this->props('frame:button', $values, $node)['url']);
    $this->assertSame('/uk/node/' . $target->id(), $this->props('frame:button', $values, $translation)['url']);
  }

  /**
   * Tokens work in links too.
   */
  public function testTokensInUrl(): void {
    $node = $this->createBuilderNode();
    $this->assertSame('/search?about=' . $node->id(), $this->props('frame:button', ['label' => 'Go', 'url' => '/search?about=[node:nid]'], $node)['url']);
    $this->assertSame($node->toUrl('canonical', ['absolute' => TRUE])->toString(), $this->props('frame:button', ['label' => 'Go', 'url' => '[node:url]'], $node)['url']);
  }

  /**
   * A link cannot leave its attribute.
   */
  public function testUrlIsEscapedInHtml(): void {
    $html = $this->renderHtml($this->layouts()->buildItem(self::item('frame:button', 'b1', ['label' => 'Go', 'url' => '/a" onclick="alert(1)'])));
    $this->assertStringContainsString('href="/a&quot; onclick=&quot;alert(1)"', $html);
    $this->assertStringNotContainsString('onclick="alert', $html);
  }

  /**
   * Formatted text is filtered by its text format and passed as markup.
   */
  public function testFormattedText(): void {
    $build = $this->layouts()->buildItem(self::item('frame:text', 't1', ['text' => [
      'value' => '<p>Hello <strong>there</strong><script>alert(1)</script> <em>you</em> <a href="/x" onclick="y()">link</a></p>',
      'format' => 'cat_basic',
    ]]));
    $text = $build['#props']['text'];
    $this->assertInstanceOf(MarkupInterface::class, $text, 'Safe markup: the template prints it as it is.');
    $this->assertSame('<p>Hello <strong>there</strong>alert(1) you <a href="/x">link</a></p>', (string) $text);
    $this->assertContains('config:filter.format.cat_basic', $build['#cache']['tags'], 'The page changes with the text format.');
    $this->assertStringContainsString('class="rich-text"><p>Hello <strong>there</strong>alert(1) you <a href="/x">link</a></p></div>', $this->renderHtml($build));
  }

  /**
   * Text without a format, or with one that does not exist, goes through
   * the fallback format of the site.
   */
  public function testFormattedTextFallbackFormat(): void {
    $this->assertSame('plain_text', filter_fallback_format());
    foreach ([
      'a format that is gone' => ['value' => '<p>Hello <b>there</b></p>', 'format' => 'gone_format'],
      'no format' => ['value' => '<p>Hello <b>there</b></p>'],
      'a plain string' => '<p>Hello <b>there</b></p>',
    ] as $case => $value) {
      $build = $this->layouts()->buildItem(self::item('frame:text', 't1', ['text' => $value]));
      $this->assertStringContainsString('&lt;p&gt;Hello &lt;b&gt;there&lt;/b&gt;&lt;/p&gt;', (string) $build['#props']['text'], $case);
      $this->assertStringNotContainsString('<b>', (string) $build['#props']['text'], $case);
      $this->assertContains('config:filter.format.plain_text', $build['#cache']['tags'], $case);
    }
  }

  /**
   * A prop that pins its format ignores the format a layout names.
   */
  public function testPinnedFormat(): void {
    $props = $this->props(self::KINDS, [
      'plain' => 'Plain',
      'rich' => ['value' => '<p>Free <strong>format</strong></p>', 'format' => 'cat_basic'],
      'pinned' => ['value' => '<p>Pinned <strong>format</strong></p>', 'format' => 'cat_basic'],
    ]);
    $this->assertSame('<p>Free <strong>format</strong></p>', (string) $props['rich']);
    $this->assertStringContainsString('&lt;p&gt;Pinned &lt;strong&gt;format&lt;/strong&gt;&lt;/p&gt;', (string) $props['pinned']);
  }

  /**
   * Empty formatted text is an empty prop.
   */
  public function testEmptyFormattedText(): void {
    foreach ([['value' => '', 'format' => 'cat_basic'], ['format' => 'cat_basic'], [], ''] as $value) {
      $this->assertArrayNotHasKey('rich', $this->props(self::KINDS, ['plain' => 'Plain', 'rich' => $value]));
    }
  }

  /**
   * Tokens in formatted text are replaced before the text is filtered, and
   * their values are escaped.
   */
  public function testTokensInFormattedText(): void {
    $node = $this->createBuilderNode(['title' => 'Fish & <em>chips</em>']);
    $build = $this->layouts()->buildItem(self::item('frame:text', 't1', ['text' => [
      'value' => '<p>About <strong>[node:title]</strong> on [site:name][node:gone]</p>',
      'format' => 'cat_basic',
    ]]), $node);
    $this->assertSame('<p>About <strong>Fish &amp; &lt;em&gt;chips&lt;/em&gt;</strong> on Test site</p>', (string) $build['#props']['text']);
    $this->assertContains('node:' . $node->id(), $build['#cache']['tags']);
    $this->assertContains('config:system.site', $build['#cache']['tags']);
    $this->assertContains('config:filter.format.cat_basic', $build['#cache']['tags']);
  }

  /**
   * Without the Media module an image is a plain URL.
   */
  public function testImagesWithoutMediaModule(): void {
    $media = $this->container->get('frame_builder.media_items');
    $this->assertSame([], $media->imageTypes());
    $picked = ['url' => '/a.png', 'media' => 5, 'style' => 'style:thumbnail'];
    $this->assertSame(['url' => '/a.png'], $media->resolve($picked));
    $this->assertNull($media->build($picked));
    $this->assertSame([], $media->backgroundSources($picked));
    $this->assertSame('/a.png', $this->props('frame:card', ['heading' => 'Card', 'image' => $picked])['image']);
  }

  /**
   * A text prop is never markup, whatever is stored for it.
   */
  public function testTextPropIsNotMarkup(): void {
    $props = $this->props('frame:heading', ['text' => Markup::create('<b>Bold</b>'), 'eyebrow' => ['value' => '<b>Bold</b>', 'format' => 'cat_basic']]);
    $this->assertArrayNotHasKey('text', $props);
    $this->assertArrayNotHasKey('eyebrow', $props);
  }

}
