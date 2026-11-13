<?php

declare(strict_types=1);

namespace Drupal\Tests\frame_builder\Functional;

use Drupal\Tests\frame_builder\Traits\EndpointTestTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * The HTML of one item for the canvas of the editor: frame_builder.render.
 */
#[Group('frame_builder')]
class RenderEndpointTest extends BuilderFunctionalTestBase {

  use EndpointTestTrait;

  /**
   * Renders an item through the endpoint.
   *
   * @return array
   *   The status code and the decoded body: [int, mixed].
   */
  protected function renderItem(string $type, array $props = [], array $more = []): array {
    return $this->postJson($this->builderUrl('render'), ['type' => $type, 'props' => ['id' => 'x1'] + $props] + $more);
  }

  /**
   * A component of the theme is rendered with its props; its slots are
   * markers the editor fills.
   */
  public function testComponents(): void {
    [$status, $body] = $this->renderItem(static::PROBE, [
      'title' => 'On the canvas <b>&</b>',
      'shown' => TRUE,
      'tone' => 'loud',
      'count' => '4',
      // What is in a slot is not rendered here.
      'content' => [static::probe('p2', 'Nested')],
    ]);
    $this->assertSame(200, $status);
    $this->assertSame(['html', 'wrapper', 'styles'], array_keys($body));
    $this->assertNull($body['wrapper']);
    $this->assertNull($body['styles']);
    $html = $body['html'];
    $this->assertStringContainsString('probe--loud', $html);
    $this->assertStringContainsString('data-count="4"', $html);
    // Text is escaped by the template.
    $this->assertStringContainsString('<h2 class="probe__title">On the canvas &lt;b&gt;&amp;&lt;/b&gt;</h2>', $html);
    $this->assertMatchesRegularExpression('#<div class="probe__content">\s*<div data-frame-builder-slot="content"></div>\s*</div>#', $html);
    $this->assertStringNotContainsString('Nested', $html);

    // Tokens are replaced as on the page.
    [$status, $body] = $this->renderItem(static::PROBE, ['title' => 'At [site:name]', 'shown' => TRUE]);
    $this->assertSame(200, $status);
    $this->assertStringContainsString('At ' . $this->config('system.site')->get('name') . '</h2>', $body['html']);

    // A choice left empty is the default of the component.
    [$status, $body] = $this->renderItem(static::PROBE, ['tone' => '']);
    $this->assertSame(200, $status);
    $this->assertStringContainsString('probe--calm', $body['html']);

    // A component nobody has: an error the editor shows in its place.
    foreach (['frame_builder_test_theme:gone', 'nonsense', ''] as $type) {
      [$status, $body] = $this->renderItem($type);
      $this->assertSame(404, $status);
      $this->assertSame(['error' => 'Nothing to show: unknown component or no access.'], $body);
    }
    [$status, $body] = $this->postJson($this->builderUrl('render'), []);
    $this->assertSame(404, $status);
  }

  /**
   * A Field block is the field of the page being edited, with the values
   * the user typed and has not saved; without a value, a placeholder.
   */
  public function testFields(): void {
    $this->addTextField('field_note', 'Note');
    $node = $this->createBuilderNode(['title' => 'Saved title']);
    $of_page = ['node' => $node->id(), 'langcode' => 'en'];

    [$status, $body] = $this->renderItem('drupal_field:title', ['formatter' => 'string', 'label' => 'hidden'], $of_page);
    $this->assertSame(200, $status);
    $this->assertStringContainsString('Saved title', $body['html']);
    $this->assertStringContainsString('frame-builder-field--label-hidden', $body['html']);
    $this->assertStringNotContainsString('<a ', $body['html']);

    // The settings of the formatter apply.
    [$status, $body] = $this->renderItem('drupal_field:title', ['formatter' => ['type' => 'string', 'settings' => ['link_to_entity' => TRUE]], 'label' => 'inline'], $of_page);
    $this->assertSame(200, $status);
    $this->assertMatchesRegularExpression('#<a href="[^"]*/node/' . $node->id() . '"[^>]*>Saved title</a>#', $body['html']);
    $this->assertStringContainsString('frame-builder-field--label-inline', $body['html']);

    // A field without a value: something to see and select.
    $placeholder = '<div class="frame-builder-placeholder">The field "field_note" has no value on this page.</div>';
    [$status, $body] = $this->renderItem('drupal_field:field_note', ['formatter' => 'string'], $of_page);
    $this->assertSame(200, $status);
    $this->assertSame($placeholder, $body['html']);

    // Typed into the node form, not saved: shown at once.
    $this->keepFieldDraft($node, ['field_note[0][value]' => 'Typed note']);
    $this->keepFieldDraft($node, ['title[0][value]' => 'Typed title'], 'page');
    [$status, $body] = $this->renderItem('drupal_field:field_note', ['formatter' => 'string'], $of_page);
    $this->assertSame(200, $status);
    $this->assertStringContainsString('Typed note', $body['html']);
    [$status, $body] = $this->renderItem('drupal_field:title', ['formatter' => 'string'], $of_page);
    $this->assertStringContainsString('Typed title', $body['html']);
    $this->assertStringNotContainsString('Saved title', $body['html']);
    $this->assertTrue($this->latestRevision($node->id())->get('field_note')->isEmpty());

    // Without a page there is no field to show, nor for a field that is not
    // one of the page, nor for the layout itself.
    [$status, $body] = $this->renderItem('drupal_field:title', ['formatter' => 'string']);
    $this->assertSame(200, $status);
    $this->assertStringContainsString('frame-builder-placeholder', $body['html']);
    [$status, $body] = $this->renderItem('drupal_field:field_gone', ['formatter' => 'string'], $of_page);
    $this->assertSame(200, $status);
    $this->assertStringContainsString('The field "field_gone" has no value on this page.', $body['html']);
    $this->saveLayout($node, static::layout(static::probe('p1', 'Secret structure')));
    [$status, $body] = $this->renderItem('drupal_field:frame_layout', [], $of_page);
    $this->assertSame(200, $status);
    $this->assertStringNotContainsString('Secret structure', $body['html']);

    // Somebody else gets the saved values.
    $this->drupalLogin($this->drupalCreateUser(static::EDITOR_PERMISSIONS));
    [$status, $body] = $this->renderItem('drupal_field:title', ['formatter' => 'string'], $of_page);
    $this->assertStringContainsString('Saved title', $body['html']);
  }

  /**
   * The endpoint is for users of the builder, and shows fields only of
   * pages the user may edit.
   */
  public function testAccess(): void {
    $node = $this->createBuilderNode(['title' => 'Not for everybody', 'status' => 0]);
    $field = ['type' => 'drupal_field:title', 'node' => $node->id(), 'props' => ['id' => 'f1', 'formatter' => 'string']];
    $url = $this->builderUrl('render');

    [$status, $body] = $this->postJson($url, $field);
    $this->assertSame(200, $status);
    $this->assertStringContainsString('Not for everybody', $body['html']);

    // The permission, but not this page: as if there were no page.
    $this->drupalLogin($this->drupalCreateUser(['use frame builder', 'access content']));
    [$status, $body] = $this->postJson($url, $field);
    $this->assertSame(200, $status);
    $this->assertStringNotContainsString('Not for everybody', $body['html']);
    $this->assertStringContainsString('frame-builder-placeholder', $body['html']);
    // Components need no page.
    [$status, $body] = $this->renderItem(static::PROBE, ['title' => 'Open', 'shown' => TRUE]);
    $this->assertSame(200, $status);
    $this->assertStringContainsString('Open', $body['html']);

    // A page that does not exist is no page.
    [$status, $body] = $this->postJson($url, ['node' => 999] + $field);
    $this->assertSame(200, $status);
    $this->assertStringContainsString('frame-builder-placeholder', $body['html']);

    [$status] = $this->postJson($url, $field, 'not-the-token');
    $this->assertSame(403, $status);
    $this->assertSame(405, $this->request('GET', $url)->getStatusCode());

    $this->drupalLogin($this->drupalCreateUser(['access content', 'edit any landing content']));
    [$status] = $this->postJson($url, $field);
    $this->assertSame(403, $status);
    $this->drupalLogout();
    [$status] = $this->postJson($url, $field);
    $this->assertSame(403, $status);
  }

}
