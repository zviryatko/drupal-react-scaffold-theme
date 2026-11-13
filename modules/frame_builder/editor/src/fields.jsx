// Sidebar fields, rendered as Drupal form items. Every widget name comes from Drupal (ComponentCatalog, NodeProperties).
import { useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { createUsePuck } from '@puckeditor/core';
import { getJson, settings } from './api';
import { FormItem, Modal } from './ui';
import { FieldActions, TokenInput } from './TokenInput';
import { attachTokenAutocomplete } from './ckeditorTokens';
import { AlignWidget, BackgroundWidget, BorderWidget, LengthPicker, PositionWidget, SpacingWidget, SizeWidget, LibraryWidget, ColorWidget, TextStyleWidget } from './featureFields';

/** Calls `callback(value)` after `delay` ms without a new value. */
function useDebounced(value, delay, callback) {
  const latest = useRef(callback);
  latest.current = callback;
  useEffect(() => {
    const timer = setTimeout(() => latest.current(value), delay);
    return () => clearTimeout(timer);
  }, [value, delay]);
}

const item = (props) => ({ id: props.id, label: props.field.label, description: props.field.description, required: props.field.required });

function TextWidget({ id, value, onChange, readOnly, field }) {
  return (
    <FormItem {...item({ id, field })}>
      <TokenInput id={id} value={value} onChange={onChange} readOnly={readOnly} tokens={field.tokens !== false}
                  multiline={field.widget === 'textarea'} label={field.label} />
    </FormItem>
  );
}

const linkCache = new Map();

/** Title and current path of an "entity:node/3" link, in the language being edited. */
function useEntityLink(value) {
  const isEntity = typeof value === 'string' && value.startsWith('entity:');
  const [info, setInfo] = useState(() => (isEntity ? linkCache.get(value) : null));
  useEffect(() => {
    if (!isEntity) return undefined;
    let live = true;
    if (!linkCache.has(value)) {
      linkCache.set(value, getJson(settings.endpoints.urls, { resolve: value, langcode: settings.langcode }).catch(() => null));
    }
    Promise.resolve(linkCache.get(value)).then((found) => { linkCache.set(value, found); if (live) setInfo(found); });
    return () => { live = false; };
  }, [value]);
  return isEntity ? info || { label: value, url: '', meta: '' } : null;
}

/**
 * URL: free text and tokens, plus suggestions of site content by title, as a Drupal autocomplete.
 *
 * Content picked from the suggestions is stored as "entity:node/<id>" (as Drupal's link field does) and shown as a
 * chip: Drupal prints the path of the translation that matches the page, so the link needs no translating.
 */
function UrlWidget({ id, value, onChange, readOnly, field }) {
  const [items, setItems] = useState([]);
  const [typed, setTyped] = useState(null);
  const [busy, setBusy] = useState(false);
  // The suggestion chosen with the arrow keys; -1 is none, as long as the pointer has not moved over one.
  const [active, setActive] = useState(-1);
  const link = useEntityLink(value);
  const choose = (entry) => { linkCache.set(entry.value, entry); onChange(entry.value); setItems([]); setTyped(null); setActive(-1); };
  // As Drupal's autocomplete: up and down move through the suggestions, Enter and Tab take the one chosen, Escape
  // closes them.
  const onKeys = (event) => {
    if (!items.length) return;
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      event.preventDefault();
      const step = event.key === 'ArrowDown' ? 1 : -1;
      setActive((current) => (current + step + items.length + (current < 0 && step < 0 ? 1 : 0)) % items.length);
    }
    else if ((event.key === 'Enter' || event.key === 'Tab') && active >= 0) {
      event.preventDefault();
      choose(items[active]);
    }
    else if (event.key === 'Escape') {
      event.stopPropagation();
      setItems([]);
      setActive(-1);
    }
  };
  useDebounced(typed, 250, (query) => {
    // Search for words (by title) and for paths ("/rec…", by URL alias), not for what already is a URL or a token.
    if (!query || (query.length < 2 && query !== '/') || /^([#[]|\/\/|[a-z][a-z0-9+.-]*:)/i.test(query)) {
      setItems([]);
      return;
    }
    setBusy(true);
    getJson(settings.endpoints.urls, { q: query, langcode: settings.langcode }).then((found) => { setItems(found); setActive(-1); }).catch(() => setItems([])).finally(() => setBusy(false));
  });
  const description = field.description || 'Start typing the title of a piece of content, or its path starting with /, to select it. You can also enter any path or an external URL.';
  if (link) {
    return (
      <FormItem {...item({ id, field })} description="Links to this content in the language of the page being viewed.">
        <div className="frame-builder-chip">
          <span className="frame-builder-chip__label">{link.label}{link.meta && <small> ({link.meta})</small>}</span>
          {link.url && <code>{link.url}</code>}
          {!readOnly && <button id={id} type="button" className="frame-builder-button frame-builder-button--small" onClick={() => onChange('')}>Remove</button>}
        </div>
      </FormItem>
    );
  }
  return (
    <FormItem {...item({ id, field })} description={description}>
      <TokenInput id={id} value={value} onChange={onChange} readOnly={readOnly} onInput={setTyped} label={field.label} onKeys={onKeys}
                  listbox={{ id: `${id}-suggestions`, open: items.length > 0, active: active >= 0 ? `${id}-suggestion-${active}` : null }}>
        {busy && <span className="frame-builder-throbber" aria-hidden="true" />}
        {items.length > 0 && (
          <ul id={`${id}-suggestions`} className="frame-builder-autocomplete" role="listbox" aria-label={`Content for ${field.label}`}>
            {items.map((entry, index) => (
              <li key={entry.value} id={`${id}-suggestion-${index}`} role="option" aria-selected={index === active}
                  className={index === active ? 'is-active' : undefined}
                  ref={(element) => { if (element && index === active) element.scrollIntoView({ block: 'nearest' }); }}
                  onMouseEnter={() => setActive(index)}
                  onMouseDown={(event) => { event.preventDefault(); choose(entry); }}>
                <span className="frame-builder-autocomplete__label">{entry.label}</span>
                <code>{entry.meta} · {entry.url}</code>
              </li>
            ))}
          </ul>
        )}
      </TokenInput>
    </FormItem>
  );
}

/**
 * Drupal's media library (grid and table views, filters, upload) in a frame rendered by the admin theme, so it looks
 * and works as everywhere in Drupal without its CSS reaching the preview. The frame posts the selected media.
 */
function MediaLibraryDialog({ onSelect, onClose }) {
  useEffect(() => {
    const onMessage = (event) => {
      if (event.origin === window.location.origin && event.data?.type === 'frame-builder:media') onSelect(event.data.media);
    };
    window.addEventListener('message', onMessage);
    return () => window.removeEventListener('message', onMessage);
  }, []);
  return (
    <Modal title="Add or select media" onClose={onClose} wide flush>
      <iframe className="frame-builder-media-frame" title="Media library" src={settings.endpoints.mediaLibrary} />
    </Modal>
  );
}

/** URL of an image value: a plain URL, or `{ url, media, style }` as the pickers store it. */
export const imageUrl = (value) => (typeof value === 'string' ? value : value?.url || '');

/**
 * Picks an image: the selected file as in the media library widget, "Add media" opens Drupal's media library
 * (without the Media Library module a simple search dialog), and the image style to print it with.
 *
 * The value is `{ url, media, style }`: Drupal needs the media item to make image style derivatives, responsive
 * sources and the alternative text. `styles` are the image styles the theme offers.
 */
export function ImagePicker({ id, value, onChange, readOnly, styles = [], options = false }) {
  const url = imageUrl(value);
  const current = typeof value === 'object' && value ? value : { url };
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState('');
  const [items, setItems] = useState(null);
  useDebounced(open && !settings.endpoints.mediaLibrary ? query : null, 250, (q) => {
    if (q === null) return;
    getJson(settings.endpoints.media, { q }).then(setItems).catch(() => setItems([]));
  });
  const select = (media) => { onChange({ ...current, url: media.url, media: media.id }); setOpen(false); };
  return (
    <>
      {url && (
        <div className="frame-builder-media-item">
          <img src={url} alt="" />
          <span className="frame-builder-media-item__name">{decodeURIComponent(url.split('/').pop().split('?')[0])}</span>
          {!readOnly && <button type="button" className="frame-builder-button frame-builder-button--small" onClick={() => onChange('')}>Remove</button>}
        </div>
      )}
      {!readOnly && (
        <button id={id} type="button" className="frame-builder-button frame-builder-button--small" onClick={() => setOpen(true)}>
          {url ? 'Replace media' : 'Add media'}
        </button>
      )}
      {url && styles.length > 0 && (
        <div className="frame-builder-feature__field">
          <label className="frame-builder-label frame-builder-label--sub" htmlFor={`${id}-style`}>Image style</label>
          <select id={`${id}-style`} className="frame-builder-select" value={current.style || ''} disabled={readOnly || !current.media}
                  title={current.media ? undefined : 'Pick the image from the media library to use image styles'}
                  onChange={(event) => onChange({ ...current, style: event.target.value })}>
            <option value="">Original image</option>
            {styles.map((style) => <option key={style.value} value={style.value}>{style.label}</option>)}
          </select>
        </div>
      )}
      {url && options && (
        <>
          <div className="frame-builder-feature__field">
            <label className="frame-builder-label frame-builder-label--sub" htmlFor={`${id}-loading`}>Loading</label>
            <select id={`${id}-loading`} className="frame-builder-select" value={current.loading === 'eager' ? 'eager' : 'lazy'} disabled={readOnly || !current.media}
                    onChange={(event) => onChange({ ...current, loading: event.target.value })}>
              <option value="lazy">Lazy: when it comes near the screen</option>
              <option value="eager">Eager: with the page</option>
            </select>
          </div>
          <div className="frame-builder-feature__field">
            <label className="frame-builder-label frame-builder-label--sub" htmlFor={`${id}-link`}>Link image to</label>
            <select id={`${id}-link`} className="frame-builder-select" value={current.link || ''} disabled={readOnly || !current.media}
                    onChange={(event) => onChange({ ...current, link: event.target.value })}>
              <option value="">Nothing</option>
              <option value="content">Content (this page)</option>
              <option value="media">Media item</option>
              <option value="file">Image file</option>
              <option value="custom">Custom URL</option>
            </select>
          </div>
          {current.link === 'custom' && (
            <UrlWidget id={`${id}-href`} value={current.href || ''} readOnly={readOnly} field={{ label: 'Link URL' }}
                       onChange={(href) => onChange({ ...current, href })} />
          )}
        </>
      )}
      {open && settings.endpoints.mediaLibrary && <MediaLibraryDialog onClose={() => setOpen(false)} onSelect={select} />}
      {open && !settings.endpoints.mediaLibrary && (
        <Modal title="Add or select media" onClose={() => setOpen(false)} wide>
          <input className="frame-builder-input" type="search" placeholder="Search by name" aria-label="Search the media library" value={query}
                 onChange={(event) => setQuery(event.target.value)} />
          {items === null && <p className="frame-builder-muted">Loading…</p>}
          {items?.length === 0 && <p className="frame-builder-muted">No media available.</p>}
          {items?.length > 0 && (
            <div className="frame-builder-media-grid">
              {items.map((entry) => (
                <button type="button" key={entry.id} className={entry.url === url ? 'is-selected' : undefined} onClick={() => select(entry)}>
                  <img src={entry.thumb} alt={entry.alt || entry.label} loading="lazy" />
                  <span>{entry.label}</span>
                </button>
              ))}
            </div>
          )}
        </Modal>
      )}
    </>
  );
}

/** Image prop of a component. */
function ImageWidget({ id, value, onChange, readOnly, field }) {
  return (
    <FormItem {...item({ id, field })}>
      <ImagePicker id={id} value={value} onChange={onChange} readOnly={readOnly} styles={field.imageStyles} options={field.imageOptions} />
    </FormItem>
  );
}

const ckeditorOf = (element) => window.Drupal.CKEditor5Instances?.get(element?.getAttribute('data-ckeditor5-id'));

/** The format new formatted text starts with: the pinned one, else the first with an editor. */
export const startFormat = (field) => field.format || settings.formats.find((format) => format.editor)?.id || settings.defaultFormat;

/**
 * Formatted text, stored as `{ value, format }`.
 *
 * Formats that have an editor in Drupal get that editor (CKEditor 5 with the toolbar, plugins and allowed tags of the
 * format), the others a textarea. Drupal filters the text with the format when it renders the component.
 */
function HtmlWidget({ id, value, onChange, readOnly, field }) {
  const text = typeof value === 'string' ? value : value?.value ?? '';
  const format = (typeof value === 'object' && value?.format) || startFormat(field);
  const config = window.drupalSettings.editor?.formats?.[format];
  const hasEditor = Boolean(config && window.Drupal.editorAttach);
  const area = useRef(null);
  const latest = useRef({ text, onChange });
  latest.current = { text, onChange };
  const [suggestions, setSuggestions] = useState(null);
  const autocomplete = useRef(null);

  useEffect(() => {
    if (!hasEditor || readOnly) return undefined;
    const element = area.current;
    element.value = latest.current.text;
    window.Drupal.editorAttach(element, config);
    // Drupal creates the editor asynchronously and its own onChange() reports only the first change of a burst (forms
    // read the text on submit), so listen to the editor itself.
    let timer = null;
    const wait = setInterval(() => {
      const editor = ckeditorOf(element);
      if (!editor) return;
      clearInterval(wait);
      autocomplete.current = attachTokenAutocomplete(editor, setSuggestions);
      editor.model.document.on('change:data', () => {
        clearTimeout(timer);
        timer = setTimeout(() => latest.current.onChange({ value: editor.getData(), format }), 200);
      });
    }, 50);
    return () => {
      clearInterval(wait);
      clearTimeout(timer);
      setSuggestions(null);
      window.Drupal.editorDetach(element, config, 'unload');
    };
  }, [format, hasEditor, readOnly]);

  // The user may keep a format they cannot use (text saved by someone else), but not pick it.
  const formats = settings.formats.some((entry) => entry.id === format) ? settings.formats : [{ id: format, label: format }, ...settings.formats];

  return (
    <FormItem {...item({ id, field })}>
      {hasEditor && !readOnly
        ? <div className="frame-builder-editor"><textarea id={id} ref={area} defaultValue={text} /></div>
        : <TokenInput id={id} value={text} readOnly={readOnly} multiline label={field.label} onChange={(next) => onChange({ value: next, format })} />}
      {suggestions && createPortal(
        <ul className="frame-builder-autocomplete frame-builder-autocomplete--caret" role="listbox" style={{ left: Math.min(suggestions.left, window.innerWidth - 300), top: suggestions.top }}>
          {suggestions.items.map((entry, index) => (
            <li key={entry.insert} role="option" aria-selected={index === suggestions.active}
                className={index === suggestions.active ? 'is-active' : undefined}
                onMouseDown={(event) => { event.preventDefault(); autocomplete.current?.pick(entry); }}
                onMouseEnter={() => autocomplete.current?.activate(index)}>
              <span className="frame-builder-autocomplete__label">{entry.label}{entry.open && ' ›'}</span>
              <code>{entry.insert}</code>
            </li>
          ))}
        </ul>,
        document.body,
      )}
      <div className="frame-builder-format">
        <label className="frame-builder-label" htmlFor={`${id}-format`}>Text format</label>
        <select id={`${id}-format`} className="frame-builder-select frame-builder-select--small" value={format} disabled={readOnly || Boolean(field.format)}
                onChange={(event) => onChange({ value: ckeditorOf(area.current)?.getData() ?? text, format: event.target.value })}>
          {formats.map((entry) => <option key={entry.id} value={entry.id}>{entry.label}</option>)}
        </select>
      </div>
    </FormItem>
  );
}

/** Icon picker: the glyphs of the icon font the component uses, by name. */
function IconWidget({ id, value, onChange, readOnly, field }) {
  const [query, setQuery] = useState('');
  const className = (name) => field.iconClass.replace('{name}', name);
  const shown = field.options.filter((option) => option.value.includes(query.trim().toLowerCase()));
  return (
    <FormItem {...item({ id, field })}>
      <div className="frame-builder-icon-current">
        {value ? <><i className={className(value)} aria-hidden="true" /> <span>{value}</span></> : <span className="frame-builder-muted">No icon</span>}
        {value && !readOnly && !field.required && <button type="button" className="frame-builder-button frame-builder-button--small" onClick={() => onChange('')}>Remove</button>}
      </div>
      {!readOnly && (
        <>
          <input id={id} className="frame-builder-input" type="search" placeholder="Filter icons" value={query} onChange={(event) => setQuery(event.target.value)} />
          <div className="frame-builder-icon-grid" role="listbox" aria-label={field.label}>
            {shown.map((option) => (
              <button type="button" key={option.value} role="option" aria-selected={option.value === value} title={option.label}
                      className={option.value === value ? 'is-selected' : undefined} onClick={() => onChange(option.value)}>
                <i className={className(option.value)} aria-hidden="true" />
              </button>
            ))}
            {shown.length === 0 && <span className="frame-builder-muted">No icons match.</span>}
          </div>
        </>
      )}
    </FormItem>
  );
}

/**
 * The paragraph of a Paragraph block (Paragraphs module): its own Drupal form, in a frame rendered by the admin
 * theme. The form saves nothing: its values are kept as a draft while the user types (the frame says when, and the
 * block gets a `draft` mark so the canvas shows them). When the page is saved, Drupal creates the paragraph or a new
 * revision of it and the block names that revision: `{ id, vid, uuid }`.
 */
function ParagraphWidget({ id, value, onChange, readOnly, field }) {
  // The address is fixed when the form opens: the form itself moves on to each revision it saves.
  const block = usePuck((state) => state.selectedItem?.props?.id);
  const [src] = useState(() => `${settings.endpoints.paragraph}?${new URLSearchParams({ type: field.bundle, key: id, block, ...(value?.id ? { id: value.id, vid: value.vid } : {}) })}`);
  const [height, setHeight] = useState(240);
  const latest = useRef({ onChange, value });
  latest.current = { onChange, value };
  // Puck keeps a second, hidden copy of the fields (for narrow windows): only the copy that is shown loads the form.
  const box = useRef(null);
  const frame = useRef(null);
  const [shown, setShown] = useState(false);
  useEffect(() => {
    const check = () => setShown(Boolean(box.current?.offsetParent));
    check();
    window.addEventListener('resize', check);
    return () => window.removeEventListener('resize', check);
  }, []);
  useEffect(() => {
    const onMessage = (event) => {
      if (event.origin !== window.location.origin || event.data?.type !== 'frame-builder:paragraph' || event.source !== frame.current?.contentWindow) return;
      if (event.data.height) setHeight(Math.min(Math.max(event.data.height, 120), 2400));
      // The form kept its values as a draft: the block is changed (the canvas shows it, the page has unsaved changes).
      if (event.data.draft) latest.current.onChange({ ...(latest.current.value || {}), draft: event.data.draft });
    };
    window.addEventListener('message', onMessage);
    return () => window.removeEventListener('message', onMessage);
  }, [id]);
  if (readOnly) {
    return <FormItem {...item({ id, field })}><p className="frame-builder-muted">This content cannot be changed here.</p></FormItem>;
  }
  return (
    <div className="frame-builder-form-item" ref={box}>
      {shown && <iframe id={id} ref={frame} className="frame-builder-paragraph-frame" title={`${field.label}: paragraph form`} src={src} style={{ height }} />}
      <div className="frame-builder-description">Kept as you type, and saved with the page: visitors see it after Publish.</div>
    </div>
  );
}

const usePuck = createUsePuck();

function SelectWidget({ id, value, onChange, readOnly, field }) {
  // Options that depend on another prop of the same component, e.g. the formatters of the chosen field.
  const other = usePuck((state) => (field.dependsOn ? state.selectedItem?.props?.[field.dependsOn] : null));
  const options = field.dependsOn ? field.optionsMap[other] || [] : field.options;
  return (
    <FormItem {...item({ id, field })}>
      {/* A long choice does not fit the narrow column: the whole of it is the tooltip. */}
      <select id={id} className="frame-builder-select" value={value ?? ''} disabled={readOnly} onChange={(event) => onChange(event.target.value)}
              title={options.find((option) => String(option.value) === String(value ?? ''))?.label}>
        {!field.required && field.required !== undefined && <option value="">- None -</option>}
        {options.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
      </select>
    </FormItem>
  );
}

/**
 * Field formatter of a Field block: the formatter and its settings.
 *
 * The value is `{ type, settings, summary }` (or just the formatter id). A formatter that has settings shows its
 * own Drupal settings form below, as on "Manage display". With HTMX (Drupal 11.2+) the form is inline and every
 * change is applied at once: it posts itself in the background and the settings come back in
 * [data-frame-builder-formatter-result]. Without HTMX it opens in a dialog with an Apply button. Nothing is saved in Drupal;
 * the settings go into the layout.
 */
function FormatterWidget({ id, value, onChange, readOnly, field }) {
  const current = value && typeof value === 'object' ? value : { type: value || field.default };
  const hasSettings = Boolean(field.options.find((option) => option.value === current.type)?.settings);
  const inline = Boolean(settings.endpoints.formatterForm && window.htmx);
  const [open, setOpen] = useState(false);
  const [invalid, setInvalid] = useState(false);
  const box = useRef(null);
  const sink = useRef(null);
  const latest = useRef({ onChange, current });
  latest.current = { onChange, current };
  const query = () => new URLSearchParams({ field: field.fieldName, formatter: latest.current.current.type, settings: JSON.stringify(latest.current.current.settings || {}) });
  const apply = (result) => latest.current.onChange({ type: latest.current.current.type, settings: result.settings, summary: result.summary });

  // Inline: load the form for this formatter once; it then posts itself on every change.
  useEffect(() => {
    if (!inline || !hasSettings || readOnly) return undefined;
    const element = box.current;
    const container = element.parentElement;
    const onAnswer = (event) => {
      // Only answers to the form, not the first load of it.
      if (!event.target.closest?.('form.frame-builder-formatter-form')) return;
      const result = sink.current.querySelector('[data-frame-builder-formatter-result]');
      setInvalid(!result);
      if (result) apply(JSON.parse(result.getAttribute('data-frame-builder-formatter-result')));
    };
    const load = () => window.htmx.ajax('GET', `${settings.endpoints.formatterForm}?${query()}`, { target: element, swap: 'innerHTML', select: 'form.frame-builder-formatter-form' });
    // The format can be changed while the form of the previous one is still on its way: when that one arrives,
    // ask again for the right one.
    const type = current.type;
    const onSettle = () => {
      const form = element.querySelector('form.frame-builder-formatter-form');
      if (form && !new URLSearchParams((form.getAttribute('data-hx-post') || '').split('?')[1] || '').getAll('formatter').includes(type)) load();
    };
    container.addEventListener('htmx:afterRequest', onAnswer);
    container.addEventListener('htmx:afterSettle', onSettle);
    load();
    return () => {
      container.removeEventListener('htmx:afterRequest', onAnswer);
      container.removeEventListener('htmx:afterSettle', onSettle);
      element.innerHTML = '';
      setInvalid(false);
    };
  }, [inline, hasSettings, readOnly, current.type]);

  // In a dialog: the form posts the result as a message.
  useEffect(() => {
    if (!open) return undefined;
    const onMessage = (event) => {
      if (event.origin !== window.location.origin || event.data?.type !== 'frame-builder:formatter') return;
      apply(event.data);
      setOpen(false);
    };
    window.addEventListener('message', onMessage);
    return () => window.removeEventListener('message', onMessage);
  }, [open]);

  return (
    <FormItem {...item({ id, field })}>
      <select id={id} className="frame-builder-select" value={current.type} disabled={readOnly} onChange={(event) => onChange({ type: event.target.value })}>
        {field.options.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
      </select>
      {hasSettings && !readOnly && inline && (
        <div className="frame-builder-subform">
          <div ref={box} className="frame-builder-subform__form" aria-live="polite"><p className="frame-builder-muted">Loading…</p></div>
          <div ref={sink} className="frame-builder-subform__result" hidden />
          {invalid && <div className="frame-builder-description frame-builder-description--error" role="alert">Not applied: one of the values is not valid.</div>}
        </div>
      )}
      {hasSettings && !inline && (
        <>
          {current.summary?.length > 0 && <ul className="frame-builder-summary">{current.summary.map((line) => <li key={line}>{line}</li>)}</ul>}
          {!readOnly && <button type="button" className="frame-builder-link frame-builder-token-link" onClick={() => setOpen(true)}>Format settings</button>}
        </>
      )}
      {open && (
        <Modal title={`${field.label} settings`} onClose={() => setOpen(false)} flush>
          <iframe className="frame-builder-media-frame frame-builder-media-frame--short" title="Format settings" src={`${settings.endpoints.formatter}?${query()}`} />
        </Modal>
      )}
    </FormItem>
  );
}

/** A length prop of a component (a gap): a step of the theme's spacing scale or a custom length. */
function LengthWidget({ id, value, onChange, readOnly, field }) {
  return (
    <FormItem {...item({ id, field })}>
      <LengthPicker id={id} label={field.label} value={value} sizes={field.sizes} readOnly={readOnly} onChange={onChange} />
    </FormItem>
  );
}

function CheckboxWidget({ id, value, onChange, readOnly, field }) {
  return (
    <FormItem {...item({ id, field })} inline>
      <input id={id} className="frame-builder-checkbox" type="checkbox" checked={Boolean(value)} disabled={readOnly}
             onChange={(event) => onChange(event.target.checked)} />
    </FormItem>
  );
}

function NumberWidget({ id, value, onChange, readOnly, field }) {
  return (
    <FormItem {...item({ id, field })}>
      <input id={id} className="frame-builder-input frame-builder-input--number" type="number" value={value ?? ''} readOnly={readOnly}
             min={field.min} max={field.max} step={field.step}
             onChange={(event) => onChange(event.target.value === '' ? '' : Number(event.target.value))} />
    </FormItem>
  );
}

/** A date, a time or an email address: the input the browser has for it. */
function InputWidget({ id, value, onChange, readOnly, field }) {
  const input = useRef(null);
  // An email address is text: it can be cleared from the field, as text fields can. Date and time have their own.
  const clearable = field.widget === 'email' && !readOnly;
  return (
    <FormItem {...item({ id, field })}>
      <div className={`frame-builder-input-wrap${clearable ? ' has-actions' : ''}`}>
        <input id={id} ref={input} className="frame-builder-input" type={field.widget} value={value ?? ''} readOnly={readOnly} maxLength={field.maxLength}
               onChange={(event) => onChange(event.target.value)} />
        {clearable && <FieldActions label={field.label} empty={!value} onClear={() => { onChange(''); input.current?.focus(); }} />}
      </div>
    </FormItem>
  );
}

/** Several of a set of choices (a list whose values are of an enum): a checkbox for each. Value: the chosen ones, in the order of the choices. */
function ChoicesWidget({ id, value, onChange, readOnly, field }) {
  const chosen = Array.isArray(value) ? value.map(String) : [];
  const toggle = (option, on) => {
    const next = field.options.filter((entry) => (String(entry.value) === String(option.value) ? on : chosen.includes(String(entry.value)))).map((entry) => entry.value);
    onChange(next.length ? next : null);
  };
  return (
    <fieldset className="frame-builder-form-item frame-builder-choices">
      <legend className={`frame-builder-label${field.required ? ' frame-builder-label--required' : ''}`}>{field.label}</legend>
      {field.options.map((option, index) => (
        <label key={String(option.value)} className="frame-builder-choices__item">
          <input id={index === 0 ? id : undefined} className="frame-builder-checkbox" type="checkbox" checked={chosen.includes(String(option.value))} disabled={readOnly}
                 onChange={(event) => toggle(option, event.target.checked)} />
          <span>{option.label}</span>
        </label>
      ))}
      {field.description && <div className="frame-builder-description">{field.description}</div>}
    </fieldset>
  );
}

/** Date and time as two inputs, as Drupal's datetime element. The value is `YYYY-MM-DDTHH:MM`. */
function DateWidget({ id, value, onChange, readOnly, field }) {
  const [date = '', time = ''] = (value || '').split('T');
  const set = (nextDate, nextTime) => onChange(nextDate ? `${nextDate}T${nextTime || '00:00'}` : '');
  return (
    <FormItem {...item({ id, field })}>
      <div className="frame-builder-datetime">
        <input id={id} className="frame-builder-input" type="date" value={date} readOnly={readOnly} aria-label={`${field.label}: date`}
               onChange={(event) => set(event.target.value, time)} />
        <input className="frame-builder-input" type="time" value={time} readOnly={readOnly} aria-label={`${field.label}: time`}
               onChange={(event) => set(date, event.target.value)} />
      </div>
    </FormItem>
  );
}

// On a translation that shares the structure of the original a list keeps its values in their places: texts and
// links in it are translated, nothing is added, removed or moved.
const lockedStructure = Boolean(settings.translation?.locked);

/** What a field starts with, in a new value of a list. */
const startValue = (field) => {
  // A list in a new value starts as the component says, or without values.
  if (field.widget === 'list') return Array.isArray(field.default) ? structuredClone(field.default) : undefined;
  if (field.fields) return Object.fromEntries(field.fields.map((sub) => [sub.name, startValue(sub)]).filter(([, value]) => value !== undefined));
  if (field.default === null || field.default === undefined) return field.widget === 'boolean' ? false : undefined;
  return field.widget === 'html' ? { value: field.default, format: startFormat(field) } : field.default;
};

/** A few words that tell one value of a list from the others: its first text, also when that is in a list or a group inside it. */
const firstText = (value, depth = 0) => {
  if (typeof value === 'string') return value.trim() && !value.startsWith('entity:') ? value : '';
  if (!value || typeof value !== 'object' || depth > 4) return '';
  // Rich text: { value, format }.
  if (typeof value.value === 'string' && typeof value.format === 'string') return value.value.replace(/<[^>]*>/g, ' ').trim();
  // A picked image says nothing in words.
  if ('media' in value || 'media_uuid' in value) return '';
  for (const child of Object.values(value)) {
    const text = firstText(child, depth + 1);
    if (text) return text;
  }
  return '';
};
const summaryOf = (entry, property) => {
  // The property the component names for it, else the first text of the value.
  const text = ((property && firstText(entry?.[property])) || firstText(entry)).replace(/\s+/g, ' ').trim();
  return text.length > 44 ? `${text.slice(0, 43)}…` : text;
};

/** The fields of the properties of an object: of a group, or of one value of a list. */
function SubFields({ id, fields, value, onChange, readOnly }) {
  const current = value && typeof value === 'object' && !Array.isArray(value) ? value : {};
  return fields.map((sub) => {
    const Widget = widgets[sub.widget] || TextWidget;
    return (
      <Widget key={sub.name} id={`${id}-${sub.name}`} value={current[sub.name]} field={sub}
              readOnly={readOnly || (lockedStructure && !sub.translatable)}
              onChange={(next) => onChange({ ...current, [sub.name]: next })} />
    );
  });
}

/** A prop of the type object: values that belong together, as a fieldset. */
function GroupWidget({ id, value, onChange, readOnly, field }) {
  return (
    <fieldset className="frame-builder-group">
      <legend className={`frame-builder-label${field.required ? ' frame-builder-label--required' : ''}`}>{field.label}</legend>
      {field.description && <div className="frame-builder-description">{field.description}</div>}
      <SubFields id={id} fields={field.fields} value={value} onChange={onChange} readOnly={readOnly} />
    </fieldset>
  );
}

/** At most this many fields in a value of a list are shown open in its row; a value with more folds under a line. */
const OPEN_FIELDS = 4;

/**
 * A prop of the type array: several values, as a multiple value field of Drupal and with its look (Claro's table of a
 * multiple value widget, tabledrag.js): a table with the label in its header, a row for each value with a handle to
 * drag it, the widget of the value and a Remove button; "Add another item" under it; "Show row weights" puts an Order
 * column in place of the handles. A row that was moved is marked, and the table says that there are unsaved changes.
 * A value that is an object has the fields of the object in its row, as Drupal's link widget has URL and link text;
 * with more than a few fields they fold under a line that names the value.
 */
function ListWidget({ id, value, onChange, readOnly, field }) {
  // The list as of the last change made here: a drag makes several before the new value comes back.
  const latest = useRef([]);
  latest.current = Array.isArray(value) ? value : [];
  const list = latest.current;
  // Values have no ids of their own: these keep a widget (a rich text editor) with its value when values move.
  const keys = useRef([]);
  const counter = useRef(0);
  while (keys.current.length < list.length) keys.current.push(counter.current += 1);
  keys.current.length = list.length;
  const rows = useRef(new Map());
  const folds = Boolean(field.fields && field.fields.length > OPEN_FIELDS);
  const [open, setOpen] = useState(null);
  // The value being dragged, the one dragged last, and those moved since the field was shown: as tabledrag.js marks them.
  const [dragging, setDragging] = useState(null);
  const [dragged, setDragged] = useState(null);
  const [moved, setMoved] = useState(() => new Set());
  const [weights, setWeights] = useState(false);
  const fixed = readOnly || lockedStructure;
  const full = field.max > 0 && list.length >= field.max;
  const least = field.min > 0 && list.length <= field.min;

  const change = (next, nextKeys) => { keys.current = nextKeys; latest.current = next; onChange(next); };
  const set = (index, entry) => change(latest.current.map((existing, at) => (at === index ? entry : existing)), keys.current);
  const remove = (index) => change(latest.current.filter((_, at) => at !== index), keys.current.filter((_, at) => at !== index));
  const move = (index, to) => {
    if (to < 0 || to >= latest.current.length || to === index) return;
    const next = [...latest.current];
    const nextKeys = [...keys.current];
    next.splice(to, 0, next.splice(index, 1)[0]);
    nextKeys.splice(to, 0, nextKeys.splice(index, 1)[0]);
    setMoved((before) => new Set(before).add(nextKeys[to]));
    change(next, nextKeys);
  };
  const add = () => {
    const key = counter.current += 1;
    // The example of a value that the component gives for its list, else what the fields of a value start with.
    const entry = field.start !== undefined && field.start !== null ? structuredClone(field.start) : startValue(field.fields ? { fields: field.fields } : field.item);
    change([...latest.current, entry === undefined ? '' : entry], [...keys.current, key]);
    setOpen(key);
  };

  // Dragging: the value goes where the pointer is, past the middle of the value above or below it. The pointer is
  // followed on the window: a row that moves takes its handle out of and back into the document.
  const over = (clientY) => {
    const from = keys.current.indexOf(dragging);
    let to = from;
    keys.current.forEach((other, index) => {
      const box = other === dragging ? null : rows.current.get(other)?.getBoundingClientRect();
      if (!box) return;
      const middle = box.top + box.height / 2;
      if (index < from && clientY < middle) to = Math.min(to, index);
      if (index > from && clientY > middle) to = Math.max(to, index);
    });
    if (from >= 0) move(from, to);
  };
  const follow = useRef(over);
  follow.current = over;
  const table = useRef(null);
  useEffect(() => {
    if (dragging === null) return undefined;
    // Near the top or the bottom of the column the column scrolls, as tabledrag.js scrolls the page.
    let scroller = table.current?.parentElement;
    while (scroller && !(/(auto|scroll)/.test(getComputedStyle(scroller).overflowY) && scroller.scrollHeight > scroller.clientHeight)) scroller = scroller.parentElement;
    let pointer = null;
    const timer = setInterval(() => {
      if (pointer === null || !scroller) return;
      const box = scroller.getBoundingClientRect();
      const step = pointer < box.top + 48 ? -12 : pointer > box.bottom - 48 ? 12 : 0;
      if (step) {
        scroller.scrollTop += step;
        follow.current(pointer);
      }
    }, 16);
    const onMove = (event) => { pointer = event.clientY; follow.current(event.clientY); };
    const onEnd = () => { setDragged(dragging); setDragging(null); };
    // Before anything in the editor can stop them on their way up.
    window.addEventListener('pointermove', onMove, true);
    window.addEventListener('pointerup', onEnd, true);
    window.addEventListener('pointercancel', onEnd, true);
    return () => {
      clearInterval(timer);
      window.removeEventListener('pointermove', onMove, true);
      window.removeEventListener('pointerup', onEnd, true);
      window.removeEventListener('pointercancel', onEnd, true);
    };
  }, [dragging]);
  const drag = {
    start: (event, key) => {
      if (event.button !== 0) return;
      event.preventDefault();
      event.currentTarget.focus();
      setDragging(key);
      setDragged(null);
    },
    key: (event, key) => {
      const step = { ArrowUp: -1, ArrowDown: 1 }[event.key];
      if (!step) return;
      event.preventDefault();
      const from = keys.current.indexOf(key);
      move(from, from + step);
      setDragged(key);
    },
  };

  const widgetOf = (entry, index, key) => {
    if (!field.fields) {
      const Widget = widgets[field.item.widget] || TextWidget;
      return <Widget id={`${id}-${key}`} value={entry} field={{ ...field.item, label: `${field.item.label} (value ${index + 1})`, description: '' }}
                     readOnly={readOnly || (lockedStructure && !field.item.translatable)} onChange={(next) => set(index, next)} />;
    }
    const fields = <SubFields id={`${id}-${key}`} fields={field.fields} value={entry} onChange={(next) => set(index, next)} readOnly={readOnly} />;
    if (!folds) return <div className="frame-builder-multiple__fields">{fields}</div>;
    const isOpen = open === key;
    return (
      <>
        <button type="button" className="frame-builder-multiple__toggle" aria-expanded={isOpen} aria-controls={`${id}-${key}`} onClick={() => setOpen(isOpen ? null : key)}>
          <span className="frame-builder-multiple__summary">{summaryOf(entry, field.summary) || <em>Empty</em>}</span>
        </button>
        {isOpen && <div id={`${id}-${key}`} className="frame-builder-multiple__fields">{fields}</div>}
      </>
    );
  };

  return (
    <div className={`frame-builder-form-item frame-builder-multiple${dragging !== null ? ' is-sorting' : ''}`}>
      {!fixed && list.length > 1 && (
        <div className="frame-builder-multiple__toggle-weight">
          <button type="button" className={`frame-builder-action-link frame-builder-action-link--icon-${weights ? 'hide' : 'show'}`} onClick={() => setWeights(!weights)}>
            {weights ? 'Hide row weights' : 'Show row weights'}
          </button>
        </div>
      )}
      {moved.size > 0 && (
        <div className="frame-builder-multiple__changed" role="alert"><abbr className="frame-builder-multiple__marker" title="Changed">*</abbr> You have unsaved changes.</div>
      )}
      <table className="frame-builder-multiple__table" ref={table}>
        <thead>
          <tr>
            <th colSpan={fixed ? 1 : 3} className="frame-builder-multiple__label">
              <h4 className={`frame-builder-label${field.required ? ' frame-builder-label--required' : ''}`} id={`${id}-label`}>{field.label}</h4>
            </th>
            {weights && !fixed && <th className="frame-builder-multiple__order-head">Order</th>}
          </tr>
        </thead>
        <tbody>
          {list.map((entry, index) => {
            const key = keys.current[index];
            const row = (element) => { if (element) rows.current.set(key, element); else rows.current.delete(key); };
            const state = `${dragging === key ? ' drag' : ''}${dragged === key && dragging === null ? ' drag-previous' : ''}`;
            return (
              <tr key={key} ref={row} className={`frame-builder-multiple__row draggable${state}`}>
                {!fixed && (
                  <td className="frame-builder-multiple__drag">
                    {!weights && (
                      <button type="button" className="frame-builder-multiple__handle" title="Change order"
                              aria-label={`Change order of value ${index + 1} of ${list.length}: drag, or press the up and down arrow keys`}
                              onPointerDown={(event) => drag.start(event, key)} onKeyDown={(event) => drag.key(event, key)} />
                    )}
                    {moved.has(key) && <abbr className="frame-builder-multiple__marker" title="Changed">*</abbr>}
                  </td>
                )}
                <td className="frame-builder-multiple__value">{widgetOf(entry, index, key)}</td>
                {!fixed && (
                  <td className="frame-builder-multiple__remove">
                    <button type="button" className="frame-builder-button frame-builder-button--small" disabled={least}
                            aria-label={`Remove value ${index + 1}`} onClick={() => remove(index)}>Remove</button>
                  </td>
                )}
                {weights && !fixed && (
                  <td className="frame-builder-multiple__order">
                    <select className="frame-builder-select" aria-label={`Weight for row ${index + 1}`} value={index} onChange={(event) => move(index, Number(event.target.value))}>
                      {list.map((_, at) => <option key={at} value={at}>{at}</option>)}
                    </select>
                  </td>
                )}
              </tr>
            );
          })}
        </tbody>
      </table>
      {!fixed && (
        <div className="frame-builder-multiple__actions">
          <button type="button" className="frame-builder-button frame-builder-button--small" disabled={full} onClick={add}>Add another item</button>
        </div>
      )}
      {field.description && <div className="frame-builder-description">{field.description}</div>}
    </div>
  );
}

const widgets = {
  list: ListWidget,
  group: GroupWidget,
  choices: ChoicesWidget,
  date: InputWidget,
  time: InputWidget,
  email: InputWidget,
  text: TextWidget,
  textarea: TextWidget,
  url: UrlWidget,
  image: ImageWidget,
  html: HtmlWidget,
  select: SelectWidget,
  icon: IconWidget,
  formatter: FormatterWidget,
  length: LengthWidget,
  spacing: SpacingWidget,
  size: SizeWidget,
  align: AlignWidget,
  position: PositionWidget,
  background: BackgroundWidget,
  border: BorderWidget,
  library: LibraryWidget,
  color: ColorWidget,
  textstyle: TextStyleWidget,
  paragraph: ParagraphWidget,
  boolean: CheckboxWidget,
  number: NumberWidget,
  datetime: DateWidget,
};

/** Puck field for a field description sent by Drupal (component prop or node property). */
export function toPuckField(field) {
  const Widget = widgets[field.widget] || TextWidget;
  return {
    type: 'custom',
    label: field.label,
    // The key makes a fresh widget for every component: an editor must not be reused for another text.
    // `locked`: a prop that is not translatable, on a translation that shares the structure of the original.
    render: (props) => <Widget key={props.id} id={props.id} value={props.value} onChange={props.onChange} readOnly={props.readOnly || field.locked} field={field} />,
  };
}
