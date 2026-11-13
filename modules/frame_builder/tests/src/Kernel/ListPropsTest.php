<?php

declare(strict_types=1);

namespace Drupal\Tests\frame_builder\Kernel;

use Drupal\filter\Entity\FilterFormat;
use Drupal\Tests\frame_builder\Traits\LayoutTranslationTestTrait;
use Drupal\Tests\frame_builder\Traits\RenderingTestTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Props of the types array and object: a list of values with a field per
 * value, and a group of fields.
 */
#[Group('frame_builder')]
#[RunTestsInSeparateProcesses]
class ListPropsTest extends BuilderKernelTestBase {

  use LayoutTranslationTestTrait;
  use RenderingTestTrait;

  protected const LISTS = 'frame_builder_test_theme:list-probe';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['language', 'content_translation', 'file', 'image', 'media', 'breakpoint', 'responsive_image'];

  /**
   * The editor fields of the component, by name.
   */
  protected function fields(string $id = self::LISTS): array {
    $config = array_column($this->container->get('frame_builder.catalog')->editorConfig($this->createBuilderNode()), NULL, 'id');
    return array_column($config[$id]['fields'], NULL, 'name');
  }

  /**
   * The HTML of one item.
   */
  protected function html(array $props, string $type = self::LISTS): string {
    return $this->renderHtml($this->container->get('frame_builder.renderer')->buildItem(self::item($type, 'l1', $props)));
  }

  /**
   * A list of plain values has the field of one value; a list of objects
   * and an object have the fields of their properties.
   */
  public function testFields(): void {
    $fields = $this->fields();
    $this->assertSame('list', $fields['words']['widget']);
    $this->assertSame('text', $fields['words']['item']['widget']);
    $this->assertSame('Word', $fields['words']['item']['label']);
    $this->assertArrayNotHasKey('fields', $fields['words']);
    $this->assertTrue($fields['words']['translatable']);

    $rows = $fields['rows'];
    $this->assertSame('list', $rows['widget']);
    $this->assertSame(3, $rows['max']);
    $this->assertArrayNotHasKey('min', $rows);
    $this->assertSame(
      ['label' => 'text', 'url' => 'url', 'body' => 'html', 'tone' => 'select', 'shown' => 'boolean', 'count' => 'number'],
      array_column($rows['fields'], 'widget', 'name'),
    );
    $required = array_column($rows['fields'], 'required', 'name');
    $this->assertTrue($required['label']);
    $this->assertFalse($required['url']);
    $this->assertSame(['calm', 'loud'], array_column(array_column($rows['fields'], NULL, 'name')['tone']['options'], 'value'));
    $this->assertTrue($rows['translatable'], 'A list with text in it is in the language of the page.');

    $this->assertSame('number', $fields['sizes']['item']['widget']);
    $this->assertFalse($fields['sizes']['translatable']);

    $this->assertSame('group', $fields['figure']['widget']);
    $this->assertSame(['image' => 'image', 'caption' => 'text'], array_column($fields['figure']['fields'], 'widget', 'name'));
    $this->assertArrayNotHasKey('max', $fields['figure']);

    $this->assertArrayNotHasKey('free', $fields, 'An object that does not say what is in it has no field.');
    $this->assertArrayNotHasKey('any', $fields, 'Nor does a list that does not say what its values are.');
  }

  /**
   * The base theme's Links component: a list of links.
   */
  public function testLinksComponent(): void {
    $links = $this->fields('frame:link-list')['links'];
    $this->assertSame('list', $links['widget']);
    $this->assertSame(['label', 'url', 'new_tab'], array_column($links['fields'], 'name'));
    $this->assertSame([['label' => 'About us', 'url' => '/'], ['label' => 'Contact', 'url' => '/']], $links['default']);
    $this->assertContains('links', $this->container->get('frame_builder.catalog')->translatableProps('frame:link-list'));

    $node = $this->createBuilderNode(['title' => 'Target']);
    $html = $this->html([
      'heading' => 'More',
      'links' => [
        ['label' => 'To [site:name]', 'url' => 'entity:node/' . $node->id()],
        ['label' => 'No link'],
        ['label' => 'Out', 'url' => 'https://example.org/a', 'new_tab' => TRUE],
        ['label' => 'Bad', 'url' => 'javascript:alert(1)'],
      ],
    ], 'frame:link-list');
    $this->assertStringContainsString('href="/node/' . $node->id() . '"', $html);
    $this->assertStringContainsString('To ' . $this->config('system.site')->get('name'), $html, 'Tokens are replaced in the values of a list.');
    $this->assertStringNotContainsString('No link', $html);
    $this->assertStringContainsString('href="https://example.org/a" target="_blank"', $html);
    $this->assertStringNotContainsString('javascript:', $html);
    $this->assertSame(3, substr_count($html, '<li'));
  }

  /**
   * Values are cast and filtered one by one, as props are.
   */
  public function testRenderedValues(): void {
    FilterFormat::create(['format' => 'plain', 'name' => 'Plain', 'filters' => ['filter_html_escape' => ['status' => TRUE]]])->save();
    $html = $this->html([
      'words' => ['one', '', 'two', ['not', 'text']],
      'rows' => [
        ['label' => 'First', 'body' => ['value' => '<b>bold</b>', 'format' => 'plain'], 'tone' => 'loud', 'shown' => 'true', 'count' => '7'],
        ['label' => 'Second', 'url' => '/second', 'count' => 'many'],
        'not a row',
        [],
      ],
      'sizes' => ['3', 4, 'x'],
    ]);
    $this->assertSame(2, substr_count($html, 'list-probe__word'));
    $this->assertStringContainsString('list-probe__row--loud" data-count="7" data-shown', $html);
    $this->assertStringContainsString('&lt;b&gt;bold&lt;/b&gt;', $html, 'Formatted text in a list goes through its text format.');
    $this->assertStringContainsString('<a href="/second">Second</a>', $html);
    $this->assertStringContainsString('list-probe__row--none" data-count=""', $html, 'A choice left empty stays empty when the schema names no default; a number that is none is left out.');
    $this->assertSame(2, substr_count($html, 'list-probe__row '), 'What is no object, and an object without values, is no row.');
    $this->assertSame(2, substr_count($html, 'list-probe__size'));

    $this->assertStringNotContainsString('list-probe__word', $this->html(['words' => 'one']), 'A value that is no list is no list.');
    $this->assertStringNotContainsString('<figure', $this->html(['figure' => ['caption' => '']]));
    $this->assertStringContainsString('<figcaption>Hills</figcaption>', $this->html(['figure' => ['caption' => 'Hills']]));
  }

  /**
   * An image in a group is the URL of the picked media item.
   */
  public function testImageInAGroup(): void {
    $this->setUpImageMedia();
    $media = $this->createImageMedia('A red square');
    $html = $this->html(['figure' => ['image' => ['media' => $media->id(), 'url' => '/original.png', 'style' => 'style:thumbnail'], 'caption' => 'Square']]);
    $this->assertMatchesRegularExpression('#<img src="[^"]*/styles/thumbnail/[^"]*picture-\d+\.png[^"]*"#', $html, 'The image of the picked media item, in the chosen image style.');
    $this->assertStringContainsString('<figcaption>Square</figcaption>', $html);
  }

  /**
   * Formatted text inside lists is found, so that its format is checked
   * when a layout is saved.
   */
  public function testHtmlValues(): void {
    $items = [self::item(self::LISTS, 'l1', [
      'rows' => [
        ['label' => 'A', 'body' => ['value' => '<p>one</p>', 'format' => 'full']],
        ['label' => 'B'],
        ['label' => 'C', 'body' => ['value' => '<p>two</p>', 'format' => 'basic']],
      ],
      'words' => ['x'],
    ])];
    $this->assertSame([['full', '<p>one</p>'], ['basic', '<p>two</p>']], $this->container->get('frame_builder.catalog')->htmlValues($items));
  }

  /**
   * In synced mode a list that differs from the original's is a value of
   * the translation; a list that is not translatable stays the original's.
   */
  public function testSyncedTranslation(): void {
    $this->setUpTranslation('synced');
    $layouts = $this->container->get('frame_builder.layouts');
    $rows = [['label' => 'One', 'url' => '/one'], ['label' => 'Two', 'url' => '/two']];
    $node = $this->createBuilderNode();
    $this->saveLayout($node, self::layout(self::item(self::LISTS, 'l1', ['rows' => $rows, 'sizes' => [1, 2], 'words' => ['a']])));

    $german = $this->translate($node);
    $translated = [['label' => 'Eins', 'url' => '/one'], ['label' => 'Zwei', 'url' => '/two']];
    $layouts->store($german, self::layout(self::item(self::LISTS, 'l1', ['rows' => $translated, 'sizes' => [9], 'words' => ['a']])));
    $german->save();

    $this->assertSame(['l1.rows' => $translated], $this->stored($this->reload($node, 'de'))['strings'], 'Only the list that changed is kept, and not the one that is not translatable.');
    $props = $layouts->load($this->reload($node, 'de'))['content'][0]['props'];
    $this->assertSame($translated, $props['rows']);
    $this->assertSame([1, 2], $props['sizes']);
    $this->assertSame($rows, $layouts->load($this->reload($node))['content'][0]['props']['rows']);
  }

  /**
   * A list says what a new value starts with and which property names a
   * value; an image can be an object with more than its URL.
   */
  public function testStartSummaryAndImageObject(): void {
    $gallery = $this->fields()['gallery'];
    $this->assertSame(['caption' => 'A new picture', 'credit' => 'Staff'], $gallery['start']);
    $this->assertSame('caption', $gallery['summary']);
    $this->assertSame(2, $gallery['min']);
    $this->assertSame('image', array_column($gallery['fields'], 'widget', 'name')['picture']);
    $this->assertArrayNotHasKey('start', $this->fields()['rows'], 'Without an example a new value starts with what its fields start with.');
    $this->assertArrayNotHasKey('summary', $this->fields()['rows']);

    $this->setUpImageMedia();
    $media = $this->createImageMedia('A red square');
    $html = $this->html(['gallery' => [
      ['caption' => 'One', 'picture' => ['media' => $media->id(), 'url' => '/x.png', 'style' => 'style:thumbnail']],
      ['caption' => 'No picture'],
    ]]);
    $this->assertMatchesRegularExpression('#<img class="list-probe__shot" src="[^"]*/styles/thumbnail/[^"]*" alt="A red square" width="100" height="\d+" data-srcset="" title="One">#', $html, 'URL, alternative text and size of the image; not srcset, which the schema does not name.');
    $this->assertStringContainsString('title="No picture"', $html);
  }

  /**
   * What a component requires must be there when a layout is saved: its
   * props, and what is in its lists and groups.
   */
  public function testMissing(): void {
    $catalog = $this->container->get('frame_builder.catalog');
    $picture = ['media' => 5, 'url' => '/x.png'];
    $complete = self::item(self::LISTS, 'l1', [
      'rows' => [['label' => 'A'], ['label' => 'B', 'count' => 0, 'shown' => FALSE]],
      'gallery' => [['caption' => 'One', 'picture' => $picture], ['caption' => 'Two', 'picture' => $picture]],
    ]);
    $this->assertSame([], $catalog->missing([$complete, self::item('frame_builder_test_theme:probe', 'p')]));
    $this->assertSame([], $catalog->missing([self::item(self::LISTS, 'l1', [])]), 'A list that is not required can be empty.');

    $broken = self::item('frame:columns', 'c', ['content' => [
      self::item(self::LISTS, 'l1', [
        'rows' => [['label' => 'A'], ['label' => '  ', 'url' => '/x'], ['url' => '/y']],
        'gallery' => [['caption' => 'One', 'picture' => ['media' => 0, 'url' => '']]],
      ]),
      self::item('frame:button', 'b', ['label' => 'Go', 'url' => '']),
      self::item('frame:link-list', 'k', ['links' => [['label' => 'Home']]]),
    ]]);
    $this->assertSame([
      'List probe › Rows 2: Label is required.',
      'List probe › Rows 3: Label is required.',
      'List probe › Gallery 1: Picture is required.',
      'List probe: Gallery needs at least 2.',
      'Button: Link is required.',
      'Links › Links 1: Link is required.',
    ], $catalog->missing([$broken]), 'Also in the slots of other components.');
    $this->assertSame([], $catalog->missing([self::item('no:such', 'x', ['label' => ''])]), 'What is no component of the theme requires nothing.');
  }

}
