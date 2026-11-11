/**
 * @file
 * Runs inside the frame of a paragraph's form in the page builder.
 *
 * Tells the editor in the parent window how tall the form is. While the
 * user edits, the values are kept as a draft: the hidden "Keep as draft"
 * button of ParagraphBlockForm is pressed in the background, and the editor
 * is told, so that the canvas shows the paragraph as it is in the form.
 */
((Drupal, drupalSettings, once) => {
  const post = (message) => window.parent.postMessage({ type: 'react-scaffold-builder:paragraph', key: drupalSettings.reactScaffoldBuilderParagraph?.key, ...message }, window.location.origin);
  let onChange = null;
  Drupal.behaviors.reactScaffoldBuilderParagraphFrame = {
    attach(context) {
      // Widgets that work through Drupal's Ajax change the form without an input event.
      if (onChange && context !== document && context instanceof Element && context.closest('form')) {
        onChange();
      }
      once('react-scaffold-builder-paragraph', 'body').forEach((body) => {
        const size = () => post({ height: Math.ceil(document.documentElement.scrollHeight) });
        new ResizeObserver(size).observe(body);
        size();
        const form = document.querySelector('form');
        const button = form?.querySelector('[data-rsb-autosave]');
        if (!button) return;
        let timer = null;
        let saving = false;
        let again = false;
        const autosave = async () => {
          if (saving) { again = true; return; }
          saving = true;
          try {
            // Rich text lives in the editor until the form is submitted.
            Drupal.CKEditor5Instances?.forEach((editor) => editor.updateSourceElement());
            const data = new FormData(form);
            data.set(button.name, button.value);
            const response = await fetch(form.getAttribute('action') || window.location.href, { method: 'POST', body: data, credentials: 'same-origin' });
            if (response.ok) post({ draft: Date.now() });
          }
          catch (error) {
            // Offline or interrupted: the next change tries again.
          }
          saving = false;
          if (again) { again = false; autosave(); }
        };
        onChange = () => { clearTimeout(timer); timer = setTimeout(autosave, 700); };
        ['input', 'change'].forEach((name) => form.addEventListener(name, onChange));
        // CKEditor 5 changes its own model, not the textarea.
        const watch = () => Drupal.CKEditor5Instances?.forEach((editor) => {
          if (!editor.rsbWatched) { editor.rsbWatched = true; editor.model.document.on('change:data', onChange); }
        });
        watch();
        setTimeout(watch, 1500);
        setTimeout(watch, 4000);
      });
    },
  };
})(Drupal, drupalSettings, once);
