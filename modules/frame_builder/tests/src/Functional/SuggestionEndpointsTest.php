<?php

declare(strict_types=1);

namespace Drupal\Tests\frame_builder\Functional;

use Drupal\user\RoleInterface;
use Drupal\file\Entity\File;
use Drupal\media\Entity\Media;
use Drupal\media_library\MediaLibraryState;
use Drupal\path_alias\Entity\PathAlias;
use Drupal\Tests\frame_builder\Traits\EndpointTestTrait;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use Drupal\Tests\TestFileCreationTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * What the editor offers while the user fills in a prop: pages to link to,
 * images, tokens, and Drupal's media library.
 */
#[Group('frame_builder')]
class SuggestionEndpointsTest extends BuilderFunctionalTestBase {

  use EndpointTestTrait;
  use MediaTypeCreationTrait;
  use TestFileCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['media', 'media_library', 'image', 'path_alias'];

  /**
   * Creates an image media item of the "image" media type.
   */
  protected function createImage(string $name, string $alt, bool $published = TRUE): Media {
    $image = current($this->getTestFiles('image'));
    $file = File::create(['uri' => $image->uri, 'status' => 1]);
    $file->save();
    $media = Media::create([
      'bundle' => 'image',
      'name' => $name,
      'status' => $published,
      'field_media_image' => ['target_id' => $file->id(), 'alt' => $alt],
    ]);
    $media->save();
    return $media;
  }

  /**
   * Links: content the user can see, found by title or by path, as the
   * value a link field stores.
   */
  public function testUrls(): void {
    $url = fn (array $query) => $this->getJson($this->builderUrl('urls', NULL, $query));
    $about = $this->createBuilderNode(['title' => 'About the company']);
    $team = $this->createBuilderNode(['title' => 'The company team']);
    // Not published and of somebody else: the editor may not see it.
    $hidden = $this->createBuilderNode(['title' => 'Secrets', 'status' => 0, 'uid' => 1]);
    PathAlias::create(['path' => '/node/' . $about->id(), 'alias' => '/about-us', 'langcode' => 'en'])->save();
    PathAlias::create(['path' => '/user/' . $this->editor->id(), 'alias' => '/about-me', 'langcode' => 'en'])->save();
    PathAlias::create(['path' => '/node/' . $hidden->id(), 'alias' => '/about-secrets', 'langcode' => 'en'])->save();
    $describe = static fn ($node, string $path): array => [
      'label' => $node->label(),
      'value' => 'entity:node/' . $node->id(),
      'url' => $path,
      'meta' => 'Landing',
    ];

    // By title: at least two characters, pages the user may see.
    $this->assertSame([200, []], $url([]));
    $this->assertSame([200, []], $url(['q' => 'c']));
    [$status, $found] = $url(['q' => 'company']);
    $this->assertSame(200, $status);
    $this->assertEqualsCanonicalizing([$describe($about, '/about-us'), $describe($team, '/node/' . $team->id())], $found);
    $this->assertSame([200, [$describe($team, '/node/' . $team->id())]], $url(['q' => ' team ']));
    $this->assertSame([200, []], $url(['q' => 'nothing like this']));

    // By path: aliases that start with what was typed. Content is offered
    // as content, so the link follows the page; other paths as typed.
    [$status, $found] = $url(['q' => '/about']);
    $this->assertSame(200, $status);
    $this->assertSame([
      ['label' => '/about-me', 'value' => '/about-me', 'url' => '/about-me', 'meta' => 'Path'],
      $describe($about, '/about-us'),
    ], $found);
    $this->assertSame([200, [$describe($about, '/about-us')]], $url(['q' => '/about-u']));
    $this->assertSame([200, [$describe($team, '/node/' . $team->id())]], $url(['q' => '/node/' . $team->id()]));
    $this->assertSame([200, []], $url(['q' => '/node/' . $hidden->id()]));
    $this->assertSame([200, []], $url(['q' => '/node/9999']));
    $this->assertSame([200, []], $url(['q' => '/nowhere']));

    // A stored value is described for the editor.
    $this->assertSame([200, $describe($about, '/about-us')], $url(['resolve' => 'entity:node/' . $about->id()]));
    // Nothing to describe is an empty answer.
    $this->assertSame([200, []], $url(['resolve' => 'entity:node/' . $hidden->id()]));
    $this->assertSame([200, []], $url(['resolve' => 'entity:node/9999']));
    $this->assertSame([200, []], $url(['resolve' => 'https://example.com/']));

    // Somebody who may see unpublished pages finds them.
    $this->drupalLogin($this->drupalCreateUser(['use frame builder', 'access content', 'bypass node access']));
    $this->assertSame([200, [$describe($hidden, '/about-secrets')]], $url(['q' => 'secret']));
    $this->assertSame([200, [$describe($hidden, '/about-secrets')]], $url(['q' => '/about-s']));
    $this->assertSame([200, $describe($hidden, '/about-secrets')], $url(['resolve' => 'entity:node/' . $hidden->id()]));
  }

  /**
   * A page the user may not see is not offered by its title either.
   */
  public function testUrlsLeaveOutContentTheUserMayNotSee(): void {
    $this->createBuilderNode(['title' => 'Company secrets', 'status' => 0, 'uid' => 1]);
    $visible = $this->createBuilderNode(['title' => 'Company news']);
    [$status, $found] = $this->getJson($this->builderUrl('urls', NULL, ['q' => 'company']));
    $this->assertSame(200, $status);
    $this->assertSame(['entity:node/' . $visible->id()], array_column($found, 'value'));
  }

  /**
   * Images: published image media, by name.
   */
  public function testMedia(): void {
    $url = fn (array $query = []) => $this->getJson($this->builderUrl('media', NULL, $query));
    // No media type with images yet.
    $this->assertSame([200, []], $url());

    $this->createMediaType('image', ['id' => 'image']);
    $this->createMediaType('file', ['id' => 'document']);
    $this->drupalLogin($this->drupalCreateUser(array_merge(static::EDITOR_PERMISSIONS, ['view media'])));
    $this->assertSame([200, []], $url());

    $sea = $this->createImage('Sea view', 'Waves');
    $hill = $this->createImage('Hill view', 'A hill');
    $this->createImage('Hidden view', 'Nothing', FALSE);
    $file = File::create(['uri' => current($this->getTestFiles('text'))->uri, 'status' => 1]);
    $file->save();
    Media::create(['bundle' => 'document', 'name' => 'A view on paper', 'field_media_file' => $file->id()])->save();

    [$status, $found] = $url();
    $this->assertSame(200, $status);
    $this->assertEqualsCanonicalizing(['Sea view', 'Hill view'], array_column($found, 'label'));
    $item = array_column($found, NULL, 'label')['Sea view'];
    $this->assertSame(['id', 'label', 'alt', 'url', 'thumb'], array_keys($item));
    $this->assertSame((int) $sea->id(), $item['id']);
    $this->assertSame('Waves', $item['alt']);
    $uri = $sea->get('field_media_image')->entity->getFileUri();
    $this->assertSame(\Drupal::service('file_url_generator')->generateString($uri), $item['url']);
    // The thumbnail is the "medium" image style.
    $this->assertStringContainsString('/styles/medium/', $item['thumb']);
    $this->assertStringStartsWith('/', $item['thumb']);

    [$status, $found] = $url(['q' => 'hill']);
    $this->assertSame([(int) $hill->id()], array_column($found, 'id'));
    $this->assertSame([200, []], $url(['q' => 'paper']));
    $this->assertSame([200, []], $url(['q' => 'hidden']));
  }

  /**
   * Media the user may not view are not offered.
   */
  public function testMediaLeavesOutWhatTheUserMayNotSee(): void {
    $this->createMediaType('image', ['id' => 'image']);
    $this->createImage('Sea view', 'Waves');
    // Media gives every user the right to view media when it is installed.
    user_role_revoke_permissions(RoleInterface::AUTHENTICATED_ID, ['view media']);
    $this->assertSame([200, []], $this->getJson($this->builderUrl('media')));
  }

  /**
   * Tokens: the global types and the node, with the types they lead to.
   */
  public function testTokens(): void {
    [$status, $tree] = $this->getJson($this->builderUrl('tokens'));
    $this->assertSame(200, $status);
    $this->assertSame(['roots', 'types'], array_keys($tree));
    // The node first, then what needs no data.
    $this->assertSame('node', $tree['roots'][0]);
    $this->assertContains('site', $tree['roots']);
    $this->assertContains('date', $tree['roots']);
    $this->assertContains('current-user', $tree['roots']);
    // Types that need an entity the page does not have are reached only
    // through a token.
    $this->assertNotContains('user', $tree['roots']);
    $this->assertArrayHasKey('user', $tree['types']);
    foreach ($tree['roots'] as $root) {
      $this->assertArrayHasKey($root, $tree['types']);
    }

    $node = $tree['types']['node'];
    $this->assertSame('Nodes', $node['label']);
    $tokens = array_column($node['tokens'], NULL, 'name');
    $this->assertSame(['name', 'label', 'description', 'type', 'dynamic'], array_keys($tokens['title']));
    $this->assertSame('Title', $tokens['title']['label']);
    $this->assertNull($tokens['title']['type']);
    $this->assertFalse($tokens['title']['dynamic']);
    // A token that leads on names its type.
    $this->assertSame('user', $tokens['author']['type']);
    $this->assertSame('date', $tokens['created']['type']);
    $this->assertArrayHasKey('name', array_column($tree['types']['site']['tokens'], NULL, 'name'));
    // Sorted by label, descriptions without markup.
    $labels = array_column($node['tokens'], 'label');
    $sorted = $labels;
    usort($sorted, 'strnatcasecmp');
    $this->assertSame($sorted, $labels);
    foreach ($tree['types'] as $type) {
      foreach ($type['tokens'] as $token) {
        $this->assertSame(strip_tags($token['description']), $token['description']);
      }
    }
  }

  /**
   * Drupal's media library in a frame: offered when there are image media
   * types, a bare page, and only for the opener of the builder.
   */
  public function testMediaLibraryFrame(): void {
    $node = $this->createBuilderNode();
    // No image media type: nothing to open.
    $this->assertArrayNotHasKey('mediaLibrary', $this->editorSettings($node)['endpoints']);

    $this->createMediaType('image', ['id' => 'image']);
    $this->createMediaType('file', ['id' => 'document']);
    $this->drupalLogin($this->drupalCreateUser(array_merge(static::EDITOR_PERMISSIONS, ['view media'])));
    $this->createImage('Sea view', 'Waves');

    $endpoint = $this->editorSettings($node)['endpoints']['mediaLibrary'];
    $this->assertStringContainsString('/frame-builder/media-library?', $endpoint);
    parse_str(parse_url($endpoint, PHP_URL_QUERY), $query);
    $this->assertSame('frame_builder.media_library_opener', $query['media_library_opener_id']);
    // Image types only, one image to pick.
    $this->assertSame(['image'], $query['media_library_allowed_types']);
    $this->assertSame('image', $query['media_library_selected_type']);
    $this->assertSame('1', $query['media_library_remaining']);

    $this->drupalGet($this->builderUrl('media_frame', NULL, $query));
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->elementExists('css', '#media-library-wrapper');
    $this->assertSession()->pageTextContains('Sea view');
    $this->assertSession()->responseContains('js-static/media-frame.js');
    // Only the library: no page around it.
    $this->assertSession()->elementNotExists('css', '.dialog-off-canvas-main-canvas');
    $this->assertSession()->elementNotExists('css', '.page__header');
    $this->assertSession()->elementNotExists('css', 'h1');

    // The library opened for something else is not served here.
    $other = MediaLibraryState::create('media_library.opener.field_widget', ['image'], 'image', 1);
    $this->drupalGet($this->builderUrl('media_frame', NULL, $other->all()));
    $this->assertSession()->statusCodeEquals(403);
    // Nor a state that was changed after the builder made it.
    $this->drupalGet($this->builderUrl('media_frame', NULL, ['media_library_remaining' => 5] + $query));
    $this->assertSession()->statusCodeEquals(400);
  }

  /**
   * Without the right to see media the library stays closed, as Drupal's
   * own media library does.
   */
  public function testMediaLibraryFrameNeedsAccessToMedia(): void {
    $this->createMediaType('image', ['id' => 'image']);
    $this->createImage('Sea view', 'Waves');
    $state = MediaLibraryState::create('frame_builder.media_library_opener', ['image'], 'image', 1);
    // Media gives every user the right to view media when it is installed.
    user_role_revoke_permissions(RoleInterface::AUTHENTICATED_ID, ['view media']);
    $this->drupalGet($this->builderUrl('media_frame', NULL, $state->all()));
    $this->assertSession()->statusCodeEquals(403);
    $this->assertSession()->pageTextNotContains('Sea view');
  }

}
