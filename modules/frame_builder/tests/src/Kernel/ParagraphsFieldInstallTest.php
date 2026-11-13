<?php

declare(strict_types=1);

namespace Drupal\Tests\frame_builder\Kernel;

use Drupal\frame_builder\Paragraphs;
use Drupal\Tests\frame_builder\Traits\ParagraphsTestTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Paragraphs installed after the builder: nodes get the field that lists
 * the paragraphs of a layout.
 */
#[Group('frame_builder')]
#[RunTestsInSeparateProcesses]
class ParagraphsFieldInstallTest extends BuilderKernelTestBase {

  use ParagraphsTestTrait;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $this->requireModules('entity_reference_revisions', 'paragraphs');
    parent::setUp();
  }

  /**
   * The field is added when the modules are installed, once.
   */
  public function testFieldIsAddedWhenParagraphsIsInstalled(): void {
    $update_manager = $this->container->get('entity.definition_update_manager');
    $this->assertNull($update_manager->getFieldStorageDefinition(Paragraphs::FIELD, 'node'));
    $this->assertFalse($this->container->get('frame_builder.paragraphs')->available());
    // Without the modules there is nothing to add.
    frame_builder_install_paragraphs_field();
    $this->assertNull($update_manager->getFieldStorageDefinition(Paragraphs::FIELD, 'node'));
    $node = $this->createBuilderNode();

    $this->container->get('module_installer')->install(['paragraphs']);
    $this->container = \Drupal::getContainer();
    $this->assertTrue($this->container->get('frame_builder.paragraphs')->available());
    $definition = $this->container->get('entity.definition_update_manager')->getFieldStorageDefinition(Paragraphs::FIELD, 'node');
    $this->assertNotNull($definition, 'hook_modules_installed() added the field.');
    $this->assertSame('entity_reference_revisions', $definition->getType());
    $this->assertSame('frame_builder', $definition->getProvider());
    $this->assertTrue($this->container->get('database')->schema()->tableExists('node__' . Paragraphs::FIELD));
    $this->assertTrue($this->container->get('database')->schema()->tableExists('node_revision__' . Paragraphs::FIELD));

    // Called again (the update of existing sites): nothing to do, no error.
    frame_builder_install_paragraphs_field();
    $this->assertArrayNotHasKey('node', $this->container->get('entity.definition_update_manager')->getChangeSummary(), 'Nothing of nodes is left to update.');

    // A page made before gets its paragraphs on the next save.
    $this->createParagraphType();
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $storage->resetCache();
    $node = $storage->load($node->id());
    $this->assertTrue($node->hasField(Paragraphs::FIELD));
    $this->container->get('frame_builder.paragraph_drafts')->set($node, 'b1', [self::TEXT_FIELD => 'Typed']);
    $data = self::layout(self::paragraphItem('b1'));
    $saved = [];
    $this->assertSame([], $this->container->get('frame_builder.paragraphs')->persist($data, $node, $saved));
    $this->saveLayout($node, $data);
    $storage->resetCache();
    $this->assertSame([$saved['b1']['id']], array_map('intval', array_column($storage->load($node->id())->get(Paragraphs::FIELD)->getValue(), 'target_id')));
  }

}
