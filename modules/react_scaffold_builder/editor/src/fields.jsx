// Sidebar fields, rendered as Drupal form items. Every widget name comes from Drupal (ComponentCatalog, NodeProperties).
import { useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { createUsePuck } from '@puckeditor/core';
import { getJson, settings } from './api';
import { FormItem, Modal } from './ui';
import { TokenInput } from './TokenInput';
import { TokenBrowser } from './TokenBrowser';
import { attachTokenAutocomplete } from './ckeditorTokens';
import { AlignWidget, BackgroundWidget, LengthPicker, PositionWidget, SpacingWidget, LibraryWidget, ColorWidget, TextStyleWidget } from './featureFields';

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
                  multiline={field.widget === 'textarea'} />
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
  const link = useEntityLink(value);
  useDebounced(typed, 250, (query) => {
    // Search for words (by title) and for paths ("/rec…", by URL alias), not for what already is a URL or a token.
    if (!query || (query.length < 2 && query !== '/') || /^([#[]|\/\/|[a-z][a-z0-9+.-]*:)/i.test(query)) {
      setItems([]);
      return;
    }
    setBusy(true);
    getJson(settings.endpoints.urls, { q: query, langcode: settings.langcode }).then(setItems).catch(() => setItems([])).finally(() => setBusy(false));
  });
  const description = field.description || 'Start typing the title of a piece of content, or its path starting with /, to select it. You can also enter any path or an external URL.';
  if (link) {
    return (
      <FormItem {...item({ id, field })} description="Links to this content in the language of the page being viewed.">
        <div className="rsb-chip">
          <span className="rsb-chip__label">{link.label}{link.meta && <small> ({link.meta})</small>}</span>
          {link.url && <code>{link.url}</code>}
          {!readOnly && <button id={id} type="button" className="rsb-button rsb-button--small" onClick={() => onChange('')}>Remove</button>}
        </div>
      </FormItem>
    );
  }
  return (
    <FormItem {...item({ id, field })} description={description}>
      <TokenInput id={id} value={value} onChange={onChange} readOnly={readOnly} onInput={setTyped}>
        {busy && <span className="rsb-throbber" aria-hidden="true" />}
        {items.length > 0 && (
          <ul className="rsb-autocomplete" role="listbox">
            {items.map((entry) => (
              <li key={entry.value} role="option" aria-selected="false"
                  onMouseDown={(event) => { event.preventDefault(); linkCache.set(entry.value, entry); onChange(entry.value); setItems([]); setTyped(null); }}>
                <span className="rsb-autocomplete__label">{entry.label}</span>
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
      if (event.origin === window.location.origin && event.data?.type === 'react-scaffold-builder:media') onSelect(event.data.media);
    };
    window.addEventListener('message', onMessage);
    return () => window.removeEventListener('message', onMessage);
  }, []);
  return (
    <Modal title="Add or select media" onClose={onClose} wide flush>
      <iframe className="rsb-media-frame" title="Media library" src={settings.endpoints.mediaLibrary} />
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
        <div className="rsb-media-item">
          <img src={url} alt="" />
          <span className="rsb-media-item__name">{decodeURIComponent(url.split('/').pop().split('?')[0])}</span>
          {!readOnly && <button type="button" className="rsb-button rsb-button--small" onClick={() => onChange('')}>Remove</button>}
        </div>
      )}
      {!readOnly && (
        <button id={id} type="button" className="rsb-button rsb-button--small" onClick={() => setOpen(true)}>
          {url ? 'Replace media' : 'Add media'}
        </button>
      )}
      {url && styles.length > 0 && (
        <div className="rsb-feature__field">
          <label className="rsb-label rsb-label--sub" htmlFor={`${id}-style`}>Image style</label>
          <select id={`${id}-style`} className="rsb-select" value={current.style || ''} disabled={readOnly || !current.media}
                  title={current.media ? undefined : 'Pick the image from the media library to use image styles'}
                  onChange={(event) => onChange({ ...current, style: event.target.value })}>
            <option value="">Original image</option>
            {styles.map((style) => <option key={style.value} value={style.value}>{style.label}</option>)}
          </select>
        </div>
      )}
      {url && options && (
        <>
          <div className="rsb-feature__field">
            <label className="rsb-label rsb-label--sub" htmlFor={`${id}-loading`}>Loading</label>
            <select id={`${id}-loading`} className="rsb-select" value={current.loading === 'eager' ? 'eager' : 'lazy'} disabled={readOnly || !current.media}
                    onChange={(event) => onChange({ ...current, loading: event.target.value })}>
              <option value="lazy">Lazy: when it comes near the screen</option>
              <option value="eager">Eager: with the page</option>
            </select>
          </div>
          <div className="rsb-feature__field">
            <label className="rsb-label rsb-label--sub" htmlFor={`${id}-link`}>Link image to</label>
            <select id={`${id}-link`} className="rsb-select" value={current.link || ''} disabled={readOnly || !current.media}
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
          <input className="rsb-input" type="search" placeholder="Search by name" aria-label="Search the media library" value={query}
                 onChange={(event) => setQuery(event.target.value)} />
          {items === null && <p className="rsb-muted">Loading…</p>}
          {items?.length === 0 && <p className="rsb-muted">No media available.</p>}
          {items?.length > 0 && (
            <div className="rsb-media-grid">
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
  const [browsing, setBrowsing] = useState(false);
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
        ? <div className="rsb-editor"><textarea id={id} ref={area} defaultValue={text} /></div>
        : <TokenInput id={id} value={text} readOnly={readOnly} multiline onChange={(next) => onChange({ value: next, format })} />}
      {suggestions && createPortal(
        <ul className="rsb-autocomplete rsb-autocomplete--caret" role="listbox" style={{ left: Math.min(suggestions.left, window.innerWidth - 300), top: suggestions.top }}>
          {suggestions.items.map((entry, index) => (
            <li key={entry.insert} role="option" aria-selected={index === suggestions.active}
                className={index === suggestions.active ? 'is-active' : undefined}
                onMouseDown={(event) => { event.preventDefault(); autocomplete.current?.pick(entry); }}
                onMouseEnter={() => autocomplete.current?.activate(index)}>
              <span className="rsb-autocomplete__label">{entry.label}{entry.open && ' ›'}</span>
              <code>{entry.insert}</code>
            </li>
          ))}
        </ul>,
        document.body,
      )}
      {hasEditor && !readOnly && (
        <button type="button" className="rsb-link rsb-token-link" onClick={() => setBrowsing(true)}>Browse available tokens</button>
      )}
      {browsing && (
        <TokenBrowser
          onClose={() => setBrowsing(false)}
          onPick={(token) => {
            const editor = ckeditorOf(area.current);
            editor?.model.change((writer) => editor.model.insertContent(writer.createText(token)));
            setBrowsing(false);
          }}
        />
      )}
      <div className="rsb-format">
        <label className="rsb-label" htmlFor={`${id}-format`}>Text format</label>
        <select id={`${id}-format`} className="rsb-select rsb-select--small" value={format} disabled={readOnly || Boolean(field.format)}
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
      <div className="rsb-icon-current">
        {value ? <><i className={className(value)} aria-hidden="true" /> <span>{value}</span></> : <span className="rsb-muted">No icon</span>}
        {value && !readOnly && !field.required && <button type="button" className="rsb-button rsb-button--small" onClick={() => onChange('')}>Remove</button>}
      </div>
      {!readOnly && (
        <>
          <input id={id} className="rsb-input" type="search" placeholder="Filter icons" value={query} onChange={(event) => setQuery(event.target.value)} />
          <div className="rsb-icon-grid" role="listbox" aria-label={field.label}>
            {shown.map((option) => (
              <button type="button" key={option.value} role="option" aria-selected={option.value === value} title={option.label}
                      className={option.value === value ? 'is-selected' : undefined} onClick={() => onChange(option.value)}>
                <i className={className(option.value)} aria-hidden="true" />
              </button>
            ))}
            {shown.length === 0 && <span className="rsb-muted">No icons match.</span>}
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
      if (event.origin !== window.location.origin || event.data?.type !== 'react-scaffold-builder:paragraph' || event.source !== frame.current?.contentWindow) return;
      if (event.data.height) setHeight(Math.min(Math.max(event.data.height, 120), 2400));
      // The form kept its values as a draft: the block is changed (the canvas shows it, the page has unsaved changes).
      if (event.data.draft) latest.current.onChange({ ...(latest.current.value || {}), draft: event.data.draft });
    };
    window.addEventListener('message', onMessage);
    return () => window.removeEventListener('message', onMessage);
  }, [id]);
  if (readOnly) {
    return <FormItem {...item({ id, field })}><p className="rsb-muted">This content cannot be changed here.</p></FormItem>;
  }
  return (
    <div className="rsb-form-item" ref={box}>
      {shown && <iframe id={id} ref={frame} className="rsb-paragraph-frame" title={`${field.label}: paragraph form`} src={src} style={{ height }} />}
      <div className="rsb-description">Kept as you type, and saved with the page: visitors see it after Publish.</div>
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
      <select id={id} className="rsb-select" value={value ?? ''} disabled={readOnly} onChange={(event) => onChange(event.target.value)}>
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
 * [data-rsb-formatter-result]. Without HTMX it opens in a dialog with an Apply button. Nothing is saved in Drupal;
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
      if (!event.target.closest?.('form.rsb-formatter-form')) return;
      const result = sink.current.querySelector('[data-rsb-formatter-result]');
      setInvalid(!result);
      if (result) apply(JSON.parse(result.getAttribute('data-rsb-formatter-result')));
    };
    const load = () => window.htmx.ajax('GET', `${settings.endpoints.formatterForm}?${query()}`, { target: element, swap: 'innerHTML', select: 'form.rsb-formatter-form' });
    // The format can be changed while the form of the previous one is still on its way: when that one arrives,
    // ask again for the right one.
    const type = current.type;
    const onSettle = () => {
      const form = element.querySelector('form.rsb-formatter-form');
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
      if (event.origin !== window.location.origin || event.data?.type !== 'react-scaffold-builder:formatter') return;
      apply(event.data);
      setOpen(false);
    };
    window.addEventListener('message', onMessage);
    return () => window.removeEventListener('message', onMessage);
  }, [open]);

  return (
    <FormItem {...item({ id, field })}>
      <select id={id} className="rsb-select" value={current.type} disabled={readOnly} onChange={(event) => onChange({ type: event.target.value })}>
        {field.options.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
      </select>
      {hasSettings && !readOnly && inline && (
        <div className="rsb-subform">
          <div ref={box} className="rsb-subform__form" aria-live="polite"><p className="rsb-muted">Loading…</p></div>
          <div ref={sink} className="rsb-subform__result" hidden />
          {invalid && <div className="rsb-description rsb-description--error" role="alert">Not applied: one of the values is not valid.</div>}
        </div>
      )}
      {hasSettings && !inline && (
        <>
          {current.summary?.length > 0 && <ul className="rsb-summary">{current.summary.map((line) => <li key={line}>{line}</li>)}</ul>}
          {!readOnly && <button type="button" className="rsb-link rsb-token-link" onClick={() => setOpen(true)}>Format settings</button>}
        </>
      )}
      {open && (
        <Modal title={`${field.label} settings`} onClose={() => setOpen(false)} flush>
          <iframe className="rsb-media-frame rsb-media-frame--short" title="Format settings" src={`${settings.endpoints.formatter}?${query()}`} />
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
      <input id={id} className="rsb-checkbox" type="checkbox" checked={Boolean(value)} disabled={readOnly}
             onChange={(event) => onChange(event.target.checked)} />
    </FormItem>
  );
}

function NumberWidget({ id, value, onChange, readOnly, field }) {
  return (
    <FormItem {...item({ id, field })}>
      <input id={id} className="rsb-input rsb-input--number" type="number" value={value ?? ''} readOnly={readOnly}
             onChange={(event) => onChange(event.target.value === '' ? '' : Number(event.target.value))} />
    </FormItem>
  );
}

/** Date and time as two inputs, as Drupal's datetime element. The value is `YYYY-MM-DDTHH:MM`. */
function DateWidget({ id, value, onChange, readOnly, field }) {
  const [date = '', time = ''] = (value || '').split('T');
  const set = (nextDate, nextTime) => onChange(nextDate ? `${nextDate}T${nextTime || '00:00'}` : '');
  return (
    <FormItem {...item({ id, field })}>
      <div className="rsb-datetime">
        <input id={id} className="rsb-input" type="date" value={date} readOnly={readOnly} aria-label={`${field.label}: date`}
               onChange={(event) => set(event.target.value, time)} />
        <input className="rsb-input" type="time" value={time} readOnly={readOnly} aria-label={`${field.label}: time`}
               onChange={(event) => set(date, event.target.value)} />
      </div>
    </FormItem>
  );
}

const widgets = {
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
  align: AlignWidget,
  position: PositionWidget,
  background: BackgroundWidget,
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
