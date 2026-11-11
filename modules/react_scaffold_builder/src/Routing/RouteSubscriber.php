<?php

namespace Drupal\react_scaffold_builder\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Drupal\react_scaffold_builder\Controller\BuilderController;
use Symfony\Component\Routing\RouteCollection;

/**
 * Makes the page builder the edit page of the content types that use it.
 *
 * "Edit" (/node/{node}/edit) goes through BuilderController::edit(), which
 * shows the builder for those content types and the normal edit form for all
 * others. There is no separate tab: a content type is edited one way.
 */
class RouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection) {
    if ($route = $collection->get('entity.node.edit_form')) {
      $defaults = $route->getDefaults();
      unset($defaults['_entity_form']);
      $defaults['_controller'] = BuilderController::class . '::edit';
      $route->setDefaults($defaults);
    }
  }

}
