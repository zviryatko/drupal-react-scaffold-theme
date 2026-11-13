// Puck editor over Drupal Single Directory Components.
//
// Components are not React components here: Drupal renders each one (Twig) and the editor shows that HTML. Slots come
// back as empty markers, and Puck's drop zones are mounted into them with portals, so components can be nested.
import { createElement, Fragment, useEffect, useLayoutEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { createRoot } from 'react-dom/client';
import { createUsePuck, Puck, blocksPlugin } from '@puckeditor/core';
import '@puckeditor/core/puck.css';
import { postJson, settings } from './api';
import { startFormat, toPuckField } from './fields';
import { ContentFrames, ContentPanel, saveContent, useContentDirty, useContentVersion } from './contentFrame';
import { Modal } from './ui';
import { BlocksPanel } from './BlocksPanel';
import { PanelIcon } from './icons';
import { useBreakpoint } from './breakpoint';
import { BreakpointTabs } from './featureFields';
import { DeleteSelected, followMove, parentOf, selectorFor, SelectionOnCanvas, structurePlugin } from './Structure';
import { HistoryButton, HistoryPanel, markSaved, useHistoryOpen } from './History';
import { fromDrupal, toDrupalLayout, toDrupalType, toPuckType } from './types';
import { subscribeLibrary } from './library';
import { AiPanel } from './AiPanel';

// Rendered HTML by component + props, so moving or remounting a component does not ask Drupal again.
const htmlCache = new Map();

function renderOnServer(type, props, key, breakpoint) {
  if (!htmlCache.has(key)) {
    const request = postJson(settings.endpoints.render, { type, props, node: settings.node, langcode: settings.langcode, breakpoint })
      .then(({ ok, body }) => (ok ? { html: body.html, wrapper: body.wrapper, styles: body.styles } : { error: body?.error || 'The component could not be rendered.' }));
    htmlCache.set(key, request);
    request.then((result) => htmlCache.set(key, result));
  }
  return htmlCache.get(key);
}

// Styles of the position of a component (placed in the free space around it, or pinned to a corner of its parent),
// by component id. They go on Puck's wrapper of the component: that is the box the parent lays out. Puck sets inline
// styles on its wrappers, so these are rules of a stylesheet, with !important.
const pins = new Map();
function pin(id, style) {
  const css = style ? Object.entries(style).map(([property, value]) => `${property}: ${value} !important`).join('; ') : '';
  if ((pins.get(id) || '') === css) return;
  css ? pins.set(id, css) : pins.delete(id);
  let sheet = document.getElementById('frame-builder-pins');
  if (!sheet) {
    sheet = document.head.appendChild(Object.assign(document.createElement('style'), { id: 'frame-builder-pins' }));
  }
  // The pinned wrapper is placed relative to the root of the parent component, as on the page: the slot marker and
  // Puck's drop zone between them must not be the reference.
  sheet.textContent = [...pins].map(([component, rule]) => {
    const wrapper = `[data-puck-component="${CSS.escape(component)}"]`;
    // Only what is pinned (absolute) needs the reference moved; what is placed in the flow stays where it is.
    const anchor = /position: absolute/.test(rule) ? `\n[data-frame-builder-slot]:has(> * > ${wrapper}), [data-frame-builder-slot] > :has(> ${wrapper}) { position: static !important; }` : '';
    return `${wrapper} { ${rule} }${anchor}`;
  }).join('\n');
}

// CSS rules that come with a component and belong in the head of the page: a background with a responsive image
// style is media queries. On the page Drupal puts them there; here they are kept by component id.
const sheets = new Map();
function styles(id, css) {
  if ((sheets.get(id) || '') === (css || '')) return;
  css ? sheets.set(id, css) : sheets.delete(id);
  let sheet = document.getElementById('frame-builder-styles');
  if (!sheet) {
    sheet = document.head.appendChild(Object.assign(document.createElement('style'), { id: 'frame-builder-styles' }));
  }
  sheet.textContent = [...sheets.values()].join('\n');
}

/**
 * Loads the images of freshly rendered HTML before it replaces what is shown. Without it, every change of a prop
 * shows the component for a moment without its image: the new <img> starts loading only once it is in the page.
 */
const preloaded = new Set();
function preloadImages(result) {
  if (!result?.html || !result.html.includes('<img') && !result.html.includes('url(')) return result;
  const template = document.createElement('template');
  template.innerHTML = result.html;
  const urls = new Set();
  template.content.querySelectorAll('img').forEach((image) => {
    // What the browser would pick for this image, srcset and <picture> sources included, is only known in the page:
    // the fallback is what is certain.
    if (image.getAttribute('src')) urls.add(image.getAttribute('src'));
  });
  [...result.html.matchAll(/url\((?:&quot;|["'])?([^"')&]+)/g)].forEach((match) => urls.add(match[1]));
  const waiting = [...urls].filter((url) => !preloaded.has(url)).map((url) => {
    const image = new Image();
    image.src = url;
    return image.decode().catch(() => {}).then(() => preloaded.add(url));
  });
  if (!waiting.length) return result;
  // A slow or broken image does not hold the preview back.
  return Promise.race([Promise.all(waiting), new Promise((resolve) => { setTimeout(resolve, 1500); })]).then(() => result);
}

function ServerComponent({ id, definition, values, slots }) {
  // Field blocks and tokens show saved field values: render again after the Content form was saved.
  const version = useContentVersion();
  // The canvas shows the page as it is from the breakpoint being edited on, whatever the width of the window.
  const breakpoint = useBreakpoint();
  const key = JSON.stringify([definition.id, values, version, breakpoint]);
  const cached = htmlCache.get(key);
  const [result, setResult] = useState(cached && !cached.then ? cached : null);
  const first = useRef(true);
  const host = useRef(null);
  const [targets, setTargets] = useState({});

  useEffect(() => {
    let live = true;
    // Wait for typing to pause before asking Drupal, except for the first render.
    const timer = setTimeout(() => {
      Promise.resolve(renderOnServer(definition.id, values, key, breakpoint)).then(preloadImages).then((value) => live && setResult(value));
    }, first.current ? 0 : 300);
    first.current = false;
    return () => { live = false; clearTimeout(timer); };
  }, [key]);

  useLayoutEffect(() => {
    if (!result || result.error) return;
    host.current.innerHTML = result.html;
    // Already loaded (preloadImages): painted with the swap, not a frame later.
    host.current.querySelectorAll('img').forEach((image) => { image.loading = 'eager'; image.decoding = 'sync'; });
    // What folds on the page (an accordion item) is open here, so that what is in it can be edited.
    host.current.querySelectorAll('details').forEach((details) => { details.open = true; });
    const found = {};
    host.current.querySelectorAll('[data-frame-builder-slot]').forEach((element) => { found[element.dataset.frameBuilderSlot] = element; });
    setTargets(found);
    // Components pinned to a corner (the "position" feature) are placed relative to the component they are in: its
    // root element anchors them. The pin itself goes on Puck's wrapper of this component, not on the component, so
    // the wrapper keeps its size and selecting and dragging work.
    const root = host.current.firstElementChild;
    if (root && getComputedStyle(root).position === 'static') root.style.position = 'relative';
    pin(id, result.wrapper);
    styles(id, result.styles);
    // React islands replace their children when they mount, which would remove the drop zones: components with slots
    // stay static in the editor.
    if (!definition.slots.length) {
      window.Drupal.attachBehaviors(host.current, window.drupalSettings);
    }
  }, [result]);

  return (
    <>
      {!result && <div className="frame-builder-loading">{definition.label}…</div>}
      {result?.error && <div className="frame-builder-error">{definition.label}: {result.error}</div>}
      <div ref={host} />
      {definition.slots.map((slot) => (targets[slot.name] && slots[slot.name]
        ? createPortal(createElement(slots[slot.name], { minEmptyHeight: 64 }), targets[slot.name], slot.name)
        : null))}
    </>
  );
}

// On a translation that shares the structure of the original, only text and links can be edited.
const locked = Boolean(settings.translation?.locked);

function puckComponent(definition) {
  const fields = {};
  const defaultProps = {};
  definition.fields.forEach((field) => {
    fields[field.name] = toPuckField({ ...field, locked: locked && !field.translatable });
    if (field.default !== null && field.default !== undefined) {
      // Formatted text starts in a format with an editor, not in the fallback format that would escape the example.
      defaultProps[field.name] = field.widget === 'html' ? { value: field.default, format: startFormat(field) } : field.default;
    }
  });
  definition.slots.forEach((slot) => {
    fields[slot.name] = { type: 'slot', label: slot.label };
    defaultProps[slot.name] = [];
  });

  return {
    // With its group, so that it is clear where a selected item comes from: a component of the theme, a field of the
    // page, a view, a block of Drupal, a paragraph. Shown on the selected item, above its fields and in Structure.
    label: definition.group ? `${definition.group}: ${definition.label}` : definition.label,
    fields,
    defaultProps,
    render: (props) => {
      const values = {};
      definition.fields.forEach((field) => { values[field.name] = props[field.name]; });
      // A Paragraph block is shown with the unsaved values of its form, which Drupal keeps under the id of the block.
      if (definition.fields.some((field) => field.widget === 'paragraph')) values.id = props.id;
      const slots = {};
      definition.slots.forEach((slot) => { slots[slot.name] = props[slot.name]; });
      return <ServerComponent id={props.id} definition={definition} values={values} slots={slots} />;
    },
  };
}

function buildConfig() {
  const components = {};
  const categories = {};
  settings.components.forEach((definition) => {
    components[toPuckType(definition.id)] = puckComponent(definition);
    // Long lists (Drupal's blocks) start collapsed.
    (categories[definition.group] ||= { title: definition.group, components: [], defaultExpanded: !definition.collapsed }).components.push(toPuckType(definition.id));
  });
  // A saved layout can use components that are gone (a removed field, block or view display, an SDC that was
  // renamed). Puck cannot work with items it has no config for, so each gets a stand-in that can be selected and
  // deleted. Drupal prints nothing for them on the page.
  const missing = new Set();
  const scan = (items) => items.forEach((entry) => {
    if (!components[entry.type]) missing.add(entry.type);
    Object.values(entry.props || {}).forEach((value) => {
      if (Array.isArray(value) && value.every((child) => child && typeof child === 'object' && child.type && child.props)) scan(value);
    });
  });
  scan(fromDrupal(settings.draft?.data || settings.data)?.content || []);
  missing.forEach((type) => {
    components[type] = {
      label: `Missing: ${toDrupalType(type)}`,
      fields: {},
      render: () => <div className="frame-builder-error">“{toDrupalType(type)}” is no longer available. It is not shown on the page; remove it or leave it.</div>,
    };
  });

  return {
    components,
    categories,
    root: {
      // Nothing selected: the right panel shows the page settings, a part of Drupal's node form (title, state, URL
      // alias, authoring...). They are saved by that form, not kept in the layout.
      fields: { _page: { type: 'custom', render: () => <ContentPanel frame="page" /> } },
      render: ({ children }) => <div className="frame-builder-root">{children}</div>,
    },
  };
}

const icon = (children) => (
  <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" aria-hidden="true">{children}</svg>
);

// Side panels, in this order: the fields and the menu settings of the page (Drupal's node form), the components to
// add, the structure of the layout. With the Field Group module, each top-level group of the form is a panel of its
// own, after Content: the rail of icons switches between them.
const formPanels = {
  content: { label: 'Content', icon: icon(<><path d="M5 4h14v16H5z" strokeLinejoin="round" /><path d="M8.5 9h7M8.5 13h7M8.5 17h4" /></>) },
  menu: { label: 'Menu', icon: icon(<path d="M4 6h16M4 12h16M4 18h10" />) },
};
const plugins = [
  ...settings.sections.map(({ name, label, icon: groupIcon }) => ({
    name,
    ...(formPanels[name] || { label, icon: <PanelIcon icon={groupIcon} label={label} /> }),
    render: () => <ContentPanel frame="fields" section={name} />,
  })),
  // Puck's own Blocks tab (its name and icon) with our list in it: groups, icons and a search.
  { ...blocksPlugin(), render: () => <BlocksPanel disabled={locked} /> },
  // Three lines, each one further in: a tree.
  // The tree of the layout, where several components can be selected, moved, deleted or put into a layout together.
  structurePlugin({ label: 'Structure', icon: icon(<path d="M3 6h11M7 12h11M11 18h11" />) }),
  // With the frame_builder_ai module: the assistant. A four-pointed star, as AI features are marked.
  ...(settings.ai ? [{ name: 'ai', label: 'AI', icon: icon(<path d="M12 3l2.2 6.3L20.5 12l-6.3 2.7L12 21l-2.2-6.3L3.5 12l6.3-2.7z" strokeLinejoin="round" />), render: () => <AiPanel disabled={locked} /> }] : []),
];

/**
 * The page as visitors would see it, with the unsaved layout and field values, in a frame that can take the widths
 * of the theme's breakpoints (Drupal's Breakpoint module): the page really is that wide, so its media queries apply.
 */
function PreviewDialog({ onClose }) {
  const sizes = [...settings.viewports, { label: 'Full width', width: null }];
  const [size, setSize] = useState(sizes[sizes.length - 1]);
  return (
    <Modal title="Preview" onClose={onClose} wide flush full>
      <div className="frame-builder-preview">
        <div className="frame-builder-preview__bar" role="group" aria-label="Width of the preview">
          {sizes.map((entry) => (
            <button type="button" key={entry.label} className={`frame-builder-button frame-builder-button--small${entry.label === size.label ? ' is-active' : ''}`} aria-pressed={entry.label === size.label}
                    title={entry.width ? `${entry.width}px wide` : 'As wide as this window'} onClick={() => setSize(entry)}>
              {entry.label}{entry.width ? ` · ${entry.width}` : ''}
            </button>
          ))}
          <a className="frame-builder-link frame-builder-preview__open" href={settings.endpoints.preview} target="_blank" rel="noreferrer">Open in a new tab</a>
        </div>
        <div className="frame-builder-preview__stage">
          <iframe className="frame-builder-preview__frame" title="Preview of the page" src={`${settings.endpoints.preview}?frame=1`} style={{ width: size.width ? `${size.width}px` : '100%' }} />
        </div>
      </div>
    </Modal>
  );
}

const layoutOf = toDrupalLayout;
const serialize = (data) => JSON.stringify(data.content);

const usePuckApi = createUsePuck();
// Puck's dispatch, for code outside its tree (after a save, Drupal says which paragraph revisions blocks now name).
const puckApi = { dispatch: null };
function CapturePuck() {
  puckApi.dispatch = usePuckApi((state) => state.dispatch);
  return null;
}

/** Paragraph blocks after a save: the revisions Drupal saved, and no marks of unsaved values. */
const withSavedParagraphs = (items, saved) => items.map((entry) => {
  const props = Object.fromEntries(Object.entries(entry.props || {}).map(([name, value]) => [
    name,
    Array.isArray(value) && value.length && value.every((child) => child && typeof child === 'object' && typeof child.type === 'string' && child.props) ? withSavedParagraphs(value, saved) : value,
  ]));
  if (entry.type.startsWith('drupal_paragraph/') && props.paragraph) {
    if (saved[props.id]) props.paragraph = saved[props.id];
    else { const { draft, ...rest } = props.paragraph; props.paragraph = rest; }
  }
  return { ...entry, props };
});

/**
 * The help of a component, behind a "?" at the end of the line with its name in the right column: a popover that a
 * click opens and closes, as Escape and a click elsewhere close it.
 */
function ComponentHelp({ label, help }) {
  const [open, setOpen] = useState(false);
  const box = useRef(null);
  useEffect(() => {
    if (!open) return undefined;
    const close = (event) => { if (!box.current?.contains(event.target)) setOpen(false); };
    const escape = (event) => { if (event.key === 'Escape') { event.stopPropagation(); setOpen(false); box.current?.querySelector('button')?.focus(); } };
    document.addEventListener('mousedown', close, true);
    document.addEventListener('keydown', escape, true);
    return () => { document.removeEventListener('mousedown', close, true); document.removeEventListener('keydown', escape, true); };
  }, [open]);
  return (
    <span className="frame-builder-help" ref={box}>
      <button type="button" className="frame-builder-help__button" aria-expanded={open} aria-controls="frame-builder-help" aria-label={`Help: ${label}`}
              title="Help" onClick={() => setOpen(!open)}>
        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 20 20" aria-hidden="true" focusable="false">
          <circle cx="10" cy="10" r="8.25" fill="none" stroke="currentColor" strokeWidth="1.5" />
          <path d="M7.75 7.75a2.25 2.25 0 1 1 3.3 2c-.65.35-1.05.9-1.05 1.6v.4" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" />
          <circle cx="10" cy="14.4" r="1" fill="currentColor" />
        </svg>
      </button>
      {open && (
        <span id="frame-builder-help" className="frame-builder-help__popover" role="tooltip">
          {help.split(/\n\s*\n/).map((paragraph) => <span key={paragraph} className="frame-builder-help__paragraph">{paragraph.trim()}</span>)}
        </span>
      )}
    </span>
  );
}

const definitionsById = new Map(settings.components.map((definition) => [toPuckType(definition.id), definition]));

/** Puts the help of the selected component at the end of the line with its name, at the top of the right column. */
function HelpOfSelected() {
  const type = usePuckApi((state) => state.selectedItem?.type);
  const definition = type ? definitionsById.get(type) : null;
  const [target, setTarget] = useState(null);
  const marker = useRef(null);
  useLayoutEffect(() => {
    // Puck draws the fields twice: in the right column, and in a tab of the left one for narrow windows. Only the
    // right column has a title to put the help next to; the other copy has none and shows none.
    const column = marker.current?.closest('[class^="_Sidebar_"], [class*=" _Sidebar_"], [class*="PuckPluginTab-body"]');
    const title = column && !column.matches('[class*="PuckPluginTab-body"]') ? column.querySelector('[class*="SidebarSection-title"]') : null;
    setTarget(definition?.help ? title : null);
  }, [type, definition]);
  return (
    <>
      <span ref={marker} hidden />
      {target && definition?.help && createPortal(<ComponentHelp key={type} label={definition.label} help={definition.help} />, target)}
    </>
  );
}

/**
 * The breakpoints at the top of the canvas, as above the features of a component. With a breakpoint chosen, the canvas
 * is as wide as that breakpoint starts (scrolled sideways when the canvas is narrower), and its components show the
 * layout and style they have from there on.
 */
function CanvasBreakpoints() {
  const current = useBreakpoint();
  const [canvas, setCanvas] = useState(null);
  useEffect(() => {
    // Puck replaces its canvas while it starts (and when the layout of the editor changes): the one shown now.
    const find = () => document.querySelector('#frame-builder [class*="PuckCanvas_"]');
    let found = null;
    const check = () => {
      const now = find();
      if (now !== found) { found = now; setCanvas(now); }
    };
    check();
    const observer = new MutationObserver(check);
    observer.observe(document.getElementById('frame-builder'), { childList: true, subtree: true });
    return () => observer.disconnect();
  }, []);
  useLayoutEffect(() => {
    if (!canvas) return undefined;
    const breakpoint = (settings.breakpoints || []).find((entry) => entry.id === current);
    const apply = () => {
      if (!breakpoint) {
        canvas.removeAttribute('data-frame-builder-width');
        canvas.style.removeProperty('--frame-builder-canvas-width');
        return;
      }
      canvas.setAttribute('data-frame-builder-width', breakpoint.id);
      canvas.style.setProperty('--frame-builder-canvas-width', `${breakpoint.width}px`);
    };
    apply();
    return undefined;
  }, [canvas, current]);
  return canvas && (settings.breakpoints || []).length ? createPortal(<div className="frame-builder-canvas-breakpoints"><BreakpointTabs widths label="Width of the canvas" /></div>, canvas) : null;
}

/** The right column of the editor: Puck's fields, or the history timeline in their place. */
function RightColumn({ children }) {
  if (useHistoryOpen()) return <HistoryPanel />;
  return <>{children}<HelpOfSelected /><FullNames /></>;
}

/**
 * Above the name of the selected component, in place of Puck's breadcrumbs: a house that goes back to the page, and
 * the one component it is in, if any. Both small, on a line of their own; a long name is cut, the whole name in its
 * tooltip.
 */
// Mounted once, in the header: it fills every copy of the title itself.
function Trail() {
  const selected = usePuckApi((state) => state.selectedItem?.props?.id ?? null);
  const content = usePuckApi((state) => state.appState.data.content);
  const config = usePuckApi((state) => state.config);
  const dispatch = usePuckApi((state) => state.dispatch);
  const [hosts, setHosts] = useState([]);
  useEffect(() => {
    // Puck draws the right column twice (the second for narrow windows) and anew when the selection changes.
    const place = () => {
      const found = [...document.querySelectorAll('#frame-builder [class*="SidebarSection-breadcrumbs"]')].map((row) => {
        let host = row.querySelector(':scope > .frame-builder-trail');
        if (!host) {
          host = Object.assign(document.createElement('nav'), { className: 'frame-builder-trail' });
          host.setAttribute('aria-label', 'Where the component is');
          row.prepend(host);
        }
        return host;
      });
      setHosts((before) => (before.length === found.length && before.every((host, at) => host === found[at]) ? before : found));
    };
    place();
    const observer = new MutationObserver(place);
    observer.observe(document.getElementById('frame-builder'), { childList: true, subtree: true });
    return () => observer.disconnect();
  }, []);
  if (!selected) return null;
  const parent = parentOf(content, selected);
  const name = parent ? config.components[parent.type]?.label || parent.type : '';
  const trail = (
    <>
      <button type="button" className="frame-builder-trail__home" title="Page" aria-label="Page" onClick={() => dispatch({ type: 'setUi', ui: { itemSelector: null } })}>
        <svg width="16" height="16" viewBox="0 0 20 20" aria-hidden="true"><path d="M3 9.5 10 3.5l7 6M5 8.2V16.5h3.8v-4.5h2.4v4.5H15V8.2" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round" /></svg>
      </button>
      {parent && (
        <>
          <svg className="frame-builder-trail__separator" width="12" height="12" viewBox="0 0 12 12" aria-hidden="true"><path d="M4.5 2.5 8 6l-3.5 3.5" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" /></svg>
          <button type="button" className="frame-builder-trail__parent" title={name}
                  onClick={() => dispatch({ type: 'setUi', ui: { itemSelector: selectorFor(content, parent.props.id) } })}>{name}</button>
        </>
      )}
    </>
  );
  return <>{hosts.map((host, at) => <Fragment key={at}>{createPortal(trail, host)}</Fragment>)}</>;
}

/** The name in the title of the right column can be long: the whole name is its tooltip. */
function FullNames() {
  const selected = usePuckApi((state) => state.selectedItem?.props?.id);
  useEffect(() => {
    document.querySelectorAll('#frame-builder [class*="SidebarSection-heading"] h2').forEach((label) => {
      label.title = label.textContent;
    });
  }, [selected]);
  return null;
}

function Editor() {
  const [config, setConfig] = useState(buildConfig);
  // A block saved to the library is a new component.
  useEffect(() => subscribeLibrary(() => setConfig(buildConfig())), []);
  // Unsaved state this user left behind (autosaved per user by Drupal): continue from it.
  const { draft } = settings;
  const [initial] = useState(() => ({
    content: [],
    ...(fromDrupal(draft?.data || settings.data) || {}),
    root: { props: {} },
  }));
  const [viewUrl, setViewUrl] = useState(settings.viewUrl);
  const [layoutDirty, setLayoutDirty] = useState(Boolean(draft));
  const dirty = useContentDirty() || layoutDirty;

  const latest = useRef(initial);
  // What is saved in Drupal, to tell whether there are changes. Unknown when a draft was restored.
  const baseline = useRef(draft ? null : serialize(initial));
  const started = useRef(Date.now());
  const revision = useRef(draft?.revision ?? settings.revision);
  const autosave = useRef({ timer: null, pending: null });

  const flushDraft = async () => {
    clearTimeout(autosave.current.timer);
    const data = autosave.current.pending;
    autosave.current.pending = null;
    if (data) {
      await postJson(settings.endpoints.draft, { data: layoutOf(data), revision: revision.current });
    }
  };
  const discardDraft = async () => {
    clearTimeout(autosave.current.timer);
    autosave.current.pending = null;
    await postJson(settings.endpoints.draft, { discard: true });
  };

  const [status, setStatus] = useState(() => (draft ? {
    type: 'info',
    messages: [`Your unsaved changes from ${draft.label} were restored.${draft.stale ? ' The page has been saved since then: saving now replaces that version.' : ''}`],
    action: { label: 'Discard them', run: async () => { await discardDraft(); window.location.reload(); } },
  } : null));

  const onChange = (data) => {
    latest.current = data;
    const now = serialize(data);
    // Right after loading Puck completes the data (defaults, ids): that is not a change by the user.
    if (baseline.current !== null && !layoutDirty && Date.now() - started.current < 1500) {
      baseline.current = now;
      return;
    }
    const changed = baseline.current === null || now !== baseline.current;
    setLayoutDirty(changed);
    if (changed) {
      // Keep the unsaved state in Drupal (per user), so the editor can be reloaded and previewed.
      autosave.current.pending = data;
      clearTimeout(autosave.current.timer);
      autosave.current.timer = setTimeout(flushDraft, 1500);
    }
    else {
      discardDraft();
    }
  };

  // `as`: "publish" or "draft" set the state of the page; without it the state chosen in the page settings is kept.
  const save = async (data, { as = null } = {}) => {
    setStatus({ type: 'busy', messages: ['Saving…'] });
    clearTimeout(autosave.current.timer);
    autosave.current.pending = null;
    // Fields changed in the Content panel are saved first, by Drupal's own form.
    if (!(await saveContent())) {
      setStatus({ type: 'error', messages: ['The page could not be saved. Check the panels with the fields of the page and the page settings for what needs fixing; the layout was not saved.'] });
      return;
    }
    const { ok, body } = await postJson(settings.endpoints.save, { data: layoutOf(data), draft: as === 'draft', publish: as === 'publish' });
    if (ok) {
      if (body.paragraphs && puckApi.dispatch) {
        latest.current = { ...latest.current, content: withSavedParagraphs(latest.current.content, body.paragraphs) };
        baseline.current = serialize(latest.current);
        puckApi.dispatch({ type: 'setData', data: latest.current, recordHistory: false });
      }
      baseline.current = serialize(latest.current);
      revision.current = body.revision;
      markSaved();
      setLayoutDirty(false);
      setViewUrl(body.viewUrl);
      setStatus({ type: 'status', messages: [{ draft: 'Saved as a draft. Visitors do not see it until it is published.', publish: 'The page has been published.' }[as] || 'The page has been saved.'] });
    }
    else {
      setStatus({ type: 'error', messages: body?.errors || ['The page could not be saved.'] });
    }
  };

  // How this page relates to its translations: a short note where Puck shows the title (the admin page around the
  // editor already has the page title), with the full explanation as its tooltip.
  const note = settings.translation?.short;
  useEffect(() => {
    if (!note) return undefined;
    const container = document.getElementById('frame-builder');
    container.setAttribute('data-note', '');
    // Puck draws its header a moment after the editor mounts.
    const timer = setInterval(() => {
      const title = container.querySelector('[class*="PuckHeader-title"]');
      if (title) {
        title.setAttribute('title', settings.translation.notice);
        clearInterval(timer);
      }
    }, 200);
    return () => clearInterval(timer);
  }, []);

  const [previewing, setPreviewing] = useState(false);
  const preview = async () => {
    // The preview shows what is autosaved: make sure the latest change is.
    await flushDraft();
    setPreviewing(true);
  };

  return (
    <>
      <Puck
        config={config}
        data={initial}
        onChange={onChange}
        // Undo and redo bring back a whole earlier state of Puck, with the panel that was open then: the panel open now
        // stays open, as the user chose it.
        onAction={(action, state, before) => {
          // Several selected: the others follow the one dragged.
          followMove(action, state, before, (next) => puckApi.dispatch?.(next));
          const was = before?.ui?.plugin?.current;
          if (action.type === 'set' && was && state?.ui?.plugin?.current !== was) {
            setTimeout(() => puckApi.dispatch?.({ type: 'setUi', ui: { plugin: { current: was } } }), 0);
          }
        }}
        onPublish={(data) => save(data, { as: settings.actions.publish ? 'publish' : null })}
        iframe={{ enabled: false }}
        headerTitle={note || undefined}
        plugins={plugins}
        // Room for Drupal's field widgets in the Content panel; the sidebar can be resized.
        // On a small window the left column leaves the canvas some room.
        // The right column has room for a row of a multiple value field: a handle, the value and Remove.
        ui={{ leftSideBarWidth: Math.max(260, Math.min(380, Math.round(window.innerWidth * 0.27))), rightSideBarWidth: Math.max(280, Math.min(360, Math.round(window.innerWidth * 0.24))) }}
        dictionary={{ 'header-publish': 'Save', 'plugin-outline': 'Structure', 'outline-header-title': 'Structure' }}
        permissions={locked ? { drag: false, insert: false, delete: false, duplicate: false } : undefined}
        overrides={{
          // The right column: the fields of what is selected, or the history while it is open.
          fields: RightColumn,
          // Preview or View, then what can be done with the page. Puck's own button is not used: all the same size.
          headerActions: () => (
            <>
              <CapturePuck />
              <SelectionOnCanvas />
              <Trail />
              <HistoryButton />
              <DeleteSelected />
              {dirty && <span className="frame-builder-unsaved" role="status">Unsaved changes</span>}
              {dirty
                ? <button type="button" className="frame-builder-button frame-builder-button--small" title="See the page with your unsaved changes. Nothing is saved." onClick={preview}>Preview</button>
                : <a className="frame-builder-button frame-builder-button--small" href={viewUrl} target="_top" title="Go to the saved page">View</a>}
              <span className="frame-builder-divider" aria-hidden="true" />
              {settings.actions.publish
                ? <button type="button" className="frame-builder-button frame-builder-button--small frame-builder-button--primary" title="Save and publish: visitors see the page" onClick={() => save(latest.current, { as: 'publish' })}>Publish</button>
                : <button type="button" className="frame-builder-button frame-builder-button--small frame-builder-button--primary" title="Save your changes" onClick={() => save(latest.current)}>Save</button>}
              {settings.actions.draft && (
                <button type="button" className="frame-builder-button frame-builder-button--small" title="Save as draft: keeps your changes without publishing them" onClick={() => save(latest.current, { as: 'draft' })}>Draft</button>
              )}
              {settings.actions.delete && (
                <a className="frame-builder-button frame-builder-button--small frame-builder-button--danger" href={settings.actions.delete} target="_top" title="Delete this page (asks for confirmation)">Delete</a>
              )}
            </>
          ),
        }}
      />
      <ContentFrames />
      <CanvasBreakpoints />
      {previewing && <PreviewDialog onClose={() => setPreviewing(false)} />}
      {status && (
        <div className={`frame-builder-messages frame-builder-messages--${status.type} frame-builder-messages--floating`} role={status.type === 'error' ? 'alert' : 'status'}>
          <div className="frame-builder-messages__title">{{ status: 'Status message', error: 'Error message', busy: 'Working', info: 'Unsaved changes' }[status.type]}</div>
          {status.messages.length === 1 ? status.messages[0] : <ul>{status.messages.map((message) => <li key={message}>{message}</li>)}</ul>}
          {status.action && <button type="button" className="frame-builder-link frame-builder-messages__action" onClick={status.action.run}>{status.action.label}</button>}
          {status.type !== 'busy' && <button type="button" className="frame-builder-messages__close" aria-label="Dismiss" onClick={() => setStatus(null)}>×</button>}
        </div>
      )}
    </>
  );
}

createRoot(document.getElementById('frame-builder')).render(<Editor />);
