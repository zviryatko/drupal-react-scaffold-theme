<?php

declare(strict_types=1);

namespace Drupal\Tests\frame_builder\Kernel;

use Drupal\Core\Render\Markup;
use Drupal\Core\Template\Attribute;
use Drupal\frame_builder\LayoutRenderer;
use Drupal\Tests\frame_builder\Traits\RenderingTestTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * A layout becomes render arrays of components, and those become HTML.
 */
#[Group('frame_builder')]
#[RunTestsInSeparateProcesses]
class LayoutRendererTest extends BuilderKernelTestBase {

  use RenderingTestTrait;

  protected const KINDS = 'frame_builder_test_theme:cat-kinds';
  protected const PROBE = 'frame_builder_test_theme:probe';
  protected const CAT_THEME = 'frame_builder_test_cat_theme';

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
   * The renderer of layouts.
   */
  protected function layouts(): LayoutRenderer {
    return $this->container->get('frame_builder.renderer');
  }

  /**
   * The props a component is rendered with for the given stored values.
   */
  protected function props(string $type, array $values): array {
    return $this->layouts()->buildItem(self::item($type, 'item', $values))['#props'];
  }

  /**
   * Every item of a layout is a component element with its props.
   */
  public function testBuild(): void {
    $build = $this->layouts()->build(self::layout(
      self::item('frame:heading', 'h1', ['text' => 'Hello', 'level' => 'h3']),
      self::item(self::PROBE, 'p1', ['title' => 'A probe']),
    ));
    $this->assertSame([0, 1], array_keys($build));
    $this->assertSame('component', $build[0]['#type']);
    $this->assertSame('frame:heading', $build[0]['#component']);
    $this->assertSame(['text' => 'Hello', 'level' => 'h3', 'size' => 'l'], $build[0]['#props'], 'The id of the item is no prop; a choice left empty is its declared start value.');
    $this->assertSame([], $build[0]['#slots']);
    $this->assertSame(self::PROBE, $build[1]['#component']);
    $this->assertSame(['title' => 'A probe', 'tone' => 'calm'], $build[1]['#props'], 'Empty optional props are not passed, so the defaults of the template apply.');

    $this->assertSame([], $this->layouts()->build([]));
    $this->assertSame([], $this->layouts()->build(['root' => ['props' => []], 'content' => []]));
  }

  /**
   * Components that are not offered print nothing, and do not stop the rest.
   */
  public function testMissingComponentsPrintNothing(): void {
    $this->enableModules(['sdc_test']);
    $layouts = $this->layouts();
    $this->assertSame([], $layouts->buildItem(self::item('frame:gone', 'g1', ['text' => 'Gone'])));
    $this->assertSame([], $layouts->buildItem(self::item('gone', 'g2')));
    $this->assertSame([], $layouts->buildItem(self::item('sdc_test:my-button', 'g3', ['text' => 'A component of a module'])));
    $this->assertSame([], $layouts->buildItem(['props' => ['text' => 'No type']]));
    $this->assertSame([], $layouts->buildItem([]));
    $this->assertSame([], $layouts->buildItem(self::item('frame:gone', 'g4', ['_spacing' => ['margin' => ['size' => 'm']]])), 'Not even a styled wrapper.');

    $build = $layouts->build(self::layout(
      self::item('frame:gone', 'g1'),
      self::item('frame:heading', 'h1', ['text' => 'Kept']),
      self::item('frame:columns', 'c1', ['content' => [self::item('frame:gone', 'g2'), self::item('frame:heading', 'h2', ['text' => 'Nested'])]]),
    ));
    $this->assertCount(2, $build);
    $this->assertSame('Kept', $build[0]['#props']['text']);
    $this->assertCount(1, $build[1]['#slots']['content']);
    $html = $this->renderHtml($build);
    $this->assertStringContainsString('Kept', $html);
    $this->assertStringContainsString('Nested', $html);
  }

  /**
   * Components hidden from user interfaces and obsolete ones are not
   * rendered from a layout either.
   */
  public function testHiddenComponentsPrintNothing(): void {
    $this->useTheme(self::CAT_THEME);
    $this->assertSame([], $this->layouts()->buildItem(self::item(self::CAT_THEME . ':cat-hidden', 'x1', ['text' => 'Hidden'])));
    $this->assertSame([], $this->layouts()->buildItem(self::item(self::CAT_THEME . ':cat-old', 'x2', ['text' => 'Old'])));
    $this->assertSame(self::CAT_THEME . ':cat-divider', $this->layouts()->buildItem(self::item(self::CAT_THEME . ':cat-divider', 'x3'))['#component']);
  }

  /**
   * A layout that names a component the theme has since replaced still
   * shows it, as the replacement.
   */
  public function testReplacedComponentStillRenders(): void {
    $this->useTheme(self::CAT_THEME);
    $build = $this->layouts()->buildItem(self::item('frame:divider', 'd1', ['style' => 'dashed']));
    $this->assertNotSame([], $build);
    $this->assertStringContainsString('cat-divider--dashed', $this->renderHtml($build));
  }

  /**
   * Stored values are cast to the type of the prop; what cannot be is left
   * out.
   */
  public function testPropsAreCast(): void {
    $this->assertSame(
      ['title' => '12', 'count' => 7, 'shown' => FALSE, 'tone' => 'loud'],
      $this->props(self::PROBE, ['title' => 12, 'count' => '7', 'shown' => 'false', 'tone' => 'loud']),
    );
    $this->assertSame(['count' => 0, 'shown' => TRUE, 'tone' => 'calm'], $this->props(self::PROBE, ['title' => '', 'count' => 0, 'shown' => 'yes', 'tone' => '']));
    $this->assertSame(['shown' => FALSE, 'tone' => 'calm'], $this->props(self::PROBE, ['title' => ['a', 'list'], 'count' => 'seven', 'shown' => 0, 'tone' => NULL]));

    $props = $this->props(self::KINDS, [
      'plain' => 'Plain',
      'amount' => '2.5',
      'whole' => '4.9',
      'flag' => 'on',
      'maybe' => 'Maybe',
      'maybe_count' => '5',
      'options' => ['a' => 'b'],
      'entries' => ['a', 'b'],
      'bogus' => 'Not a prop of the component',
    ]);
    $this->assertSame(2.5, $props['amount']);
    $this->assertSame(4, $props['whole']);
    $this->assertTrue($props['flag']);
    $this->assertSame('Maybe', $props['maybe']);
    $this->assertSame(5, $props['maybe_count'], 'Null or an integer: an integer.');
    $this->assertSame(['a', 'b'], $props['entries'], 'A list of texts is passed as a list.');
    foreach (['options', 'bogus', 'id', 'untitled', 'link', 'picture'] as $name) {
      $this->assertArrayNotHasKey($name, $props);
    }
    $this->assertArrayNotHasKey('amount', $this->props(self::KINDS, ['amount' => 'much']));
  }

  /**
   * A choice left empty gets its default, else its first example; one
   * without either is not passed.
   */
  public function testEmptyChoices(): void {
    $props = $this->props(self::KINDS, ['plain' => 'Plain']);
    $this->assertSame('a', $props['choice']);
    $this->assertSame('loud', $props['mood']);
    $this->assertArrayNotHasKey('open', $props);
    $this->assertArrayNotHasKey('symbol', $props);
    $this->assertSame('star', $this->props(self::KINDS, ['symbol' => 'star'])['symbol']);
    $this->assertSame('b', $this->props(self::KINDS, ['choice' => 'b'])['choice']);
  }

  /**
   * A colour prop receives the CSS colour of a name of the palette, a length
   * prop a CSS length of the spacing scale or a custom one.
   */
  public function testColorAndLengthProps(): void {
    $props = $this->props(self::KINDS, ['tint' => 'brand', 'gap' => ['size' => 'l'], 'inset' => ['size' => 'custom', 'custom' => '3px'], 'loose' => 's']);
    $this->assertSame('#112233', $props['tint']);
    $this->assertSame('2rem', $props['gap']);
    $this->assertSame('3px', $props['inset']);
    $this->assertSame('0.5rem', $props['loose']);

    $props = $this->props(self::KINDS, [
      'tint' => 'red; background: url(x)',
      'gap' => ['size' => 'custom', 'custom' => '1px; position: fixed'],
      'inset' => ['size' => 'huge'],
      'loose' => 'expression(1)',
    ]);
    foreach (['tint', 'gap', 'inset', 'loose'] as $name) {
      $this->assertArrayNotHasKey($name, $props, "A value of '$name' that is no token and no length is not printed.");
    }
    $this->assertSame('1rem', $this->props('frame:columns', ['gap' => ['size' => 'm']])['gap']);
  }

  /**
   * The items of a slot are built into it, at any depth; a slot without
   * anything to print is left out.
   */
  public function testSlots(): void {
    $build = $this->layouts()->buildItem(self::item(self::KINDS, 'k1', [
      'plain' => 'Outer',
      'main' => [
        self::item('frame:heading', 'h1', ['text' => 'First']),
        self::item(self::PROBE, 'p1', ['title' => 'Inner', 'content' => [self::item('frame:heading', 'h2', ['text' => 'Deep'])]]),
      ],
      'aside' => [self::item('frame:gone', 'g1')],
    ]));
    $this->assertSame(['main'], array_keys($build['#slots']));
    $this->assertArrayNotHasKey('main', $build['#props'], 'A slot is not a prop.');
    $main = $build['#slots']['main'];
    $this->assertSame(['frame:heading', self::PROBE], array_column($main, '#component'));
    $this->assertSame('Deep', $main[1]['#slots']['content'][0]['#props']['text']);

    $this->assertSame([], $this->layouts()->buildItem(self::item(self::PROBE, 'p2', ['content' => 'not a list']))['#slots']);
    $this->assertSame([], $this->layouts()->buildItem(self::item(self::PROBE, 'p3', ['content' => []]))['#slots']);
    $this->assertSame([], $this->layouts()->buildItem(self::item('frame:heading', 'h3', ['text' => 'No slots', 'content' => [self::item(self::PROBE, 'p4')]]))['#slots'], 'Items under a name that is no slot of the component are not built.');
  }

  /**
   * The editor passes its own content for the slots: the nested items are
   * then not built.
   */
  public function testSlotsGivenByTheEditor(): void {
    $placeholder = ['content' => ['#markup' => '<div data-frame-builder-slot="content"></div>']];
    $build = $this->layouts()->buildItem(
      self::item(self::PROBE, 'p1', ['title' => 'Probe', 'content' => [self::item('frame:heading', 'h1', ['text' => 'Nested'])]]),
      NULL,
      $placeholder,
    );
    $this->assertSame($placeholder, $build['#slots']);
    $this->assertSame([], $this->layouts()->buildItem(self::item(self::PROBE, 'p1', ['content' => [self::item('frame:heading', 'h1', ['text' => 'Nested'])]]), NULL, [])['#slots']);
  }

  /**
   * The HTML of a nested layout: the templates of the components, with
   * their props, the children inside the slot of their parent.
   */
  public function testRenderedHtml(): void {
    $html = $this->renderHtml($this->layouts()->build(self::layout(
      self::item('frame:columns', 'c1', [
        'layout' => '3',
        'gap' => ['size' => 'm'],
        'content' => [
          self::item('frame:heading', 'h1', ['text' => 'Hello', 'level' => 'h3', 'eyebrow' => 'Above']),
          self::item(self::PROBE, 'p1', [
            'title' => 'A probe',
            'shown' => TRUE,
            'count' => '7',
            'tone' => 'loud',
            'content' => [self::item('frame:heading', 'h2', ['text' => 'Deep & <b>plain</b>'])],
          ]),
        ],
      ]),
      self::item(self::PROBE, 'p2', ['shown' => FALSE, 'title' => 'Not shown']),
    )));

    $this->assertMatchesRegularExpression('/<div[^>]*class="[^"]*layout-columns layout-columns--3 layout-columns--align-stretch layout-columns--stack/', $html);
    $this->assertStringContainsString('style="--layout-gap: 1rem"', $html);
    $this->assertStringContainsString('<p class="heading-block__eyebrow">Above</p>', $html);
    $this->assertStringContainsString('<h3 class="heading-block__text">Hello</h3>', $html);
    $this->assertMatchesRegularExpression('/<div[^>]*class="[^"]*probe probe--loud[^"]*"[^>]*data-count="7"/', $html);
    $this->assertStringContainsString('<h2 class="probe__title">A probe</h2>', $html);
    $this->assertStringContainsString('<h2 class="heading-block__text">Deep &amp; &lt;b&gt;plain&lt;/b&gt;</h2>', $html, 'Text props are escaped by Twig.');
    $this->assertStringNotContainsString('Not shown', $html);
    $this->assertMatchesRegularExpression('/probe--calm[^>]*data-count=""/', $html, 'A number left empty is not passed.');

    // The nesting: columns > probe > heading.
    $columns = strpos($html, 'layout-columns__items');
    $probe = strpos($html, 'probe--loud');
    $slot = strpos($html, 'probe__content');
    $deep = strpos($html, 'Deep &amp;');
    $second = strpos($html, 'probe--calm');
    $this->assertTrue($columns < $probe && $probe < $slot && $slot < $deep && $deep < $second);
  }

  /**
   * The values of the features are inline declarations on the root element
   * of the component, through its attributes.
   */
  public function testFeatureStyles(): void {
    $item = self::item('frame:heading', 'h1', [
      'text' => 'Styled',
      '_spacing' => ['margin' => ['size' => 'm', 'sides' => 'vertical'], 'padding' => ['size' => 'custom', 'custom' => '3px']],
      '_size' => ['max_width' => ['size' => 's']],
      '_align' => 'center',
      '_text' => ['weight' => 'bold', 'color' => 'paper'],
      '_background' => ['color' => 'brand'],
    ]);
    $build = $this->layouts()->buildItem($item);
    $this->assertInstanceOf(Attribute::class, $build['#props']['attributes']);
    $style = 'margin-block: 1rem; padding: 3px; max-width: 30rem; text-align: center; font-weight: 700; color: #ffffff; background-color: #112233';
    $this->assertSame($style, (string) $build['#props']['attributes']['style']);
    foreach (['_spacing', '_size', '_align', '_text', '_background'] as $name) {
      $this->assertArrayNotHasKey($name, $build['#props']);
    }
    $this->assertMatchesRegularExpression('/<div[^>]*style="' . preg_quote($style, '/') . '"[^>]*class="[^"]*heading-block[^"]*"/', $this->renderHtml($build));

    $plain = $this->layouts()->buildItem(self::item('frame:heading', 'h2', ['text' => 'Plain', '_align' => 'left', '_spacing' => ['margin' => ['size' => 'none']]]));
    $this->assertArrayNotHasKey('attributes', $plain['#props'], 'Nothing to print: no attributes.');
  }

  /**
   * A feature the component switches off is not printed, whatever the
   * layout says.
   */
  public function testSwitchedOffFeatureIsNotPrinted(): void {
    $build = $this->layouts()->buildItem(self::item('frame:badge', 'b1', ['text' => 'New', '_background' => ['color' => 'brand'], '_align' => 'end']));
    $this->assertSame('text-align: end', (string) $build['#props']['attributes']['style']);
    $build = $this->layouts()->buildItem(self::item(self::KINDS, 'k1', ['plain' => 'Plain', '_position' => ['mode' => 'absolute', 'corner' => 'top-left']]));
    $this->assertArrayNotHasKey('attributes', $build['#props']);
  }

  /**
   * A pinned component is positioned, and its parent becomes what it is
   * pinned to; a placed one takes automatic margins.
   */
  public function testPosition(): void {
    $badge = self::item('frame:badge', 'b1', ['text' => 'New', '_position' => ['mode' => 'absolute', 'corner' => 'top-right', 'offset' => ['size' => 's'], 'overlap' => TRUE]]);
    $build = $this->layouts()->buildItem(self::item(self::PROBE, 'p1', ['title' => 'Parent', '_spacing' => ['padding' => ['size' => 'm']], 'content' => [$badge]]));
    $this->assertSame('padding: 1rem; position: relative', (string) $build['#props']['attributes']['style']);
    $this->assertSame(
      'position: absolute; z-index: 1; top: 0.5rem; right: 0.5rem; transform: translate(50%, -50%)',
      (string) $build['#slots']['content'][0]['#props']['attributes']['style'],
    );

    $parent = $this->layouts()->buildItem(self::item(self::PROBE, 'p2', ['content' => [$badge]]));
    $this->assertSame('position: relative', (string) $parent['#props']['attributes']['style'], 'Also without a style of its own.');

    $placed = self::item('frame:badge', 'b2', ['text' => 'New', '_position' => ['mode' => 'relative', 'place' => 'middle-right']]);
    $build = $this->layouts()->buildItem(self::item(self::PROBE, 'p3', ['content' => [$placed]]));
    $this->assertArrayNotHasKey('attributes', $build['#props'], 'A placed child needs nothing of its parent.');
    $this->assertSame('margin-block: auto auto; margin-inline: auto 0; width: fit-content; max-width: 100%', (string) $build['#slots']['content'][0]['#props']['attributes']['style']);
  }

  /**
   * In the editor, which passes the slots, a component is not pinned: the
   * editor pins its own wrapper of it.
   */
  public function testPositionIsLeftToTheEditor(): void {
    $badge = self::item('frame:badge', 'b1', ['text' => 'New', '_align' => 'center', '_position' => ['mode' => 'absolute', 'corner' => 'top-right']]);
    $this->assertSame('text-align: center', (string) $this->layouts()->buildItem($badge, NULL, [])['#props']['attributes']['style']);
    $this->assertStringContainsString('position: absolute', (string) $this->layouts()->buildItem($badge)['#props']['attributes']['style']);
  }

  /**
   * A background with a responsive image style marks the component and
   * puts its rules with media queries in the head of the page.
   */
  public function testResponsiveBackground(): void {
    $media = $this->createImageMedia();
    $this->createBreakpointStyle('cat_art');
    $values = ['_background' => ['image' => ['media' => $media->id(), 'style' => 'responsive:cat_art'], 'color' => 'brand']];
    $expected = $this->container->get('frame_builder.features')->responsiveBackground([], $values);

    $build = $this->layouts()->buildItem(self::item('frame:heading', 'h1', ['text' => 'Over a picture'] + $values));
    $this->assertSame($expected['key'], (string) $build['#props']['attributes']['data-frame-builder-bg']);
    $this->assertStringNotContainsString('background-image', (string) $build['#props']['attributes']['style']);
    $this->assertStringContainsString('background-color: #112233', (string) $build['#props']['attributes']['style']);
    $head = $build['#attached']['html_head'];
    $this->assertCount(1, $head);
    $this->assertSame('frame_builder_background_' . $expected['key'], $head[0][1]);
    $this->assertSame('style', $head[0][0]['#tag']);
    $this->assertSame($expected['css'], (string) $head[0][0]['#value']);
    $this->assertStringContainsString('data-frame-builder-bg="' . $expected['key'] . '"', $this->renderHtml($build));
  }

  /**
   * An image prop that is a plain URL is passed as it is, without a script.
   */
  public function testImagePropWithUrl(): void {
    $props = $this->props('frame:card', ['heading' => 'Card', 'image' => '/sites/default/files/a.png']);
    $this->assertSame('/sites/default/files/a.png', $props['image']);
    foreach (['image_picture', 'image_srcset', 'image_sizes', 'image_alt'] as $name) {
      $this->assertArrayNotHasKey($name, $props);
    }
    $this->assertSame('alert(1)', $this->props('frame:card', ['heading' => 'Card', 'image' => 'javascript:alert(1)'])['image']);
    $this->assertSame('/b.png', $this->props('frame:card', ['heading' => 'Card', 'image' => ['url' => '/b.png']])['image']);
    $this->assertArrayNotHasKey('image', $this->props('frame:card', ['heading' => 'Card', 'image' => '']));
    $this->assertArrayNotHasKey('image', $this->props('frame:card', ['heading' => 'Card', 'image' => ['media' => 99]]));
    $this->assertSame('Typed', $this->props('frame:card', ['heading' => 'Card', 'image_alt' => 'Typed'])['image_alt'], 'Without a picked image the prop is one like the others.');
  }

  /**
   * A picked image gives the URL of its image style, and fills the props the
   * component names for the alternative text and for the image as Drupal
   * renders it.
   */
  public function testImagePropWithMedia(): void {
    $media = $this->createImageMedia('A red square');
    $build = $this->layouts()->buildItem(self::item('frame:card', 'c1', [
      'heading' => 'Card',
      'image' => ['media' => $media->id(), 'url' => '/original.png', 'style' => 'style:thumbnail'],
      'image_alt' => 'Typed alternative text',
    ]));
    $props = $build['#props'];
    $this->assertStringContainsString('/styles/thumbnail/public/picture-', $props['image']);
    $this->assertSame('A red square', $props['image_alt'], 'The alternative text of the media item wins.');
    $this->assertArrayNotHasKey('image_srcset', $props, 'A plain image style has no srcset.');
    $this->assertInstanceOf(Markup::class, $props['image_picture']);
    $picture = (string) $props['image_picture'];
    $this->assertStringStartsWith('<img', $picture);
    $this->assertStringContainsString('/styles/thumbnail/public/picture-', $picture);
    $this->assertStringContainsString('alt="A red square"', $picture);
    $this->assertStringContainsString('loading="lazy"', $picture);
    $this->assertContains('media:' . $media->id(), $build['#cache']['tags'], 'The page changes with the media item.');

    $html = $this->renderHtml($build);
    $this->assertMatchesRegularExpression('#<div class="card__image">\s*<img[^>]*alt="A red square"#', $html);
  }

  /**
   * With a responsive image style the component gets the fallback image,
   * the srcset and sizes of the style, and the <img srcset> of Drupal.
   */
  public function testImagePropWithResponsiveStyle(): void {
    $media = $this->createImageMedia('A red square');
    $this->createSizesStyle('cat_wide', '(min-width: 700px) 700px, 100vw');
    $props = $this->props('frame:card', [
      'heading' => 'Card',
      'image' => ['media' => $media->id(), 'style' => 'responsive:cat_wide', 'loading' => 'eager'],
    ]);
    $this->assertStringContainsString('/styles/medium/public/picture-', $props['image']);
    $this->assertSame('(min-width: 700px) 700px, 100vw', $props['image_sizes']);
    $this->assertMatchesRegularExpression('#^\S*/styles/thumbnail/\S* 100w, \S*/styles/medium/\S* 220w, \S*/styles/large/\S* 360w$#', $props['image_srcset']);
    $picture = (string) $props['image_picture'];
    $this->assertStringContainsString('srcset="', $picture);
    $this->assertStringContainsString('sizes="(min-width: 700px) 700px, 100vw"', $picture);
    $this->assertStringContainsString('loading="eager"', $picture);
  }

  /**
   * A component that only takes a URL gets no rendered image.
   */
  public function testImagePropWithoutPictureProp(): void {
    $media = $this->createImageMedia();
    $props = $this->props(self::KINDS, ['plain' => 'Plain', 'photo' => ['media' => $media->id(), 'style' => 'style:medium']]);
    $this->assertStringContainsString('/styles/medium/public/picture-', $props['photo']);
    $this->assertArrayNotHasKey('picture_markup', $props);
    $this->assertArrayNotHasKey('picture_alt', $props);
  }

}
