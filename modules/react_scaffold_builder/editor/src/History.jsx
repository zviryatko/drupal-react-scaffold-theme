// History of the layout since the page was opened or last saved: a button next to undo and redo, and a timeline that
// takes the place of the fields in the right column while it is open. Every step says what it did; clicking one goes
// back (or forward) to it.
import { useMemo, useSyncExternalStore } from 'react';
import { createUsePuck } from '@puckeditor/core';
import { settings } from './api';
import { describe } from './history';
import { toDrupalType } from './types';

const usePuck = createUsePuck();

// Shared by the button (in the header) and the timeline (in the right column): whether the timeline is open, and the
// step at which the layout was last saved, which the editor sets after a save.
let store = { open: false, saved: 0 };
const listeners = new Set();
const subscribe = (listener) => { listeners.add(listener); return () => listeners.delete(listener); };
const update = (patch) => { store = { ...store, ...patch }; listeners.forEach((listener) => listener()); };
const useStore = () => useSyncExternalStore(subscribe, () => store);
let currentIndex = 0;
/** Marks the current step as the saved one. */
export const markSaved = () => update({ saved: currentIndex });
/** Whether the timeline is shown in the right column. */
export const useHistoryOpen = () => useStore().open;

// When each step was first seen.
const times = new Map();

const definitions = new Map(settings.components.map((component) => [component.id, component]));
const label = (type) => definitions.get(toDrupalType(type))?.label || toDrupalType(type);
const fieldLabel = (type, prop) => definitions.get(toDrupalType(type))?.fields.find((field) => field.name === prop)?.label || prop;
const clock = (time) => new Date(time).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });

const ICONS = { add: 'M12 5v14M5 12h14', remove: 'M5 12h14', move: 'M7 9l-3 3 3 3M17 9l3 3-3 3M4 12h16', change: 'M4 20h4L19 9l-4-4L4 16z', none: 'M5 12h14', start: 'M5 12l5 5L20 7' };

/**
 * The steps to show: one line per step. Typing in a field makes many steps with the same words; they show as one,
 * the latest, unless one of them is the current or the saved step.
 */
function useSteps() {
  const histories = usePuck((state) => state.history.histories);
  const index = usePuck((state) => state.history.index);
  const { saved } = useStore();
  currentIndex = index;
  histories.forEach((entry) => { if (!times.has(entry.id)) times.set(entry.id, Date.now()); });
  const steps = useMemo(() => {
    const lines = histories.map((entry, position) => {
      if (position === 0) {
        return { position, kind: 'start', text: settings.draft ? 'Opened with your unsaved changes' : 'Opened', time: times.get(entry.id) };
      }
      return { position, ...describe(histories[position - 1].state.data, entry.state.data, label, fieldLabel), time: times.get(entry.id) };
    });
    return lines.filter((line, position) => {
      const next = lines[position + 1];
      const boundary = [index, saved].some((marker) => marker === line.position);
      return line.kind !== 'none' && (boundary || !next || next.text !== line.text || next.kind !== 'change');
    });
  }, [histories, index, saved]);
  return { steps, index, saved };
}

/** The button next to undo and redo; the count is the steps since the save. */
export function HistoryButton() {
  const { steps, index, saved } = useSteps();
  const { open } = useStore();
  const dispatch = usePuck((state) => state.dispatch);
  const unsaved = steps.filter((line) => line.position > saved && line.position <= index).length;
  const toggle = () => {
    // The timeline is in the right column: make sure that column is shown.
    if (!open) dispatch({ type: 'setUi', ui: { rightSideBarVisible: true } });
    update({ open: !open });
  };
  return (
    <button type="button" className={`rsb-button rsb-button--small rsb-history-button${open ? ' is-active' : ''}`} aria-expanded={open}
            title="History: the changes since the page was opened or saved" onClick={toggle}>
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" aria-hidden="true">
        <path d="M8 6h12M8 12h12M8 18h12M4 6h.01M4 12h.01M4 18h.01" />
      </svg>
      {unsaved > 0 && <span className="rsb-history-button__count">{unsaved}</span>}
    </button>
  );
}

/** The timeline, shown in the right column in place of the fields. */
export function HistoryPanel() {
  const { steps, index, saved } = useSteps();
  const setHistoryIndex = usePuck((state) => state.history.setHistoryIndex);
  return (
    <aside className="rsb-history" aria-label="History">
      <div className="rsb-history__header">
        <strong>History</strong>
        <button type="button" className="rsb-button rsb-button--small" onClick={() => update({ open: false })}>Back to settings</button>
      </div>
      <p className="rsb-muted rsb-history__intro">Changes to the layout, newest first. Click a step to go back or forward to it.</p>
      <ol className="rsb-timeline">
        {[...steps].reverse().map((line) => (
          <li key={line.position} className={`rsb-timeline__step${line.position === index ? ' is-current' : ''}${line.position > index ? ' is-undone' : ''}`}>
            <button type="button" onClick={() => setHistoryIndex(line.position)} aria-current={line.position === index ? 'step' : undefined}>
              <span className={`rsb-timeline__dot rsb-timeline__dot--${line.kind}`}>
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><path d={ICONS[line.kind]} /></svg>
              </span>
              <span className="rsb-timeline__text">{line.text}</span>
              <span className="rsb-timeline__meta">
                {clock(line.time)}
                {line.position === saved && <em>{line.position === 0 && !settings.draft ? 'as saved' : 'saved'}</em>}
                {line.position === index && <em className="rsb-timeline__now">now</em>}
                {line.position > index && <em>undone</em>}
              </span>
            </button>
          </li>
        ))}
      </ol>
    </aside>
  );
}
