<?php

namespace Drupal\frame_builder;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\media_library\MediaLibraryOpenerInterface;
use Drupal\media_library\MediaLibraryState;
use Drupal\frame_builder\Ajax\MediaSelectedCommand;

/**
 * Lets the editor open Drupal's media library and receive the selection.
 */
class MediaLibraryOpener implements MediaLibraryOpenerInterface {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected MediaItems $mediaItems,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function checkAccess(MediaLibraryState $state, AccountInterface $account) {
    return AccessResult::allowedIfHasPermission($account, 'use frame builder');
  }

  /**
   * {@inheritdoc}
   */
  public function getSelectionResponse(MediaLibraryState $state, array $selected_ids) {
    $response = new AjaxResponse();
    $media = $selected_ids ? $this->entityTypeManager->getStorage('media')->load(reset($selected_ids)) : NULL;
    if ($media && $media->access('view') && ($item = $this->mediaItems->describe($media))) {
      $response->addCommand(new MediaSelectedCommand($item));
    }
    return $response;
  }

}
