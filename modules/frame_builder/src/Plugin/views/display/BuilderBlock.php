<?php

namespace Drupal\frame_builder\Plugin\views\display;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\views\Attribute\ViewsDisplay;
use Drupal\views\Plugin\views\display\DisplayPluginBase;

/**
 * The "Component" display: makes a view available in the page builder.
 *
 * A display of this type has no path and no block of its own: it only exists
 * to be placed on pages with the builder, where it appears in the "Views"
 * group. Combine it with the "React component (SDC)" style to show the view
 * as an interactive component.
 */
#[ViewsDisplay(
  id: 'frame_builder',
  title: new TranslatableMarkup('Component'),
  help: new TranslatableMarkup('Offers this view as a component in the page builder, in the "Views" group.'),
  theme: 'views_view',
  uses_menu_links: FALSE,
)]
class BuilderBlock extends DisplayPluginBase {

  /**
   * {@inheritdoc}
   */
  protected $usesAttachments = TRUE;

  /**
   * {@inheritdoc}
   */
  public function execute() {
    parent::execute();
    return $this->view->render();
  }

}
