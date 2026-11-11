<?php

namespace Drupal\react_scaffold_builder\Form;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Field\FormatterPluginManager;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The settings form of a field formatter, for a Field block of the builder.
 *
 * It is the formatter's own settingsForm(), as on "Manage display". Applying
 * it does not save anything in Drupal: the settings go back to the editor and
 * are stored in the layout. Where core has HTMX the form is shown inline and
 * applies every change at once; otherwise it is shown in a frame with an
 * Apply button (formatter-frame.js).
 */
class FormatterSettingsForm extends FormBase {

  public function __construct(protected FormatterPluginManager $formatters) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static($container->get('plugin.manager.field.formatter'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'react_scaffold_builder_formatter_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = NULL, string $field = '', string $formatter = '', array $settings = [], bool $inline = FALSE) {
    $definition = $node && $node->hasField($field) ? $node->getFieldDefinition($field) : NULL;
    if (!$definition) {
      return ['#markup' => $this->t('Unknown field.')];
    }
    // After "Apply" the form is rebuilt with the submitted settings.
    $settings = $form_state->get('settings') ?? $settings;
    $plugin = $this->formatters->getInstance([
      'field_definition' => $definition,
      'view_mode' => '_custom',
      'configuration' => ['type' => $formatter, 'label' => 'hidden', 'settings' => $settings, 'third_party_settings' => []],
    ]);

    $elements = $plugin ? $plugin->settingsForm($form, $form_state) : [];
    if ($elements) {
      $form['settings'] = $elements + ['#tree' => TRUE];
      $form['actions'] = ['#type' => 'actions'];
      $form['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t('Apply'), '#button_type' => 'primary'];
    }
    else {
      $form['empty'] = ['#markup' => '<p data-rsb-no-settings>' . $this->t('This format has no settings.') . '</p>'];
    }

    $result = $form_state->get('applied') && $plugin ? [
      'settings' => $plugin->getSettings(),
      'summary' => array_map(static fn ($line): string => strip_tags((string) $line), $plugin->settingsSummary()),
    ] : NULL;

    if ($inline) {
      // HTMX, applied as the user changes a setting: no button. The form
      // posts itself to this same URL on every change and stays as it is
      // (focus and what is being typed are kept); only the result of the
      // answer is taken, into the element next to the form, where the editor
      // reads it. A change that does not validate gives no result.
      $form['#attributes']['class'][] = 'rsb-formatter-form';
      // The URL of this form with its own parameters only: HTMX adds the
      // state of the page (theme, loaded libraries) to every request itself.
      $form['#attributes']['data-hx-post'] = Url::fromRoute('react_scaffold_builder.formatter_form', ['node' => $node->id()], [
        'query' => ['field' => $field, 'formatter' => $formatter, 'settings' => Json::encode($settings)],
      ])->toString();
      $form['#attributes']['data-hx-trigger'] = 'submit, change delay:150ms, input changed delay:600ms';
      $form['#attributes']['data-hx-target'] = 'next .rsb-subform__result';
      $form['#attributes']['data-hx-select'] = '[data-rsb-formatter-result]';
      $form['#attributes']['data-hx-swap'] = 'innerHTML';
      if ($result) {
        $form['result'] = [
          '#type' => 'html_tag',
          '#tag' => 'div',
          '#attributes' => ['data-rsb-formatter-result' => Json::encode($result), 'hidden' => TRUE],
        ];
      }
      return $form;
    }

    // In a frame: formatter-frame.js posts the result to the editor.
    $form['#attached']['library'][] = 'react_scaffold_builder/formatter_frame';
    if ($result) {
      $form['#attached']['drupalSettings']['reactScaffoldBuilderFormatter'] = $result;
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $settings = $form_state->getValue('settings');
    $form_state->set('settings', is_array($settings) ? $settings : []);
    $form_state->set('applied', TRUE);
    $form_state->setRebuild();
  }

}
