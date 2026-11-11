// Puck editor over Drupal Single Directory Components.
//
// Components are not React components here: Drupal renders each one (Twig) and the editor shows that HTML. Slots come
// back as empty markers, and Puck's drop zones are mounted into them with portals, so components can be nested.
import { createElement, useEffect, useLayoutEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { createRoot } from 'react-dom/client';
import { createUsePuck, Puck, blocksPlugin, outlinePlugin } from '@puckeditor/core';
import '@puckeditor/core/puck.css';
import { postJson, settings } from './api';
import { startFormat, toPuckField } from './fields';
import { ContentFrames, ContentPanel, saveContent, useContentDirty, useContentVersion } from './contentFrame';
import { Modal } from './ui';
import { BlocksPanel } from './BlocksPanel';
import { HistoryButton, HistoryPanel, markSaved, useHistoryOpen } from './History';
import { fromDrupal, toDrupalLayout, toDrupalType, toPuckType } from './types';
import { subscribeLibrary } from './library';
import { AiPanel } from './AiPanel';

// Rendered HTML by component + props, so moving or remounting a component does not ask Drupal again.
const htmlCache = new Map();

function renderOnServer(type, props, key) {
  if (!htmlCache.has(key)) {
    const request = postJson(settings.endpoints.render, { type, props, node: settings.node, langcode: settings.langcode })
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
  let sheet = document.getElementById('rsb-pins');
  if (!sheet) {
    sheet = document.head.appendChild(Object.assign(document.createElement('style'), { id: 'rsb-pins' }));
  }
  // The pinned wrapper is placed relative to the root of the parent component, as on the page: the slot marker and
  // Puck's drop zone between them must not be the reference.
  sheet.textContent = [...pins].map(([component, rule]) => {
    const wrapper = `[data-puck-component="${CSS.escape(component)}"]`;
    // Only what is pinned (absolute) needs the reference moved; what is placed in the flow stays where it is.
    const anchor = /position: absolute/.test(rule) ? `\n[data-rsb-slot]:has(> * > ${wrapper}), [data-rsb-slot] > :has(> ${wrapper}) { position: static !important; }` : '';
    return `${wrapper} { ${rule} }${anchor}`;
  }).join('\n');
}

// CSS rules that come with a component and belong in the head of the page: a background with a responsive image
// style is media queries. On the page Drupal puts them there; here they are kept by component id.
const sheets = new Map();
function styles(id, css) {
  if ((sheets.get(id) || '') === (css || '')) return;
  css ? sheets.set(id, css) : sheets.delete(id);
  let sheet = document.getElementById('rsb-styles');
  if (!sheet) {
    sheet = document.head.appendChild(Object.assign(document.createElement('style'), { id: 'rsb-styles' }));
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
  const key = JSON.stringify([definition.id, values, version]);
  const cached = htmlCache.get(key);
  const [result, setResult] = useState(cached && !cached.then ? cached : null);
  const first = useRef(true);
  const host = useRef(null);
  const [targets, setTargets] = useState({});

  useEffect(() => {
    let live = true;
    // Wait for typing to pause before asking Drupal, except for the first render.
    const timer = setTimeout(() => {
      Promise.resolve(renderOnServer(definition.id, values, key)).then(preloadImages).then((value) => live && setResult(value));
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
    host.current.querySelectorAll('[data-rsb-slot]').forEach((element) => { found[element.dataset.rsbSlot] = element; });
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
      {!result && <div className="rsb-loading">{definition.label}…</div>}
      {result?.error && <div className="rsb-error">{definition.label}: {result.error}</div>}
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
    label: definition.label,
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
      render: () => <div className="rsb-error">“{toDrupalType(type)}” is no longer available. It is not shown on the page; remove it or leave it.</div>,
    };
  });

  return {
    components,
    categories,
    root: {
      // Nothing selected: the right panel shows the page settings, a part of Drupal's node form (title, state, URL
      // alias, authoring...). They are saved by that form, not kept in the layout.
      fields: { _page: { type: 'custom', render: () => <ContentPanel frame="page" /> } },
      render: ({ children }) => <div className="rsb-root">{children}</div>,
    },
  };
}

const icon = (children) => (
  <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" aria-hidden="true">{children}</svg>
);

// Side panels, in this order: the fields and the menu settings of the page (Drupal's node form), the components to
// add, the structure of the layout.
const formPanels = {
  content: { label: 'Content', icon: icon(<><path d="M5 4h14v16H5z" strokeLinejoin="round" /><path d="M8.5 9h7M8.5 13h7M8.5 17h4" /></>) },
  menu: { label: 'Menu', icon: icon(<path d="M4 6h16M4 12h16M4 18h10" />) },
};
const plugins = [
  ...settings.sections.map((section) => ({ name: section, ...formPanels[section], render: () => <ContentPanel frame="fields" section={section} /> })),
  // Puck's own Blocks tab (its name and icon) with our list in it: groups, icons and a search.
  { ...blocksPlugin(), render: () => <BlocksPanel disabled={locked} /> },
  // Three lines, each one further in: a tree.
  outlinePlugin({ label: 'Structure', icon: icon(<path d="M3 6h11M7 12h11M11 18h11" />) }),
  // With the react_scaffold_builder_ai module: the assistant. A four-pointed star, as AI features are marked.
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
      <div className="rsb-preview">
        <div className="rsb-preview__bar" role="group" aria-label="Width of the preview">
          {sizes.map((entry) => (
            <button type="button" key={entry.label} className={`rsb-button rsb-button--small${entry === size ? ' is-active' : ''}`} aria-pressed={entry === size}
                    title={entry.width ? `${entry.width}px wide` : 'As wide as this window'} onClick={() => setSize(entry)}>
              {entry.label}{entry.width ? ` · ${entry.width}` : ''}
            </button>
          ))}
          <a className="rsb-link rsb-preview__open" href={settings.endpoints.preview} target="_blank" rel="noreferrer">Open in a new tab</a>
        </div>
        <div className="rsb-preview__stage">
          <iframe className="rsb-preview__frame" title="Preview of the page" src={`${settings.endpoints.preview}?frame=1`} style={{ width: size.width ? `${size.width}px` : '100%' }} />
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

/** The right column of the editor: Puck's fields, or the history timeline in their place. */
function RightColumn({ children }) {
  return useHistoryOpen() ? <HistoryPanel /> : children;
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
      setStatus({ type: 'error', messages: ['The page could not be saved. Check the Content and Menu panels and the page settings for what needs fixing; the layout was not saved.'] });
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
    const container = document.getElementById('react-scaffold-builder');
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
        onPublish={(data) => save(data, { as: settings.actions.publish ? 'publish' : null })}
        iframe={{ enabled: false }}
        headerTitle={note || undefined}
        plugins={plugins}
        // Room for Drupal's field widgets in the Content panel; the sidebar can be resized.
        ui={{ leftSideBarWidth: 380 }}
        dictionary={{ 'header-publish': 'Save', 'plugin-outline': 'Structure', 'outline-header-title': 'Structure' }}
        permissions={locked ? { drag: false, insert: false, delete: false, duplicate: false } : undefined}
        overrides={{
          // The right column: the fields of what is selected, or the history while it is open.
          fields: RightColumn,
          // Preview or View, then what can be done with the page. Puck's own button is not used: all the same size.
          headerActions: () => (
            <>
              <CapturePuck />
              <HistoryButton />
              {dirty && <span className="rsb-unsaved" role="status">Unsaved changes</span>}
              {dirty
                ? <button type="button" className="rsb-button rsb-button--small" title="See the page with your unsaved changes. Nothing is saved." onClick={preview}>Preview</button>
                : <a className="rsb-button rsb-button--small" href={viewUrl} target="_top" title="Go to the saved page">View</a>}
              <span className="rsb-divider" aria-hidden="true" />
              {settings.actions.publish
                ? <button type="button" className="rsb-button rsb-button--small rsb-button--primary" title="Save and publish: visitors see the page" onClick={() => save(latest.current, { as: 'publish' })}>Publish</button>
                : <button type="button" className="rsb-button rsb-button--small rsb-button--primary" title="Save your changes" onClick={() => save(latest.current)}>Save</button>}
              {settings.actions.draft && (
                <button type="button" className="rsb-button rsb-button--small" title="Save as draft: keeps your changes without publishing them" onClick={() => save(latest.current, { as: 'draft' })}>Draft</button>
              )}
              {settings.actions.delete && (
                <a className="rsb-button rsb-button--small rsb-button--danger" href={settings.actions.delete} target="_top" title="Delete this page (asks for confirmation)">Delete</a>
              )}
            </>
          ),
        }}
      />
      <ContentFrames />
      {previewing && <PreviewDialog onClose={() => setPreviewing(false)} />}
      {status && (
        <div className={`rsb-messages rsb-messages--${status.type} rsb-messages--floating`} role={status.type === 'error' ? 'alert' : 'status'}>
          <div className="rsb-messages__title">{{ status: 'Status message', error: 'Error message', busy: 'Working', info: 'Unsaved changes' }[status.type]}</div>
          {status.messages.length === 1 ? status.messages[0] : <ul>{status.messages.map((message) => <li key={message}>{message}</li>)}</ul>}
          {status.action && <button type="button" className="rsb-link rsb-messages__action" onClick={status.action.run}>{status.action.label}</button>}
          {status.type !== 'busy' && <button type="button" className="rsb-messages__close" aria-label="Dismiss" onClick={() => setStatus(null)}>×</button>}
        </div>
      )}
    </>
  );
}

createRoot(document.getElementById('react-scaffold-builder')).render(<Editor />);
