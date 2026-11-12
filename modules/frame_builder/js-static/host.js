/**
 * @file
 * Fits the page builder frame to the space left in the admin page.
 *
 * The admin theme's gutters around the content are removed for this page, and
 * the frame ends at the bottom of the window, so the page itself does not
 * scroll.
 */
((Drupal, once) => {
  Drupal.behaviors.frameBuilderHost = {
    attach(context) {
      once('frame-builder-host', '.frame-builder-frame', context).forEach((frame) => {
        for (let element = frame.parentElement; element && element.tagName !== 'MAIN' && element !== document.body; element = element.parentElement) {
          element.classList.add('frame-builder-flush');
        }
        const fit = () => {
          const top = frame.getBoundingClientRect().top + window.scrollY;
          frame.style.height = `${Math.max(480, Math.floor(window.innerHeight - top))}px`;
        };
        fit();
        window.addEventListener('resize', fit);
        // Admin toolbars and trays change the offset after load.
        new ResizeObserver(fit).observe(document.body);
      });
    },
  };
})(Drupal, once);
