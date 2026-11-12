<?php

namespace Drupal\frame_builder;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;

/**
 * The publishing state of a node, for the Publish and Draft buttons.
 *
 * With content moderation these move the node to the "published" and "draft"
 * states, when the workflow has them and the user may make the transition;
 * without it they set and clear the published flag. Everything else about a
 * page is edited in Drupal's node form (BuilderContentForm).
 */
class NodeProperties {

  public function __construct(
    protected ModuleHandlerInterface $moduleHandler,
    protected AccountInterface $currentUser,
  ) {}

  /**
   * Whether the user can publish the node.
   */
  public function canPublish(NodeInterface $node): bool {
    return $this->can($node, 'published');
  }

  /**
   * Whether the user can save the node as a draft.
   */
  public function canSaveDraft(NodeInterface $node): bool {
    return $this->can($node, 'draft');
  }

  /**
   * Publishes the node. The caller saves.
   */
  public function applyPublish(NodeInterface $node): void {
    if ($this->canPublish($node)) {
      $this->isModerated($node) ? $node->set('moderation_state', 'published') : $node->setPublished();
    }
  }

  /**
   * Makes the node a draft: not shown to visitors. The caller saves.
   */
  public function applyDraft(NodeInterface $node): void {
    if ($this->canSaveDraft($node)) {
      $this->isModerated($node) ? $node->set('moderation_state', 'draft') : $node->setUnpublished();
    }
  }

  /**
   * Whether the node can be moved to a state.
   */
  protected function can(NodeInterface $node, string $state): bool {
    if (!$this->isModerated($node)) {
      return $node->get('status')->access('edit', $this->currentUser);
    }
    $transitions = \Drupal::service('content_moderation.state_transition_validation')->getValidTransitions($node, $this->currentUser);
    foreach ($transitions as $transition) {
      if ($transition->to()->id() === $state) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Whether a content moderation workflow applies to the node.
   */
  protected function isModerated(NodeInterface $node): bool {
    return $this->moduleHandler->moduleExists('content_moderation')
      && \Drupal::service('content_moderation.moderation_information')->isModeratedEntity($node);
  }

}
