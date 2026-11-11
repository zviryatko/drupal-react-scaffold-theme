/**
 * @file
 * Runs inside the "Format settings" frame of the page builder.
 *
 * After "Apply", FormatterSettingsForm puts the settings in drupalSettings;
 * they are passed to the editor in the parent window.
 */
((Drupal, drupalSettings, once) => {
  Drupal.behaviors.reactScaffoldBuilderFormatterFrame = {
    attach() {
      once('react-scaffold-builder-formatter', 'body').forEach(() => {
        const result = drupalSettings.reactScaffoldBuilderFormatter;
        if (result) {
          window.parent.postMessage({ type: 'react-scaffold-builder:formatter', settings: result.settings, summary: result.summary }, window.location.origin);
        }
      });
    },
  };
})(Drupal, drupalSettings, once);
