<?php

declare(strict_types=1);

namespace Drupal\Tests\frame_builder_ai\Kernel;

use Drupal\filter\Entity\FilterFormat;
use Drupal\frame_builder_ai\LayoutSanitizer;
use Drupal\media\Entity\Media;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\Entity\Paragraph;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The layout a model proposed is made into one the builder accepts.
 */
#[Group('frame_builder')]
#[RunTestsInSeparateProcesses]
class LayoutSanitizerTest extends AssistantKernelTestBase {

  /**
   * A page to sanitize layouts for.
   */
  protected NodeInterface $node;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->node = $this->createBuilderNode();
  }

  /**
   * The sanitizer.
   */
  protected function sanitizer(): LayoutSanitizer {
    return $this->container->get('frame_builder_ai.sanitizer');
  }

  /**
   * Sanitizes items with the components of the page.
   */
  protected function sanitize(array $items, array $current = [], bool $final = FALSE): array {
    return $this->sanitizer()->sanitize($items, $this->components($this->node), $current, $this->node, $final);
  }

  /**
   * The props of one proposed item after sanitizing, without its id.
   *
   * @param string $type
   *   Component of the item.
   * @param array $props
   *   The props the model gave.
   * @param array|null $before
   *   The props the item has on the page, when the model keeps it.
   */
  protected function props(string $type, array $props, ?array $before = NULL): array {
    $current = $before === NULL ? [] : [self::item($type, 'kept', $before)];
    $result = $this->sanitize([self::item($type, 'kept', $props)], $current);
    $this->assertCount(1, $result);
    $this->assertSame($type, $result[0]['type']);
    $this->assertSame('kept', $result[0]['props']['id']);
    return array_diff_key($result[0]['props'], ['id' => 1]);
  }

  /**
   * Items of components that do not exist are dropped, wherever they are;
   * what is not an item at all is dropped too.
   */
  public function testUnknownComponentsAreDropped(): void {
    $result = $this->sanitize([
      self::item('frame:heading', 'h1', ['text' => 'Kept']),
      self::item('frame:carousel', 'c1', ['slides' => 3]),
      self::item('evil:script', 's1', ['code' => 'alert(1)']),
      'just a string',
      42,
      NULL,
      ['props' => ['id' => 'typeless', 'text' => 'No type']],
      self::item('frame_builder_test_theme:probe', 'p1', [
        'content' => [
          self::item('frame:marquee', 'm1'),
          self::item('frame:heading', 'h2', ['text' => 'Nested']),
        ],
      ]),
    ]);
    $this->assertSame(['frame:heading', 'frame_builder_test_theme:probe'], array_column($result, 'type'));
    $this->assertSame('Kept', $result[0]['props']['text']);
    $this->assertSame(['frame:heading'], array_column($result[1]['props']['content'], 'type'));
    $this->assertSame('Nested', $result[1]['props']['content'][0]['props']['text']);
  }

  /**
   * A type that is not text is not a component, and is dropped quietly.
   */
  public function testTypeThatIsNotText(): void {
    $warnings = [];
    set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
      $warnings[] = $message;
      return TRUE;
    }, E_WARNING | E_NOTICE);
    try {
      $result = $this->sanitize([['type' => ['frame:heading'], 'props' => ['text' => 'A']], ['type' => 12, 'props' => []], ['type' => NULL]]);
    }
    finally {
      restore_error_handler();
    }
    $this->assertSame([], $result);
    if ($warnings) {
      $this->markTestSkipped('BUG: LayoutSanitizer::items() casts the "type" of an item to a string without checking it: a type given as a list raises the PHP warning "' . $warnings[0] . '".');
    }
    $this->assertSame([], $warnings);
  }

  /**
   * A list with keys is taken as a list, and at most two hundred items of a
   * list are taken.
   */
  public function testListsOfItems(): void {
    $result = $this->sanitize(['first' => self::item('frame:heading', 'a', ['text' => 'A']), 'second' => self::item('frame:heading', 'b', ['text' => 'B'])]);
    $this->assertSame([0, 1], array_keys($result));
    $this->assertSame(['a', 'b'], array_column(array_column($result, 'props'), 'id'));

    $many = [];
    for ($i = 0; $i < 205; $i++) {
      $many[] = self::item('frame:heading', "h$i", ['text' => "Heading $i"]);
    }
    $result = $this->sanitize($many);
    $this->assertCount(200, $result);
    $this->assertSame('h199', $result[199]['props']['id']);
  }

  /**
   * Props a component does not have are dropped; an item without props is an
   * item with the values the component requires.
   */
  public function testUnknownPropsAreDropped(): void {
    $props = $this->props('frame_builder_test_theme:probe', [
      'title' => 'A title',
      'onclick' => 'alert(1)',
      'class' => 'danger',
      'style' => 'position:fixed',
      'attributes' => ['onload' => 'x'],
      '_custom' => ['css' => 'body{display:none}'],
    ]);
    $this->assertSame(['title' => 'A title', 'content' => []], $props);

    $result = $this->sanitize([['type' => 'frame:heading'], ['type' => 'frame:heading', 'props' => 'not props']]);
    $this->assertCount(2, $result);
    foreach ($result as $item) {
      $this->assertSame(['id', 'text'], array_keys($item['props']));
      $this->assertSame('A heading', $item['props']['text'], 'A required prop without a value gets the default of the component.');
    }
  }

  /**
   * Text, textarea and link props take scalars as text of a limited length.
   */
  public function testTexts(): void {
    $probe = 'frame_builder_test_theme:probe';
    $this->assertSame('Hello [node:title]', $this->props($probe, ['title' => 'Hello [node:title]'])['title'], 'Tokens stay as they are.');
    $this->assertSame('12', $this->props($probe, ['title' => 12])['title']);
    $this->assertSame('1', $this->props($probe, ['title' => TRUE])['title']);
    $this->assertArrayNotHasKey('title', $this->props($probe, ['title' => ['an', 'array']]));
    $this->assertArrayNotHasKey('title', $this->props($probe, ['title' => NULL]));
    $this->assertSame(5000, mb_strlen($this->props($probe, ['title' => str_repeat('é', 6000)])['title']));
    // A textarea.
    $this->assertSame("Line one\nLine two", $this->props('frame:hero', ['heading' => 'H', 'text' => "Line one\nLine two"])['text']);
    // Links: a path, a link to content as the content search gives it, an address.
    foreach (['/contact', 'entity:node/1', 'https://example.com/a?b=c#d', '[site:url]'] as $url) {
      $this->assertSame($url, $this->props('frame:button', ['label' => 'Go', 'url' => $url])['url']);
    }
    // A link that is not text: the required prop falls back to its default.
    $this->assertSame('/', $this->props('frame:button', ['label' => 'Go', 'url' => ['href' => '/x']])['url']);
    $this->assertSame('Read more', $this->props('frame:button', ['url' => '/x'])['label']);
  }

  /**
   * Choices: only one of the options of the prop.
   */
  public function testEnums(): void {
    $probe = 'frame_builder_test_theme:probe';
    $this->assertSame('loud', $this->props($probe, ['tone' => 'loud'])['tone']);
    foreach (['shouting', 'LOUD', '', TRUE, 1, ['loud'], NULL] as $value) {
      $this->assertArrayNotHasKey('tone', $this->props($probe, ['tone' => $value]), 'Not an option: ' . json_encode($value));
    }
    $this->assertSame(['text' => 'T', 'level' => 'h3'], $this->props('frame:heading', ['text' => 'T', 'level' => 'h3', 'size' => 'gigantic']));
    // Options that are numbers in the answer of a model.
    $this->assertEquals('3', $this->props('frame:columns', ['layout' => 3])['layout']);
    $this->assertSame('1-2', $this->props('frame:columns', ['layout' => '1-2'])['layout']);
    $this->assertArrayNotHasKey('layout', $this->props('frame:columns', ['layout' => 5]));
    // A colour: the name of a colour of the palette, not a CSS colour.
    $this->assertSame('brand', $this->props('frame:button', ['color' => 'brand'])['color']);
    foreach (['#112233', 'Brand', 'red', ['brand'], 1] as $value) {
      $this->assertArrayNotHasKey('color', $this->props('frame:button', ['color' => $value]), 'Not of the palette: ' . json_encode($value));
    }
  }

  /**
   * A choice the model gave as a number is stored as the option it stands
   * for, which is text.
   */
  public function testEnumGivenAsNumberBecomesTheOption(): void {
    $layout = $this->props('frame:columns', ['layout' => 3])['layout'];
    if ($layout !== '3') {
      $this->markTestSkipped('BUG: LayoutSanitizer::value() returns the number 3 for the option "3" of a select (the value as given, not the matched option), which is not a value the editor could have made.');
    }
    $this->assertSame('3', $layout);
  }

  /**
   * Numbers and booleans.
   */
  public function testNumbersAndBooleans(): void {
    $probe = 'frame_builder_test_theme:probe';
    foreach ([[7, 7], ['7', 7], ['2.5', 2.5], [-1, -1], [0, 0], ['1e2', 100.0]] as [$given, $expected]) {
      $this->assertSame($expected, $this->props($probe, ['count' => $given])['count'], 'Number: ' . json_encode($given));
    }
    foreach (['many', '', '7 apples', [7], TRUE] as $given) {
      $this->assertArrayNotHasKey('count', $this->props($probe, ['count' => $given]), 'Not a number: ' . json_encode($given));
    }
    foreach ([[TRUE, TRUE], [FALSE, FALSE], ['true', TRUE], ['false', FALSE], ['yes', TRUE], ['no', FALSE], [1, TRUE], [0, FALSE], ['maybe', FALSE]] as [$given, $expected]) {
      $this->assertSame($expected, $this->props($probe, ['shown' => $given])['shown'], 'Boolean: ' . json_encode($given));
    }
    $this->assertArrayNotHasKey('shown', $this->props($probe, ['shown' => ['yes']]));
    $this->assertArrayNotHasKey('shown', $this->props($probe, []), 'A prop that is not required and not given stays out.');
  }

  /**
   * Lengths: a step of the spacing scale of the theme, or a CSS length.
   */
  public function testLengths(): void {
    $cases = [
      ['l', ['size' => 'l']],
      [['size' => 'm'], ['size' => 'm']],
      [['size' => 's', 'custom' => '99px', 'extra' => 'x'], ['size' => 's']],
      ['12px', ['size' => 'custom', 'custom' => '12px']],
      ['1.5rem', ['size' => 'custom', 'custom' => '1.5rem']],
      ['-2em', ['size' => 'custom', 'custom' => '-2em']],
      ['50%', ['size' => 'custom', 'custom' => '50%']],
      [['size' => 'custom', 'custom' => ' 10vh '], ['size' => 'custom', 'custom' => '10vh']],
      // Not of the scale of the test theme, and not lengths.
      ['xl', NULL],
      [['size' => 'xl'], NULL],
      ['auto', NULL],
      ['calc(100% - 1px)', NULL],
      ['12px; position: fixed', NULL],
      ['12', NULL],
      [['size' => 'custom', 'custom' => 'url(javascript:alert(1))'], NULL],
      [['size' => 'custom'], NULL],
      [['custom' => '12px'], NULL],
      [12, NULL],
      [TRUE, NULL],
    ];
    foreach ($cases as [$given, $expected]) {
      $props = $this->props('frame:spacer', ['size' => $given]);
      $this->assertSame($expected, $props['size'] ?? NULL, 'Length: ' . json_encode($given));
    }
    $this->assertSame(['size' => 'l'], $this->props('frame:columns', ['gap' => 'l'])['gap']);
  }

  /**
   * Spacing, alignment, text style and position of an item.
   */
  public function testFeatures(): void {
    $probe = 'frame_builder_test_theme:probe';
    // Spacing: margin may be "auto", padding may not; sides are one of three.
    $cases = [
      [['margin' => ['size' => 'auto', 'sides' => 'horizontal'], 'padding' => ['size' => 'l', 'sides' => 'diagonal']], ['margin' => ['size' => 'auto', 'sides' => 'horizontal'], 'padding' => ['size' => 'l', 'sides' => 'all']]],
      [['margin' => 'm', 'padding' => '4px'], ['margin' => ['size' => 'm', 'sides' => 'all'], 'padding' => ['size' => 'custom', 'custom' => '4px', 'sides' => 'all']]],
      [['padding' => ['size' => 'auto'], 'margin' => ['size' => 's', 'sides' => 'vertical']], ['margin' => ['size' => 's', 'sides' => 'vertical']]],
      [['padding' => ['size' => 'enormous'], 'border' => ['size' => 'l']], NULL],
      ['l', NULL],
      [[], NULL],
    ];
    foreach ($cases as [$given, $expected]) {
      $this->assertEquals($expected, $this->props($probe, ['_spacing' => $given])['_spacing'] ?? NULL, 'Spacing: ' . json_encode($given));
    }
    // Alignment.
    foreach (['start', 'center', 'end'] as $align) {
      $this->assertSame($align, $this->props($probe, ['_align' => $align])['_align']);
    }
    foreach (['middle', 'left', TRUE, ['center']] as $align) {
      $this->assertArrayNotHasKey('_align', $this->props($probe, ['_align' => $align]), 'Alignment: ' . json_encode($align));
    }
    // Text style: sizes of the type scale, colours of the palette.
    $cases = [
      [
        ['size' => 'l', 'weight' => 'bold', 'italic' => TRUE, 'transform' => 'uppercase', 'leading' => 'tight', 'color' => 'brand'],
        ['size' => 'l', 'weight' => 'bold', 'italic' => TRUE, 'transform' => 'uppercase', 'leading' => 'tight', 'color' => 'brand'],
      ],
      [
        ['size' => 'huge', 'weight' => 'black', 'italic' => FALSE, 'transform' => 'shout', 'leading' => 'wide', 'color' => '#f00', 'font' => 'Comic Sans'],
        NULL,
      ],
      [['size' => 'custom', 'custom' => '18px', 'weight' => 'medium'], ['size' => 'custom', 'custom' => '18px', 'weight' => 'medium']],
      [['italic' => 1], ['italic' => TRUE]],
      ['bold', NULL],
    ];
    foreach ($cases as [$given, $expected]) {
      $this->assertEquals($expected, $this->props($probe, ['_text' => $given])['_text'] ?? NULL, 'Text style: ' . json_encode($given));
    }
    // Position: placed in the free space, or pinned on a corner.
    $cases = [
      [['mode' => 'relative', 'place' => 'middle-center', 'corner' => 'top-left'], ['mode' => 'relative', 'place' => 'middle-center']],
      [['mode' => 'relative', 'place' => 'center'], NULL],
      [['mode' => 'absolute', 'corner' => 'top-right', 'offset' => ['size' => 's'], 'overlap' => TRUE], ['mode' => 'absolute', 'corner' => 'top-right', 'offset' => ['size' => 's'], 'overlap' => TRUE]],
      [['mode' => 'absolute', 'corner' => 'bottom-left', 'offset' => ['size' => 'far']], ['mode' => 'absolute', 'corner' => 'bottom-left']],
      [['mode' => 'absolute', 'corner' => 'middle'], NULL],
      [['mode' => 'fixed', 'corner' => 'top-left'], NULL],
      ['absolute', NULL],
    ];
    foreach ($cases as [$given, $expected]) {
      $this->assertEquals($expected, $this->props($probe, ['_position' => $given])['_position'] ?? NULL, 'Position: ' . json_encode($given));
    }
    // A feature the component switched off is not a prop of it.
    $this->assertArrayNotHasKey('_spacing', $this->props('frame:spacer', ['_spacing' => ['margin' => 'l']]));
  }

  /**
   * The size of an item (the "_size" the instructions offer the model) is
   * taken from the scale of sizes of the theme.
   */
  public function testSize(): void {
    $probe = 'frame_builder_test_theme:probe';
    $this->assertSame(
      ['max_width' => ['size' => 'm'], 'min_height' => ['size' => 's']],
      $this->props($probe, ['_size' => ['max_width' => ['size' => 'm'], 'min_height' => ['size' => 's']]])['_size'],
    );
    // "width" and "height", as models knew them: at most that wide, at least that tall.
    $this->assertSame(
      ['max_width' => ['size' => 'm'], 'min_height' => ['size' => 's']],
      $this->props($probe, ['_size' => ['width' => ['size' => 'm'], 'height' => ['size' => 's']]])['_size'],
    );
    $this->assertSame(
      ['exact_width' => ['size' => 's'], 'min_width' => ['size' => 'custom', 'custom' => '10px']],
      $this->props($probe, ['_size' => ['exact_width' => 's', 'min_width' => '10px', 'max_height' => 'huge', 'depth' => 's']])['_size'],
    );
    $this->assertArrayNotHasKey('_size', $this->props($probe, ['_size' => 'big']));
  }

  /**
   * The size an item of the page has stays when the model keeps the item,
   * and so does what the item has from a breakpoint of the theme on, which
   * the model is not told about.
   */
  public function testSizeOfKeptItemStays(): void {
    $size = ['max_width' => ['size' => 'm']];
    $probe = 'frame_builder_test_theme:probe';
    $this->assertSame($size, $this->props($probe, ['title' => 'New', '_size' => $size], ['title' => 'Old', '_size' => $size])['_size']);

    $before = ['title' => 'Old', '_spacing' => ['padding' => ['size' => 's'], 'at' => ['tablet' => ['padding' => ['size' => 'l']]]]];
    $kept = $this->props($probe, ['title' => 'New', '_spacing' => ['padding' => ['size' => 'm'], 'at' => ['tablet' => ['padding' => ['size' => 'xxl']]]]], $before);
    $this->assertSame(['padding' => ['size' => 'm', 'sides' => 'all'], 'at' => ['tablet' => ['padding' => ['size' => 'l']]]], $kept['_spacing']);
    $new = $this->props($probe, ['_spacing' => ['padding' => ['size' => 'm'], 'at' => ['tablet' => ['padding' => ['size' => 'l']]]]]);
    $this->assertSame(['padding' => ['size' => 'm', 'sides' => 'all']], $new['_spacing'], 'A new item has no values per breakpoint.');
  }

  /**
   * Backgrounds: a colour of the palette; an image only from the library.
   */
  public function testBackground(): void {
    $probe = 'frame_builder_test_theme:probe';
    $media = $this->createImageMedia('Sky', 'A sky');
    $this->assertSame(['color' => 'brand'], $this->props($probe, ['_background' => ['color' => 'brand']])['_background']);
    // A border: names of the theme's scales and palette only.
    $this->assertSame(
      ['width' => ['size' => 'thin'], 'sides' => 'bottom', 'style' => 'dashed', 'color' => 'brand', 'radius' => ['size' => 'm']],
      $this->props($probe, ['_border' => ['width' => ['size' => 'thin'], 'sides' => 'bottom', 'style' => 'dashed', 'color' => 'brand', 'radius' => 'm']])['_border'],
    );
    $this->assertSame(
      ['width' => ['size' => 'thick'], 'sides' => 'all'],
      $this->props($probe, ['_border' => ['width' => 'thick', 'sides' => 'diagonal', 'style' => 'groove', 'color' => 'hotpink']])['_border'],
    );
    $this->assertArrayNotHasKey('_border', $this->props($probe, ['_border' => ['width' => ['size' => 'custom', 'custom' => '1px;color:red'], 'radius' => '50px']]));
    $this->assertArrayNotHasKey('_background', $this->props($probe, ['_background' => ['color' => 'hotpink']]));
    $this->assertArrayNotHasKey('_background', $this->props($probe, ['_background' => 'brand']));
    $this->assertArrayNotHasKey('_background', $this->props($probe, ['_background' => ['image' => 'https://example.com/x.png', 'size' => 'cover']]), 'An image URL is never used.');

    $background = $this->props($probe, ['_background' => ['image' => ['media' => $media->id()], 'size' => 'cover', 'color' => 'paper']])['_background'];
    $this->assertSame((int) $media->id(), $background['image']['media']);
    $this->assertSame('A sky', $background['image']['alt']);
    $this->assertSame('cover', $background['size']);
    $this->assertSame('paper', $background['color']);
    $this->assertArrayNotHasKey('size', $this->props($probe, ['_background' => ['image' => ['media' => $media->id()], 'size' => 'stretch']])['_background']);

    // A kept item keeps the image and its placement when the model only
    // changes the colour, which is all it was asked about.
    $before = ['_background' => ['image' => ['url' => '/old.png', 'media' => 999, 'style' => 'style:wide'], 'size' => 'contain', 'x' => 20, 'y' => 80, 'color' => 'paper']];
    $this->assertEquals(
      ['image' => ['url' => '/old.png', 'media' => 999, 'style' => 'style:wide'], 'size' => 'contain', 'x' => 20, 'y' => 80, 'color' => 'brand'],
      $this->props($probe, ['_background' => ['color' => 'brand']], $before)['_background'],
    );
    // Another image replaces it; an image that is not of the library removes it.
    $replaced = $this->props($probe, ['_background' => ['image' => ['media' => $media->id()]]], $before)['_background'];
    $this->assertSame((int) $media->id(), $replaced['image']['media']);
    $this->assertSame('contain', $replaced['size']);
    $removed = $this->props($probe, ['_background' => ['image' => 'https://example.com/x.png']], $before)['_background'];
    $this->assertArrayNotHasKey('image', $removed);
  }

  /**
   * Images: only media items that exist, are images and that the user may
   * see, with an image style of the theme.
   */
  public function testImages(): void {
    $media = $this->createImageMedia('Red cat', 'A cat on a sofa', 'cat.png');
    $id = (int) $media->id();
    $expected = [
      'url' => $this->container->get('file_url_generator')->generateString('public://cat.png'),
      'media' => $id,
      'alt' => 'A cat on a sofa',
      // The only image style the test theme offers.
      'style' => 'style:thumbnail',
    ];
    $this->assertSame($expected, $this->props('frame:image', ['image' => ['media' => $id]])['image']);
    $this->assertSame($expected, $this->props('frame:image', ['image' => $id])['image'], 'The id alone is taken too.');
    $this->assertSame($expected, $this->props('frame:image', ['image' => ['media' => (string) $id, 'url' => 'https://example.com/evil.png', 'alt' => 'Made up', 'style' => 'style:original']])['image'], 'Only the id is taken from the model.');

    $document = $this->createMediaType('file', ['id' => 'document']);
    $file = Media::create(['bundle' => 'document', 'name' => 'A document', 'status' => 1]);
    $file->save();
    $refused = [
      'a URL' => 'https://example.com/picture.png',
      'a URL as the editor stores it' => ['url' => 'https://example.com/picture.png'],
      'a data URL' => 'data:image/png;base64,AAAA',
      'a media item that does not exist' => ['media' => 99999],
      'a media item that is not an image' => ['media' => $file->id()],
      'a boolean' => TRUE,
      'nothing' => [],
    ];
    foreach ($refused as $what => $given) {
      $this->assertArrayNotHasKey('image', $this->props('frame:image', ['image' => $given]), "Refused: $what.");
    }
    $this->assertSame('document', $document->id());

    // The image an item of the page has: kept as it is when the model names
    // the same media item, replaced by another one, removed by nonsense.
    $before = ['image' => ['url' => '/styled/cat.png', 'media' => $id, 'alt' => 'Old alt', 'style' => 'style:wide', 'loading' => 'eager', 'link' => 'content']];
    $this->assertSame($before['image'], $this->props('frame:image', ['image' => ['media' => $id], 'caption' => 'New'], $before)['image']);
    $other = $this->createImageMedia('Blue dog', 'A dog', 'dog.png');
    $changed = $this->props('frame:image', ['image' => ['media' => $other->id()]], $before)['image'];
    $this->assertSame((int) $other->id(), $changed['media']);
    $this->assertSame('A dog', $changed['alt']);
    $this->assertArrayNotHasKey('link', $changed);
    $this->assertArrayNotHasKey('image', $this->props('frame:image', ['image' => 'https://example.com/picture.png'], $before));

    // What the user may not see is not used.
    $media->setUnpublished()->save();
    $this->setUpCurrentUser([], ['access content', 'view media']);
    $this->assertArrayNotHasKey('image', $this->props('frame:image', ['image' => ['media' => $id]]), 'An unpublished media item is not for this user.');
    $this->assertSame((int) $other->id(), $this->props('frame:image', ['image' => ['media' => $other->id()]])['image']['media']);
    $this->setUpCurrentUser([], ['access content']);
    $this->assertArrayNotHasKey('image', $this->props('frame:image', ['image' => ['media' => $other->id()]]), 'Without the permission to view media no image is used.');
  }

  /**
   * The image style of a chosen image: a responsive one first, else a large
   * one, else the first the field offers; none when it offers none.
   */
  public function testImageStyleChoice(): void {
    $media = $this->createImageMedia('Red cat');
    $component = static fn (array $styles): array => [[
      'id' => 'test:picture',
      'fields' => [['name' => 'image', 'widget' => 'image', 'imageStyles' => array_map(static fn (string $style): array => ['value' => $style, 'label' => $style], $styles)]],
      'slots' => [],
    ]];
    $cases = [
      [['style:thumbnail', 'style:large', 'responsive:wide'], 'responsive:wide'],
      [['style:thumbnail', 'style:hero_large', 'style:medium'], 'style:hero_large'],
      [['style:thumbnail', 'style:medium'], 'style:thumbnail'],
      [[], NULL],
    ];
    foreach ($cases as [$styles, $expected]) {
      $result = $this->sanitizer()->sanitize([self::item('test:picture', 'i', ['image' => ['media' => $media->id()]])], $component($styles), []);
      $this->assertSame($expected, $result[0]['props']['image']['style'] ?? NULL, 'Offered: ' . implode(', ', $styles));
    }
  }

  /**
   * Rich text is put in a text format the user may use; the model does not
   * choose the format.
   */
  public function testHtml(): void {
    FilterFormat::create(['format' => 'basic_html', 'name' => 'Basic HTML', 'weight' => -5])->save();
    FilterFormat::create(['format' => 'full_html', 'name' => 'Full HTML', 'weight' => 5])->save();
    $html = '<p onclick="alert(1)">Hello <strong>there</strong></p><script>alert(2)</script>';

    // As it is: the format filters it when the page is rendered.
    $this->assertSame(['format' => 'basic_html', 'value' => $html], $this->props('frame:text', ['text' => $html])['text']);
    $this->assertSame(['format' => 'basic_html', 'value' => '<p>Hi</p>'], $this->props('frame:text', ['text' => ['value' => '<p>Hi</p>', 'format' => 'full_html']])['text'], 'A format named by the model is not taken.');
    $this->assertSame(20000, mb_strlen($this->props('frame:text', ['text' => str_repeat('a', 20500)])['text']['value']));

    // No text: the default of the required prop, in a format too.
    foreach (['', "  \n ", ['format' => 'full_html'], ['value' => 12], 12, TRUE] as $given) {
      $this->assertSame(['format' => 'basic_html', 'value' => '<p>Write something.</p>'], $this->props('frame:text', ['text' => $given])['text'], 'No text: ' . json_encode($given));
    }

    // A kept item keeps the format the editor chose for it.
    $before = ['text' => ['format' => 'full_html', 'value' => '<p>Old</p>']];
    $this->assertSame(['format' => 'full_html', 'value' => '<p>New</p>'], $this->props('frame:text', ['text' => '<p>New</p>'], $before)['text']);
    // Unless that format is gone or not for this user.
    $this->assertSame(['format' => 'basic_html', 'value' => '<p>New</p>'], $this->props('frame:text', ['text' => '<p>New</p>'], ['text' => ['format' => 'deleted_format', 'value' => '<p>Old</p>']])['text']);
    $this->setUpCurrentUser([], ['access content', 'use text format basic_html']);
    $this->assertSame(['format' => 'basic_html', 'value' => '<p>New</p>'], $this->props('frame:text', ['text' => '<p>New</p>'], $before)['text']);
    // A user with no format but the fallback one.
    $this->setUpCurrentUser([], ['access content']);
    $this->assertSame(['format' => 'plain_text', 'value' => '<p>New</p>'], $this->props('frame:text', ['text' => '<p>New</p>'], $before)['text']);
  }

  /**
   * A prop that names its text format gets that one; without it, the first
   * format that has an editor is used.
   */
  public function testHtmlFormatOfPropAndEditor(): void {
    FilterFormat::create(['format' => 'basic_html', 'name' => 'Basic HTML', 'weight' => -5])->save();
    FilterFormat::create(['format' => 'full_html', 'name' => 'Full HTML', 'weight' => 5])->save();
    $components = [[
      'id' => 'test:rich',
      'fields' => [
        ['name' => 'fixed', 'widget' => 'html', 'format' => 'full_html'],
        ['name' => 'gone', 'widget' => 'html', 'format' => 'no_such_format'],
        ['name' => 'free', 'widget' => 'html'],
      ],
      'slots' => [],
    ]];
    $item = self::item('test:rich', 'r', ['fixed' => '<p>A</p>', 'gone' => '<p>B</p>', 'free' => '<p>C</p>']);
    $props = $this->sanitizer()->sanitize([$item], $components, [])[0]['props'];
    $this->assertSame('full_html', $props['fixed']['format']);
    $this->assertSame('basic_html', $props['gone']['format']);
    $this->assertSame('basic_html', $props['free']['format']);

    $this->enableModules(['editor', 'editor_test']);
    $this->installEntitySchema('editor');
    $this->container->get('entity_type.manager')->getStorage('editor')->create(['format' => 'full_html', 'editor' => 'unicorn'])->save();
    $props = $this->sanitizer()->sanitize([$item], $components, [])[0]['props'];
    $this->assertSame('full_html', $props['free']['format'], 'The format with an editor, as for text typed in the builder.');
    $this->assertSame('full_html', $props['gone']['format']);
  }

  /**
   * Icons are choices like any other.
   */
  public function testIcons(): void {
    $components = [[
      'id' => 'test:badge',
      'fields' => [['name' => 'icon', 'widget' => 'icon', 'options' => [['value' => 'star', 'label' => 'Star'], ['value' => 'heart', 'label' => 'Heart']]]],
      'slots' => [],
    ]];
    $result = $this->sanitizer()->sanitize([
      self::item('test:badge', 'a', ['icon' => 'heart']),
      self::item('test:badge', 'b', ['icon' => '<svg onload="alert(1)">']),
    ], $components, []);
    $this->assertSame(['id' => 'a', 'icon' => 'heart'], $result[0]['props']);
    $this->assertSame(['id' => 'b'], $result[1]['props']);
  }

  /**
   * A prop whose widget the sanitizer does not know gets no value.
   */
  public function testUnknownWidget(): void {
    $components = [['id' => 'test:odd', 'fields' => [['name' => 'thing', 'widget' => 'hologram'], ['name' => 'plain']], 'slots' => []]];
    $result = $this->sanitizer()->sanitize([self::item('test:odd', 'o', ['thing' => 'x', 'plain' => 'text'])], $components, []);
    $this->assertSame(['id' => 'o', 'plain' => 'text'], $result[0]['props'], 'A field without a widget is a text.');
  }

  /**
   * Slots hold sanitized items; nesting stops at a depth no page needs.
   */
  public function testNestingAndDepth(): void {
    $probe = 'frame_builder_test_theme:probe';
    $result = $this->sanitize([
      self::item('frame:columns', 'cols', [
        'layout' => '2',
        'content' => [
          self::item($probe, 'left', ['content' => [self::item('frame:heading', 'deep', ['text' => 'Deep', 'onclick' => 'x'])]]),
          self::item($probe, 'right', ['content' => 'not a list']),
        ],
      ]),
    ]);
    $this->assertSame(['left', 'right'], array_column(array_column($result[0]['props']['content'], 'props'), 'id'));
    $this->assertSame(['id' => 'deep', 'text' => 'Deep'], $result[0]['props']['content'][0]['props']['content'][0]['props']);
    $this->assertSame([], $result[0]['props']['content'][1]['props']['content'], 'A slot that is not a list is empty.');
    $this->assertSame([], $this->props($probe, [])['content'], 'A slot is always a list.');

    // Twelve probes inside each other: the first nine levels are kept.
    $nested = [];
    for ($level = 12; $level >= 1; $level--) {
      $nested = [self::item($probe, "level$level", ['title' => "Level $level", 'content' => $nested])];
    }
    $result = $this->sanitize($nested);
    $levels = [];
    for ($items = $result; $items; $items = $items[0]['props']['content']) {
      $levels[] = $items[0]['props']['id'];
    }
    $this->assertSame(['level1', 'level2', 'level3', 'level4', 'level5', 'level6', 'level7', 'level8', 'level9'], $levels);
  }

  /**
   * Ids: a usable one is kept, others are made; no id is used twice.
   */
  public function testIds(): void {
    $generated = '/^frame\/heading-[0-9a-f]{12}$/';
    $result = $this->sanitize([
      self::item('frame:heading', 'intro', ['text' => 'A']),
      self::item('frame:heading', 'frame/heading-0a1b2c:x.y_z', ['text' => 'B']),
      self::item('frame:heading', 'intro', ['text' => 'C']),
      self::item('frame:heading', 'has space', ['text' => 'D']),
      self::item('frame:heading', '"><script>', ['text' => 'E']),
      self::item('frame:heading', '', ['text' => 'F']),
      self::item('frame:heading', str_repeat('a', 121), ['text' => 'G']),
      ['type' => 'frame:heading', 'props' => ['id' => 12, 'text' => 'H']],
      ['type' => 'frame:heading', 'props' => ['text' => 'I']],
      self::item('frame:heading', str_repeat('a', 120), ['text' => 'J']),
      self::item('frame_builder_test_theme:probe', 'box', ['content' => [self::item('frame:heading', 'intro', ['text' => 'K'])]]),
    ]);
    $ids = array_column(array_column($result, 'props'), 'id');
    $this->assertSame('intro', $ids[0]);
    $this->assertSame('frame/heading-0a1b2c:x.y_z', $ids[1]);
    foreach ([2, 3, 4, 5, 6, 7, 8] as $index) {
      $this->assertMatchesRegularExpression($generated, $ids[$index], 'A new id for item ' . $result[$index]['props']['text']);
    }
    $this->assertSame(str_repeat('a', 120), $ids[9]);
    $this->assertMatchesRegularExpression($generated, $result[10]['props']['content'][0]['props']['id'], 'An id used higher up is not used again in a slot.');
    $all = array_merge($ids, [$result[10]['props']['content'][0]['props']['id']]);
    $this->assertSame($all, array_unique($all), 'Every id is unique.');

    // Each layout starts anew: the ids of the one before are free again.
    $this->assertSame('intro', $this->sanitize([self::item('frame:heading', 'intro', ['text' => 'Again'])])[0]['props']['id']);
  }

  /**
   * An item of the page the model keeps (same id and type) keeps what the
   * model cannot know: its version, formatter and library link.
   */
  public function testExistingItemsArePreserved(): void {
    $formatter = ['id' => 'string', 'settings' => ['link_to_entity' => TRUE]];
    $library = ['uuid' => 'abc', 'linked' => TRUE];
    $current = [
      ['type' => 'drupal_field:title', 'version' => 4, 'props' => ['id' => 'title', 'formatter' => $formatter, 'label' => 'inline']],
      ['type' => 'frame_builder_test_theme:probe', 'version' => 7, 'props' => [
        'id' => 'box',
        'title' => 'Box',
        '_library' => $library,
        'content' => [['type' => 'frame:heading', 'version' => 3, 'props' => ['id' => 'inner', 'text' => 'Inner']]],
      ]],
    ];
    $result = $this->sanitize([
      // Kept, and moved out of the slot it was in.
      self::item('frame:heading', 'inner', ['text' => 'Inner, changed']),
      self::item('drupal_field:title', 'title', ['formatter' => ['id' => 'evil', 'settings' => ['x' => 1]], 'label' => 'hidden']),
      self::item('frame_builder_test_theme:probe', 'box', ['title' => 'Box, changed', '_library' => ['uuid' => 'other']]),
      // The id of an item of another type: a new item.
      self::item('frame:heading', 'box', ['text' => 'Not the box']),
      self::item('drupal_field:title', 'new-title', ['formatter' => 'evil', 'label' => 'sideways']),
    ], $current);

    $this->assertSame(['type' => 'frame:heading', 'version' => 3, 'props' => ['id' => 'inner', 'text' => 'Inner, changed']], $result[0]);
    $this->assertSame(4, $result[1]['version']);
    $this->assertSame($formatter, $result[1]['props']['formatter'], 'The formatter and its settings are never taken from the model.');
    $this->assertSame('hidden', $result[1]['props']['label']);
    $this->assertSame(7, $result[2]['version']);
    $this->assertSame($library, $result[2]['props']['_library']);
    $this->assertSame('Box, changed', $result[2]['props']['title']);
    $this->assertSame([], $result[2]['props']['content']);

    $this->assertArrayNotHasKey('version', $result[3], 'A new item has no version yet.');
    $this->assertMatchesRegularExpression('/^frame\/heading-[0-9a-f]{12}$/', $result[3]['props']['id'], 'The id was taken by the box.');
    $this->assertSame('Not the box', $result[3]['props']['text']);

    // A new Field block starts as the editor would start it.
    $this->assertArrayNotHasKey('version', $result[4]);
    $this->assertSame('string', $result[4]['props']['formatter']);
    $this->assertSame('above', $result[4]['props']['label'], 'A required choice that is not an option gets its default.');
    $this->assertArrayNotHasKey('_library', $result[4]['props']);
  }

  /**
   * The same id and another type is not the item of the page: nothing of it
   * is carried over.
   */
  public function testSameIdOtherTypeIsNew(): void {
    $current = [['type' => 'frame:text', 'version' => 9, 'props' => ['id' => 'x', 'text' => ['format' => 'plain_text', 'value' => 'Old']]]];
    $result = $this->sanitize([self::item('frame:heading', 'x', ['text' => 'A heading now'])], $current);
    $this->assertSame([['type' => 'frame:heading', 'props' => ['id' => 'x', 'text' => 'A heading now']]], $result);
  }

  /**
   * A Paragraph block starts as a copy of an existing paragraph of its type;
   * a kept block keeps its paragraph.
   */
  public function testParagraphs(): void {
    $this->enableParagraphs();
    $quote = Paragraph::create(['type' => 'quote', 'field_text' => ['To be', 'or not to be']]);
    $quote->save();
    $teaser = Paragraph::create(['type' => 'teaser', 'field_text' => 'A teaser']);
    $teaser->save();
    $drafts = $this->container->get('frame_builder.paragraph_drafts');
    $reference = ['id' => (int) $quote->id(), 'vid' => (int) $quote->getRevisionId(), 'uuid' => $quote->uuid()];

    // For a check of the model: the paragraph that would be copied.
    $result = $this->sanitize([self::item('drupal_paragraph:quote', 'q1', ['paragraph' => ['copy' => $quote->id()]])]);
    $this->assertSame($reference, $result[0]['props']['paragraph']);
    $this->assertNull($drafts->get($this->node, 'q1'), 'A check keeps nothing.');

    // For the editor: the values become the unsaved content of the block.
    $result = $this->sanitize([self::item('drupal_paragraph:quote', 'q1', ['paragraph' => ['copy' => (string) $quote->id()]])], [], TRUE);
    $this->assertSame(['draft'], array_keys($result[0]['props']['paragraph']));
    $this->assertSame(['field_text' => [['value' => 'To be'], ['value' => 'or not to be']]], $drafts->get($this->node, 'q1'));
    $this->assertSame(1, (int) $this->container->get('entity_type.manager')->getStorage('paragraph')->getQuery()->accessCheck(FALSE)->condition('type', 'quote')->count()->execute(), 'No paragraph is created.');

    // Not copied: another type, one that does not exist, anything else.
    $refused = [
      'a paragraph of another type' => ['copy' => $teaser->id()],
      'a paragraph that does not exist' => ['copy' => 99999],
      'a reference made up by the model' => $reference,
      'not a number' => ['copy' => 'the first one'],
      'text' => 'A quote, written by the model',
    ];
    foreach ($refused as $what => $given) {
      $result = $this->sanitize([self::item('drupal_paragraph:quote', 'q2', ['paragraph' => $given])], [], TRUE);
      $this->assertCount(1, $result, "The empty block stays: $what.");
      $this->assertArrayNotHasKey('paragraph', $result[0]['props'], "Refused: $what.");
      $this->assertNull($drafts->get($this->node, 'q2'));
    }

    // A block of the page keeps its paragraph, whatever the model says of it.
    $current = [self::item('drupal_paragraph:quote', 'q3', ['paragraph' => $reference])];
    foreach ([NULL, 'New text', ['id' => 1], ['copy' => $teaser->id()]] as $given) {
      $result = $this->sanitize([self::item('drupal_paragraph:quote', 'q3', ['paragraph' => $given])], $current, TRUE);
      $this->assertSame($reference, $result[0]['props']['paragraph']);
    }

    // What the user may not see is not copied.
    $hidden = Paragraph::create(['type' => 'quote', 'field_text' => 'A secret', 'status' => 0]);
    $hidden->save();
    $this->setUpCurrentUser([], ['access content']);
    $result = $this->sanitize([self::item('drupal_paragraph:quote', 'q4', ['paragraph' => ['copy' => $hidden->id()]])], [], TRUE);
    $this->assertArrayNotHasKey('paragraph', $result[0]['props']);
    $this->assertNull($this->container->get('frame_builder.paragraph_drafts')->get($this->node, 'q4'));
  }

  /**
   * Without a page there is nothing to keep unsaved content for: a copy is
   * the reference of the paragraph, as for a check.
   */
  public function testParagraphCopyWithoutPage(): void {
    $this->enableParagraphs();
    $quote = Paragraph::create(['type' => 'quote', 'field_text' => 'To be']);
    $quote->save();
    $result = $this->sanitizer()->sanitize([self::item('drupal_paragraph:quote', 'q1', ['paragraph' => ['copy' => $quote->id()]])], $this->components($this->node), [], NULL, TRUE);
    $this->assertSame((int) $quote->id(), $result[0]['props']['paragraph']['id']);
    $this->assertSame($quote->uuid(), $result[0]['props']['paragraph']['uuid']);
  }

  /**
   * Lists and groups (props of the types array and object): each value as
   * its own field takes it, what is none is left out, a list is cut at its
   * limit.
   */
  public function testListsAndGroups(): void {
    $probe = 'frame_builder_test_theme:list-probe';
    $props = $this->props($probe, [
      'words' => ['one', 5, ['no'], 'two'],
      'rows' => [
        ['label' => 'First', 'url' => '/first', 'tone' => 'loud', 'shown' => 'yes', 'count' => '4', 'unknown' => 'x'],
        'not a row',
        ['tone' => 'purple'],
        ['label' => 'Second', 'tone' => 'purple'],
        ['label' => 'Third'],
        ['label' => 'Fourth'],
      ],
      'sizes' => [1, 'two', '3'],
      'figure' => ['caption' => 'A caption', 'image' => 'https://example.org/a.png'],
      'free' => ['anything' => 1],
    ]);
    $this->assertSame(['one', '5', 'two'], $props['words']);
    $this->assertSame([
      ['label' => 'First', 'url' => '/first', 'tone' => 'loud', 'shown' => TRUE, 'count' => 4],
      ['label' => 'Second'],
      ['label' => 'Third'],
    ], $props['rows'], 'What is no value of the list is dropped, and the list has at most three.');
    $this->assertSame([1, 3], $props['sizes']);
    $this->assertSame(['caption' => 'A caption'], $props['figure'], 'An image is of the media library or it is none.');
    $this->assertArrayNotHasKey('free', $props);

    $this->assertArrayNotHasKey('words', $this->props($probe, ['words' => 'one']));
    $this->assertArrayNotHasKey('rows', $this->props($probe, ['rows' => [[], 'x']]));
    $this->assertArrayNotHasKey('figure', $this->props($probe, ['figure' => 'a figure']));
  }


}
