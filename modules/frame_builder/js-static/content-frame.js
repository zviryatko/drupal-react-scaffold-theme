/**
 * @file
 * Runs inside the node form frames of the page builder.
 *
 * Tells the editor in the parent window when the form is changed, saved or
 * has errors, submits it when the editor asks (one Save for everything), and
 * while the user edits keeps the values as a draft so the preview of the
 * editor shows them: the hidden "Keep as draft" button of BuilderContentForm
 * is pressed in the background.
 */
((Drupal, drupalSettings, once) => {
  const frame = drupalSettings.frameBuilderFrame || {};
  const post = (status) => window.parent.postMessage({ type: 'frame-builder:content', status, part: frame.part }, window.location.origin);
  // Set once the form is wired up: what to do when a value changed.
  let onChange = null;

  Drupal.behaviors.frameBuilderContentFrame = {
    attach(context) {
      // Widgets that work through Drupal's Ajax (media library, file upload, "Add another item", remove buttons)
      // change the form without an input event: Drupal replaces a part of the form and attaches behaviors to it.
      // That is the signal here. Dialogs (the media library itself) are outside the form and do not count.
      if (onChange && context !== document && context instanceof Element && context.closest('form')) {
        onChange();
      }
      once('frame-builder-content', 'body').forEach(() => {
        const form = document.querySelector('form.node-form, form');
        // The browser would stop a save at an empty required field without telling the editor, and cannot show its
        // hint on a field of a panel that is not open. Drupal validates: its messages are above every panel.
        form?.setAttribute('novalidate', '');
        // The panel of this part of the form to show: the editor says which one is open. Panels are "content",
        // "menu" and the groups of the Field Group module, so the rule that hides the others is written here.
        const rule = document.head.appendChild(document.createElement('style'));
        const show = (section) => {
          document.body.setAttribute('data-frame-builder-show', section);
          rule.textContent = `[data-frame-builder-section]:not([data-frame-builder-section="${CSS.escape(section)}"]) { display: none !important; }`;
        };
        show(frame.part === 'page' ? 'page' : 'content');
        window.addEventListener('message', (event) => {
          if (event.origin !== window.location.origin) return;
          if (event.data?.type === 'frame-builder:section' && frame.part !== 'page' && typeof event.data.section === 'string') {
            show(event.data.section);
            document.querySelector('[data-frame-builder-section="menu"]')?.setAttribute('open', '');
          }
          if (event.data?.type === 'frame-builder:submit') {
            form?.querySelector('[data-drupal-selector="edit-submit"]')?.click();
          }
        });

        // Drupal marks the fields that failed validation whatever the theme prints for its messages.
        if (document.querySelector('.messages--error, .form-item--error-message, form [aria-invalid="true"]')) {
          post('error');
        }
        else {
          post(new URLSearchParams(window.location.search).has('saved') ? 'saved' : 'ready');
        }
        if (!form) return;

        let dirty = false;
        const markDirty = () => { if (!dirty) { dirty = true; post('dirty'); } };
        // Values of an earlier visit were put back into the form: still unsaved.
        if (frame.draft) markDirty();

        // Autosave: a normal post of the form with the hidden button as the one pressed. The answer (the form
        // again) is not needed. One at a time; a change during a save triggers one more afterwards.
        const button = form.querySelector('[data-frame-builder-autosave]');
        let timer = null;
        let saving = false;
        let again = false;
        const autosave = async () => {
          if (!button) return;
          if (saving) { again = true; return; }
          saving = true;
          try {
            // Rich text lives in the editor until the form is submitted.
            Drupal.CKEditor5Instances?.forEach((editor) => editor.updateSourceElement());
            const data = new FormData(form);
            data.set(button.name, button.value);
            const response = await fetch(form.getAttribute('action') || window.location.href, { method: 'POST', body: data, credentials: 'same-origin' });
            if (response.ok) post('preview');
          }
          catch (error) {
            // Offline or interrupted: the next change tries again.
          }
          saving = false;
          if (again) { again = false; autosave(); }
        };
        const changed = () => {
          markDirty();
          clearTimeout(timer);
          timer = setTimeout(autosave, 700);
        };
        ['input', 'change'].forEach((name) => form.addEventListener(name, changed));
        onChange = changed;
      });
    },
  };
})(Drupal, drupalSettings, once);
