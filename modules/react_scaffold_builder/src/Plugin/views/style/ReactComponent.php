<?php

namespace Drupal\react_scaffold_builder\Plugin\views\style;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\views\Attribute\ViewsStyle;
use Drupal\views\Plugin\views\style\StylePluginBase;

/**
 * Renders a view display as a React component (SDC) fed by a REST display.
 *
 * The component gets the URL of a REST export display of the same view as its
 * `endpoint` prop and draws results, exposed filters, sorts and the pager
 * itself (views_better_rest puts their definitions in the response). This
 * display therefore runs no query and prints no rows.
 */
#[ViewsStyle(
  id: 'react_scaffold_component',
  title: new TranslatableMarkup('React component (SDC)'),
  help: new TranslatableMarkup('Mounts an interactive component that reads a REST export display of this view.'),
  display_types: ['normal'],
)]
class ReactComponent extends StylePluginBase {

  /**
   * {@inheritdoc}
   */
  protected $usesRowPlugin = FALSE;

  /**
   * {@inheritdoc}
   */
  protected $usesFields = FALSE;

  /**
   * {@inheritdoc}
   */
  protected function defineOptions() {
    $options = parent::defineOptions();
    $options['component'] = ['default' => ''];
    $options['rest_display'] = ['default' => ''];
    $options['props'] = ['default' => ''];
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state) {
    parent::buildOptionsForm($form, $form_state);

    $components = [];
    foreach (\Drupal::service('plugin.manager.sdc')->getDefinitions() as $id => $definition) {
      // The contract: the component takes the REST URL as `endpoint`.
      if (isset($definition['props']['properties']['endpoint'])) {
        $components[$id] = ($definition['name'] ?? $id) . " ($id)";
      }
    }
    $form['component'] = [
      '#type' => 'select',
      '#title' => $this->t('Component'),
      '#description' => $this->t('Components that have an <code>endpoint</code> prop.'),
      '#options' => $components,
      '#default_value' => $this->options['component'],
      '#required' => TRUE,
    ];

    $displays = [];
    foreach ($this->view->storage->get('display') as $id => $display) {
      if (in_array($display['display_plugin'], ['better_rest_export', 'rest_export'], TRUE)) {
        $displays[$id] = ($display['display_title'] ?? $id) . ' (/' . ($display['display_options']['path'] ?? '') . ')';
      }
    }
    $form['rest_display'] = [
      '#type' => 'select',
      '#title' => $this->t('REST display'),
      '#description' => $this->t('A REST export display of this view. With <em>Better REST export</em> the component also receives exposed filters, sorts and the pager.'),
      '#options' => $displays,
      '#default_value' => $this->options['rest_display'],
      '#required' => TRUE,
    ];
    $form['props'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Extra props (JSON)'),
      '#description' => $this->t('For example <code>{"heading": "Recipes"}</code>.'),
      '#default_value' => $this->options['props'],
      '#rows' => 3,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function evenEmpty() {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function query() {
    parent::query();
    // Results are fetched by the component from the REST display.
    $this->view->executed = TRUE;
    $this->view->result = [];
  }

  /**
   * {@inheritdoc}
   */
  public function render() {
    $component = $this->options['component'];
    $display = $this->options['rest_display'];
    if (!$component || !$display) {
      return [];
    }
    // The component draws the exposed form itself.
    $this->view->exposed_widgets = NULL;
    $props = Json::decode((string) $this->options['props']) ?: [];
    $props['endpoint'] = Url::fromRoute('view.' . $this->view->storage->id() . '.' . $display)->toString();
    return [
      '#type' => 'component',
      '#component' => $component,
      '#props' => $props,
    ];
  }

}
