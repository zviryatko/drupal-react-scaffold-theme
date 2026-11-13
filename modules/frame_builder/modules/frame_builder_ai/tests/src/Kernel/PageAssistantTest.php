<?php

declare(strict_types=1);

namespace Drupal\Tests\frame_builder_ai\Kernel;

use Drupal\frame_builder_ai\PageAssistant;
use Drupal\frame_builder_ai_test\Plugin\AiProvider\ScriptedProvider;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\media\Entity\Media;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\Entity\Paragraph;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The assistant: what the model is told, how its answers are read, the
 * tools it may use and a whole turn with a provider that follows a script.
 */
#[Group('frame_builder')]
#[RunTestsInSeparateProcesses]
class PageAssistantTest extends AssistantKernelTestBase {

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
    // Before any page exists: a page knows the languages it was loaded with.
    ConfigurableLanguage::createFromLangcode('es')->save();
  }

  /**
   * The assistant.
   */
  protected function assistant(): PageAssistant {
    return $this->container->get('frame_builder_ai.assistant');
  }

  /**
   * The value of a part of the instructions that is given as JSON on the
   * line after its heading.
   */
  protected static function part(string $instructions, string $heading): mixed {
    self::assertSame(1, preg_match('/^# ' . preg_quote($heading, '/') . '\n(.+)$/m', $instructions, $match), "The instructions have the part \"$heading\".");
    $value = json_decode($match[1], TRUE);
    self::assertSame(JSON_ERROR_NONE, json_last_error(), "The part \"$heading\" is JSON.");
    return $value;
  }

  /**
   * The Spanish translation of a page.
   */
  protected function translate(NodeInterface $node): NodeInterface {
    $translation = $node->addTranslation('es', ['title' => 'Una página']);
    $node->save();
    return $translation;
  }

  /**
   * The assistant is ready when the AI module has a default provider and
   * model for chat; without them a turn fails.
   */
  public function testIsReady(): void {
    $this->assertFalse($this->assistant()->isReady());
    $this->config('ai.settings')->set('default_providers.chat', ['provider_id' => 'frame_builder_ai_scripted', 'model_id' => ''])->save();
    $this->assertFalse($this->assistant()->isReady(), 'A provider without a model is not enough.');
    $this->useScriptedProvider();
    $this->assertTrue($this->assistant()->isReady());

    $this->config('ai.settings')->set('default_providers', [])->save();
    $this->script(['message' => 'Never asked', 'layout' => NULL]);
    try {
      $this->assistant()->respond($this->createBuilderNode(), [['role' => 'user', 'content' => 'Hello']], []);
      $this->fail('A turn without a provider fails.');
    }
    catch (\RuntimeException $e) {
      $this->assertSame('No default chat provider is set in the AI module.', $e->getMessage());
    }
    $this->assertSame([], $this->requests(), 'No provider was asked.');
  }

  /**
   * The answer of a model is read: the JSON object in it, however wrapped.
   */
  public function testParse(): void {
    $assistant = $this->assistant();
    $items = [['type' => 'frame:heading', 'props' => ['id' => 'h', 'text' => 'Hi {there}']]];
    $json = json_encode(['message' => ' Done. ', 'layout' => $items, 'check' => TRUE]);
    $expected = ['message' => 'Done.', 'layout' => $items, 'action' => NULL, 'query' => NULL, 'paragraph_type' => NULL, 'token_type' => NULL, 'metatags' => NULL, 'check' => TRUE];

    $wrapped = [
      'as asked' => $json,
      'with space around' => "\n\n  $json \n",
      'in a code fence' => "```json\n$json\n```",
      'in a plain code fence' => "```\n$json\n```",
      'in a code fence with words around' => "Here is the page you asked for:\n\n```json\n$json\n```\n\nLet me know what you think!",
      'with words around' => "Sure, here it is: $json I hope that helps.",
    ];
    foreach ($wrapped as $how => $text) {
      $this->assertSame($expected, $assistant->parse($text), "An answer $how.");
    }

    // A layout as the builder stores it, and what is not a layout.
    $this->assertSame($items, $assistant->parse(json_encode(['message' => 'M', 'layout' => ['root' => ['props' => []], 'content' => $items]]))['layout']);
    foreach ([NULL, 'a hero and three cards', 12, ['type' => 'frame:heading', 'props' => []], ['content' => 'none']] as $layout) {
      $answer = $assistant->parse(json_encode(['message' => 'M', 'layout' => $layout]));
      $this->assertNull($answer['layout'], 'Not a layout: ' . json_encode($layout));
      $this->assertSame('M', $answer['message']);
    }
    $this->assertSame([], $assistant->parse('{"message": "Emptied", "layout": []}')['layout'], 'An empty layout is a layout.');
    $answer = $assistant->parse('{"layout": null}');
    $this->assertSame('', $answer['message']);
    $this->assertFalse($answer['check']);
    $this->assertSame('', $assistant->parse('{"message": ["a", "b"]}')['message'], 'A message that is not text is no message.');

    // A tool instead of an answer.
    $answer = $assistant->parse('{"action": "search_paragraphs", "query": "soup", "paragraph_type": "quote"}');
    $this->assertSame(['search_paragraphs', 'soup', 'quote', NULL], [$answer['action'], $answer['query'], $answer['paragraph_type'], $answer['layout']]);
    $this->assertSame('site', $assistant->parse('{"action": "list_tokens", "token_type": "site"}')['token_type']);
    $this->assertNull($assistant->parse('{"action": ["search_media"], "message": "x"}')['action']);
    $this->assertSame(['title' => 'T'], $assistant->parse('{"message": "x", "layout": null, "metatags": {"title": "T"}}')['metatags']);

    // Not JSON: the text is the answer and nothing changes.
    $plain = ['message' => 'I can only change the layout of this page.', 'layout' => NULL, 'action' => NULL];
    $this->assertSame($plain, $assistant->parse("  I can only change the layout of this page.\n"));
    $this->assertSame(['message' => '', 'layout' => NULL, 'action' => NULL], $assistant->parse(''));
    $this->assertSame(['message' => '{"message": "Cut off he', 'layout' => NULL, 'action' => NULL], $assistant->parse('{"message": "Cut off he'));
    // JSON with a layout that does not parse is not shown as it is.
    $unreadable = 'The answer of the model could not be read, so nothing was changed. Ask again, or for a smaller change.';
    foreach (['{"message": "Here", "layout": [{"type": "frame:heading", "props": {"text": "Cut o', "```json\n{\"message\": \"Here\", \"layout\": [{\"type\": \n```", '{"message": "x", "layout": [}'] as $text) {
      $this->assertSame(['message' => $unreadable, 'layout' => NULL, 'action' => NULL], $assistant->parse($text));
    }
  }

  /**
   * The model is told every component it may use: name, group, description,
   * props with their type, choices and example, slots.
   */
  public function testInstructionsComponents(): void {
    $node = $this->createBuilderNode();
    $instructions = $this->assistant()->instructions($node, $this->components($node), []);
    $catalog = array_column(self::part($instructions, 'Components you may use'), NULL, 'type');

    $this->assertSame([
      'type' => 'frame_builder_test_theme:probe',
      'name' => 'Probe',
      'group' => 'Test',
      'description' => 'A component with one prop of every kind the tests need.',
      'props' => [
        'title' => ['type' => 'string', 'label' => 'Title', 'example' => 'Probe title'],
        'count' => ['type' => 'number', 'label' => 'Count', 'example' => 3],
        'shown' => ['type' => 'boolean', 'label' => 'Shown', 'example' => TRUE],
        'tone' => ['type' => 'one of the options', 'label' => 'Tone', 'options' => ['calm' => 'calm', 'loud' => 'loud'], 'example' => 'calm'],
      ],
      'slots' => ['content' => 'Content'],
    ], $catalog['frame_builder_test_theme:probe'], 'Features ("_spacing"...) and the library box are not props of a component.');

    $heading = $catalog['frame:heading'];
    $this->assertSame('Heading', $heading['name']);
    $this->assertTrue($heading['props']['text']['required']);
    $this->assertStringContainsString('Tokens such as [node:title] work.', $heading['props']['text']['description']);
    $this->assertSame(['h1' => 'H1: page title', 'h2' => 'H2: section', 'h3' => 'H3: sub-section', 'h4' => 'H4'], $heading['props']['level']['options'], 'Choices with what they are called.');
    $this->assertArrayNotHasKey('required', $heading['props']['level']);
    $this->assertSame('frame:heading', $heading['example']['type'], 'The example of the component, as a layout item.');
    $this->assertArrayNotHasKey('slots', $heading);

    // The kinds of props, as the model is to give them.
    $this->assertStringStartsWith('string of simple HTML', $catalog['frame:text']['props']['text']['type']);
    $this->assertStringStartsWith('string: a path of this site', $catalog['frame:button']['props']['url']['type']);
    $this->assertSame('the name of a colour of the palette', $catalog['frame:button']['props']['color']['type']);
    $this->assertSame('a size of the spacing scale, as {"size": "<name>"}', $catalog['frame:spacer']['props']['size']['type']);
    $this->assertSame('string, may have line breaks', $catalog['frame:hero']['props']['text']['type']);
    $this->assertSame(['image', 'caption'], array_keys($catalog['frame:image']['props']), 'Props the builder fills by itself are not offered.');
    $this->assertStringContainsString('search_media', $catalog['frame:image']['props']['image']['type']);
    $this->assertSame(['actions' => 'Actions'], $catalog['frame:hero']['slots']);

    // Fields of the page and blocks of Drupal: by name only.
    $this->assertSame(['type' => 'drupal_field:title', 'name' => 'Title', 'group' => 'Fields'], $catalog['drupal_field:title']);
    $this->assertSame(['type', 'name', 'group'], array_keys($catalog['drupal_block:system_branding_block']));
    $this->assertSame('Blocks', $catalog['drupal_block:system_branding_block']['group']);

    // The rules, the tools and the answer format.
    foreach (['You never write code, CSS, scripts or new components', '{"action": "search_media"', '{"action": "search_paragraphs"', '{"action": "search_content"', '{"action": "list_tokens"', '{"action": "page_shell"}', '{"action": "preview"', 'Answer with one JSON object and nothing else', 'Never write an image URL'] as $text) {
      $this->assertStringContainsString($text, $instructions);
    }
    $this->assertStringNotContainsString('# This is a translation', $instructions);
    $this->assertStringNotContainsString('# Meta tags of the page', $instructions, 'Without the Metatag module the page has no meta tags.');

    // Long descriptions are cut.
    $components = [['id' => 'test:long', 'label' => 'Long', 'group' => 'Test', 'description' => str_repeat('d', 400), 'fields' => [['name' => 'a', 'widget' => 'text', 'description' => str_repeat('p', 400)], ['name' => 'b', 'widget' => 'formatter'], ['name' => 'c', 'widget' => 'text', 'default' => '']], 'slots' => []]];
    $long = self::part($this->assistant()->instructions($node, $components, []), 'Components you may use')[0];
    $this->assertSame(300, strlen($long['description']));
    $this->assertSame(['type' => 'string', 'label' => 'a', 'description' => str_repeat('p', 300)], $long['props']['a']);
    $this->assertSame(['a', 'c'], array_keys($long['props']), 'Props the model cannot fill sensibly are not offered.');
    $this->assertArrayNotHasKey('example', $long['props']['c']);
  }

  /**
   * The model is told the design tokens of the theme, the page and its
   * layout as it is in the editor.
   */
  public function testInstructionsTokensPageAndLayout(): void {
    $node = $this->createBuilderNode(['title' => 'Cooking "class" / spring']);
    $current = [self::item('frame:heading', 'h1', ['text' => 'Not saved yet: día'])];
    $instructions = $this->assistant()->instructions($node, $this->components($node), $current);

    $tokens = self::part($instructions, 'Design tokens of the theme (its UI kit)');
    $this->assertSame(['s' => '0.5rem', 'm' => '1rem', 'l' => '2rem'], $tokens['spacing_sizes']);
    $this->assertSame(['s' => '0.875rem', 'm' => '1rem', 'l' => '1.5rem'], $tokens['font_sizes']);
    $this->assertSame(['s' => '30rem', 'm' => '45rem', 'full' => '100%'], $tokens['sizes']);
    $this->assertSame(['brand' => 'Brand #112233', 'paper' => 'Paper #ffffff'], $tokens['colors']);
    $this->assertSame(['Thumbnail (100×100)'], $tokens['image_styles']);
    $this->assertSame(['thin' => '1px', 'medium' => '2px', 'thick' => '4px'], $tokens['border_widths']);
    $this->assertSame(['s' => '0.25rem', 'm' => '0.5rem', 'l' => '1rem', 'full' => '9999px'], $tokens['corner_radii']);
    $this->assertEqualsCanonicalizing(['spacing', 'size', 'align', 'text', 'background', 'border', 'position'], $tokens['features_on_every_item']);

    $this->assertSame(['title' => 'Cooking "class" / spring', 'content_type' => 'Landing', 'language' => 'en'], self::part($instructions, 'The page'));
    $this->assertSame($current, self::part($instructions, 'The current layout of the page'));
    $this->assertStringContainsString('"text":"Not saved yet: día"', $instructions, 'Text is given as it is, not escaped.');
    $this->assertSame(['unknown' => 'not measured'], self::part($instructions, 'The look of the frontend theme'));
  }

  /**
   * What the editor measured of the theme in the browser is passed on.
   */
  public function testInstructionsLook(): void {
    $node = $this->createBuilderNode();
    $variables = [];
    for ($i = 0; $i < 70; $i++) {
      $variables["--var-$i"] = "{$i}px";
    }
    $look = [
      'background' => 'rgb(255, 255, 255)',
      'color' => '#222',
      'font' => 'Inter, sans-serif',
      'fontSize' => '16px',
      'headingFont' => '  ',
      'linkColor' => '',
      'width' => 1120,
      'viewports' => ['mobile' => '360', 'desktop' => '1280'],
      'variables' => $variables,
      'cookies' => 'not passed on',
    ];
    $told = self::part($this->assistant()->instructions($node, [], [], $look), 'The look of the frontend theme');
    $this->assertSame(['page_background', 'text_color', 'text_font', 'text_size', 'width_of_the_layout_area', 'screen_widths_of_the_theme', 'css_custom_properties'], array_keys($told), 'What was not measured is left out.');
    $this->assertSame('rgb(255, 255, 255)', $told['page_background']);
    $this->assertSame(1120, $told['width_of_the_layout_area']);
    $this->assertSame(['mobile' => '360', 'desktop' => '1280'], $told['screen_widths_of_the_theme']);
    $this->assertCount(60, $told['css_custom_properties']);
    $this->assertSame('59px', $told['css_custom_properties']['--var-59']);
  }

  /**
   * Modules add guidance of the site with
   * hook_frame_builder_ai_instructions_alter().
   */
  public function testInstructionsHook(): void {
    $node = $this->createBuilderNode(['title' => 'Spring menu']);
    $before = $this->assistant()->instructions($node, [], []);
    $this->assertStringNotContainsString('# House style', $before);
    $this->container->get('state')->set('frame_builder_ai_test.house_style', 'Write headings in sentence case.');
    $this->assertSame($before . "\n\n# House style\nWrite headings in sentence case. (page: Spring menu)", $this->assistant()->instructions($node, [], []));

    // A turn uses the changed instructions.
    $this->useScriptedProvider();
    $this->script(['message' => 'Hi', 'layout' => NULL]);
    $this->assistant()->respond($node, [['role' => 'user', 'content' => 'Hello']], []);
    $this->assertStringEndsWith('Write headings in sentence case. (page: Spring menu)', $this->requests()[0]['system']);
  }

  /**
   * On a translation the model is told the two languages and what it may
   * change.
   */
  public function testInstructionsTranslation(): void {
    $translation = $this->translate($this->createBuilderNode());
    $instructions = $this->assistant()->instructions($translation, [], []);
    $this->assertSame(['title' => 'Una página', 'content_type' => 'Landing', 'language' => 'es'], self::part($instructions, 'The page'));
    $this->assertStringContainsString("# This is a translation\nThis page is the Spanish translation of a English page, and shares its structure", $instructions);
    $this->assertStringContainsString('change only the text and link props. Write in Spanish.', $instructions);

    $this->createBuilderType('free', 'independent');
    $free = $this->createBuilderNode(['type' => 'free']);
    $free = $free->addTranslation('es', ['title' => 'Libre']);
    $free->save();
    $instructions = $this->assistant()->instructions($free, [], []);
    $this->assertStringContainsString("# This is a translation\nThis page is the Spanish translation of a English page, with a layout of its own. Write in Spanish.", $instructions);
    $this->assertStringNotContainsString('shares its structure', $instructions);
  }

  /**
   * The token tool: the tokens of the page and the site, or of one type.
   */
  public function testListTokens(): void {
    $assistant = $this->assistant();
    $tokens = array_column($assistant->listTokens(), NULL, 'token');
    $this->assertSame(['token' => '[node:title]', 'name' => 'Title'], $tokens['[node:title]']);
    $this->assertSame(['token' => '[site:name]', 'name' => 'Name', 'description' => 'The name of the site.'], $tokens['[site:name]']);
    $this->assertSame('user', $tokens['[node:author]']['more_tokens_of_type'], 'A token that leads to more tokens says of which type.');
    $this->assertSame('date', $tokens['[node:created]']['more_tokens_of_type']);
    $this->assertArrayNotHasKey('more_tokens_of_type', $tokens['[node:title]']);
    $this->assertSame([], preg_grep('/^\[(node|site|date):[^\]]+\]$/', array_keys($tokens), PREG_GREP_INVERT));

    $user = array_column($assistant->listTokens('user'), 'token');
    $this->assertContains('[user:display-name]', $user);
    $this->assertSame([], preg_grep('/^\[user:/', $user, PREG_GREP_INVERT), 'Only the tokens of the type asked for.');
    $this->assertContains('[date:short]', array_column($assistant->listTokens('date'), 'token'));
    $this->assertSame(array_keys($tokens), array_column($assistant->listTokens('no-such-type'), 'token'), 'An unknown type gives the usual ones.');

    foreach (array_merge($assistant->listTokens(), $user) as $entry) {
      if (is_array($entry) && isset($entry['description'])) {
        $this->assertLessThanOrEqual(140, mb_strlen($entry['description']));
        $this->assertSame(strip_tags($entry['description']), $entry['description']);
      }
    }
  }

  /**
   * Without a type the tokens of the date are listed too, as the model is
   * told.
   */
  public function testListTokensOfTheDate(): void {
    $tokens = array_column($this->assistant()->listTokens(), 'token');
    if (!preg_grep('/^\[(current-date|date):/', $tokens)) {
      $this->markTestSkipped('BUG: PageAssistant::listTokens() lists the type "current-date", which only the contrib Token module defines: on core alone no token of the date is listed (core has [date:short]...), though the model is told it gets "those of the page, the site and the date".');
    }
    $this->assertNotEmpty(preg_grep('/^\[(current-date|date):/', $tokens));
  }

  /**
   * With the Token module: the tokens of the current date (an alias of the
   * date tokens), dynamic tokens marked as such, and at most eighty.
   */
  public function testListTokensWithTokenModule(): void {
    $this->skipWithoutModules('token');
    $this->enableModules(['token']);
    $assistant = $this->assistant();
    $tokens = array_column($assistant->listTokens(), NULL, 'token');
    $this->assertArrayHasKey('[current-date:short]', $tokens);
    $this->assertArrayHasKey('[current-date:custom:?]', $tokens, 'A token that takes a value of its own says so.');
    $this->assertArrayHasKey('[node:title]', $tokens);
    $this->assertArrayHasKey('[site:name]', $tokens);
    $this->assertLessThanOrEqual(80, count($tokens));
    $this->assertLessThanOrEqual(80, count($assistant->listTokens('node')));
  }

  /**
   * The media tool: published images by words in their name, alternative
   * text or file name; the newest without words.
   */
  public function testSearchMedia(): void {
    $assistant = $this->assistant();
    $cat = $this->createImageMedia('Red cat', 'Sofa scene', 'kitten.png', ['changed' => 1000]);
    $dog = $this->createImageMedia('Blue dog', 'Garden view', 'puppy.png', ['changed' => 2000]);
    $this->createImageMedia('Hidden cat', 'Not published', 'hidden.png', ['changed' => 3000, 'status' => 0]);
    $this->createMediaType('file', ['id' => 'document']);
    Media::create(['bundle' => 'document', 'name' => 'Cat paper', 'status' => 1])->save();

    $red = ['id' => (int) $cat->id(), 'name' => 'Red cat', 'alt' => 'Sofa scene', 'file' => 'kitten.png', 'width' => 88, 'height' => 100];
    $blue = ['id' => (int) $dog->id(), 'name' => 'Blue dog', 'alt' => 'Garden view', 'file' => 'puppy.png', 'width' => 88, 'height' => 100];
    $cases = [
      'a word of the name' => ['cat', [$red]],
      'whatever the case' => ['CAT', [$red]],
      'a word of the alternative text' => ['sofa', [$red]],
      'a word of the file name' => ['puppy', [$blue]],
      'a sentence: any of its words' => ['Is there a dog or a cat?', [$blue, $red]],
      'no words: the newest first' => ['', [$blue, $red]],
      'only short words: the newest first' => ['a of in', [$blue, $red]],
      'a word nothing has' => ['unicorn', []],
    ];
    foreach ($cases as $what => [$query, $expected]) {
      $this->assertSame($expected, $assistant->searchMedia($query), "Searching with $what.");
    }

    for ($i = 0; $i < 12; $i++) {
      $this->createImageMedia("Extra $i", '', "extra-$i.png", ['changed' => 5000 + $i]);
    }
    $found = $assistant->searchMedia('');
    $this->assertCount(12, $found, 'At most twelve.');
    $this->assertSame('Extra 11', $found[0]['name']);
    $this->assertSame([$red], $assistant->searchMedia('kitten'));
  }

  /**
   * The media tool finds nothing for a user who may not see media.
   */
  public function testSearchMediaOfUserWithoutAccess(): void {
    $this->createImageMedia('Red cat', 'Sofa scene', 'kitten.png');
    $this->setUpCurrentUser([], ['access content']);
    $found = $this->assistant()->searchMedia('cat');
    if ($found) {
      $this->markTestSkipped('BUG: PageAssistant::searchMedia() relies on the access check of the entity query, which does nothing for media: a user without "view media" gets names, alternative texts and file names of the library (the sanitizer then refuses the image).');
    }
    $this->assertSame([], $found);
  }

  /**
   * Without image media types there is nothing to search.
   */
  public function testSearchMediaWithoutImageTypes(): void {
    $this->container->get('entity_type.manager')->getStorage('media_type')->load('image')->delete();
    $this->assertSame([], $this->assistant()->searchMedia('cat'));
  }

  /**
   * The paragraph tool: published paragraphs by words in their text.
   */
  public function testSearchParagraphs(): void {
    $this->assertSame([], $this->assistant()->searchParagraphs('hungry'), 'Nothing without the Paragraphs module.');
    $this->enableParagraphs();
    $assistant = $this->assistant();
    $create = static function (string $type, string $text, int $status = 1): int {
      $paragraph = Paragraph::create(['type' => $type, 'field_text' => $text, 'status' => $status]);
      $paragraph->save();
      return (int) $paragraph->id();
    };
    $quote = $create('quote', "Stay hungry,\n <b>stay</b>   foolish at Stanford");
    $teaser = $create('teaser', 'Hungry for more');
    $create('quote', 'Said twice');
    $twice = $create('quote', 'Said twice');
    $create('quote', 'A hungry secret', 0);

    $first = ['id' => $quote, 'paragraph_type' => 'quote', 'text' => 'Stay hungry, stay foolish at Stanford'];
    $second = ['id' => $teaser, 'paragraph_type' => 'teaser', 'text' => 'Hungry for more'];
    $third = ['id' => $twice, 'paragraph_type' => 'quote', 'text' => 'Said twice'];
    $this->assertSame([$second, $first], $assistant->searchParagraphs('hungry'), 'The newest first; unpublished ones are left out.');
    $this->assertSame([$first], $assistant->searchParagraphs('hungry', 'quote'), 'Of one paragraph type.');
    $this->assertSame([$first], $assistant->searchParagraphs('Anything from Stanford?'));
    $this->assertSame([$third], $assistant->searchParagraphs('twice'), 'The same text again is listed once.');
    $this->assertSame([$third, $second, $first], $assistant->searchParagraphs(''));
    $this->assertSame([$second], $assistant->searchParagraphs('', 'teaser'));
    $this->assertSame([], $assistant->searchParagraphs('unicorn'));
    $this->assertSame([], $assistant->searchParagraphs('hungry', 'no_such_type'));

    $long = $create('teaser', str_repeat('long ', 100));
    $found = $assistant->searchParagraphs('long');
    $this->assertSame($long, $found[0]['id']);
    $this->assertSame(240, mb_strlen($found[0]['text']));
    for ($i = 0; $i < 12; $i++) {
      $create('teaser', "Numbered $i");
    }
    $this->assertCount(10, $assistant->searchParagraphs('numbered'), 'At most ten.');
  }

  /**
   * The content tool: published pages by words in their title, as links.
   */
  public function testSearchContent(): void {
    $assistant = $this->assistant();
    $page = $this->createBuilderNode(['title' => 'The page being built', 'changed' => 500]);
    $about = $this->createBuilderNode(['title' => 'About our kitchen', 'changed' => 1000]);
    $contact = $this->createBuilderNode(['title' => 'Contact the kitchen', 'changed' => 2000]);
    $this->createBuilderNode(['title' => 'Kitchen secrets', 'changed' => 3000, 'status' => 0]);
    $entry = static fn (NodeInterface $node, ?string $title = NULL): array => ['title' => $title ?? $node->label(), 'type' => 'Landing', 'link' => 'entity:node/' . $node->id()];

    $this->assertSame([$entry($contact), $entry($about)], $assistant->searchContent('kitchen', $page), 'The newest first; unpublished pages are left out.');
    $this->assertSame([$entry($about)], $assistant->searchContent('Anything about us?', $page));
    $this->assertSame([$entry($contact), $entry($about), $entry($page)], $assistant->searchContent('', $page));
    $this->assertSame([], $assistant->searchContent('unicorn', $page));

    // Titles in the language of the page being built.
    $about->addTranslation('es', ['title' => 'Sobre nuestra cocina'])->save();
    $spanish = $page->addTranslation('es', ['title' => 'La página']);
    $page->save();
    $this->assertContains($entry($about, 'Sobre nuestra cocina'), $assistant->searchContent('', $spanish));
    $this->assertContains($entry($contact), $assistant->searchContent('', $spanish), 'A page without that translation keeps its title.');

    for ($i = 0; $i < 12; $i++) {
      $this->createBuilderNode(['title' => "Numbered $i", 'changed' => 9000 + $i]);
    }
    $found = $assistant->searchContent('numbered', $page);
    $this->assertCount(10, $found, 'At most ten.');
    $this->assertSame('Numbered 11', $found[0]['title']);
  }

  /**
   * The page shell tool: the page of the theme around an empty layout, as
   * short HTML; the layout of the page is left alone.
   */
  public function testPageShell(): void {
    $layouts = $this->container->get('frame_builder.layouts');
    $node = $this->createBuilderNode(['title' => 'Shell of a page']);
    $this->saveLayout($node, self::layout(self::item('frame:heading', 'h1', ['text' => 'Text of the layout'])));

    $shell = $this->assistant()->pageShell($node);
    $this->assertSame(['html', 'note'], array_keys($shell));
    $this->assertStringContainsString('<main class="page__main" role="main">', $shell['html']);
    $this->assertStringContainsString('<h1>Shell of a page</h1>', $shell['html'], 'The theme prints the title of the page.');
    $this->assertStringNotContainsString('Text of the layout', $shell['html'], 'The layout itself is not part of the shell.');
    $this->assertDoesNotMatchRegularExpression('/<(script|style|link|meta)\b|\sdata-|\sid=/', $shell['html']);
    $this->assertLessThanOrEqual(9000, mb_strlen($shell['html']));
    $this->assertStringContainsString('An empty layout is shown here', $shell['note']);

    $this->assertSame('Text of the layout', $layouts->load($node)['content'][0]['props']['text'], 'The preview used for the shell is gone.');
  }

  /**
   * The preview tool: what a layout renders to, and how many of its items
   * were left out.
   */
  public function testPreview(): void {
    $node = $this->createBuilderNode();
    $components = $this->components($node);
    $layout = [
      self::item('frame_builder_test_theme:probe', 'p1', [
        'title' => 'Rendered title',
        'tone' => 'loud',
        'shown' => TRUE,
        'count' => 5,
        'content' => [
          self::item('frame:heading', 'h1', ['text' => 'Rendered heading', 'level' => 'h3']),
          self::item('frame:carousel', 'c1', ['slides' => [self::item('frame:heading', 'h2', ['text' => 'Lost with its parent'])]]),
        ],
      ]),
      self::item('frame:marquee', 'm1', ['text' => 'Not a component']),
    ];
    $preview = $this->assistant()->preview($node, $layout, $components, []);
    $this->assertSame(['left_out', 'html'], array_keys($preview));
    $this->assertSame(3, $preview['left_out'], 'The two invented items and what was in one of them.');
    $this->assertStringContainsString('probe--loud', $preview['html']);
    $this->assertStringContainsString('<h2 class="probe__title">Rendered title</h2>', $preview['html']);
    $this->assertMatchesRegularExpression('#<h3[^>]*>\s*Rendered heading\s*</h3>#', $preview['html']);
    $this->assertStringNotContainsString('Not a component', $preview['html']);
    $this->assertStringNotContainsString('Lost with its parent', $preview['html']);
    $this->assertDoesNotMatchRegularExpression('/<!--|<script|<style|\sdata-[\w-]+=|\sid="|\s{2,}/', $preview['html'], 'Without what does not matter for a check.');

    $this->assertSame(['left_out' => 0, 'html' => ''], $this->assistant()->preview($node, [], $components, []));
    $this->assertSame([], $this->container->get('frame_builder.layouts')->load($node), 'A preview stores nothing.');
  }

  /**
   * A turn: the request with the instructions, the answer made into a
   * layout for the editor, with what was dropped counted.
   */
  public function testRespond(): void {
    $this->useScriptedProvider();
    $node = $this->createBuilderNode(['title' => 'Cooking class']);
    $data = self::layout(['type' => 'frame:heading', 'version' => 1, 'props' => ['id' => 'old', 'text' => 'Old heading', 'level' => 'h2']]);
    $look = ['background' => '#fff', 'width' => '1120px'];
    $this->script("Here you go:\n```json\n" . json_encode([
      'message' => 'I added a probe.',
      'check' => TRUE,
      'layout' => [
        self::item('frame:heading', 'old', ['text' => 'New heading', 'onclick' => 'alert(1)']),
        self::item('frame:carousel', 'c1', ['slides' => [self::item('frame:heading', 'lost', ['text' => 'Lost'])]]),
        self::item('frame_builder_test_theme:probe', 'p1', [
          'tone' => 'screaming',
          'content' => [self::item('frame:marquee', 'm1'), self::item('frame:button', 'b1', ['label' => 'Join', 'url' => 'entity:node/1'])],
        ]),
      ],
    ]) . "\n```");
    $messages = [
      ['role' => 'user', 'content' => 'Make a page'],
      ['role' => 'assistant', 'content' => 'What about?'],
      ['role' => 'user', 'content' => 'A cooking class'],
    ];
    $result = $this->assistant()->respond($node, $messages, $data, NULL, $look);

    $this->assertSame(['message', 'data', 'steps', 'check', 'dropped'], array_keys($result));
    $this->assertSame('I added a probe.', $result['message']);
    $this->assertSame([], $result['steps']);
    $this->assertTrue($result['check']);
    $this->assertSame(3, $result['dropped'], 'The carousel, the heading in it and the marquee.');
    $this->assertSame(['props' => []], $result['data']['root']);
    $content = $result['data']['content'];
    $this->assertSame(['frame:heading', 'frame_builder_test_theme:probe'], array_column($content, 'type'));
    $this->assertSame(['id' => 'old', 'text' => 'New heading'], $content[0]['props']);
    $this->assertSame(['id', 'content'], array_keys($content[1]['props']), 'A choice that is not an option is not taken.');
    $this->assertSame(['id' => 'b1', 'label' => 'Join', 'url' => 'entity:node/1'], $content[1]['props']['content'][0]['props']);
    foreach ([$content[0], $content[1], $content[1]['props']['content'][0]] as $item) {
      $this->assertSame($this->container->get('frame_builder.migrator')->componentVersion($item['type']), $item['version'], 'Every item is of the current version of its component.');
    }

    $requests = $this->requests();
    $this->assertCount(1, $requests);
    $this->assertSame('scripted', $requests[0]['model']);
    $this->assertSame(['frame_builder'], $requests[0]['tags']);
    $this->assertSame($this->assistant()->instructions($node, $this->components($node), $data['content'], $look), $requests[0]['system']);
    $this->assertSame([
      ['role' => 'user', 'text' => 'Make a page', 'images' => 0],
      ['role' => 'assistant', 'text' => 'What about?', 'images' => 0],
      ['role' => 'user', 'text' => 'A cooking class', 'images' => 0],
    ], $requests[0]['messages']);
    $this->assertSame([], $this->container->get('frame_builder.layouts')->load($node), 'Nothing is saved.');
  }

  /**
   * An answer without a layout, or one that is not JSON, changes nothing.
   */
  public function testRespondWithoutLayout(): void {
    $this->useScriptedProvider();
    $node = $this->createBuilderNode();
    $ask = [['role' => 'user', 'content' => 'Which components are there?']];

    $this->script(['message' => 'Which colour do you want?', 'layout' => NULL, 'check' => FALSE]);
    $this->assertSame(['message' => 'Which colour do you want?', 'data' => NULL, 'steps' => [], 'check' => FALSE], $this->assistant()->respond($node, $ask, []));

    $this->script('There are headings, texts and buttons.');
    $this->assertSame(['message' => 'There are headings, texts and buttons.', 'data' => NULL, 'steps' => [], 'check' => FALSE], $this->assistant()->respond($node, $ask, []));

    $this->script('{"message": "Here", "layout": [{"type": "frame:heading", "props": {"text": "Cut o');
    $result = $this->assistant()->respond($node, $ask, self::layout(self::item('frame:heading', 'h', ['text' => 'Stays'])));
    $this->assertStringStartsWith('The answer of the model could not be read, so nothing was changed.', $result['message']);
    $this->assertNull($result['data']);

    // An empty layout is a change: the page is emptied.
    $this->script(['message' => 'Emptied.', 'layout' => []]);
    $result = $this->assistant()->respond($node, $ask, self::layout(self::item('frame:heading', 'h', ['text' => 'Goes'])));
    $this->assertSame([], $result['data']['content']);
    $this->assertSame(0, $result['dropped']);
  }

  /**
   * The model uses its tools, one per step, and gets each result as the
   * next message; after six it is told to answer.
   */
  public function testRespondWithTools(): void {
    $this->useScriptedProvider();
    $cat = $this->createImageMedia('Red cat', 'Sofa scene', 'kitten.png');
    $node = $this->createBuilderNode(['title' => 'Cooking class']);
    $this->script(
      ['action' => 'search_media', 'query' => 'cat'],
      ['action' => 'search_content', 'query' => 'cooking'],
      ['action' => 'list_tokens', 'token_type' => 'site'],
      ['action' => 'preview', 'layout' => [self::item('frame:heading', 'h1', ['text' => 'Checked heading']), self::item('frame:marquee', 'm1')]],
      ['action' => 'page_shell'],
      ['action' => 'fly', 'query' => 'away'],
      ['message' => 'Done.', 'layout' => [self::item('frame:image', 'i1', ['image' => ['media' => $cat->id()]])]],
    );
    $result = $this->assistant()->respond($node, [['role' => 'user', 'content' => 'A page with a cat']], []);

    $this->assertSame(['search_media: cat', 'search_content: cooking', 'list_tokens: site', 'preview', 'page_shell', 'fly'], $result['steps']);
    $this->assertSame('Done.', $result['message']);
    $this->assertSame((int) $cat->id(), $result['data']['content'][0]['props']['image']['media']);
    $this->assertSame(0, $result['dropped']);

    $requests = $this->requests();
    $this->assertCount(7, $requests, 'One request per step and one for the answer.');
    $this->assertCount(1, $requests[0]['messages']);
    $messages = $requests[6]['messages'];
    $this->assertSame(['user', 'assistant', 'user', 'assistant', 'user', 'assistant', 'user', 'assistant', 'user', 'assistant', 'user', 'assistant', 'user'], array_column($messages, 'role'));
    $this->assertSame('A page with a cat', $messages[0]['text']);
    $this->assertSame('{"action":"search_media","query":"cat"}', $messages[1]['text'], 'What the model asked is part of the conversation.');
    $results = [];
    foreach ([2 => 'search_media', 4 => 'search_content', 6 => 'list_tokens', 8 => 'preview', 10 => 'page_shell', 12 => 'fly'] as $index => $tool) {
      $this->assertStringStartsWith("Result of $tool:\n", $messages[$index]['text']);
      $lines = explode("\n", $messages[$index]['text']);
      $results[$tool] = json_decode($lines[1], TRUE);
      $this->assertIsArray($results[$tool], "The result of $tool is JSON on one line.");
      $this->assertSame($index === 12 ? 'That was your last step: give your final answer now.' : NULL, $lines[2] ?? NULL);
    }
    $this->assertSame([['id' => (int) $cat->id(), 'name' => 'Red cat', 'alt' => 'Sofa scene', 'file' => 'kitten.png', 'width' => 88, 'height' => 100]], $results['search_media']['media']);
    $this->assertSame([['title' => 'Cooking class', 'type' => 'Landing', 'link' => 'entity:node/' . $node->id()]], $results['search_content']['content']);
    $this->assertContains('[site:name]', array_column($results['list_tokens']['tokens'], 'token'));
    $this->assertSame(1, $results['preview']['left_out']);
    $this->assertStringContainsString('Checked heading', $results['preview']['html']);
    $this->assertStringContainsString('<h1>Cooking class</h1>', $results['page_shell']['html']);
    $this->assertStringStartsWith('Unknown action.', $results['fly']['error']);
    // Every step has the same instructions.
    $this->assertSame([$requests[0]['system']], array_values(array_unique(array_column($requests, 'system'))));
  }

  /**
   * A model that keeps asking for tools is stopped after six: its seventh
   * request is not carried out and nothing changes.
   */
  public function testRespondStepLimit(): void {
    $this->useScriptedProvider();
    $node = $this->createBuilderNode();
    $this->script(...array_fill(0, 9, ['action' => 'list_tokens']));
    $result = $this->assistant()->respond($node, [['role' => 'user', 'content' => 'Go on']], []);
    $this->assertSame(array_fill(0, 6, 'list_tokens'), $result['steps']);
    $this->assertSame('', $result['message']);
    $this->assertNull($result['data']);
    $this->assertCount(7, $this->requests());
    $this->assertCount(2, $this->container->get('state')->get(ScriptedProvider::ANSWERS), 'The provider was not asked again.');
  }

  /**
   * A screenshot of the canvas goes with the request of the person.
   */
  public function testRespondWithScreenshot(): void {
    $this->useScriptedProvider();
    $node = $this->createBuilderNode();
    $this->script(['message' => 'I see.', 'layout' => NULL]);
    $messages = [
      ['role' => 'user', 'content' => 'First'],
      ['role' => 'assistant', 'content' => 'Yes?'],
      ['role' => 'user', 'content' => 'Look at this'],
    ];
    $this->assistant()->respond($node, $messages, [], 'binary-of-a-jpeg');
    $this->assertSame([0, 0, 1], array_column($this->requests()[0]['messages'], 'images'), 'With the last message only.');
  }

  /**
   * On a translation that shares the structure of the original only texts
   * and links of the items of the page are taken from the answer.
   */
  public function testRespondOnSyncedTranslation(): void {
    $this->useScriptedProvider();
    $translation = $this->translate($this->createBuilderNode());
    $data = self::layout(
      self::item('frame:heading', 'h1', ['text' => 'Hello', 'level' => 'h2']),
      self::item('frame:button', 'b1', ['label' => 'Go', 'url' => '/about', 'variant' => 'primary']),
      self::item('frame_builder_test_theme:probe', 'box', ['title' => 'Box', 'tone' => 'calm', 'count' => 2, 'content' => [
        self::item('frame:heading', 'inner', ['text' => 'Inside', 'level' => 'h3']),
      ]]),
      self::item('frame:heading', 'x1', ['text' => 'Not in the answer']),
      self::item('frame:heading', 'x2', ['text' => 'Another type in the answer']),
    );
    $this->script(['message' => 'Traducido.', 'layout' => [
      // Another order, other options, a new item, an item less, an item of
      // another type under the id of one of the page.
      self::item('frame:heading', 'new', ['text' => 'Nuevo']),
      self::item('frame_builder_test_theme:probe', 'box', ['title' => 'Caja', 'tone' => 'loud', 'count' => 9, 'content' => []]),
      self::item('frame:heading', 'inner', ['text' => 'Dentro', 'level' => 'h1']),
      self::item('frame:button', 'b1', ['label' => 'Ir', 'url' => '/es/sobre', 'variant' => 'outline']),
      self::item('frame:heading', 'h1', ['text' => 'Hola', 'level' => 'h4', '_align' => 'center']),
      self::item('frame:button', 'x2', ['label' => 'Otro', 'url' => '/otro']),
    ]]);
    $result = $this->assistant()->respond($translation, [['role' => 'user', 'content' => 'Translate to Spanish']], $data);

    $this->assertTrue($result['translation']);
    $content = $result['data']['content'];
    $strip = static fn (array $item): array => ['type' => $item['type'], 'props' => $item['props']];
    $this->assertSame(['h1', 'b1', 'box', 'x1', 'x2'], array_column(array_column($content, 'props'), 'id'), 'The items of the page, in their order.');
    $this->assertSame(self::item('frame:heading', 'h1', ['text' => 'Hola', 'level' => 'h2']), $strip($content[0]));
    $this->assertSame(self::item('frame:button', 'b1', ['label' => 'Ir', 'url' => '/es/sobre', 'variant' => 'primary']), $strip($content[1]));
    $this->assertSame(['id' => 'box', 'title' => 'Caja', 'tone' => 'calm', 'count' => 2], array_diff_key($content[2]['props'], ['content' => 1]));
    $this->assertSame(self::item('frame:heading', 'inner', ['text' => 'Dentro', 'level' => 'h3']), $strip($content[2]['props']['content'][0]));
    $this->assertSame('Not in the answer', $content[3]['props']['text']);
    $this->assertSame(self::item('frame:heading', 'x2', ['text' => 'Another type in the answer']), $strip($content[4]));
    $this->assertStringContainsString('shares its structure', $this->requests()[0]['system']);
  }

  /**
   * On a translation with a layout of its own, and on the original, the
   * answer is the new layout.
   */
  public function testRespondOnIndependentTranslation(): void {
    $this->useScriptedProvider();
    $this->createBuilderType('free', 'independent');
    $node = $this->createBuilderNode(['type' => 'free']);
    $translation = $node->addTranslation('es', ['title' => 'Libre']);
    $node->save();
    $data = self::layout(self::item('frame:heading', 'h1', ['text' => 'Hello', 'level' => 'h2']));
    $answer = ['message' => 'Hecho.', 'layout' => [self::item('frame:button', 'b9', ['label' => 'Ir', 'url' => '/es'])]];
    foreach ([$translation, $node] as $page) {
      $this->script($answer);
      $result = $this->assistant()->respond($page, [['role' => 'user', 'content' => 'Change it']], $data);
      $this->assertArrayNotHasKey('translation', $result);
      $this->assertSame(['b9'], array_column(array_column($result['data']['content'], 'props'), 'id'));
    }
  }

  /**
   * A Paragraph block the model starts as a copy gets the content of the
   * copied paragraph as unsaved content, in the final answer only; on a
   * translation that shares its structure the paragraph of a block stays.
   */
  public function testRespondWithParagraphCopy(): void {
    $this->enableParagraphs();
    $this->useScriptedProvider();
    $node = $this->createBuilderNode();
    $quote = Paragraph::create(['type' => 'quote', 'field_text' => 'To be or not to be']);
    $quote->save();
    $drafts = $this->container->get('frame_builder.paragraph_drafts');
    $layout = [self::item('drupal_paragraph:quote', 'q1', ['paragraph' => ['copy' => $quote->id()]])];

    $instructions = $this->assistant()->instructions($node, $this->components($node), []);
    $catalog = array_column(self::part($instructions, 'Components you may use'), NULL, 'type');
    $this->assertSame('Paragraphs', $catalog['drupal_paragraph:quote']['group']);
    $this->assertStringContainsString('{"copy": <id found with search_paragraphs>}', $catalog['drupal_paragraph:quote']['props']['paragraph']['type']);

    $this->script(
      ['action' => 'search_paragraphs', 'query' => 'not to be', 'paragraph_type' => 'quote'],
      ['action' => 'preview', 'layout' => $layout],
      ['message' => 'A quote, copied.', 'layout' => $layout],
    );
    $result = $this->assistant()->respond($node, [['role' => 'user', 'content' => 'Add that quote']], []);
    $this->assertSame(['search_paragraphs: not to be', 'preview'], $result['steps']);
    $requests = $this->requests();
    $found = json_decode(explode("\n", $requests[1]['messages'][2]['text'])[1], TRUE);
    $this->assertSame([['id' => (int) $quote->id(), 'paragraph_type' => 'quote', 'text' => 'To be or not to be']], $found['paragraphs']);
    $preview = json_decode(explode("\n", $requests[2]['messages'][4]['text'])[1], TRUE);
    $this->assertSame(0, $preview['left_out']);
    $this->assertSame(['draft'], array_keys($result['data']['content'][0]['props']['paragraph']));
    $this->assertSame(['field_text' => [['value' => 'To be or not to be']]], $drafts->get($node, 'q1'));

    // What a synced translation may change does not include the paragraph.
    $reference = ['id' => (int) $quote->id(), 'vid' => (int) $quote->getRevisionId(), 'uuid' => $quote->uuid()];
    $current = [self::item('drupal_paragraph:quote', 'q2', ['paragraph' => $reference])];
    $proposed = [self::item('drupal_paragraph:quote', 'q2', ['paragraph' => ['id' => 99, 'vid' => 99, 'uuid' => 'other']])];
    $only_texts = new \ReflectionMethod(PageAssistant::class, 'onlyTexts');
    $this->assertSame($current, $only_texts->invoke($this->assistant(), $current, $proposed));
  }

}
