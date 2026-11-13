<?php

declare(strict_types=1);

namespace Drupal\Tests\frame_builder\Kernel;

use Drupal\Core\Url;
use Drupal\frame_builder\MediaItems;
use Drupal\media\Entity\Media;
use Drupal\node\Entity\Node;
use Drupal\Tests\frame_builder\Traits\RenderingTestTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Image media for the editor and for templates.
 *
 * The image of the tests is 360 by 240 pixels: the thumbnail image style
 * makes it 100 wide, medium 220, large and wide leave it as it is.
 */
#[Group('frame_builder')]
#[RunTestsInSeparateProcesses]
class MediaItemsTest extends BuilderKernelTestBase {

  use RenderingTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['file', 'image', 'media', 'breakpoint', 'responsive_image'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->setUpImageMedia();
  }

  /**
   * The media service.
   */
  protected function items(): MediaItems {
    return $this->container->get('frame_builder.media_items');
  }

  /**
   * Media types whose source is an image, with the field of the image.
   */
  public function testImageTypes(): void {
    $this->createMediaType('file', ['id' => 'document', 'label' => 'Document']);
    $this->assertSame(['image' => 'field_media_image'], $this->items()->imageTypes());
    $this->createMediaType('image', ['id' => 'photo', 'label' => 'Photo']);
    $types = $this->items()->imageTypes();
    $this->assertSame(['image', 'photo'], array_keys($types));
    $this->assertArrayNotHasKey('document', $types);
  }

  /**
   * What the editor shows of an image: its name, alternative text, URL and
   * a small version.
   */
  public function testDescribe(): void {
    $media = $this->createImageMedia('A red square', 'Square');
    $described = $this->items()->describe($media);
    $this->assertSame(['id', 'label', 'alt', 'url', 'thumb'], array_keys($described));
    $this->assertSame((int) $media->id(), $described['id']);
    $this->assertSame('Square', $described['label']);
    $this->assertSame('A red square', $described['alt']);
    $uri = $media->get('field_media_image')->entity->getFileUri();
    $this->assertSame($this->container->get('file_url_generator')->generateString($uri), $described['url']);
    $this->assertStringContainsString('/styles/medium/public/' . basename($uri), $described['thumb']);
    $this->assertStringStartsWith('/', $described['thumb'], 'A URL of this site, without the host.');
  }

  /**
   * Media without an image file, and media that is no image, has nothing to
   * describe.
   */
  public function testDescribeWithoutImage(): void {
    $empty = Media::create(['bundle' => 'image', 'name' => 'Empty']);
    $empty->save();
    $this->assertNull($this->items()->describe($empty));

    $this->createMediaType('file', ['id' => 'document', 'label' => 'Document']);
    $document = Media::create(['bundle' => 'document', 'name' => 'Document']);
    $this->assertNull($this->items()->describe($document));
  }

  /**
   * An image value that names no media is its URL.
   */
  #[DataProvider('plainValues')]
  public function testResolvePlainValue(mixed $value, array $expected): void {
    $this->assertSame($expected, $this->items()->resolve($value));
  }

  /**
   * Image values without media and what they resolve to.
   */
  public static function plainValues(): array {
    return [
      'a URL' => ['/a.png', ['url' => '/a.png']],
      'a URL in a value' => [['url' => '/a.png'], ['url' => '/a.png']],
      'a URL and a style, no media' => [['url' => '/a.png', 'style' => 'style:thumbnail'], ['url' => '/a.png']],
      'media that is gone' => [['url' => '/a.png', 'media' => 999, 'style' => 'style:thumbnail'], ['url' => '/a.png']],
      'media that is gone, no URL' => [['media' => 999], ['url' => '']],
      'empty value' => [[], ['url' => '']],
      'null' => [NULL, ['url' => '']],
      'a number' => [12, ['url' => '']],
    ];
  }

  /**
   * A picked image resolves to the URL of its image style and the
   * alternative text of the media item.
   */
  public function testResolveImageStyle(): void {
    $media = $this->createImageMedia('A red square');
    $uri = $media->get('field_media_image')->entity->getFileUri();
    $items = $this->items();

    $plain = $items->resolve(['media' => $media->id(), 'url' => '/original.png']);
    $this->assertSame(['url' => '/original.png', 'alt' => 'A red square', 'width' => 360, 'height' => 240], $plain, 'Without a style: the URL the editor stored, and the size of the file.');

    $styled = $items->resolve(['media' => $media->id(), 'url' => '/original.png', 'style' => 'style:thumbnail']);
    $this->assertSame(['url', 'alt', 'width', 'height'], array_keys($styled));
    $this->assertSame(100, $styled['width'], 'The size is that of the image style.');
    $this->assertStringContainsString('/styles/thumbnail/public/' . basename($uri), $styled['url']);
    $this->assertStringContainsString('itok=', $styled['url']);
    $this->assertStringStartsWith('/', $styled['url']);

    foreach (['style:gone', 'gone', 'thumbnail', 'responsive:gone', ''] as $style) {
      $this->assertSame($plain, $items->resolve(['media' => $media->id(), 'url' => '/original.png', 'style' => $style]), "The style '$style' does not exist.");
    }
  }

  /**
   * With a responsive image style: the fallback image, one srcset of every
   * image style it uses by width, and the sizes of the style.
   */
  public function testResolveResponsiveStyle(): void {
    $media = $this->createImageMedia('A red square');
    $this->createSizesStyle('cat_wide', '(min-width: 700px) 700px, 100vw');
    $resolved = $this->items()->resolve(['media' => $media->id(), 'url' => '/original.png', 'style' => 'responsive:cat_wide']);
    $this->assertSame(['url', 'alt', 'width', 'height', 'srcset', 'sizes'], array_keys($resolved));
    $this->assertStringContainsString('/styles/medium/public/', $resolved['url']);
    $this->assertSame('(min-width: 700px) 700px, 100vw', $resolved['sizes']);
    $candidates = explode(', ', $resolved['srcset']);
    $this->assertCount(3, $candidates);
    $this->assertMatchesRegularExpression('#/styles/thumbnail/public/\S+ 100w$#', $candidates[0]);
    $this->assertMatchesRegularExpression('#/styles/medium/public/\S+ 220w$#', $candidates[1]);
    $this->assertMatchesRegularExpression('#/styles/large/public/\S+ 360w$#', $candidates[2]);
  }

  /**
   * A responsive image style that maps breakpoints to image styles has no
   * sizes of its own: the image is as wide as the window.
   */
  public function testResolveResponsiveStyleOfBreakpoints(): void {
    $media = $this->createImageMedia();
    $this->createBreakpointStyle('cat_art');
    $resolved = $this->items()->resolve(['media' => $media->id(), 'style' => 'responsive:cat_art']);
    $this->assertSame('100vw', $resolved['sizes']);
    $this->assertStringContainsString('/styles/thumbnail/public/', $resolved['srcset']);
    $this->assertStringContainsString(' 100w, ', $resolved['srcset']);
    $this->assertStringContainsString(' 220w, ', $resolved['srcset']);
    $this->assertStringEndsWith(' 360w', $resolved['srcset']);
  }

  /**
   * A picked image as Drupal renders it: the image formatter of core with
   * the image style, lazy unless said otherwise.
   */
  public function testBuild(): void {
    $media = $this->createImageMedia('A red square');
    $build = $this->items()->build(['media' => $media->id(), 'url' => '/original.png', 'style' => 'style:thumbnail']);
    $this->assertSame('image_formatter', $build['#theme']);
    $this->assertSame('thumbnail', $build['#image_style']);
    $this->assertSame(['loading' => 'lazy'], $build['#item_attributes']);
    $this->assertNull($build['#url']);
    $this->assertSame($media->get('field_media_image')->first()->target_id, $build['#item']->target_id);
    $this->assertSame(array_merge($media->getCacheTags(), ['config:image.style.thumbnail']), $build['#cache']['tags'], 'The image depends on the media item and on its image style.');

    $html = $this->renderHtml($build);
    $this->assertMatchesRegularExpression('#^\s*<img[^>]*src="[^"]*/styles/thumbnail/public/[^"]*"#', $html);
    $this->assertStringContainsString('alt="A red square"', $html);
    $this->assertStringContainsString('loading="lazy"', $html);
    $this->assertStringContainsString('width="100"', $html);
    $this->assertStringNotContainsString('<a ', $html);

    $eager = $this->items()->build(['media' => $media->id(), 'loading' => 'eager']);
    $this->assertSame(['loading' => 'eager'], $eager['#item_attributes']);
    $this->assertSame('', $eager['#image_style'], 'Without a style: the original image.');
    $this->assertSame(['loading' => 'lazy'], $this->items()->build(['media' => $media->id(), 'loading' => 'auto" onload="x'])['#item_attributes']);
    $this->assertSame('', $this->items()->build(['media' => $media->id(), 'style' => 'style:gone'])['#image_style']);
    $this->assertSame('image_formatter', $this->items()->build(['media' => $media->id(), 'style' => 'responsive:gone'])['#theme']);
  }

  /**
   * With a responsive image style the image is rendered by that style.
   */
  public function testBuildResponsive(): void {
    $media = $this->createImageMedia('A red square');
    $this->createSizesStyle('cat_wide');
    $this->createBreakpointStyle('cat_art');

    $build = $this->items()->build(['media' => $media->id(), 'style' => 'responsive:cat_wide']);
    $this->assertSame('responsive_image_formatter', $build['#theme']);
    $this->assertSame('cat_wide', $build['#responsive_image_style_id']);
    $this->assertArrayNotHasKey('#image_style', $build);
    $html = $this->renderHtml($build);
    $this->assertStringContainsString('<img', $html);
    $this->assertStringNotContainsString('<picture', $html, 'A style that uses sizes is one <img srcset>.');
    $this->assertMatchesRegularExpression('#srcset="[^"]*/styles/thumbnail/public/[^"]* 100w, [^"]*/styles/medium/public/[^"]* 220w, [^"]*/styles/large/public/[^"]* 360w"#', $html);
    $this->assertStringContainsString('alt="A red square"', $html);

    $art = $this->renderHtml($this->items()->build(['media' => $media->id(), 'style' => 'responsive:cat_art', 'loading' => 'eager']));
    $this->assertStringContainsString('<picture', $art, 'A style of breakpoints is a <picture>.');
    $this->assertStringContainsString('media="all and (min-width: 75em)"', $art);
    $this->assertStringContainsString('loading="eager"', $art);
  }

  /**
   * There is nothing to render for a plain URL or for media without an
   * image.
   */
  public function testBuildWithoutMedia(): void {
    $items = $this->items();
    $this->assertNull($items->build('/a.png'));
    $this->assertNull($items->build(['url' => '/a.png']));
    $this->assertNull($items->build(['url' => '/a.png', 'media' => 999]));
    $this->assertNull($items->build(NULL));
    $empty = Media::create(['bundle' => 'image', 'name' => 'Empty']);
    $empty->save();
    $this->assertNull($items->build(['media' => $empty->id(), 'style' => 'style:thumbnail']));
    $this->assertSame([], $items->backgroundSources(['media' => $empty->id(), 'style' => 'responsive:cat_wide']));
  }

  /**
   * The rendered image changes with its image style.
   */
  public function testBuildDependsOnTheImageStyle(): void {
    $media = $this->createImageMedia();
    $this->createSizesStyle('cat_wide');
    $build = $this->items()->build(['media' => $media->id(), 'style' => 'style:thumbnail']);
    $this->assertContains('config:image.style.thumbnail', $build['#cache']['tags']);
    $build = $this->items()->build(['media' => $media->id(), 'style' => 'responsive:cat_wide']);
    $this->assertContains('config:responsive_image.styles.cat_wide', $build['#cache']['tags']);
  }

  /**
   * The image can link to the page it is on, to its media item, to its
   * file or to a URL.
   */
  public function testBuildWithLink(): void {
    $media = $this->createImageMedia();
    $node = $this->createBuilderNode();
    $items = $this->items();

    $build = $items->build(['media' => $media->id(), 'style' => 'style:thumbnail', 'link' => 'content'], $node);
    $this->assertInstanceOf(Url::class, $build['#url']);
    $this->assertSame('/node/' . $node->id(), $build['#url']->toString());
    $this->assertMatchesRegularExpression('#<a href="/node/' . $node->id() . '"[^>]*>\s*<img#', $this->renderHtml($build));

    $build = $items->build(['media' => $media->id(), 'link' => 'custom', 'href' => 'https://example.com/more'], $node);
    $this->assertStringContainsString('<a href="https://example.com/more"', $this->renderHtml($build));

    $this->assertNull($items->build(['media' => $media->id(), 'link' => 'content'])['#url'], 'Without a page there is nothing to link to.');
    $this->assertNull($items->build(['media' => $media->id(), 'href' => 'https://example.com/more'], $node)['#url'], 'A URL without the choice to link to it.');
  }

  /**
   * Where an image links to, for each kind of link.
   */
  public function testLinkUrl(): void {
    $media = $this->createImageMedia();
    $uri = $media->get('field_media_image')->entity->getFileUri();
    $node = $this->createBuilderNode();
    $items = $this->items();
    $link = static fn (string $kind, string $href = '', $page = NULL): ?string => $items->linkUrl($kind, $href, $page, $media, $uri)?->toString();

    $this->assertSame('/node/' . $node->id(), $link('content', '', $node));
    $this->assertSame('/node/' . $node->id(), $link('content', 'https://example.com/ignored', $node));
    $this->assertNull($link('content'));
    $this->assertNull($link('content', '', Node::create(['type' => 'landing', 'title' => 'Not saved'])), 'A page that is not saved has no URL.');

    $this->assertSame($media->toUrl()->toString(), $link('media'));
    $this->assertSame($this->container->get('file_url_generator')->generateAbsoluteString($uri), $link('file'));

    $this->assertNull($link(''));
    $this->assertNull($link('none', '/about'));
    $this->assertNull($link('somewhere', '/about'));
  }

  /**
   * A custom link is an address, a path or picked content; nothing else.
   */
  #[DataProvider('customLinks')]
  public function testCustomLinkUrl(string $href, ?string $expected): void {
    $media = $this->createImageMedia();
    $node = $this->createBuilderNode();
    $url = $this->items()->linkUrl('custom', $href, $node, $media, 'public://picture.png');
    $this->assertSame($expected, $url?->toString());
  }

  /**
   * Custom links and the URL they give.
   */
  public static function customLinks(): array {
    return [
      'an address' => ['https://example.com/a?b=c#d', 'https://example.com/a?b=c#d'],
      'an address, trimmed' => ['  https://example.com  ', 'https://example.com'],
      'a path' => ['/about', '/about'],
      'a path with a query' => ['/about?a=1', '/about?a=1'],
      'a fragment' => ['#top', '#top'],
      'a query' => ['?page=2', '?page=2'],
      'picked content' => ['entity:node/1', '/node/1'],
      'empty' => ['', NULL],
      'spaces' => ['   ', NULL],
      'a script' => ['javascript:alert(1)', NULL],
      'data' => ['data:text/html,x', NULL],
      'words' => ['not a url', NULL],
      'a path without a slash' => ['about', NULL],
    ];
  }

  /**
   * A custom link to content of a type that does not exist is no link.
   */
  public function testCustomLinkUrlThatCannotBeBuilt(): void {
    $media = $this->createImageMedia();
    $node = $this->createBuilderNode();
    $this->assertNull($this->items()->linkUrl('custom', 'entity:gone_type/1', $node, $media, 'public://picture.png'));
    $build = $this->items()->build(['media' => $media->id(), 'link' => 'custom', 'href' => 'entity:gone_type/1'], $node);
    $this->assertStringContainsString('<img', $this->renderHtml($build));
  }

  /**
   * The images of a responsive image style that uses sizes, for a
   * background: stepped by their width, the smallest first.
   */
  public function testBackgroundSourcesOfSizes(): void {
    $media = $this->createImageMedia();
    $this->createSizesStyle('cat_wide');
    $sources = $this->items()->backgroundSources(['media' => $media->id(), 'style' => 'responsive:cat_wide']);
    $this->assertSame(['', '', '(min-width: 101px)', '(min-width: 221px)'], array_column($sources, 0), 'Each image is used up to the width of the window it can fill.');
    $this->assertStringContainsString('/styles/medium/public/', $sources[0][1], 'The fallback image comes first.');
    $this->assertStringContainsString('/styles/thumbnail/public/', $sources[1][1]);
    $this->assertStringContainsString('/styles/medium/public/', $sources[2][1]);
    $this->assertStringContainsString('/styles/large/public/', $sources[3][1]);
  }

  /**
   * The images of a responsive image style of breakpoints, for a
   * background: the media queries of the breakpoints, the smallest first,
   * with the density of the screen.
   */
  public function testBackgroundSourcesOfBreakpoints(): void {
    $media = $this->createImageMedia();
    $this->createBreakpointStyle('cat_art');
    $sources = $this->items()->backgroundSources(['media' => $media->id(), 'style' => 'responsive:cat_art']);
    $this->assertSame([
      '',
      '',
      'all and (min-width: 48em)',
      'all and (min-width: 75em)',
      'all and (min-width: 75em) and (min-resolution: 2dppx)',
    ], array_column($sources, 0));
    foreach (['medium', 'thumbnail', 'medium', 'large', 'wide'] as $index => $style) {
      $this->assertStringContainsString("/styles/$style/public/", $sources[$index][1]);
      $this->assertStringStartsWith('/', $sources[$index][1]);
    }
  }

  /**
   * Only a picked image with a responsive image style has background
   * sources.
   */
  public function testBackgroundSourcesOnlyWhenResponsive(): void {
    $media = $this->createImageMedia();
    $this->createSizesStyle('cat_wide');
    $items = $this->items();
    $this->assertSame([], $items->backgroundSources('/a.png'));
    $this->assertSame([], $items->backgroundSources(['url' => '/a.png', 'style' => 'responsive:cat_wide']));
    $this->assertSame([], $items->backgroundSources(['media' => $media->id()]));
    $this->assertSame([], $items->backgroundSources(['media' => $media->id(), 'style' => 'style:thumbnail']));
    $this->assertSame([], $items->backgroundSources(['media' => $media->id(), 'style' => 'responsive:gone']));
    $this->assertSame([], $items->backgroundSources(['media' => 999, 'style' => 'responsive:cat_wide']));
    $this->assertNotSame([], $items->backgroundSources(['media' => $media->id(), 'style' => 'responsive:cat_wide']));
  }

}
