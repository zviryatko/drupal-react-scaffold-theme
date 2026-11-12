<?php

namespace Drupal\frame_builder\Form;

use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Entity\EntityConstraintViolationListInterface;
use Drupal\Core\Entity\Plugin\Validation\Constraint\EntityChangedConstraint;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\Url;
use Drupal\node\NodeForm;
use Drupal\node\NodeInterface;

/**
 * The node form inside the page builder.
 *
 * It is the real node form, so every widget and everything modules add to it
 * works as on the edit page. The editor shows it in two frames, each with a
 * part of the form (the `frame_builder_part` of the form state):
 * - "fields", on the left: the fields of the content type (Content panel)
 *   and the menu settings (Menu panel). With the Field Group module every
 *   top-level group of the form display is a panel of its own, see
 *   groupSections().
 * - "page", on the right: title, publishing or moderation state and what the
 *   edit page has in its sidebar: URL alias, authoring information,
 *   promotion, revision log, translation, and whatever modules put there.
 *
 * The two parts are separate forms that can be open and changed at the same
 * time, so each one saves only its own fields, on top of the latest revision
 * (see save()). While editing, the values are autosaved per user (FieldDrafts)
 * so the preview of the builder shows them before anything is saved.
 */
class BuilderContentForm extends NodeForm {

  /**
   * Elements of the Page part that are not in the sidebar group.
   */
  protected const PAGE = ['title', 'moderation_state', 'status', 'meta', 'revision', 'revision_log', 'revision_information', 'footer'];

  /**
   * Groups whose elements belong to the Page part.
   */
  protected const PAGE_GROUPS = ['advanced', 'footer', 'meta', 'revision_information'];

  /**
   * The panels of the fields part, for the editor.
   *
   * "content" for the fields that are in no group (always there without
   * groups), then one per top-level group of the Field Group module that has
   * fields of this part. Same rules as limitToPart(), without building the
   * form: fields of the node type the user may edit count, base fields and
   * what modules add to the form are in the sidebar (the Page part).
   *
   * @return array[]
   *   Panels with `name` and, for groups, `label` and `icon` (the "Icon in
   *   the page builder" setting of the group, may be empty).
   */
  public static function sections(NodeInterface $node): array {
    $groups = [];
    $display = EntityFormDisplay::collectRenderDisplay($node, 'edit');
    if (\Drupal::moduleHandler()->moduleExists('field_group')) {
      $groups = field_group_info_groups('node', $node->bundle(), 'form', $display->getMode());
    }
    $layout = self::groupSections($groups);
    $used = [];
    foreach (array_keys($display->getComponents()) as $name) {
      if (in_array($name, self::PAGE, TRUE) || $name === 'frame_layout' || !$node->hasField($name)) {
        continue;
      }
      if ($node->getFieldDefinition($name)->getFieldStorageDefinition()->isBaseField() || !$node->get($name)->access('edit')) {
        continue;
      }
      $used[$layout['sections'][$name] ?? 'content'] = TRUE;
    }
    $panels = array_intersect_key($layout['panels'], $used);
    $sections = !$panels || !empty($used['content']) ? [['name' => 'content']] : [];
    foreach ($panels as $name => $group) {
      $sections[] = [
        'name' => $name,
        'label' => (string) ($group->label ?? $name),
        'icon' => (string) ($group->format_settings['frame_builder_icon'] ?? ''),
      ];
    }
    return $sections;
  }

  /**
   * Sorts the groups of the Field Group module into panels of the editor.
   *
   * A top-level group is a panel, whatever its format: the rail of the
   * editor is the switch, the group itself is not printed. Of a top-level
   * "Tabs" group the tabs are the panels. A "Details sidebar" group is in
   * the sidebar of the edit page, so it belongs to the Page part. Groups
   * inside a panel stay what they are.
   *
   * @param object[] $groups
   *   The groups of the form display, by name.
   *
   * @return array
   *   - panels: the groups that are panels, by name, in their order.
   *   - sections: for every field and group in a group, the panel it is in:
   *     the name of a group, or "page".
   *   - tabs: names of the "Tabs" groups whose tabs became panels.
   */
  public static function groupSections(array $groups): array {
    $layout = ['panels' => [], 'sections' => [], 'tabs' => []];
    $assign = function (object $group, string $section, int $depth = 0) use (&$assign, &$layout, $groups): void {
      foreach ($group->children ?? [] as $child) {
        $layout['sections'][$child] = $section;
        if (isset($groups[$child]) && $depth < 10) {
          $assign($groups[$child], $section, $depth + 1);
        }
      }
    };
    $by_weight = fn(object $a, object $b): int => ($a->weight ?? 0) <=> ($b->weight ?? 0);
    $top = array_filter($groups, fn(object $group): bool => empty($group->parent_name) || !isset($groups[$group->parent_name]));
    uasort($top, $by_weight);
    foreach ($top as $name => $group) {
      $type = $group->format_type ?? '';
      if ($type === 'details_sidebar') {
        $layout['sections'][$name] = 'page';
        $assign($group, 'page');
        continue;
      }
      $panels = [$name => $group];
      if ($type === 'tabs') {
        $layout['tabs'][] = $name;
        $panels = array_intersect_key($groups, array_flip($group->children ?? []));
        uasort($panels, $by_weight);
      }
      foreach ($panels as $panel => $panel_group) {
        $layout['panels'][$panel] = $panel_group;
        $layout['sections'][$panel] = $panel;
        $assign($panel_group, $panel);
      }
    }
    return $layout;
  }

  /**
   * {@inheritdoc}
   *
   * Reported as the edit form: modules add their parts of the node form
   * (menu settings, translation, scheduling, meta tags...) by checking for
   * it.
   */
  public function getOperation() {
    return 'edit';
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    $form = parent::form($form, $form_state);
    // The language of a page is not changed here, and the layout has no widget.
    foreach (['langcode', 'frame_layout'] as $name) {
      if (isset($form[$name])) {
        $form[$name]['#access'] = FALSE;
      }
    }
    $part = $form_state->get('frame_builder_part') === 'page' ? 'page' : 'fields';
    $form['#attached']['library'][] = 'frame_builder/content_frame';
    $form['#attached']['drupalSettings']['frameBuilderFrame'] = [
      'part' => $part,
      'tokens' => Url::fromRoute('frame_builder.tokens')->toString(),
      // Whether unsaved values of this user were put back into this part of
      // the form; set in limitToPart(), which knows the fields of the part.
      'draft' => FALSE,
    ];
    // Runs when the form is processed, after every module has altered it and
    // before the values are read: what is removed here is not submitted.
    $form['#process'][] = [static::class, 'limitToPart'];
    return $form;
  }

  /**
   * Form #process callback: keeps the elements of this part of the form.
   *
   * The sidebar of the edit page is the "advanced" group: what is in it is
   * the Page part, except the menu settings. The rest are fields. Elements of
   * the other part are removed, so they are neither validated nor saved by
   * this form. What stays is marked with its panel ("content", "menu",
   * "page", or the name of a group of the Field Group module) for
   * content-frame.js.
   *
   * Field Group builds its groups after this, from $form['#fieldgroups'].
   * The list is rewritten here: groups that are panels are replaced by a
   * plain container with the mark of the panel, groups of the other part
   * are dropped, and fields of the other part are taken out of the rest.
   */
  public static function limitToPart(array &$form, FormStateInterface $form_state): array {
    // Which group every grouped element is in, at any depth: a widget can
    // put a part of itself into the sidebar (the URL alias does).
    $groups = [];
    $collect = function (array $element) use (&$collect, &$groups): void {
      foreach (Element::children($element) as $key) {
        if (!empty($element[$key]['#group'])) {
          $groups[$key] = $element[$key]['#group'];
        }
        $collect($element[$key]);
      }
    };
    $collect($form);
    $in_page = function (string $group) use ($groups): bool {
      for ($i = 0; $i < 10 && !in_array($group, self::PAGE_GROUPS, TRUE) && isset($groups[$group]); $i++) {
        $group = $groups[$group];
      }
      return in_array($group, self::PAGE_GROUPS, TRUE);
    };
    $has_page_inside = function (array $element) use (&$has_page_inside, $in_page): bool {
      foreach (Element::children($element) as $key) {
        if ((!empty($element[$key]['#group']) && $in_page($element[$key]['#group'])) || $has_page_inside($element[$key])) {
          return TRUE;
        }
      }
      return FALSE;
    };

    $part = $form_state->get('frame_builder_part') === 'page' ? 'page' : 'fields';
    $entity = $form_state->getFormObject()->getEntity();
    $field_groups = is_array($form['#fieldgroups'] ?? NULL) ? $form['#fieldgroups'] : [];
    $layout = self::groupSections($field_groups);
    // Panels that have something to show.
    $used = [];
    $fields = [];
    foreach (Element::children($form) as $key) {
      if (in_array($form[$key]['#type'] ?? '', ['hidden', 'token', 'value', 'actions'], TRUE) || in_array($key, ['advanced', 'actions'], TRUE)) {
        continue;
      }
      $grouped = !empty($form[$key]['#group']) && $in_page($form[$key]['#group']);
      $in_group = $layout['sections'][$key] ?? 'content';
      $section = match (TRUE) {
        $key === 'menu' => 'menu',
        $grouped, in_array($key, self::PAGE, TRUE), $has_page_inside($form[$key]), $in_group === 'page' => 'page',
        default => $in_group,
      };
      if (($section === 'page') !== ($part === 'page')) {
        unset($form[$key]);
        continue;
      }
      $form[$key]['#attributes']['data-frame-builder-section'] = $section;
      $form[$key]['#wrapper_attributes']['data-frame-builder-section'] = $section;
      if (($form[$key]['#access'] ?? TRUE) !== FALSE) {
        $used[$section] = TRUE;
      }
      if ($entity->hasField($key)) {
        $fields[] = $key;
      }
    }
    foreach ($field_groups as $name => $group) {
      $section = $layout['sections'][$name] ?? '';
      $is_panel = isset($layout['panels'][$name]);
      // A group stays a group when it is inside a panel of this part.
      if (in_array($name, $layout['tabs'], TRUE) || $is_panel || ($section === 'page') !== ($part === 'page') || empty($used[$section])) {
        unset($form['#fieldgroups'][$name]);
      }
      else {
        $group->children = array_values(array_filter($group->children ?? [], fn($child) => isset($form[$child]) || isset($field_groups[$child])));
      }
      if ($is_panel && $part !== 'page' && !empty($used[$name])) {
        // Groups inside the panel find it by its name (their parent).
        foreach ($group->children ?? [] as $child) {
          if (isset($form[$child]) && !isset($field_groups[$child])) {
            $form[$child]['#group'] = $name;
          }
        }
        $form[$name] = [
          '#type' => 'container',
          '#weight' => $group->weight ?? 0,
          '#attributes' => ['data-frame-builder-section' => $name],
        ];
      }
    }
    // Modules add their submit handlers to every button of the node form
    // (menu links, translations...). Keeping a draft must do nothing but
    // that: none of them may run, they would save for real.
    if (isset($form['actions']['frame_builder_autosave'])) {
      $form['actions']['frame_builder_autosave']['#submit'] = ['::autosave'];
    }
    $form_state->set('frame_builder_fields', $fields);
    $form['#attached']['drupalSettings']['frameBuilderFrame']['draft'] = (bool) array_intersect($fields, $form_state->get('frame_builder_draft') ?? []);
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  protected function actions(array $form, FormStateInterface $form_state) {
    $actions = parent::actions($form, $form_state);
    $actions = array_intersect_key($actions, ['submit' => TRUE]);
    $actions['submit']['#value'] = $this->t('Save');
    // Pressed by content-frame.js while the user edits: keeps the values as a
    // draft of this user, without validating or saving the node.
    $actions['frame_builder_autosave'] = [
      '#type' => 'submit',
      '#value' => $this->t('Keep as draft'),
      '#frame_builder_autosave' => TRUE,
      '#submit' => ['::autosave'],
      '#attributes' => ['class' => ['visually-hidden'], 'tabindex' => -1, 'data-frame-builder-autosave' => ''],
    ];
    return $actions;
  }

  /**
   * {@inheritdoc}
   *
   * A draft is kept whatever state the form is in: half-filled, a required
   * field still empty. The values are needed as submitted (which
   * #limit_validation_errors would withhold), so validation runs and its
   * errors are dropped.
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    if (!empty($form_state->getTriggeringElement()['#frame_builder_autosave'])) {
      $form_state->clearErrors();
      return $this->entity;
    }
    return parent::validateForm($form, $form_state);
  }

  /**
   * Submit handler of the hidden autosave button.
   */
  public function autosave(array $form, FormStateInterface $form_state) {
    try {
      $entity = $this->buildEntity($form, $form_state);
      $values = [];
      foreach ($form_state->get('frame_builder_fields') ?? [] as $name) {
        $values[$name] = $entity->get($name)->getValue();
      }
      \Drupal::service('frame_builder.field_drafts')->merge($this->entity, $values);
    }
    catch (\Throwable $e) {
      // Half-typed values that cannot be put on the node: keep the last draft.
      $this->logger('frame_builder')->notice('Draft of the node form not kept: @message', ['@message' => $e->getMessage()]);
    }
    $form_state->setRebuild();
  }

  /**
   * {@inheritdoc}
   *
   * Only the fields of this part are edited.
   */
  protected function getEditedFieldNames(FormStateInterface $form_state) {
    return array_values(array_intersect(parent::getEditedFieldNames($form_state), $form_state->get('frame_builder_fields') ?? []));
  }

  /**
   * {@inheritdoc}
   *
   * The other part of the form, or the layout, may have been saved since
   * this form was opened. That is expected here, not a conflict: save()
   * starts from the latest revision.
   */
  protected function flagViolations(EntityConstraintViolationListInterface $violations, array $form, FormStateInterface $form_state) {
    foreach ($violations as $offset => $violation) {
      if ($violation->getConstraint() instanceof EntityChangedConstraint) {
        $violations->remove($offset);
      }
    }
    parent::flagViolations($violations, $form, $form_state);
  }

  /**
   * {@inheritdoc}
   *
   * Saves the fields of this part on top of the latest revision of the
   * translation, so the other part and the layout keep what they saved.
   */
  public function save(array $form, FormStateInterface $form_state) {
    $edited = $this->entity;
    $langcode = $edited->language()->getId();
    $storage = $this->entityTypeManager->getStorage('node');
    $revision_id = $storage->getLatestTranslationAffectedRevisionId($edited->id(), $langcode);
    $latest = $revision_id ? $storage->loadRevision($revision_id) : $storage->load($edited->id());
    $node = $storage->createRevision($latest->hasTranslation($langcode) ? $latest->getTranslation($langcode) : $latest);

    $fields = $form_state->get('frame_builder_fields') ?? [];
    foreach ($fields as $name) {
      $node->set($name, $edited->get($name)->getValue());
    }
    $node->setRevisionUserId($this->currentUser()->id());
    $node->setRevisionCreationTime($this->time->getRequestTime());
    $node->setRevisionLogMessage($edited->getRevisionLogMessage() ?: 'Edited in the page builder.');
    // Submit handlers of modules that run after this one get the saved node.
    $this->entity = $node;
    $node->save();
    \Drupal::service('frame_builder.field_drafts')->forget($node, $fields);

    // Back to this form: the frame tells the editor that this part is saved.
    $form_state->setRedirect('frame_builder.content_form', ['node' => $node->id()], [
      'query' => ['part' => $form_state->get('frame_builder_part') === 'page' ? 'page' : 'fields', 'saved' => 1],
    ]);
  }

}
