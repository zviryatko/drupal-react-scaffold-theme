<?php

namespace Drupal\react_scaffold_builder\Ajax;

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
    return ['command' => 'reactScaffoldBuilderMedia', 'media' => $this->media];
  }

}
