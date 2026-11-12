/**
 * @file
 * Runs inside the "Format settings" frame of the page builder.
 *
 * After "Apply", FormatterSettingsForm puts the settings in drupalSettings;
 * they are passed to the editor in the parent window.
 */
((Drupal, drupalSettings, once) => {
  Drupal.behaviors.frameBuilderFormatterFrame = {
    attach() {
      once('frame-builder-formatter', 'body').forEach(() => {
        const result = drupalSettings.frameBuilderFormatter;
        if (result) {
          window.parent.postMessage({ type: 'frame-builder:formatter', settings: result.settings, summary: result.summary }, window.location.origin);
        }
      });
    },
  };
})(Drupal, drupalSettings, once);
