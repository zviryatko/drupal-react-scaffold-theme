// Building blocks that look like a Drupal (Claro) form: form item, modal dialog.
import { useEffect, useRef } from 'react';
import { createPortal } from 'react-dom';

/** Label, required mark, the element and its description, like a Drupal form item. */
export function FormItem({ id, label, description, required, children, inline = false, heading }) {
  return (
    <div className={`rsb-form-item${inline ? ' rsb-form-item--inline' : ''}`}>
      {heading && <div className="rsb-form-heading">{heading}</div>}
      {inline && children}
      <label className={`rsb-label${required ? ' rsb-label--required' : ''}`} htmlFor={id}>{label}</label>
      {!inline && children}
      {description && <div className="rsb-description">{description}</div>}
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
    <div className="rsb-overlay" onMouseDown={(event) => event.target === event.currentTarget && onClose()}>
      <div className={`rsb-dialog${wide ? ' rsb-dialog--wide' : ''}${full ? ' rsb-dialog--full' : ''}`} role="dialog" aria-modal="true" aria-label={title} tabIndex={-1} ref={dialog}>
        <div className="rsb-dialog__titlebar">
          <span className="rsb-dialog__title">{title}</span>
          <button type="button" className="rsb-dialog__close" aria-label="Close" onClick={onClose}>×</button>
        </div>
        <div className={`rsb-dialog__content${flush ? ' rsb-dialog__content--flush' : ''}`}>{children}</div>
      </div>
    </div>,
    document.body,
  );
}
