<?php

declare(strict_types=1);

namespace Drupal\Tests\frame_builder\Kernel;

use Drupal\frame_builder\ComponentCatalog;
use Drupal\Tests\frame_builder\Traits\RenderingTestTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * How the builder reads the schema of a prop: the ways a schema can say what
 * a value is, what it limits, and what is checked when a page is saved.
 */
#[Group('frame_builder')]
#[RunTestsInSeparateProcesses]
class SchemaTest extends BuilderKernelTestBase {

  use RenderingTestTrait;

  protected const PROBE = 'frame_builder_test_theme:schema-probe';

  /**
   * The editor fields of the component, by name.
   */
  protected function fields(): array {
    $config = array_column($this->container->get('frame_builder.catalog')->editorConfig($this->createBuilderNode()), NULL, 'id');
    return array_column($config[self::PROBE]['fields'], NULL, 'name');
  }

  /**
   * One part of a "oneOf", all of an "allOf"; what only points elsewhere is
   * not understood.
   */
  public function testSchemaIsRead(): void {
    $this->assertSame(['type' => 'string', 'title' => 'T'], ComponentCatalog::schema(['type' => 'string', 'title' => 'T']));
    $this->assertSame(['title' => 'T'], ComponentCatalog::schema(['title' => 'T']), 'No type is text, as before.');
    $this->assertSame(['title' => 'T', 'type' => 'integer', 'minimum' => 1], ComponentCatalog::schema(['title' => 'T', 'oneOf' => [['type' => 'null'], ['type' => 'integer', 'minimum' => 1], ['type' => 'string']]]));
    $this->assertSame(['title' => 'T', 'type' => 'string', 'maxLength' => 5], ComponentCatalog::schema(['title' => 'T', 'allOf' => [['type' => 'string'], ['maxLength' => 5]]]));
    $this->assertNull(ComponentCatalog::schema(['$ref' => '#/$defs/thing']));
    $this->assertNull(ComponentCatalog::schema(['anyOf' => [['$ref' => '#/$defs/thing']]]));
  }

  /**
   * The field each way of saying it gets.
   */
  public function testFields(): void {
    $fields = $this->fields();
    foreach (['id', 'kind', 'elsewhere', 'unknown'] as $name) {
      $this->assertArrayNotHasKey($name, $fields, "$name has no field.");
    }
    $this->assertSame('number', $fields['either']['widget']);
    $this->assertSame(1, $fields['either']['min']);
    $this->assertSame('text', $fields['both']['widget']);
    $this->assertSame(5, $fields['both']['maxLength']);
    $this->assertSame('boolean', $fields['flag']['widget'], 'Yes or no is a checkbox, also as an enum.');
    $this->assertArrayNotHasKey('options', $fields['flag']);
    $this->assertSame(['min' => 0, 'max' => 10, 'step' => 0.5], array_intersect_key($fields['amount'], ['min' => 1, 'max' => 1, 'step' => 1]));
    $this->assertSame(['email', 'date', 'time', 'datetime'], [$fields['mail']['widget'], $fields['day']['widget'], $fields['hour']['widget'], $fields['when']['widget']]);
    $this->assertSame('choices', $fields['days']['widget']);
    $this->assertSame([['value' => 'mon', 'label' => 'Monday'], ['value' => 'tue', 'label' => 'Tuesday'], ['value' => 'wed', 'label' => 'Wednesday']], $fields['days']['options']);
    $this->assertFalse($fields['days']['translatable']);

    // Four levels of groups have fields; the fifth has none.
    $c = $fields['deep']['fields'][0]['fields'][0]['fields'][0];
    $this->assertSame('c', $c['name']);
    $this->assertSame(['here'], array_column($c['fields'], 'name'));
  }

  /**
   * What the component receives.
   */
  public function testRendered(): void {
    $html = $this->renderHtml($this->container->get('frame_builder.renderer')->buildItem(self::item(self::PROBE, 'item-1', [
      'kind' => 'changed',
      'either' => '7',
      'both' => 'Hello',
      'elsewhere' => ['any' => 'thing'],
      'flag' => FALSE,
      'tone' => 'purple',
      'days' => ['wed', 'someday', 'mon'],
      'amount' => '2.5',
      'deep' => ['a' => ['b' => ['c' => ['here' => 'reached', 'd' => ['e' => 'too deep']]]]],
    ])));
    $this->assertStringContainsString('data-id=""', $html, 'The id of the item in the layout is not the prop "id" of the component.');
    $this->assertStringContainsString('data-kind="fixed"', $html, 'What the schema fixes is what the component gets.');
    $this->assertStringContainsString('data-either="7"', $html);
    $this->assertStringContainsString('>Hello</div>', $html);
    $this->assertStringContainsString('data-flag="no"', $html);
    $this->assertStringContainsString('data-tone=""', $html, 'A choice that is none of the choices is no value.');
    $this->assertStringContainsString('data-days="wed,mon"', $html);
    $this->assertStringContainsString('data-amount="2.5"', $html);
    $this->assertStringContainsString('data-here="reached"', $html);
    $this->assertStringContainsString('data-e=""', $html, 'Deeper than the fields go, nothing is passed on.');
  }

  /**
   * What the schema does not allow is said when a page is saved.
   */
  public function testProblems(): void {
    $catalog = $this->container->get('frame_builder.catalog');
    $valid = ['either' => 3, 'both' => 'Hello', 'flag' => FALSE, 'amount' => 2.5, 'code' => 'ABC', 'mail' => 'a@example.org', 'day' => '2026-11-12', 'hour' => '09:30', 'tone' => 'loud', 'days' => ['mon', 'wed']];
    $this->assertSame([], $catalog->missing([self::item(self::PROBE, 'p', $valid)]));
    $this->assertSame([], $catalog->missing([self::item(self::PROBE, 'p', ['both' => 'Hello [site:name]', 'code' => '[node:title]'])]), 'A text with a token is not measured.');
    $cases = [
      ['either', 0, 'Either must be 1 or more'],
      ['both', 'Too long', 'Both can have at most 5 characters'],
      ['amount', 11, 'Amount must be 10 or less'],
      ['amount', -1, 'Amount must be 0 or more'],
      ['amount', 0.3, 'Amount must be a multiple of 0.5'],
      ['code', 'AB', 'Code needs at least 3 characters'],
      ['code', 'abcd', 'Code does not have the form it needs'],
      ['mail', 'nobody', 'Mail is not an email address'],
      ['day', 'tomorrow', 'Day is not a date'],
      ['hour', 'noon', 'Hour is not a time'],
      ['tone', 'purple', 'Tone is not one of the choices'],
      ['days', ['mon', 'mon'], 'Days has the same value twice'],
      ['days', ['mon', 'someday'], 'Days has a value that is not one of the choices'],
    ];
    foreach ($cases as [$name, $value, $message]) {
      $this->assertSame(["Schema probe: $message."], $catalog->missing([self::item(self::PROBE, 'p', [$name => $value] + $valid)]), $name);
    }
  }

  /**
   * Shapes real components have: a number or a text, the rows of a table,
   * an optional group with a choice that has a default, and text in square
   * brackets that is no token.
   */
  public function testShapes(): void {
    $fields = $this->fields();
    $this->assertSame('text', $fields['mixed']['widget'], 'A number or a text is typed as text.');
    $this->assertSame('list', $fields['table']['item']['widget']);
    $this->assertSame('text', $fields['table']['item']['item']['widget']);

    $render = fn (array $props): string => $this->renderHtml($this->container->get('frame_builder.renderer')->buildItem(self::item(self::PROBE, 'p', $props), $this->createBuilderNode(['title' => 'The page'])));
    $this->assertStringContainsString('data-mixed="12"', $render(['mixed' => 12]));
    $this->assertStringContainsString('data-mixed="(min-width: 40em)"', $render(['mixed' => '(min-width: 40em)']));

    $html = $render(['table' => [['a', '', 'c'], ['', '', ''], ['d', 'e', '']]]);
    $this->assertStringContainsString('<p>a||c</p><p>d|e|</p>', $html, 'A cell without text keeps its place; a row without any is no row.');

    $this->assertStringContainsString('data-target="none"', $render(['target' => ['window' => '']]), 'A group with nothing set is no group, whatever a choice in it defaults to.');
    $this->assertStringContainsString('data-target="/x|_self"', $render(['target' => ['url' => '/x']]));
    $this->assertStringContainsString('data-target="/x|_blank"', $render(['target' => ['url' => '/x', 'window' => '_blank']]));

    $html = $render(['both' => 'See [note: below], [1] and [node:title][node:no-such-token]']);
    $this->assertStringContainsString('See [note: below], [1] and The page</div>', $html, 'Text in square brackets stays; a token of the page without a value leaves nothing.');
  }

  /**
   * The help of a component, from `help` or from the settings of the
   * builder; core accepts the key in a *.component.yml.
   */
  public function testHelp(): void {
    $config = array_column($this->container->get('frame_builder.catalog')->editorConfig($this->createBuilderNode()), NULL, 'id');
    $this->assertSame('How to use the list probe.', $config['frame_builder_test_theme:list-probe']['help']);
    $this->assertSame('Help from the settings of the builder.', $config[self::PROBE]['help']);
    $this->assertSame('', $config['frame_builder_test_theme:probe']['help']);
    $this->assertStringStartsWith('A list of links. Drag the rows', $config['frame:link-list']['help']);
  }

}
