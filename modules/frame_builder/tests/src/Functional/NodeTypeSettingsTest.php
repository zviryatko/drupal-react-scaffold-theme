<?php

declare(strict_types=1);

namespace Drupal\Tests\frame_builder\Functional;

use Drupal\node\Entity\NodeType;
use Drupal\Tests\frame_builder\Traits\EndpointTestTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * The builder as a setting of a content type, and what follows from it.
 */
#[Group('frame_builder')]
class NodeTypeSettingsTest extends BuilderFunctionalTestBase {

  use EndpointTestTrait;

  /**
   * The "Page builder" settings of the content type form are stored with
   * the content type and switch the builder on and off.
   */
  public function testSettingsOfTheContentType(): void {
    $this->drupalLogin($this->drupalCreateUser(array_merge(static::EDITOR_PERMISSIONS, ['administer content types'])));
    $assert = $this->assertSession();
    $stored = static function (string $type): array {
      \Drupal::entityTypeManager()->getStorage('node_type')->resetCache();
      return NodeType::load($type)->getThirdPartySettings('frame_builder');
    };

    // The form shows what the type has.
    $this->drupalGet('admin/structure/types/manage/landing');
    $assert->statusCodeEquals(200);
    $assert->pageTextContains('Page builder');
    $assert->checkboxChecked('frame_builder_enabled');
    $this->assertSame('synced', $this->getSession()->getPage()->findField('frame_builder_translation_mode')->getValue());

    $this->submitForm(['frame_builder_translation_mode' => 'independent'], 'Save');
    $this->assertSame(['enabled' => TRUE, 'translation_mode' => 'independent'], $stored('landing'));
    $this->drupalGet('admin/structure/types/manage/landing');
    $assert->checkboxChecked('frame_builder_enabled');
    $this->assertSame('independent', $this->getSession()->getPage()->findField('frame_builder_translation_mode')->getValue());

    // Switched off: the pages of the type are edited with the normal form.
    $node = $this->createBuilderNode();
    $this->drupalGet($node->toUrl('edit-form'));
    $assert->elementExists('css', 'iframe.frame-builder-frame');
    $this->drupalGet('admin/structure/types/manage/landing');
    $this->submitForm(['frame_builder_enabled' => FALSE], 'Save');
    $this->assertSame(['enabled' => FALSE, 'translation_mode' => 'independent'], $stored('landing'));
    $this->drupalGet($node->toUrl('edit-form'));
    $assert->elementNotExists('css', 'iframe.frame-builder-frame');
    $assert->fieldExists('title[0][value]');
    $this->drupalGet($this->builderUrl('editor_frame', $node));
    $assert->statusCodeEquals(403);

    // A new content type: off unless asked for, synced unless said otherwise.
    $this->drupalGet('admin/structure/types/add');
    $assert->checkboxNotChecked('frame_builder_enabled');
    $this->assertSame('synced', $this->getSession()->getPage()->findField('frame_builder_translation_mode')->getValue());
    $this->submitForm(['name' => 'Story', 'type' => 'story'], 'Save');
    $this->assertSame(['enabled' => FALSE, 'translation_mode' => 'synced'], $stored('story'));

    $this->drupalGet('admin/structure/types/add');
    $this->submitForm(['name' => 'Campaign', 'type' => 'campaign', 'frame_builder_enabled' => TRUE], 'Save');
    $this->assertSame(['enabled' => TRUE, 'translation_mode' => 'synced'], $stored('campaign'));
  }

  /**
   * A new page of a builder content type is created with the normal form
   * and continues in the builder; pages of other types do not.
   */
  public function testANewPageContinuesInTheBuilder(): void {
    $this->createBuilderType('plain', 'synced', FALSE);
    $this->drupalLogin($this->drupalCreateUser(array_merge(static::EDITOR_PERMISSIONS, ['create plain content', 'edit any plain content'])));
    $assert = $this->assertSession();

    // The form of a new page is the normal one: a page needs a title.
    $this->drupalGet('node/add/landing');
    $assert->statusCodeEquals(200);
    $assert->elementNotExists('css', 'iframe.frame-builder-frame');
    $this->submitForm(['title[0][value]' => 'Brand new'], 'Save');
    $nodes = \Drupal::entityTypeManager()->getStorage('node')->loadByProperties(['title' => 'Brand new']);
    $this->assertCount(1, $nodes);
    $node = reset($nodes);
    $this->assertSame($node->toUrl('edit-form')->setAbsolute()->toString(), $this->getUrl());
    $assert->elementAttributeContains('css', 'iframe.frame-builder-frame', 'src', '/node/' . $node->id() . '/builder/frame');
    $assert->pageTextContains('Brand new has been created.');

    // A content type without the builder: to the page, as always.
    $this->drupalGet('node/add/plain');
    $this->submitForm(['title[0][value]' => 'Plain new'], 'Save');
    $nodes = \Drupal::entityTypeManager()->getStorage('node')->loadByProperties(['title' => 'Plain new']);
    $this->assertSame(reset($nodes)->toUrl()->setAbsolute()->toString(), $this->getUrl());

    // Nor with a theme the builder does not work with.
    $this->container->get('theme_installer')->install(['stark']);
    $this->config('system.theme')->set('default', 'stark')->save();
    $this->drupalGet('node/add/landing');
    $this->submitForm(['title[0][value]' => 'Without the builder'], 'Save');
    $nodes = \Drupal::entityTypeManager()->getStorage('node')->loadByProperties(['title' => 'Without the builder']);
    $this->assertSame(reset($nodes)->toUrl()->setAbsolute()->toString(), $this->getUrl());
  }

  /**
   * Somebody who may not use the builder has no builder to continue in: a
   * new page is followed by the page, as for any content type.
   */
  public function testANewPageWithoutThePermission(): void {
    $this->drupalLogin($this->drupalCreateUser(['access content', 'create landing content', 'edit own landing content']));
    $this->drupalGet('node/add/landing');
    $this->submitForm(['title[0][value]' => 'Made without the builder'], 'Save');
    $nodes = \Drupal::entityTypeManager()->getStorage('node')->loadByProperties(['title' => 'Made without the builder']);
    $this->assertCount(1, $nodes);
    $this->assertSame(reset($nodes)->toUrl()->setAbsolute()->toString(), $this->getUrl());
  }

  /**
   * The status report says whether the default theme is one the builder
   * works with, and whether the editor is built.
   */
  public function testStatusReport(): void {
    $this->drupalLogin($this->drupalCreateUser(['administer site configuration', 'access site reports']));
    $assert = $this->assertSession();
    $built = file_exists(dirname(__DIR__, 3) . '/js/editor.js');

    $this->drupalGet('admin/reports/status');
    $assert->statusCodeEquals(200);
    $assert->pageTextContains('Default theme frame_builder_test_theme is based on Frame.');
    $assert->pageTextNotContains('The page builder is switched off');
    $built
      ? $assert->pageTextNotContains('The editor is not compiled.')
      : $assert->pageTextContains('The editor is not compiled.');

    $this->container->get('theme_installer')->install(['stark']);
    $this->config('system.theme')->set('default', 'stark')->save();
    $this->drupalGet('admin/reports/status');
    $assert->pageTextContains('Default theme stark is not based on Frame');
    $assert->pageTextContains('The page builder is switched off: content types that use it are edited with the normal form.');
  }

}
