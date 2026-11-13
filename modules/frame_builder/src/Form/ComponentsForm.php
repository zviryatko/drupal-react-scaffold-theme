<?php

namespace Drupal\frame_builder\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Theme\ThemeInitializationInterface;
use Drupal\frame_builder\ComponentCatalog;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Which components of the theme the page builder offers.
 *
 * A theme brings components that are no blocks of a page: a pagination, a
 * loading indicator, parts of other components. Those left out here are not
 * in the list of blocks, nor offered to the AI assistant; a page that has one
 * still shows it.
 */
class ComponentsForm extends ConfigFormBase {

  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config,
    protected ComponentCatalog $catalog,
    protected ThemeInitializationInterface $themes,
  ) {
    parent::__construct($config_factory, $typed_config);
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('frame_builder.catalog'),
      $container->get('theme.initialization'),
    );
  }

  public function getFormId() {
    return 'frame_builder_components';
  }

  protected function getEditableConfigNames() {
    return ['frame_builder.settings'];
  }

  /**
   * The components of the theme of the site (not of this admin page).
   */
  protected function candidates(): array {
    $theme = $this->config('system.theme')->get('default');
    return $this->catalog->candidates($this->themes->getActiveThemeByName($theme));
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $hidden = $this->config('frame_builder.settings')->get('hidden_components') ?? [];
    $form['intro'] = [
      '#markup' => '<p>' . $this->t('Components of the theme %theme and its base themes. Only those checked are offered in the page builder; a page that already has one of the others still shows it.', [
        '%theme' => $this->config('system.theme')->get('default'),
      ]) . '</p>',
    ];
    $groups = [];
    foreach ($this->candidates() as $id => $definition) {
      $groups[$definition['group'] ?? 'Components'][$id] = $definition;
    }
    ksort($groups);
    $form['groups'] = ['#tree' => TRUE];
    foreach ($groups as $group => $definitions) {
      $key = preg_replace('/\W+/', '_', strtolower($group));
      $options = [];
      $defaults = [];
      $disabled = [];
      foreach ($definitions as $id => $definition) {
        $byTheme = ($definition['thirdPartySettings']['frame_builder']['offer'] ?? TRUE) === FALSE;
        $options[$id] = $this->t('@label <small>@id</small>', ['@label' => $definition['name'] ?? $id, '@id' => $id]);
        if ($byTheme) {
          $disabled[] = $id;
          $options[$id] = $this->t('@label <small>@id · left out by the theme</small>', ['@label' => $definition['name'] ?? $id, '@id' => $id]);
        }
        elseif (!in_array($id, $hidden, TRUE)) {
          $defaults[] = $id;
        }
      }
      $form['groups'][$key] = [
        '#type' => 'details',
        '#title' => $this->t('@group (@offered of @all offered)', ['@group' => $group, '@offered' => count($defaults), '@all' => count($options)]),
        '#open' => TRUE,
      ];
      $form['groups'][$key]['components'] = [
        '#type' => 'checkboxes',
        '#title' => $this->t('Offered in the page builder'),
        '#title_display' => 'invisible',
        '#options' => $options,
        '#default_value' => $defaults,
      ];
      foreach ($definitions as $id => $definition) {
        if (!empty($definition['description'])) {
          $form['groups'][$key]['components'][$id]['#description'] = $definition['description'];
        }
        if (in_array($id, $disabled, TRUE)) {
          $form['groups'][$key]['components'][$id]['#disabled'] = TRUE;
        }
      }
    }
    return parent::buildForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $hidden = $this->config('frame_builder.settings')->get('hidden_components') ?? [];
    $candidates = $this->candidates();
    // Components of other themes keep their choice.
    $hidden = array_values(array_diff($hidden, array_keys($candidates)));
    foreach ($form_state->getValue('groups') ?? [] as $group) {
      foreach ($group['components'] ?? [] as $id => $checked) {
        if (!$checked && ($candidates[$id]['thirdPartySettings']['frame_builder']['offer'] ?? TRUE) !== FALSE) {
          $hidden[] = $id;
        }
      }
    }
    sort($hidden);
    $this->config('frame_builder.settings')->set('hidden_components', $hidden)->save();
    parent::submitForm($form, $form_state);
  }

}
