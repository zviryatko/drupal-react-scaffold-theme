// Building blocks that look like a Drupal (Claro) form: form item, modal dialog.
import { useEffect, useRef } from 'react';
import { createPortal } from 'react-dom';

/** Label, required mark, the element and its description, like a Drupal form item. */
export function FormItem({ id, label, description, required, children, inline = false, heading }) {
  return (
    <div className={`frame-builder-form-item${inline ? ' frame-builder-form-item--inline' : ''}`}>
      {heading && <div className="frame-builder-form-heading">{heading}</div>}
      {inline && children}
      <label className={`frame-builder-label${required ? ' frame-builder-label--required' : ''}`} htmlFor={id}>{label}</label>
      {!inline && children}
      {description && <div className="frame-builder-description">{description}</div>}
    </div>
  );
}

/** Modal dialog styled as a Drupal dialog. Closes on Escape and on a click outside. */
export function Modal({ title, onClose, children, wide = false, flush = false, full = false }) {
  const dialog = useRef(null);
  useEffect(() => {
    const previous = document.activeElement;
    dialog.current?.focus();
    const onKey = (event) => { if (event.key === 'Escape') { event.stopPropagation(); onClose(); } };
    document.addEventListener('keydown', onKey, true);
    return () => { document.removeEventListener('keydown', onKey, true); previous?.focus?.(); };
  }, []);
  return createPortal(
    <div className="frame-builder-overlay" onMouseDown={(event) => event.target === event.currentTarget && onClose()}>
      <div className={`frame-builder-dialog${wide ? ' frame-builder-dialog--wide' : ''}${full ? ' frame-builder-dialog--full' : ''}`} role="dialog" aria-modal="true" aria-label={title} tabIndex={-1} ref={dialog}>
        <div className="frame-builder-dialog__titlebar">
          <span className="frame-builder-dialog__title">{title}</span>
          <button type="button" className="frame-builder-dialog__close" aria-label="Close" onClick={onClose}>×</button>
        </div>
        <div className={`frame-builder-dialog__content${flush ? ' frame-builder-dialog__content--flush' : ''}`}>{children}</div>
      </div>
    </div>,
    document.body,
  );
}
