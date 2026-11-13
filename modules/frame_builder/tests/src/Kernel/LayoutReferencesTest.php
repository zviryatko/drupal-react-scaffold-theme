<?php

declare(strict_types=1);

namespace Drupal\Tests\frame_builder\Kernel;

use Drupal\frame_builder\LayoutReferences;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The media items, pages, saved blocks and paragraphs a layout refers to.
 */
#[Group('frame_builder')]
#[RunTestsInSeparateProcesses]
class LayoutReferencesTest extends BuilderKernelTestBase {

  protected const UUID_A = '11111111-1111-4111-8111-111111111111';
  protected const UUID_B = '22222222-2222-4222-8222-222222222222';

  /**
   * What is found when a layout refers to nothing.
   */
  protected const NOTHING = ['paragraph' => [], 'media' => [], 'media_uuid' => [], 'node' => [], 'saved' => []];

  /**
   * A layout without references gives every kind, empty.
   */
  public function testNothing(): void {
    $this->assertSame(self::NOTHING, LayoutReferences::find([]));
    $this->assertSame(self::NOTHING, LayoutReferences::find(self::layout(
      self::item('frame:heading', 'h1', ['text' => 'Plain', 'level' => 'h2']),
      self::item('frame:button', 'b1', ['label' => 'Out', 'url' => 'https://example.com/node/3']),
      self::item('frame:text', 't1', ['text' => ['format' => 'basic_html', 'value' => 'No markup']]),
    )));
  }

  /**
   * Values of props, one kind of reference at a time.
   */
  #[DataProvider('values')]
  public function testValue(mixed $value, array $expected): void {
    $found = LayoutReferences::find(self::layout(self::item('frame_builder_test_theme:probe', 'p1', ['value' => $value])));
    $this->assertSame(array_replace(self::NOTHING, $expected), $found);
  }

  /**
   * Data of testValue(): the value of a prop, what is found.
   */
  public static function values(): array {
    $html = static fn (string $value): array => ['format' => 'basic_html', 'value' => $value];
    return [
      'picked image' => [['media' => 12, 'url' => '/files/a.png', 'style' => 'thumbnail'], ['media' => [12]]],
      'picked image, id as text' => [['media' => '12'], ['media' => [12]]],
      'no image picked' => [['media' => 0, 'url' => ''], []],
      'negative media id' => [['media' => -4], []],
      'media that is not an id' => [['media' => 'abc'], []],
      'media is null' => [['media' => NULL], []],
      'image in a feature value' => [['image' => ['media' => 5], 'size' => 'cover'], ['media' => [5]]],
      'link to content' => ['entity:node/7', ['node' => [7]]],
      'link to content with a fragment' => ['entity:node/7#part', ['node' => [7]]],
      'link in a link value' => [['url' => 'entity:node/8', 'new_tab' => TRUE], ['node' => [8]]],
      'path of a page is not a picked link' => ['/node/7', []],
      'another entity type' => ['entity:user/7', []],
      'content named inside a text' => ['See entity:node/7', []],
      'content without an id' => ['entity:node/abc', []],
      'media embedded in rich text' => [
        $html('<p>Text</p><drupal-media data-entity-type="media" data-entity-uuid="' . self::UUID_A . '"></drupal-media>'),
        ['media_uuid' => [self::UUID_A]],
      ],
      'embedded media without a uuid' => [$html('<drupal-media data-entity-type="media"></drupal-media><drupal-media data-entity-uuid=""></drupal-media>'), []],
      'links of rich text' => [
        $html('<p><a href="/node/5">Path</a> <a href="entity:node/6">Content</a> <a href="/node/5">Again</a></p>'),
        ['node' => [5, 6]],
      ],
      'links of rich text that are not a page' => [
        $html('<a href="/node/5/edit">Edit</a> <a href="https://example.com/node/9">Out</a> <a href="/nodes/4">Other</a> <a>None</a> <a href="/user/3">User</a>'),
        [],
      ],
      'media and links of rich text, nested markup' => [
        $html('<div><ul><li><a href="/node/2"><drupal-media data-entity-uuid="' . self::UUID_B . '"></drupal-media></a></li></ul></div>'),
        ['media_uuid' => [self::UUID_B], 'node' => [2]],
      ],
      'markup that is not rich text' => ['<a href="/node/5">Not parsed</a>', []],
      'text with a format but no markup' => [$html('/node/5'), []],
      'value without a format' => [['value' => '<a href="/node/5">No format</a>'], []],
      'paragraph of a Paragraph block' => [
        ['id' => 3, 'vid' => 9, 'uuid' => self::UUID_A],
        ['paragraph' => [['id' => 3, 'vid' => 9]]],
      ],
      'paragraph, ids as text' => [
        ['id' => '3', 'vid' => '9', 'uuid' => self::UUID_A],
        ['paragraph' => [['id' => 3, 'vid' => 9]]],
      ],
      'not a paragraph: no uuid' => [['id' => 3, 'vid' => 9], []],
      'not a paragraph: no revision' => [['id' => 3, 'uuid' => self::UUID_A], []],
      'not a paragraph: ids are not numbers' => [['id' => 'x', 'vid' => 'y', 'uuid' => self::UUID_A], []],
      'scalars' => [[TRUE, 12, 1.5, NULL, 'media', '12'], []],
    ];
  }

  /**
   * References are found in slots at any depth, in the root and in items
   * of components that do not exist; each one is listed once.
   */
  public function testNestedAndRepeated(): void {
    $data = [
      'root' => ['props' => ['_background' => ['image' => ['media' => 1]]]],
      'content' => [
        self::item('frame:columns', 'cols', [
          '_background' => ['image' => ['media' => 2, 'style' => 'large']],
          'content' => [
            self::item('frame:card', 'card', [
              'image' => ['media' => 3],
              'url' => 'entity:node/10',
              'content' => [
                self::item('frame:text', 't1', ['text' => [
                  'format' => 'basic_html',
                  'value' => '<a href="/node/11">In</a><drupal-media data-entity-uuid="' . self::UUID_A . '"></drupal-media>',
                ]]),
                self::item('frame:image', 'i1', ['image' => ['media' => 3]]),
                self::item('gone:component', 'g1', [
                  'picture' => ['media' => 4],
                  'area' => [self::item('frame:button', 'b1', ['url' => 'entity:node/10'])],
                ]),
              ],
            ]),
          ],
        ]),
        self::item('frame:button', 'b2', ['label' => 'More', 'url' => 'entity:node/12']),
        self::item('frame:text', 't2', ['text' => [
          'format' => 'full_html',
          'value' => '<drupal-media data-entity-uuid="' . self::UUID_A . '"></drupal-media><drupal-media data-entity-uuid="' . self::UUID_B . '"></drupal-media>',
        ]]),
      ],
    ];
    $this->assertSame([
      'paragraph' => [],
      'media' => [1, 2, 3, 4],
      'media_uuid' => [self::UUID_A, self::UUID_B],
      'node' => [10, 11, 12],
      'saved' => [],
    ], LayoutReferences::find($data));
  }

  /**
   * Linked blocks of the library are found by the type of their item.
   */
  public function testSavedBlocks(): void {
    $found = LayoutReferences::find(self::layout(
      self::item('drupal_saved:' . self::UUID_A, 's1'),
      self::item('frame:columns', 'cols', ['content' => [
        self::item('drupal_saved:' . self::UUID_B, 's2'),
        self::item('drupal_saved:' . self::UUID_A, 's3'),
      ]]),
      // Not items: a type without props, and other kinds of component.
      ['type' => 'drupal_saved:' . self::UUID_B . '-no-props'],
      self::item('drupal_block:drupal_saved', 'blk'),
      self::item('drupal_savedx:abc', 'other'),
    ));
    $this->assertSame([self::UUID_A, self::UUID_B], $found['saved']);
    $this->assertSame(array_replace(self::NOTHING, ['saved' => [self::UUID_A, self::UUID_B]]), $found);
  }

  /**
   * Paragraphs of Paragraph blocks: one entry per revision.
   */
  public function testParagraphs(): void {
    $found = LayoutReferences::find(self::layout(
      self::item('drupal_paragraph:text', 'p1', ['paragraph' => ['id' => 3, 'vid' => 9, 'uuid' => self::UUID_A]]),
      self::item('frame:columns', 'cols', ['content' => [
        self::item('drupal_paragraph:text', 'p2', ['paragraph' => ['id' => 4, 'vid' => 12, 'uuid' => self::UUID_B]]),
        self::item('drupal_paragraph:text', 'p3', ['paragraph' => ['id' => 3, 'vid' => 9, 'uuid' => self::UUID_A]]),
        self::item('drupal_paragraph:text', 'p4', ['paragraph' => ['id' => 3, 'vid' => 15, 'uuid' => self::UUID_A]]),
        self::item('drupal_paragraph:text', 'p5', ['paragraph' => NULL]),
      ]]),
    ));
    $this->assertSame([
      ['id' => 3, 'vid' => 9],
      ['id' => 4, 'vid' => 12],
      ['id' => 3, 'vid' => 15],
    ], $found['paragraph']);
    $this->assertSame([], $found['media']);
  }

  /**
   * The translated values of a synced translation are searched like a
   * layout.
   */
  public function testStringsOfASyncedTranslation(): void {
    $found = LayoutReferences::find([
      'version' => 1,
      'strings' => [
        'h1.text' => 'Willkommen',
        'b1.url' => 'entity:node/21',
        't1.text' => ['format' => 'basic_html', 'value' => '<a href="/node/22">Seite</a><drupal-media data-entity-uuid="' . self::UUID_B . '"></drupal-media>'],
        'p1.paragraph' => ['id' => 6, 'vid' => 30, 'uuid' => self::UUID_A],
      ],
      'versions' => ['h1' => 1, 'b1' => 1, 't1' => 1, 'p1' => 1],
    ]);
    $this->assertSame([
      'paragraph' => [['id' => 6, 'vid' => 30]],
      'media' => [],
      'media_uuid' => [self::UUID_B],
      'node' => [21, 22],
      'saved' => [],
    ], $found);
  }

  /**
   * What a stored layout refers to is found from the document on the node,
   * whatever theme is active.
   */
  public function testStoredLayout(): void {
    $node = $this->saveLayout($this->createBuilderNode(), self::layout(
      self::item('frame:image', 'i1', ['image' => ['media' => 40, 'style' => 'thumbnail']]),
      self::item('frame:button', 'b1', ['label' => 'Go', 'url' => 'entity:node/41']),
    ));
    $this->container->get('theme_installer')->install(['stark']);
    $this->container->get('theme.manager')->setActiveTheme($this->container->get('theme.initialization')->initTheme('stark'));
    $found = LayoutReferences::find(json_decode($node->get('frame_layout')->value, TRUE));
    $this->assertSame([40], $found['media']);
    $this->assertSame([41], $found['node']);
  }

}
