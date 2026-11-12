/**
 * @file
 * Links inside the frames of the page builder open in a new tab.
 *
 * The editor, its forms (content, paragraph, format settings, media library)
 * and the preview are frames. A link followed inside one would load a whole
 * page of the site there, admin toolbar included, and lose what the frame
 * was showing. Links that scripts handle (ajax, the editor's own) are left
 * alone, and so are links that say where they open.
 */
(function () {
  if (window.top === window) {
    return;
  }
  document.addEventListener('click', function (event) {
    // Runs after the handlers on the link and its parents: a link that
    // something else took care of is not ours.
    if (event.defaultPrevented || event.button !== 0) {
      return;
    }
    const link = event.target instanceof Element ? event.target.closest('a[href]') : null;
    if (!link || link.hasAttribute('target') || link.hasAttribute('download')) {
      return;
    }
    const href = link.getAttribute('href');
    if (!href || href.startsWith('#') || /^(javascript|mailto|tel):/i.test(href)) {
      return;
    }
    // The same page with another fragment is a jump, not a navigation.
    const url = new URL(link.href, window.location.href);
    if (url.hash && url.origin + url.pathname + url.search === window.location.origin + window.location.pathname + window.location.search) {
      return;
    }
    event.preventDefault();
    window.open(url.href, '_blank', 'noopener');
  });
})();
