/**
 * @file
 * Runs inside the media library frame of the page builder.
 *
 * Drupal's media library answers "Insert selected" with the Ajax command of
 * MediaSelectedCommand; it is passed on to the editor in the parent window.
 */
((Drupal) => {
  Drupal.AjaxCommands.prototype.frameBuilderMedia = (ajax, response) => {
    window.parent.postMessage({ type: 'frame-builder:media', media: response.media }, window.location.origin);
  };
})(Drupal);
