<?php

declare(strict_types=1);

namespace Drupal\Tests\frame_builder\Kernel;

use Drupal\frame_builder\NodeProperties;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\Tests\content_moderation\Traits\ContentModerationTestTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The Publish and Draft buttons: the published flag without content
 * moderation, the "published" and "draft" states of the workflow with it.
 */
#[Group('frame_builder')]
#[RunTestsInSeparateProcesses]
class NodePropertiesTest extends BuilderKernelTestBase {

  use ContentModerationTestTrait;

  /**
   * The service under test.
   */
  protected function properties(): NodeProperties {
    return $this->container->get('frame_builder.node_properties');
  }

  /**
   * Installs content moderation and puts the "landing" type under the
   * editorial workflow of core: draft, published and archived.
   */
  protected function moderate(): void {
    $this->enableModules(['workflows', 'content_moderation']);
    $this->installEntitySchema('content_moderation_state');
    $this->installConfig(['content_moderation']);
    $workflow = $this->createEditorialWorkflow();
    $this->addEntityTypeAndBundleToWorkflow($workflow, 'node', 'landing');
  }

  /**
   * The page as it is stored now: its latest revision.
   */
  protected function latest(NodeInterface $node): NodeInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $storage->resetCache();
    return $storage->loadRevision($storage->getLatestRevisionId($node->id()));
  }

  /**
   * Without moderation, who may administer content publishes and
   * unpublishes with the published flag. The caller saves.
   */
  public function testFlagWithPermission(): void {
    $node = $this->createBuilderNode(['status' => 0]);
    $this->setCurrentUser($this->createUser(['access content', 'administer nodes']));
    $this->assertTrue($this->properties()->canPublish($node));
    $this->assertTrue($this->properties()->canSaveDraft($node));

    $this->properties()->applyPublish($node);
    $this->assertTrue($node->isPublished());
    $this->assertFalse(Node::load($node->id())->isPublished(), 'Nothing is saved.');
    $node->save();
    $this->assertTrue($this->latest($node)->isPublished());

    $this->assertTrue($this->properties()->canPublish($node), 'A published page can be published again.');
    $this->properties()->applyDraft($node);
    $this->assertFalse($node->isPublished());
    $node->save();
    $this->assertFalse($this->latest($node)->isPublished());
    $this->properties()->applyDraft($node);
    $this->assertFalse($node->isPublished());
  }

  /**
   * Without moderation, who may only edit the page cannot change whether it
   * is published: the buttons do nothing.
   */
  public function testFlagWithoutPermission(): void {
    $this->setCurrentUser($this->createUser(['access content', 'edit any landing content', 'create landing content', 'view own unpublished content']));
    $draft = $this->createBuilderNode(['status' => 0]);
    $published = $this->createBuilderNode(['status' => 1]);
    foreach ([$draft, $published] as $node) {
      $this->assertFalse($this->properties()->canPublish($node));
      $this->assertFalse($this->properties()->canSaveDraft($node));
    }
    $this->properties()->applyPublish($draft);
    $this->assertFalse($draft->isPublished());
    $this->properties()->applyDraft($published);
    $this->assertTrue($published->isPublished());
  }

  /**
   * The user asked is the one of the request at that moment.
   */
  public function testFollowsTheCurrentUser(): void {
    $node = $this->createBuilderNode(['status' => 0]);
    $properties = $this->properties();
    $this->setCurrentUser($this->createUser(['access content']));
    $this->assertFalse($properties->canPublish($node));
    $this->setCurrentUser($this->createUser(['administer nodes']));
    $this->assertTrue($properties->canPublish($node));
  }

  /**
   * With moderation, the buttons are the transitions to "published" and to
   * "draft" the user may make from the state of the page.
   */
  public function testModeratedTransitionsByPermission(): void {
    $this->moderate();
    $node = $this->createBuilderNode(['moderation_state' => 'draft']);
    $this->assertFalse($node->isPublished());

    $this->setCurrentUser($this->createUser(['use editorial transition create_new_draft']));
    $this->assertTrue($this->properties()->canSaveDraft($node));
    $this->assertFalse($this->properties()->canPublish($node));

    $this->setCurrentUser($this->createUser(['use editorial transition publish']));
    $this->assertFalse($this->properties()->canSaveDraft($node));
    $this->assertTrue($this->properties()->canPublish($node));

    $this->setCurrentUser($this->createUser(['use editorial transition publish', 'use editorial transition create_new_draft']));
    $this->assertTrue($this->properties()->canSaveDraft($node));
    $this->assertTrue($this->properties()->canPublish($node));

    $this->setCurrentUser($this->createUser(['administer nodes', 'edit any landing content', 'use editorial transition archive']));
    $this->assertFalse($this->properties()->canSaveDraft($node), 'Administering content is not a transition of the workflow.');
    $this->assertFalse($this->properties()->canPublish($node));
  }

  /**
   * The transitions depend on the state the page is in.
   */
  public function testModeratedTransitionsByState(): void {
    $this->moderate();
    $node = $this->createBuilderNode(['moderation_state' => 'published']);
    $node->set('moderation_state', 'archived');
    $node->save();
    $node = $this->latest($node);
    $this->assertSame('archived', $node->get('moderation_state')->value);

    // From "archived" core has the transitions "archived_draft" and
    // "archived_published"; "publish" and "create_new_draft" do not start
    // there.
    $this->setCurrentUser($this->createUser(['use editorial transition publish', 'use editorial transition create_new_draft']));
    $this->assertFalse($this->properties()->canPublish($node));
    $this->assertFalse($this->properties()->canSaveDraft($node));

    $this->setCurrentUser($this->createUser(['use editorial transition archived_published']));
    $this->assertTrue($this->properties()->canPublish($node));
    $this->assertFalse($this->properties()->canSaveDraft($node));

    $this->setCurrentUser($this->createUser(['use editorial transition archived_draft']));
    $this->assertFalse($this->properties()->canPublish($node));
    $this->assertTrue($this->properties()->canSaveDraft($node));
  }

  /**
   * With moderation, Publish moves the page to "published" and Draft makes
   * a draft revision of it; the published revision stays for visitors.
   */
  public function testModeratedApply(): void {
    $this->moderate();
    $node = $this->createBuilderNode(['moderation_state' => 'draft']);
    $this->setCurrentUser($this->createUser(['use editorial transition publish', 'use editorial transition create_new_draft']));

    $this->properties()->applyPublish($node);
    $this->assertSame('published', $node->get('moderation_state')->value);
    $node->save();
    $published = $this->latest($node);
    $this->assertTrue($published->isPublished());
    $this->assertTrue($published->isDefaultRevision());
    $this->assertSame('published', $published->get('moderation_state')->value);

    $this->properties()->applyDraft($published);
    $this->assertSame('draft', $published->get('moderation_state')->value);
    $published->save();
    $draft = $this->latest($node);
    $this->assertSame('draft', $draft->get('moderation_state')->value);
    $this->assertFalse($draft->isPublished());
    $this->assertFalse($draft->isDefaultRevision());
    $this->assertTrue(Node::load($node->id())->isPublished(), 'Visitors still see the published revision.');
  }

  /**
   * With moderation, a button whose transition the user may not make
   * changes nothing.
   */
  public function testModeratedApplyWithoutPermission(): void {
    $this->moderate();
    $node = $this->createBuilderNode(['moderation_state' => 'draft']);
    $this->setCurrentUser($this->createUser(['administer nodes', 'use editorial transition create_new_draft']));
    $this->properties()->applyPublish($node);
    $this->assertSame('draft', $node->get('moderation_state')->value);
    $this->assertFalse($node->isPublished(), 'The published flag is not used on a moderated page.');

    $published = $this->createBuilderNode(['moderation_state' => 'published']);
    $this->setCurrentUser($this->createUser(['administer nodes', 'use editorial transition publish']));
    $this->properties()->applyDraft($published);
    $this->assertSame('published', $published->get('moderation_state')->value);
    $this->assertTrue($published->isPublished());
  }

  /**
   * With content moderation installed, a content type without a workflow
   * still uses the published flag.
   */
  public function testTypeWithoutWorkflow(): void {
    $this->moderate();
    $this->createBuilderType('plain');
    $node = $this->createBuilderNode(['type' => 'plain', 'status' => 0]);

    $this->setCurrentUser($this->createUser(['use editorial transition publish', 'use editorial transition create_new_draft']));
    $this->assertFalse($this->properties()->canPublish($node));
    $this->assertFalse($this->properties()->canSaveDraft($node));

    $this->setCurrentUser($this->createUser(['administer nodes']));
    $this->assertTrue($this->properties()->canPublish($node));
    $this->properties()->applyPublish($node);
    $this->assertTrue($node->isPublished());
    $node->save();
    $this->assertTrue($this->latest($node)->isPublished());
    $this->properties()->applyDraft($node);
    $this->assertFalse($node->isPublished());
  }

}
