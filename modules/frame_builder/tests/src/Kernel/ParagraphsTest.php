<?php

declare(strict_types=1);

namespace Drupal\Tests\frame_builder\Kernel;

use Drupal\frame_builder\Paragraphs;
use Drupal\node\Entity\Node;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Paragraph types as blocks of the builder: what a block names, what is
 * shown for it, and how it is offered and rendered.
 */
#[Group('frame_builder')]
#[RunTestsInSeparateProcesses]
class ParagraphsTest extends ParagraphsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['language'];

  /**
   * The service under test.
   */
  protected Paragraphs $paragraphs;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->paragraphs = $this->container->get('frame_builder.paragraphs');
  }

  /**
   * With the modules installed paragraphs are available, nodes list the
   * paragraphs of their layout, and the types are given by label.
   */
  public function testAvailableAndTypes(): void {
    $this->assertTrue($this->paragraphs->available());
    $this->assertFalse($this->paragraphs->libraryAvailable(), 'Without the Paragraphs Library module there is no such library.');
    $this->assertSame([], $this->paragraphs->libraryItems());
    $this->assertNull($this->paragraphs->libraryItem('0b6f0000-0000-4000-8000-000000000000'));

    $definition = $this->container->get('entity_field.manager')->getBaseFieldDefinitions('node')[Paragraphs::FIELD] ?? NULL;
    $this->assertNotNull($definition, 'Nodes have the field that lists the paragraphs of a layout.');
    $this->assertSame('entity_reference_revisions', $definition->getType());
    $this->assertSame('paragraph', $definition->getSetting('target_type'));
    $this->assertTrue($definition->isRevisionable());

    $this->createParagraphType('quote10', 'quote 10', FALSE, 'Words of someone.');
    $this->createParagraphType('quote2', 'Quote 2');
    $this->createParagraphType('accordion', 'accordion');
    $types = $this->paragraphs->types();
    $this->assertSame(['accordion', 'quote2', 'quote10', 'text_block'], array_keys($types), 'By label, without regard to case, numbers in their natural order.');
    $this->assertSame(['label' => 'quote 10', 'description' => 'Words of someone.'], $types['quote10']);
    $this->assertSame(['label' => 'Text block', 'description' => ''], $types['text_block']);
  }

  /**
   * A reference is the id, the revision and the UUID of a paragraph.
   */
  public function testReference(): void {
    $paragraph = $this->createParagraph('One');
    $reference = Paragraphs::reference($paragraph);
    $this->assertSame(['id' => (int) $paragraph->id(), 'vid' => (int) $paragraph->getRevisionId(), 'uuid' => $paragraph->uuid()], $reference);
    $this->assertIsInt($reference['id']);
    $this->assertIsInt($reference['vid']);
  }

  /**
   * The paragraph is loaded in the revision the layout names.
   */
  public function testLoad(): void {
    $paragraph = $this->createParagraph('First');
    $first = Paragraphs::reference($paragraph);
    $second = Paragraphs::reference($this->reviseParagraph($paragraph, 'Second'));
    $this->assertSame($first['id'], $second['id']);
    $this->assertGreaterThan($first['vid'], $second['vid']);

    $this->assertSame('First', self::paragraphText($this->paragraphs->load($first, 'text_block')), 'The named revision, not the current one.');
    $this->assertSame('Second', self::paragraphText($this->paragraphs->load($second, 'text_block')));
    // What the editor sends in a query: strings.
    $this->assertSame('First', self::paragraphText($this->paragraphs->load(['id' => (string) $first['id'], 'vid' => (string) $first['vid']], 'text_block')));
    $this->assertSame('Second', self::paragraphText($this->paragraphs->load(['id' => $first['id']], 'text_block')), 'Without a revision: the paragraph as it is now.');
    $this->assertSame('Second', self::paragraphText($this->paragraphs->load(['id' => $first['id'], 'vid' => 9999], 'text_block')), 'A revision that is gone: the paragraph as it is now.');
    $this->assertSame('First', self::paragraphText($this->paragraphs->load(['vid' => $first['vid']], 'text_block')), 'The revision alone is enough.');
  }

  /**
   * What does not name a paragraph of the type of the block loads nothing.
   */
  public function testLoadRefuses(): void {
    $this->createParagraphType('quote', 'Quote');
    $paragraph = $this->createParagraph('Text');
    $other = $this->createParagraph('Other');
    $reference = Paragraphs::reference($paragraph);

    $this->assertNull($this->paragraphs->load(NULL, 'text_block'));
    $this->assertNull($this->paragraphs->load('12', 'text_block'));
    $this->assertNull($this->paragraphs->load([], 'text_block'));
    $this->assertNull($this->paragraphs->load(['id' => 9999, 'vid' => 9999], 'text_block'));
    $this->assertNull($this->paragraphs->load($reference, 'quote'), 'A paragraph of another type than the block.');
    $this->assertNull($this->paragraphs->load(['id' => $other->id(), 'vid' => $reference['vid']], 'text_block'), 'A revision of another paragraph than the one named.');
    $this->assertNotNull($this->paragraphs->load($reference, 'text_block'));
  }

  /**
   * A block shows the paragraph it names, in the revision it names; a block
   * that names nothing shows nothing.
   */
  public function testForDisplay(): void {
    $node = $this->createBuilderNode();
    $paragraph = $this->createParagraph('First', $node);
    $first = Paragraphs::reference($paragraph);
    $second = Paragraphs::reference($this->reviseParagraph($paragraph, 'Second'));

    $this->assertNull($this->paragraphs->forDisplay(['id' => 'b1'], 'text_block', $node));
    $this->assertNull($this->paragraphs->forDisplay(['id' => 'b1', 'paragraph' => ['id' => 9999, 'vid' => 9999, 'uuid' => 'x']], 'text_block', $node));
    $this->assertNull($this->paragraphs->forDisplay(['id' => 'b1', 'paragraph' => $first], 'missing_type', $node));
    $this->assertSame('First', self::paragraphText($this->paragraphs->forDisplay(['id' => 'b1', 'paragraph' => $first], 'text_block', $node)));
    $this->assertSame('Second', self::paragraphText($this->paragraphs->forDisplay(['id' => 'b1', 'paragraph' => $second], 'text_block', $node)));
    $this->assertSame('Second', self::paragraphText($this->paragraphs->forDisplay(['id' => 'b1', 'paragraph' => $second], 'text_block', NULL)), 'A paragraph is shown without a page too.');
  }

  /**
   * Unsaved values are shown only after showDrafts(), and never change the
   * loaded paragraph.
   */
  public function testForDisplayWithDrafts(): void {
    $drafts = $this->container->get('frame_builder.paragraph_drafts');
    $node = $this->createBuilderNode();
    $paragraph = $this->createParagraph('Saved', $node);
    $props = ['id' => 'b1', 'paragraph' => Paragraphs::reference($paragraph)];
    $drafts->set($node, 'b1', [self::TEXT_FIELD => [['value' => 'Typed']]]);
    $drafts->set($node, 'new', [self::TEXT_FIELD => [['value' => 'Typed in a new block']]]);

    $this->assertSame('Saved', self::paragraphText($this->paragraphs->forDisplay($props, 'text_block', $node)), 'The page itself never shows unsaved values.');
    $this->assertNull($this->paragraphs->forDisplay(['id' => 'new'], 'text_block', $node));

    $this->paragraphs->showDrafts();
    $shown = $this->paragraphs->forDisplay($props, 'text_block', $node);
    $this->assertSame('Typed', self::paragraphText($shown));
    $this->assertSame($paragraph->id(), $shown->id());
    $this->assertFalse($shown->isNew());
    $this->assertSame('Saved', self::paragraphText($this->paragraphs->load($props['paragraph'], 'text_block')), 'The loaded paragraph, shared within the request, is not changed.');
    $this->assertSame('Saved', self::paragraphText($this->reloadParagraph($paragraph)), 'Nothing is saved.');

    $new = $this->paragraphs->forDisplay(['id' => 'new'], 'text_block', $node);
    $this->assertTrue($new->isNew(), 'A block without a paragraph shows a new one with the unsaved values.');
    $this->assertSame('text_block', $new->bundle());
    $this->assertSame('Typed in a new block', self::paragraphText($new));

    $this->assertSame('Saved', self::paragraphText($this->paragraphs->forDisplay(['id' => 'other', 'paragraph' => $props['paragraph']], 'text_block', $node)), 'The values are of one block.');
    $this->assertNull($this->paragraphs->forDisplay(['id' => 'new'], 'text_block', $this->createBuilderNode()), 'And of one page.');
    $this->assertNull($this->paragraphs->forDisplay(['id' => 'new'], 'text_block', NULL));
  }

  /**
   * A paragraph is shown in the language of the page when it has that
   * translation.
   */
  public function testForDisplayInTheLanguageOfThePage(): void {
    $this->addLanguage('de');
    $node = $this->createBuilderNode();
    $german = $node->addTranslation('de', ['title' => 'Eine Seite']);
    $node->save();
    $paragraph = $this->createParagraph('Hello', $node);
    $paragraph->addTranslation('de', [self::TEXT_FIELD => 'Hallo']);
    $paragraph->save();
    $props = ['id' => 'b1', 'paragraph' => Paragraphs::reference($paragraph)];

    $this->assertSame('Hello', self::paragraphText($this->paragraphs->forDisplay($props, 'text_block', $node)));
    $shown = $this->paragraphs->forDisplay($props, 'text_block', $german);
    $this->assertSame('de', $shown->language()->getId());
    $this->assertSame('Hallo', self::paragraphText($shown));

    $untranslated = $this->createParagraph('Only English', $node);
    $shown = $this->paragraphs->forDisplay(['id' => 'b2', 'paragraph' => Paragraphs::reference($untranslated)], 'text_block', $german);
    $this->assertSame('Only English', self::paragraphText($shown), 'Without the translation: the paragraph as it is.');

    // A new block of a translated page is in the language of the page.
    $this->container->get('frame_builder.paragraph_drafts')->set($german, 'new', [self::TEXT_FIELD => 'Neu']);
    $this->paragraphs->showDrafts();
    $new = $this->paragraphs->forDisplay(['id' => 'new'], 'text_block', $german);
    $this->assertSame('de', $new->language()->getId());
    $this->assertSame('Neu', self::paragraphText($new));
    $this->assertNull($this->paragraphs->forDisplay(['id' => 'new'], 'text_block', $node), 'The unsaved values are of one language of the page.');
  }

  /**
   * The paragraphs of a layout, wherever they are in it, are the values of
   * the field of the page that lists them.
   */
  public function testFieldValues(): void {
    $paragraph = $this->createParagraph('First');
    $first = Paragraphs::reference($paragraph);
    $second = Paragraphs::reference($this->reviseParagraph($paragraph, 'Second'));
    $nested = Paragraphs::reference($this->createParagraph('Nested'));

    $this->assertSame([], $this->paragraphs->fieldValues([]));
    $this->assertSame([], $this->paragraphs->fieldValues(self::layout(self::paragraphItem('empty'), self::item('frame_builder_test_theme:probe', 'p'))));

    $data = self::layout(
      self::paragraphItem('b1', $first),
      self::item('frame_builder_test_theme:probe', 'p', ['content' => [self::paragraphItem('b2', $nested)]]),
      // A duplicated block: the same revision once.
      self::paragraphItem('b3', $first),
      // Two revisions of one paragraph are two values.
      self::paragraphItem('b4', $second),
      // A paragraph that does not exist is not listed.
      self::paragraphItem('b5', ['id' => 9999, 'vid' => 9999, 'uuid' => 'gone']),
    );
    $this->assertSame([
      ['target_id' => $first['id'], 'target_revision_id' => $first['vid']],
      ['target_id' => $nested['id'], 'target_revision_id' => $nested['vid']],
      ['target_id' => $second['id'], 'target_revision_id' => $second['vid']],
    ], $this->paragraphs->fieldValues($data));
  }

  /**
   * Storing a layout fills the field of the page with its paragraphs, per
   * revision of the page.
   */
  public function testStoreListsTheParagraphsOnThePage(): void {
    $node = $this->createBuilderNode();
    $paragraph = $this->createParagraph('First', $node);
    $other = $this->createParagraph('Other', $node);
    $this->saveLayout($node, self::layout(self::paragraphItem('b1', $paragraph), self::paragraphItem('b2', $other)));
    $with_both = $node->getRevisionId();
    $this->assertSame(
      [[(int) $paragraph->id(), (int) $paragraph->getRevisionId()], [(int) $other->id(), (int) $other->getRevisionId()]],
      array_map(static fn (array $value): array => [(int) $value['target_id'], (int) $value['target_revision_id']], Node::load($node->id())->get(Paragraphs::FIELD)->getValue()),
    );

    // The second block is removed from the page.
    $node->setNewRevision(TRUE);
    $this->saveLayout($node, self::layout(self::paragraphItem('b1', $paragraph)));
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $storage->resetCache();
    $this->assertSame([$paragraph->id()], array_column($storage->load($node->id())->get(Paragraphs::FIELD)->getValue(), 'target_id'));
    $this->assertCount(2, $storage->loadRevision($with_both)->get(Paragraphs::FIELD), 'The older revision of the page keeps its paragraphs.');
    $this->assertNotNull($this->reloadParagraph($other), 'The paragraph itself stays: an older revision of the page shows it.');

    $node->setNewRevision(TRUE);
    $this->saveLayout($node, self::layout(self::item('frame_builder_test_theme:probe', 'p')));
    $storage->resetCache();
    $this->assertTrue($storage->load($node->id())->get(Paragraphs::FIELD)->isEmpty());
  }

  /**
   * Every paragraph type is a component of the Paragraphs group, with the
   * form of the paragraph as its field and the offer to save to the library.
   */
  public function testCatalogOffersParagraphTypes(): void {
    $this->createParagraphType('quote', 'Quote', FALSE, 'Words of someone.');
    $config = $this->container->get('frame_builder.catalog')->editorConfig($this->createBuilderNode());

    $component = self::component($config, 'drupal_paragraph:text_block');
    $this->assertNotNull($component);
    $this->assertSame('Text block', $component['label']);
    $this->assertSame('Paragraphs', $component['group']);
    $this->assertSame('paragraph', $component['icon']);
    $this->assertSame('A paragraph, edited with its own form.', $component['description']);
    $this->assertSame([], $component['slots']);
    $this->assertArrayNotHasKey('hidden', $component);
    $this->assertSame('Words of someone.', self::component($config, 'drupal_paragraph:quote')['description']);

    $field = self::componentField($component, 'paragraph');
    $this->assertSame('paragraph', $field['widget']);
    $this->assertSame('text_block', $field['bundle']);
    $this->assertTrue($field['translatable'], 'A translation names its own revision of the paragraph.');

    // The Library box: under the form, above the layout options.
    $names = array_column($component['fields'], 'name');
    $this->assertSame(['paragraph', '_library'], array_slice($names, 0, 2));
    $this->assertContains('_spacing', $names, 'A Paragraph block has the layout options every block has.');
    $library = self::componentField($component, '_library');
    $this->assertSame('library', $library['widget']);
    $this->assertTrue($library['offer'], 'A Paragraph block can be saved to the library.');
    $this->assertFalse(self::componentField(self::component($config, 'frame_builder_test_theme:probe'), '_library')['offer'], 'Other components are not offered the library.');
  }

  /**
   * A Paragraph block is rendered by Drupal as the paragraph, in the
   * revision the layout names.
   */
  public function testRender(): void {
    $renderer = $this->container->get('frame_builder.renderer');
    $node = $this->createBuilderNode();
    $paragraph = $this->createParagraph('First text', $node);
    $first = Paragraphs::reference($paragraph);
    $second = Paragraphs::reference($this->reviseParagraph($paragraph, 'Second text'));

    $build = $renderer->buildItem(self::paragraphItem('b1', $second), $node);
    $this->assertSame('paragraph', $build['#theme'] ?? NULL);
    $this->assertSame('default', $build['#view_mode']);
    $html = $this->html($build);
    $this->assertStringContainsString('Second text', $html);
    $this->assertStringContainsString('paragraph--type--text-block', $html);
    $this->assertStringNotContainsString('First text', $html);
    $this->assertStringContainsString('First text', $this->html($renderer->buildItem(self::paragraphItem('b1', $first), $node)), 'An older revision of the page keeps showing the paragraph as it was.');

    $this->assertSame([], $renderer->buildItem(self::paragraphItem('empty'), $node), 'A block whose form was never filled in shows nothing.');
    $this->assertSame([], $renderer->buildItem(self::paragraphItem('b1', $second, 'missing_type'), $node));

    // In a slot of a component, and in a whole layout.
    $html = $this->html($renderer->build(self::layout(
      self::item('frame_builder_test_theme:probe', 'p', ['title' => 'Around', 'content' => [self::paragraphItem('b1', $second)]]),
      self::paragraphItem('empty'),
    ), $node));
    $this->assertMatchesRegularExpression('#probe__content.*Second text#s', $html);
  }

  /**
   * The layout options of a Paragraph block are on an element around the
   * paragraph.
   */
  public function testRenderWithLayoutOptions(): void {
    $node = $this->createBuilderNode();
    $paragraph = $this->createParagraph('Padded', $node);
    $build = $this->container->get('frame_builder.renderer')->buildItem(self::paragraphItem('b1', $paragraph, 'text_block', ['_spacing' => ['padding' => ['size' => 'm']]]), $node);
    $this->assertSame('container', $build['#type']);
    $this->assertStringContainsString('padding', $build['#attributes']['style']);
    $this->assertStringContainsString('1rem', $build['#attributes']['style'], 'The "m" spacing of the theme.');
    $this->assertSame('paragraph', $build['content']['#theme']);
    $this->assertMatchesRegularExpression('#<div style="[^"]*padding[^"]*">.*Padded#s', $this->html($build));
  }

  /**
   * Unsaved values are rendered in the editor only; a paragraph the user
   * may not see is not rendered.
   */
  public function testRenderDraftsAndAccess(): void {
    $renderer = $this->container->get('frame_builder.renderer');
    $node = $this->createBuilderNode();
    $paragraph = $this->createParagraph('Saved text', $node);
    $drafts = $this->container->get('frame_builder.paragraph_drafts');
    $drafts->set($node, 'b1', [self::TEXT_FIELD => 'Typed text']);
    $drafts->set($node, 'new', [self::TEXT_FIELD => 'New text']);

    $this->assertStringContainsString('Saved text', $this->html($renderer->buildItem(self::paragraphItem('b1', $paragraph), $node)));
    $this->assertSame([], $renderer->buildItem(self::paragraphItem('new'), $node));

    $this->paragraphs->showDrafts();
    $html = $this->html($renderer->buildItem(self::paragraphItem('b1', $paragraph), $node));
    $this->assertStringContainsString('Typed text', $html);
    $this->assertStringNotContainsString('Saved text', $html);
    $this->assertStringContainsString('New text', $this->html($renderer->buildItem(self::paragraphItem('new'), $node)), 'A paragraph that is not saved yet is the user\'s own draft.');

    // An unpublished paragraph is shown to nobody without the permission.
    $hidden = $this->createParagraph('Hidden text', $node);
    $hidden->setUnpublished()->save();
    $this->setUpCurrentUser([], ['access content']);
    $this->assertSame([], $renderer->buildItem(self::paragraphItem('b2', $hidden), $node));
    $this->assertStringContainsString('Saved text', $this->html($renderer->buildItem(self::paragraphItem('b3', $paragraph), $node)));
  }

}
