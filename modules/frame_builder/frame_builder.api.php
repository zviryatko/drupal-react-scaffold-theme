<?php

/**
 * @file
 * Hooks of the Frame page builder.
 */

/**
 * @addtogroup hooks
 * @{
 */

/**
 * Changes the props of a stored layout item for a new version of its component.
 *
 * Called once per version step, after the changes the component declares for
 * that step under thirdPartySettings.frame_builder.migrations in its
 * *.component.yml. Use it for what cannot be declared: splitting a value,
 * computing one from others, restructuring.
 *
 * Modules implement it, and so does the site's default theme and its base
 * themes (in <theme>.theme), whatever theme the request runs with.
 *
 * For a synced translation it is also called with only the translated values
 * of an item, so do not rely on props other than the ones you change.
 *
 * @param array $props
 *   The props of the item: values of props, of features ("_spacing", ...)
 *   and the content of slots (lists of items).
 * @param array $context
 *   - type: the component id, e.g. "my_theme:promo";
 *   - from: the version the props are in;
 *   - to: the version to bring them to (from + 1).
 */
function hook_frame_builder_item_migrate_alter(array &$props, array $context) {
  if ($context['type'] === 'my_theme:promo' && $context['to'] === 3) {
    // Version 3 replaced the "wide" checkbox with a "width" choice.
    if (array_key_exists('wide', $props)) {
      $props['width'] = $props['wide'] ? 'full' : 'content';
      unset($props['wide']);
    }
  }
}

/**
 * Changes the settings the editor starts with.
 *
 * For modules that add to the editor. The AI assistant
 * (frame_builder_ai) tells the editor here where its endpoint is.
 *
 * @param array $settings
 *   What is passed to the editor as drupalSettings.frameBuilder:
 *   'components', 'data', 'endpoints', 'actions', ...
 * @param \Drupal\node\NodeInterface $node
 *   The page (translation) being edited.
 */
function hook_frame_builder_editor_settings_alter(array &$settings, \Drupal\node\NodeInterface $node) {
  $settings['myFeature'] = ['endpoint' => '/my-module/endpoint/' . $node->id()];
}

/**
 * Changes the instructions the AI assistant gives the model.
 *
 * Provided by the frame_builder_ai module. Add guidance of the site
 * here: tone of voice, which components to prefer, what a page should have.
 *
 * @param string $instructions
 *   The system prompt: the layout format, the components, the theme's tokens
 *   and the page.
 * @param \Drupal\node\NodeInterface $node
 *   The page being built.
 */
function hook_frame_builder_ai_instructions_alter(string &$instructions, \Drupal\node\NodeInterface $node) {
  $instructions .= "\n\n# House style\nWrite headings in sentence case. Start a landing page with a Section.";
}

/**
 * @} End of "addtogroup hooks".
 */
