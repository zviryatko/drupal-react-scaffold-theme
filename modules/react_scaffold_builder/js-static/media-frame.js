/**
 * @file
 * Runs inside the media library frame of the page builder.
 *
 * Drupal's media library answers "Insert selected" with the Ajax command of
 * MediaSelectedCommand; it is passed on to the editor in the parent window.
 */
((Drupal) => {
  Drupal.AjaxCommands.prototype.reactScaffoldBuilderMedia = (ajax, response) => {
    window.parent.postMessage({ type: 'react-scaffold-builder:media', media: response.media }, window.location.origin);
  };
})(Drupal);
