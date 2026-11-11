// The "AI" panel: a conversation with the assistant of the react_scaffold_builder_ai module. The assistant proposes a
// whole layout made of the components of the theme; it is put on the canvas as an unsaved change, one step in the
// history, so it can be looked at, changed by hand, undone or asked to be changed again.
import { useEffect, useRef, useState, useSyncExternalStore } from 'react';
import { createUsePuck } from '@puckeditor/core';
import { toJpeg } from 'html-to-image';
import { postJson, settings } from './api';
import { fromDrupal, toDrupalLayout } from './types';
import { reloadContent } from './contentFrame';

const usePuck = createUsePuck();

// The conversation outlives the panel: Puck unmounts a panel when another one is opened.
const chat = { messages: [], busy: false, look: true };
const listeners = new Set();
const update = (change) => { Object.assign(chat, change); listeners.forEach((listener) => listener()); };
let snapshot = 0;
const useChat = () => {
  useSyncExternalStore((listener) => { const wrapped = () => { snapshot += 1; listener(); }; listeners.add(wrapped); return () => listeners.delete(wrapped); }, () => snapshot);
  return chat;
};

/**
 * A picture of the canvas as it is now, for a model that takes images: the assistant asks for one to look at its own
 * work. Null when it cannot be made (an image of another origin, a very large page).
 */
async function screenshot() {
  const canvas = document.querySelector('.rsb-root');
  if (!canvas) return null;
  try {
    return await toJpeg(canvas, { quality: 0.7, pixelRatio: 1, backgroundColor: '#ffffff', height: Math.min(canvas.scrollHeight, 3000) });
  }
  catch {
    return null;
  }
}

/**
 * What the frontend theme looks like where the layout is: the editor runs in a page of that theme, so the browser
 * knows. Colours, fonts, the width of the layout area, and the custom properties the theme declares on the root
 * element (its own tokens and those of the builder).
 */
function lookOfTheTheme() {
  const canvas = document.querySelector('.rsb-root');
  if (!canvas) return {};
  const style = getComputedStyle(canvas);
  // The first background that is not transparent, from the canvas outwards.
  let background = '';
  for (let element = canvas; element && !background; element = element.parentElement) {
    const color = getComputedStyle(element).backgroundColor;
    if (color && color !== 'rgba(0, 0, 0, 0)' && color !== 'transparent') background = color;
  }
  const probe = (tag) => { const element = document.createElement(tag); if (tag === 'a') element.href = '#'; canvas.appendChild(element); const result = getComputedStyle(element); const values = { font: result.fontFamily, color: result.color }; element.remove(); return values; };
  const variables = {};
  [...document.styleSheets].forEach((sheet) => {
    let rules = [];
    try { rules = [...sheet.cssRules]; } catch { /* a style sheet of another origin */ }
    rules.forEach((rule) => {
      if (!rule.style || !/(^|,)\s*(:root|:where\(:root\)|html)\s*(,|$)/.test(rule.selectorText || '')) return;
      [...rule.style].filter((name) => name.startsWith('--') && !name.startsWith('--puck')).forEach((name) => { variables[name] = rule.style.getPropertyValue(name).trim(); });
    });
  });
  return {
    background,
    color: style.color,
    font: style.fontFamily,
    fontSize: style.fontSize,
    headingFont: probe('h2').font,
    linkColor: probe('a').color,
    width: `${Math.round(canvas.getBoundingClientRect().width)}px`,
    viewports: Object.fromEntries((settings.viewports || []).map((viewport) => [viewport.label, `${viewport.width}px`])),
    variables,
  };
}

const STEPS = { search_media: 'Looked for images', search_paragraphs: 'Looked for paragraphs', search_content: 'Looked for pages', preview: 'Checked what the page renders to', page_shell: 'Looked at the page of the theme', list_tokens: 'Looked up tokens' };
const CHECK = 'Here is a screenshot of the page as it looks in the editor now. Look at it: if something is wrong or could be clearly better, fix it; otherwise say that it is fine and leave the layout as it is.';

const EXAMPLES = [
  'Make a landing page: a hero section, three cards with benefits and a call to action.',
  'Add a section with frequently asked questions at the end.',
  'Shorten the texts and make the headings friendlier.',
];

export function AiPanel({ disabled: translating }) {
  const language = settings.translation?.language || 'this language';
  const TRANSLATION_EXAMPLES = [
    `Translate the texts of this page to ${language}.`,
    `Translate what is still in the original language to ${language}, keep what is already translated.`,
    'Write meta tags for this page.',
  ];
  const { messages, busy } = useChat();
  const data = usePuck((state) => state.appState.data);
  const dispatch = usePuck((state) => state.dispatch);
  const [text, setText] = useState('');
  const list = useRef(null);
  const latest = useRef(data);
  latest.current = data;
  useEffect(() => { list.current?.scrollTo({ top: list.current.scrollHeight }); }, [messages.length, busy]);

  // `check`: an automatic turn in which the assistant is shown a screenshot of what it made.
  const send = async (request, { check = false } = {}) => {
    const content = request.trim();
    if (!content || (chat.busy && !check)) return;
    const history = [...chat.messages, { role: 'user', content, auto: check }];
    update({ messages: history, busy: true });
    if (!check) setText('');
    const image = check ? await screenshot() : null;
    const { ok, body } = await postJson(settings.ai.endpoint, {
      // What was said, without notes of the panel itself.
      messages: history.filter((message) => !message.error).map(({ role, content: said }) => ({ role, content: said })),
      data: toDrupalLayout(latest.current),
      langcode: settings.langcode,
      look: lookOfTheTheme(),
      ...(image ? { image } : {}),
    });
    if (!ok || !body) {
      update({ messages: [...history, { role: 'assistant', error: true, content: body?.errors?.join(' ') || 'The assistant could not be reached.' }], busy: false });
      return;
    }
    // Meta tags it proposed are unsaved values of the node form: show them there.
    if (body.metatags) reloadContent('page');
    const applied = Boolean(body.data);
    if (applied) {
      const next = fromDrupal(body.data);
      dispatch({ type: 'setData', data: { ...latest.current, content: next.content }, recordHistory: true });
    }
    const notes = [
      ...(body.steps || []).map((step) => { const [name, detail] = step.split(': '); return `${STEPS[name] || name}${detail ? `: ${detail}` : ''}`; }),
      ...(applied ? [body.translation ? 'Texts applied to this translation' : 'Applied to the page'] : []),
      ...(body.metatags ? [`Meta tags proposed (${Object.keys(body.metatags).join(', ').replaceAll('_', ' ')}): see the page settings`] : []),
      ...(body.dropped > 0 ? [`${body.dropped} proposed ${body.dropped === 1 ? 'block was' : 'blocks were'} left out: not a component of this site.`] : []),
    ];
    // The assistant wants to see the result: once per request, when the page changed and looking is allowed.
    const look = applied && body.check && chat.look && !check;
    update({ messages: [...history, { role: 'assistant', content: body.message || (applied ? 'The page was changed.' : 'No answer.'), notes }], busy: look });
    if (look) {
      // The canvas asks Drupal for the new components and loads their images first.
      await new Promise((resolve) => { setTimeout(resolve, 3000); });
      await send(CHECK, { check: true });
    }
  };

  if (!settings.ai.ready) {
    return (
      <div className="rsb-ai">
        <p className="rsb-muted rsb-ai__note">The assistant needs a chat provider. Install a provider module of the AI module, add its key, and choose a default model for “Chat” in the AI settings (Configuration → AI → AI settings).</p>
      </div>
    );
  }
  return (
    <div className="rsb-ai">
      <div className="rsb-ai__messages" ref={list} aria-live="polite">
        {messages.length === 0 && (
          <div className="rsb-ai__intro">
            <p className="rsb-muted">Describe the page you want, or a change to it. The assistant uses the components of this site and puts its proposal on the canvas. Nothing is saved until you publish; Undo takes a proposal back.</p>
            {(translating ? TRANSLATION_EXAMPLES : EXAMPLES).map((example) => <button type="button" key={example} className="rsb-ai__example" onClick={() => send(example)}>{example}</button>)}
          </div>
        )}
        {messages.map((message, index) => (
          <div key={index} className={`rsb-ai__message rsb-ai__message--${message.error ? 'error' : message.auto ? 'auto' : message.role}`}>
            {message.auto ? 'A screenshot of the result was sent for a check.' : message.content}
            {(message.notes || []).map((note) => <span key={note} className="rsb-ai__applied">{note}</span>)}
          </div>
        ))}
        {busy && <div className="rsb-ai__message rsb-ai__message--assistant rsb-ai__message--busy">Working on it…</div>}
      </div>
      {translating && <p className="rsb-muted rsb-ai__note">This translation shares the structure of the original: the assistant changes texts and links only.</p>}
      {(
          <form className="rsb-ai__form" onSubmit={(event) => { event.preventDefault(); send(text); }}>
            <textarea className="rsb-input" rows={3} placeholder="Ask for a page or a change" aria-label="Message to the assistant" value={text} disabled={busy}
                      onChange={(event) => setText(event.target.value)}
                      onKeyDown={(event) => { if (event.key === 'Enter' && !event.shiftKey) { event.preventDefault(); send(text); } }} />
            <label className="rsb-ai__look" title="When the assistant asks for it, a picture of the canvas is sent to the model so it can look at its work and correct it. Needs a model that takes images; one more request each time.">
              <input type="checkbox" checked={chat.look} onChange={(event) => update({ look: event.target.checked })} /> Let the assistant look at the result
            </label>
            <div className="rsb-ai__actions">
              {messages.length > 0 && <button type="button" className="rsb-link" disabled={busy} onClick={() => update({ messages: [] })}>New conversation</button>}
              <button type="submit" className="rsb-button rsb-button--small rsb-button--primary" disabled={busy || !text.trim()}>Send</button>
            </div>
          </form>
        )}
    </div>
  );
}
