<?php

namespace Drupal\frame_builder\Ajax;

use Drupal\Core\Ajax\CommandInterface;

/**
 * Hands the media picked in the media library to the editor.
 */
class MediaSelectedCommand implements CommandInterface {

  public function __construct(protected array $media) {}

  /**
   * {@inheritdoc}
   */
  public function render() {
    return ['command' => 'frameBuilderMedia', 'media' => $this->media];
  }

}
