// The node form of Drupal inside the editor (every widget, everything modules add to it), in frames rendered by the
// admin theme. Two frames, each with a part of the form (BuilderContentForm):
// - "fields": the fields of the content type and the menu settings, shown in the Content and Menu panels on the left,
//   and in one panel per top-level group of the Field Group module;
// - "page": title, state, URL alias, authoring..., shown on the right when no component is selected.
//
// Puck unmounts a panel when another one is opened, which would reload a frame and lose what was typed. So the frames
// live once at the top of the editor and are laid over the panel that shows them.
import { useEffect, useLayoutEffect, useRef, useSyncExternalStore } from 'react';
import { settings } from './api';

const FRAMES = ['fields', 'page'];

// --- state shared by the panels, the frames and the Save button
let state = {
  frames: { fields: { rect: null, dirty: false }, page: { rect: null, dirty: false } },
  // The panel of the "fields" frame that is open: "content", "menu" or the name of a field group.
  section: 'content',
  // Changes when field values changed (autosaved or saved): previews render again.
  version: 0,
};
const listeners = new Set();
const set = (patch) => { state = { ...state, ...patch }; listeners.forEach((listener) => listener()); };
const setFrame = (name, patch) => set({ frames: { ...state.frames, [name]: { ...state.frames[name], ...patch } } });
const subscribe = (listener) => { listeners.add(listener); return () => listeners.delete(listener); };
const useContentState = () => useSyncExternalStore(subscribe, () => state);

/** Changes when field values changed: previews of components read it to render again. */
export const useContentVersion = () => useContentState().version;

/** Whether the node form has unsaved changes. */
export const useContentDirty = () => { const { frames } = useContentState(); return frames.fields.dirty || frames.page.dirty; };

const windows = {};
const pending = {};

/**
 * Saves the parts of the node form that were changed, one after the other. Resolves with true when there is nothing
 * to save or all is saved, with false when Drupal reports errors (they are shown in the form).
 */
export async function saveContent() {
  for (const name of FRAMES) {
    if (!state.frames[name].dirty || !windows[name]) continue;
    const ok = await new Promise((resolve) => {
      const timer = setTimeout(() => { pending[name] = null; resolve(false); }, 30000);
      pending[name] = (result) => { clearTimeout(timer); pending[name] = null; resolve(result); };
      windows[name].postMessage({ type: 'frame-builder:submit' }, window.location.origin);
    });
    if (!ok) return false;
  }
  return true;
}

// The placeholder each frame is laid over at the moment. Puck can keep the panels of several tabs mounted (hidden):
// only one that is actually visible takes a frame.
const owners = {};

/** Loads a part of the node form again: its values were changed outside the form (the assistant proposed meta tags). */
export function reloadContent(name) {
  try { windows[name]?.location.reload(); } catch { /* the frame is not there yet: it loads with the new values */ }
}

/** Placeholder that a frame is laid over. `frame` is the part of the form, `section` the panel of the fields part. */
export function ContentPanel({ frame = 'fields', section }) {
  const element = useRef(null);
  useLayoutEffect(() => {
    const me = element.current;
    const measure = () => {
      const box = me.getBoundingClientRect();
      if (box.width < 1 || box.height < 1) {
        if (owners[frame] === me) { owners[frame] = null; setFrame(frame, { rect: null }); }
        return;
      }
      owners[frame] = me;
      if (section && state.section !== section) set({ section });
      setFrame(frame, { rect: { left: box.left, top: box.top, width: box.width, height: Math.max(0, Math.min(box.height, window.innerHeight - box.top)) } });
    };
    measure();
    const observer = new ResizeObserver(measure);
    observer.observe(me);
    window.addEventListener('resize', measure);
    // The right sidebar scrolls: the frame follows its placeholder.
    document.addEventListener('scroll', measure, true);
    return () => {
      observer.disconnect();
      window.removeEventListener('resize', measure);
      document.removeEventListener('scroll', measure, true);
      if (owners[frame] === me) { owners[frame] = null; setFrame(frame, { rect: null }); }
    };
  }, []);
  return <div ref={element} className={`frame-builder-content-panel frame-builder-content-panel--${frame}`} />;
}

/** The frames themselves. Rendered once, next to Puck. */
export function ContentFrames() {
  const { frames, section } = useContentState();
  const elements = useRef({});
  const showSection = () => elements.current.fields?.contentWindow?.postMessage({ type: 'frame-builder:section', section: state.section }, window.location.origin);
  useEffect(showSection, [section]);
  useEffect(() => {
    const onMessage = (event) => {
      if (event.origin !== window.location.origin || event.data?.type !== 'frame-builder:content') return;
      const { status, part } = event.data;
      if (!FRAMES.includes(part)) return;
      windows[part] = elements.current[part]?.contentWindow;
      // The form was (re)loaded: tell it which panel is open.
      if (status !== 'dirty' && status !== 'preview') showSection();
      if (status === 'dirty') setFrame(part, { dirty: true });
      if (status === 'ready') setFrame(part, { dirty: false });
      // Values were autosaved as a draft: the preview can show them.
      if (status === 'preview') set({ version: state.version + 1 });
      if (status === 'saved') { setFrame(part, { dirty: false }); set({ version: state.version + 1 }); pending[part]?.(true); }
      if (status === 'error') pending[part]?.(false);
    };
    window.addEventListener('message', onMessage);
    return () => window.removeEventListener('message', onMessage);
  }, []);
  return FRAMES.map((name) => {
    const { rect } = frames[name];
    return (
      <iframe
        key={name}
        ref={(element) => { elements.current[name] = element; }}
        className="frame-builder-content-frame"
        title={name === 'page' ? 'Page settings' : 'Content'}
        src={name === 'page' ? settings.endpoints.page : settings.endpoints.content}
        style={rect ? { left: rect.left, top: rect.top, width: rect.width, height: rect.height } : { display: 'none' }}
      />
    );
  });
}
