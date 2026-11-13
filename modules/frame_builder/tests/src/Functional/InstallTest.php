<?php

declare(strict_types=1);

namespace Drupal\Tests\frame_builder\Functional;

use Drupal\node\Entity\NodeType;
use Drupal\Tests\frame_builder\Traits\EndpointTestTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * Installing and uninstalling the module.
 */
#[Group('frame_builder')]
class InstallTest extends BuilderFunctionalTestBase {

  use EndpointTestTrait;

  /**
   * Installed: the "Landing page" content type, the layout field of nodes,
   * the entity type of saved blocks and the permission are there.
   */
  public function testInstalled(): void {
    // The content type of config/optional, with the builder switched on.
    $type = NodeType::load('landing_page');
    $this->assertNotNull($type);
    $this->assertSame('Landing page', $type->label());
    $this->assertSame(['enabled' => TRUE, 'translation_mode' => 'synced'], $type->getThirdPartySettings('frame_builder'));
    $this->assertTrue($type->shouldCreateNewRevision());
    $this->assertFalse($type->displaySubmitted());

    // The layout: a base field of nodes, per revision and translation,
    // with its columns in the tables of nodes.
    $manager = \Drupal::entityDefinitionUpdateManager();
    $storage = $manager->getFieldStorageDefinition('frame_layout', 'node');
    $this->assertNotNull($storage);
    $this->assertSame('frame_builder', $storage->getProvider());
    $this->assertSame('string_long', $storage->getType());
    $this->assertTrue($storage->isRevisionable());
    $this->assertTrue($storage->isTranslatable());
    $schema = \Drupal::database()->schema();
    $this->assertTrue($schema->fieldExists('node_field_data', 'frame_layout'));
    $this->assertTrue($schema->fieldExists('node_field_revision', 'frame_layout'));
    // It is neither a field of the node form nor of a display.
    $definition = \Drupal::service('entity_field.manager')->getBaseFieldDefinitions('node')['frame_layout'];
    $this->assertFalse($definition->isDisplayConfigurable('form'));
    $this->assertFalse($definition->isDisplayConfigurable('view'));
    // Without Paragraphs there is no field for the paragraphs of a layout.
    $this->assertNull($manager->getFieldStorageDefinition('frame_paragraphs', 'node'));

    // Saved blocks.
    $saved = \Drupal::entityTypeManager()->getDefinition('frame_saved');
    $this->assertNotNull($manager->getEntityType('frame_saved'));
    $this->assertTrue($schema->tableExists($saved->getBaseTable()));
    $this->assertNotNull($manager->getFieldStorageDefinition('log', 'frame_saved'));

    // What is installed is what the code declares.
    $this->assertFalse($manager->needsUpdates());

    $this->assertArrayHasKey('use frame builder', \Drupal::service('user.permissions')->getPermissions());

    // A page of the shipped type is built with the builder at once.
    $this->drupalLogin($this->drupalCreateUser(['use frame builder', 'access content', 'create landing_page content', 'edit any landing_page content']));
    $this->drupalGet('node/add/landing_page');
    $this->submitForm(['title[0][value]' => 'Out of the box'], 'Save');
    $this->assertSession()->elementExists('css', 'iframe.frame-builder-frame');
  }

  /**
   * Uninstalled: the layouts and saved blocks are gone, content types and
   * pages stay and work as before the builder; the module can be installed
   * again.
   */
  public function testUninstallAndInstallAgain(): void {
    $node = $this->createBuilderNode(['title' => 'Stays']);
    $nid = $node->id();
    [$status] = $this->saveThroughEndpoint($node, static::layout(static::probe('p1', 'Built')));
    $this->assertSame(200, $status);
    $base_table = \Drupal::entityTypeManager()->getDefinition('frame_saved')->getBaseTable();

    $installer = $this->container->get('module_installer');
    $this->assertSame([], $installer->validateUninstall(['frame_builder']));
    $installer->uninstall(['frame_builder']);
    $this->rebuildAll();

    $this->assertFalse(\Drupal::moduleHandler()->moduleExists('frame_builder'));
    $this->assertFalse(\Drupal::entityTypeManager()->hasDefinition('frame_saved'));
    $schema = \Drupal::database()->schema();
    $this->assertFalse($schema->tableExists($base_table));
    $this->assertFalse($schema->fieldExists('node_field_data', 'frame_layout'));
    $this->assertFalse($schema->fieldExists('node_field_revision', 'frame_layout'));
    $this->assertFalse(\Drupal::entityDefinitionUpdateManager()->needsUpdates());
    // Content types stay, without the setting of the builder.
    $this->assertNotNull(NodeType::load('landing'));
    $this->assertSame([], NodeType::load('landing')->getThirdPartySettings('frame_builder'));

    // The page is there, shown and edited the normal way.
    $this->drupalLogin($this->drupalCreateUser(['access content', 'edit any landing content']));
    $this->drupalGet("node/$nid");
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Stays');
    $this->assertSession()->elementNotExists('css', '.probe');
    $this->drupalGet("node/$nid/edit");
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->fieldValueEquals('title[0][value]', 'Stays');
    $this->drupalGet("node/$nid/builder");
    $this->assertSession()->statusCodeEquals(404);

    // Installed again: a clean start.
    $installer->install(['frame_builder']);
    $this->rebuildAll();
    $this->assertTrue(\Drupal::database()->schema()->fieldExists('node_field_data', 'frame_layout'));
    $this->assertTrue(\Drupal::database()->schema()->tableExists($base_table));
    $this->assertFalse(\Drupal::entityDefinitionUpdateManager()->needsUpdates());
    $this->assertNotNull(NodeType::load('landing_page'));
    $again = $this->latestRevision($nid);
    $this->assertSame('Stays', $again->label());
    $this->assertTrue($again->get('frame_layout')->isEmpty());
  }

}
