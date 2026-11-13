<?php

declare(strict_types=1);

namespace Drupal\Tests\frame_builder\Functional;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\Tests\frame_builder\Traits\EndpointTestTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * The settings form of the formatter of a Field block: in a frame, and
 * inline with HTMX.
 */
#[Group('frame_builder')]
class FormatterSettingsFormTest extends BuilderFunctionalTestBase {

  use EndpointTestTrait;

  /**
   * In a frame: the formatter's own form with the settings of the block;
   * "Apply" gives the settings and their summary to the script of the
   * frame, and saves nothing in Drupal.
   */
  public function testFormInAFrame(): void {
    $node = $this->createBuilderNode();
    $assert = $this->assertSession();
    $url = fn (array $query) => $this->builderUrl('formatter_settings', $node, $query);

    // The settings the block has are the values of the form.
    $this->drupalGet($url(['field' => 'title', 'formatter' => 'string', 'settings' => Json::encode(['link_to_entity' => TRUE])]));
    $assert->statusCodeEquals(200);
    $assert->checkboxChecked('settings[link_to_entity]');
    $assert->buttonExists('Apply');
    $assert->responseContains('js-static/formatter-frame.js');
    $assert->elementNotExists('css', '.dialog-off-canvas-main-canvas');
    // Nothing applied yet.
    $this->assertArrayNotHasKey('frameBuilderFormatter', $this->getDrupalSettings());

    $this->submitForm(['settings[link_to_entity]' => FALSE], 'Apply');
    $assert->statusCodeEquals(200);
    $result = $this->getDrupalSettings()['frameBuilderFormatter'];
    $this->assertSame(['settings', 'summary'], array_keys($result));
    $this->assertEmpty($result['settings']['link_to_entity']);
    $this->assertSame([], $result['summary']);
    // The form stays, with what was applied.
    $assert->checkboxNotChecked('settings[link_to_entity]');

    $this->submitForm(['settings[link_to_entity]' => TRUE], 'Apply');
    $result = $this->getDrupalSettings()['frameBuilderFormatter'];
    $this->assertNotEmpty($result['settings']['link_to_entity']);
    // The summary of the formatter, as plain text.
    $this->assertCount(1, $result['summary']);
    $this->assertStringContainsStringIgnoringCase('link', $result['summary'][0]);
    $this->assertSame(strip_tags($result['summary'][0]), $result['summary'][0]);
    $assert->checkboxChecked('settings[link_to_entity]');

    // Without settings in the request, or with something that is not
    // settings, the form has the defaults of the formatter.
    foreach ([[], ['settings' => 'not json'], ['settings' => '"a string"']] as $query) {
      $this->drupalGet($url(['field' => 'title', 'formatter' => 'string'] + $query));
      $assert->statusCodeEquals(200);
      $assert->checkboxNotChecked('settings[link_to_entity]');
    }

    // A formatter without settings says so and has nothing to apply.
    $this->drupalGet($url(['field' => 'uid', 'formatter' => 'author']));
    $assert->elementTextEquals('css', '[data-frame-builder-no-settings]', 'This format has no settings.');
    $assert->buttonNotExists('Apply');

    // A field the page does not have.
    foreach ([['field' => 'field_gone', 'formatter' => 'string'], []] as $query) {
      $this->drupalGet($url($query));
      $assert->statusCodeEquals(200);
      $assert->pageTextContains('Unknown field.');
      $assert->buttonNotExists('Apply');
    }

    // Applying is not saving: the page and its display are untouched.
    $this->assertSame(1, $this->revisionCount($node->id()));
    $this->assertNull(EntityViewDisplay::load('node.landing.default'));
  }

  /**
   * Inline: the same form as an HTMX fragment that posts itself on every
   * change; the result is an element the editor reads.
   */
  public function testInlineForm(): void {
    $node = $this->createBuilderNode();
    $assert = $this->assertSession();
    $query = ['field' => 'title', 'formatter' => 'string', 'settings' => Json::encode(['link_to_entity' => TRUE])];

    $this->drupalGet($this->builderUrl('formatter_form', $node, $query));
    $assert->statusCodeEquals(200);
    $form = $assert->elementExists('css', 'form.frame-builder-formatter-form');
    $assert->checkboxChecked('settings[link_to_entity]');
    // It posts to its own URL, with its own parameters.
    $post = $form->getAttribute('data-hx-post');
    $this->assertStringContainsString('/node/' . $node->id() . '/builder/formatter-form?', $post);
    parse_str((string) parse_url($post, PHP_URL_QUERY), $posted);
    $this->assertSame(['field' => 'title', 'formatter' => 'string', 'settings' => '{"link_to_entity":true}'], $posted);
    $this->assertStringContainsString('change', $form->getAttribute('data-hx-trigger'));
    $this->assertSame('[data-frame-builder-formatter-result]', $form->getAttribute('data-hx-select'));
    $this->assertSame('next .frame-builder-subform__result', $form->getAttribute('data-hx-target'));
    // No result before a change, and no script of the frame.
    $assert->elementNotExists('css', '[data-frame-builder-formatter-result]');
    $assert->responseNotContains('js-static/formatter-frame.js');
    // A fragment: no page around the form.
    $assert->elementNotExists('css', '.dialog-off-canvas-main-canvas');
    $assert->elementNotExists('css', 'h1');

    // What HTMX posts on a change.
    $this->submitForm(['settings[link_to_entity]' => FALSE], 'Apply');
    $assert->statusCodeEquals(200);
    $result = Json::decode($assert->elementExists('css', '[data-frame-builder-formatter-result]')->getAttribute('data-frame-builder-formatter-result'));
    $this->assertSame(['settings', 'summary'], array_keys($result));
    $this->assertEmpty($result['settings']['link_to_entity']);
    $this->assertSame([], $result['summary']);
    // The form now posts the applied settings along.
    parse_str((string) parse_url($assert->elementExists('css', 'form.frame-builder-formatter-form')->getAttribute('data-hx-post'), PHP_URL_QUERY), $posted);
    $this->assertEmpty(Json::decode($posted['settings'])['link_to_entity']);

    $this->drupalGet($this->builderUrl('formatter_form', $node, ['field' => 'uid', 'formatter' => 'author']));
    $assert->elementExists('css', '[data-frame-builder-no-settings]');
    $this->drupalGet($this->builderUrl('formatter_form', $node, ['field' => 'field_gone', 'formatter' => 'string']));
    $assert->pageTextContains('Unknown field.');
  }

}
