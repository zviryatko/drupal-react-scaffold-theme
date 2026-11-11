<?php

namespace Drupal\react_scaffold_builder\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Field\FieldConfigInterface;
use Drupal\Core\Form\FormStateInterface;

/**
 * The form of a paragraph, shown in the right column of the page builder
 * for a Paragraph block.
 *
 * It is the paragraph's own form display, with every widget, and it has no
 * button: it saves nothing. While the user edits, paragraph-frame.js presses
 * the hidden "Keep as draft" button in the background and the values are
 * kept per user (ParagraphDrafts); the canvas shows the paragraph with them.
 * The paragraph itself is created, or gets a new revision, when the page is
 * saved (Paragraphs::persist()).
 */
class ParagraphBlockForm extends ContentEntityForm {

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    $form = parent::form($form, $form_state);
    $form['#attached']['library'][] = 'react_scaffold_builder/paragraph_frame';
    $form['#attached']['drupalSettings']['reactScaffoldBuilderParagraph'] = ['key' => (string) $form_state->get('rsb_key')];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  protected function actions(array $form, FormStateInterface $form_state) {
    return [
      'rsb_autosave' => [
        '#type' => 'submit',
        '#value' => $this->t('Keep as draft'),
        '#rsb_autosave' => TRUE,
        '#submit' => ['::autosave'],
        '#attributes' => ['class' => ['visually-hidden'], 'tabindex' => -1, 'data-rsb-autosave' => ''],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   *
   * A draft is kept whatever state the form is in. The values are checked
   * when the page is saved.
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $form_state->clearErrors();
    return $this->entity;
  }

  /**
   * Submit handler of the hidden button: keeps the values, saves nothing.
   */
  public function autosave(array $form, FormStateInterface $form_state) {
    try {
      $entity = $this->buildEntity($form, $form_state);
      $values = [];
      foreach ($entity->getFieldDefinitions() as $name => $definition) {
        // The fields of the paragraph type; not ids, parent and the like.
        if ($definition instanceof FieldConfigInterface) {
          $values[$name] = $entity->get($name)->getValue();
        }
      }
      \Drupal::service('react_scaffold_builder.paragraph_drafts')->set($form_state->get('rsb_node'), (string) $form_state->get('rsb_block'), $values);
    }
    catch (\Throwable $e) {
      $this->logger('react_scaffold_builder')->notice('Draft of a paragraph not kept: @message', ['@message' => $e->getMessage()]);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    // Never: see the class comment.
  }

}
