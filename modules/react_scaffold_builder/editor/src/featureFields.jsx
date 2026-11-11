// Fields of the features every component can have (ComponentFeatures in Drupal): spacing, position in a corner of
// the parent, background. All choices come from the theme: the spacing scale, the palette, the image styles.
import { useEffect, useRef, useState } from 'react';
import { createUsePuck } from '@puckeditor/core';
import { getJson, postJson, settings } from './api';
import { ImagePicker, imageUrl } from './fields';
import { dropSavedBlock, fromSavedItem, linkedItem, putSavedBlock, savedBlock, toSavedItem } from './library';
import { toDrupalType } from './types';

/**
 * A feature as a section that folds: closed while nothing is set in it, open when something is, so a component
 * shows at a glance what was changed. `heading` is the divider above the first feature, where the component's own
 * fields end. `summary` says in a few words what is set.
 */
function Feature({ field, changed, summary, children }) {
  // Starts open when it has a value; after that the user decides. (A widget is made anew for every component.)
  const [open, setOpen] = useState(Boolean(changed));
  return (
    <div className="rsb-form-item">
      {field.heading && <div className="rsb-form-heading">{field.heading}</div>}
      <details className={`rsb-feature${changed ? ' is-changed' : ''}`} open={open} onToggle={(event) => setOpen(event.currentTarget.open)}>
        <summary className="rsb-feature__title">
          <span>{field.label}</span>
          {changed && <span className="rsb-feature__summary">{summary}</span>}
        </summary>
        <div className="rsb-feature__body">
          {children}
          {field.description && <div className="rsb-description">{field.description}</div>}
        </div>
      </details>
    </div>
  );
}

const sizeText = (value) => (value?.size === 'custom' ? value.custom || 'custom' : value?.size === 'auto' ? 'auto' : (value?.size || '').toUpperCase());

/** A step of the theme's spacing scale, or a custom length such as "12px" or "5%". Value: `{ size, custom }`. */
export function LengthPicker({ id, value, onChange, sizes, readOnly, label, auto = false }) {
  const size = value?.size || '';
  const valid = !value?.custom || /^-?\d+(\.\d+)?(px|rem|em|%|vw|vh|ch)$/.test(value.custom.trim());
  return (
    <div className="rsb-length">
      <select id={id} className="rsb-select" aria-label={label} value={size} disabled={readOnly}
              onChange={(event) => onChange({ ...value, size: event.target.value })}>
        <option value="">None</option>
        {sizes.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
        {/* Margins only: takes the free space, which centres a component that is narrower than its place. */}
        {auto && <option value="auto">Auto</option>}
        <option value="custom">Custom…</option>
      </select>
      {size === 'custom' && (
        <input className="rsb-input" type="text" placeholder="12px, 5%, 1.5rem" aria-label={`${label}: custom value`}
               aria-invalid={!valid} value={value.custom || ''} readOnly={readOnly}
               onChange={(event) => onChange({ ...value, custom: event.target.value })} />
      )}
    </div>
  );
}

const SIDES = [['all', 'All sides'], ['horizontal', 'Horizontal'], ['vertical', 'Vertical']];

/** Margin and padding. Value: `{ margin: { size, custom, sides }, padding: { ... } }`. */
export function SpacingWidget({ id, value, onChange, readOnly, field }) {
  const current = value || {};
  const row = (property, label) => {
    const part = current[property] || {};
    const set = (next) => onChange({ ...current, [property]: next });
    return (
      <div className="rsb-feature__field">
        <label className="rsb-label" htmlFor={`${id}-${property}`}>{label}</label>
        <div className="rsb-pair">
          <LengthPicker id={`${id}-${property}`} label={label} value={part} sizes={field.sizes} readOnly={readOnly} onChange={set} auto={property === 'margin'} />
          <select className="rsb-select" aria-label={`${label}: sides`} value={part.sides || 'all'} disabled={readOnly || !part.size}
                  onChange={(event) => set({ ...part, sides: event.target.value })}>
            {SIDES.map(([side, text]) => <option key={side} value={side}>{text}</option>)}
          </select>
        </div>
      </div>
    );
  };
  const set = ['margin', 'padding'].filter((property) => current[property]?.size);
  return (
    <Feature field={field} changed={set.length > 0} summary={set.map((property) => `${property} ${sizeText(current[property])}`).join(', ')}>
      {row('margin', 'Margin')}
      {row('padding', 'Padding')}
    </Feature>
  );
}

// Three bars per icon, the way a text editor draws alignment.
const bars = (xs) => (
  <svg width="18" height="14" viewBox="0 0 18 14" aria-hidden="true">
    {xs.map(([x, width], index) => <rect key={index} x={x} y={1 + index * 5} width={width} height="2" rx="1" fill="currentColor" />)}
  </svg>
);
const ALIGNMENTS = [
  ['start', 'Start: left, or right in right-to-left languages', bars([[0, 18], [0, 11], [0, 15]])],
  ['center', 'Centre', bars([[0, 18], [3.5, 11], [1.5, 15]])],
  ['end', 'End: right, or left in right-to-left languages', bars([[0, 18], [7, 11], [3, 15]])],
];

/** Alignment of the content of a component. Value: "start", "center", "end" or nothing (as the theme has it). */
export function AlignWidget({ value, onChange, readOnly, field }) {
  return (
    <Feature field={field} changed={Boolean(value)} summary={{ start: 'start', center: 'centre', end: 'end' }[value]}>
      <div className="rsb-segments" role="group" aria-label={field.label}>
        {ALIGNMENTS.map(([align, text, drawing]) => (
          <button type="button" key={align} className={`rsb-segments__item${value === align ? ' is-selected' : ''}`} aria-pressed={value === align}
                  title={text} disabled={readOnly} onClick={() => onChange(value === align ? '' : align)}>
            {drawing}
          </button>
        ))}
        <span className="rsb-description">{{ start: 'Start', center: 'Centre', end: 'End' }[value] || 'As the theme has it'}</span>
      </div>
    </Feature>
  );
}

const CORNERS = [['top-left', 'Top left'], ['top-right', 'Top right'], ['bottom-left', 'Bottom left'], ['bottom-right', 'Bottom right']];
const PLACES = ['top', 'middle', 'bottom'].flatMap((vertical) => ['left', 'center', 'right'].map((horizontal) => [`${vertical}-${horizontal}`, `${vertical} ${horizontal}`]));
const MODES = [['', 'In the flow'], ['relative', 'Placed'], ['absolute', 'Pinned']];

/**
 * Where a component sits. Value: `{ mode, place, corner, offset: { size, custom }, overlap }`.
 *
 * - "relative" (Placed): one of nine places in the free space of its spot in the flow, as CSS does it with automatic
 *   margins: top / middle / bottom and left / centre / right (left and right swap in right-to-left languages).
 * - "absolute" (Pinned): on a corner of the parent component, over its content.
 */
export function PositionWidget({ id, value, onChange, readOnly, field }) {
  const current = value || {};
  // A value saved before there were modes has only a corner.
  const mode = current.mode ?? (current.corner ? 'absolute' : '');
  const placed = mode === 'relative' && current.place;
  const pinned = mode === 'absolute' && current.corner;
  return (
    <Feature field={field} changed={Boolean(placed || pinned)} summary={placed ? `placed ${current.place.replace('-', ' ')}` : pinned ? `pinned ${current.corner.replace('-', ' ')}` : ''}>
      <div className="rsb-segments rsb-segments--text" role="group" aria-label="Kind of position">
        {MODES.map(([name, text]) => (
          <button type="button" key={name || 'flow'} className={`rsb-segments__item${mode === name ? ' is-selected' : ''}`} aria-pressed={mode === name}
                  disabled={readOnly} onClick={() => onChange({ ...current, mode: name })}>
            {text}
          </button>
        ))}
      </div>
      {mode === 'relative' && (
        <div className="rsb-position">
          <div className="rsb-places" role="group" aria-label="Place in the free space">
            {PLACES.map(([place, text]) => (
              <button type="button" key={place} className={`rsb-places__place${current.place === place ? ' is-selected' : ''}`} aria-pressed={current.place === place}
                      title={text} disabled={readOnly} onClick={() => onChange({ ...current, place: current.place === place ? '' : place })} />
            ))}
          </div>
          <span className="rsb-description">
            {current.place ? current.place.replace('-', ', ') : 'Choose a place'}. Vertical places need room in the row or cell the component is in.
          </span>
        </div>
      )}
      {mode === 'absolute' && (
        <div className="rsb-position">
          <div className="rsb-corners" role="group" aria-label="Corner of the parent component">
            {CORNERS.map(([corner, text]) => (
              <button type="button" key={corner} className={`rsb-corners__corner rsb-corners__corner--${corner}${current.corner === corner ? ' is-selected' : ''}`}
                      aria-pressed={current.corner === corner} title={text} disabled={readOnly}
                      onClick={() => onChange({ ...current, corner: current.corner === corner ? '' : corner })} />
            ))}
            <span className="rsb-corners__parent">{current.corner ? '' : 'Corner'}</span>
          </div>
          {current.corner && (
            <div className="rsb-position__options">
              <label className="rsb-label" htmlFor={`${id}-offset`}>Distance from the corner</label>
              <LengthPicker id={`${id}-offset`} label="Distance from the corner" value={current.offset} sizes={field.sizes} readOnly={readOnly}
                            onChange={(offset) => onChange({ ...current, offset })} />
              <label className="rsb-check">
                <input className="rsb-checkbox" type="checkbox" checked={Boolean(current.overlap)} disabled={readOnly}
                       onChange={(event) => onChange({ ...current, overlap: event.target.checked })} />
                Centre it on the corner (half outside)
              </label>
            </div>
          )}
        </div>
      )}
    </Feature>
  );
}

const FILLS = [['cover', 'Cover (fill, cropped)'], ['contain', 'Fit (whole image)'], ['stretch', 'Stretch'], ['auto', 'Original size']];

/**
 * Which part of the image shows: the image with a frame in the shape of the component.
 *
 * With "Cover" the frame is the part that is visible and can be dragged along the image; with the other fills there
 * is nothing cropped that way, so a point marks where the image is anchored. Both give `x` and `y` in percent, which
 * is what CSS background-position takes.
 */
function FocusPicker({ url, fill, x, y, componentId, onChange, readOnly }) {
  const box = useRef(null);
  const [image, setImage] = useState(null); // natural width / height
  const [shape, setShape] = useState(16 / 9); // width / height of the component
  useEffect(() => {
    const element = document.querySelector(`[data-puck-component="${componentId}"]`);
    const rect = element?.getBoundingClientRect();
    if (rect?.width && rect.height) setShape(rect.width / rect.height);
  }, [componentId, url]);

  const ratio = image ? image.width / image.height : 1;
  // The visible part of a covered image, as a share of the image: full along one axis, a slice along the other.
  const frame = fill === 'cover' ? { width: Math.min(1, shape / ratio), height: Math.min(1, ratio / shape) } : null;

  const move = (event) => {
    const rect = box.current.getBoundingClientRect();
    const px = (event.clientX - rect.left) / rect.width;
    const py = (event.clientY - rect.top) / rect.height;
    // The frame is centred on the pointer; its travel is what is left of the image.
    const along = (pointer, size) => (size >= 1 ? 50 : Math.max(0, Math.min(100, ((pointer - size / 2) / (1 - size)) * 100)));
    onChange(frame
      ? { x: Math.round(along(px, frame.width)), y: Math.round(along(py, frame.height)) }
      : { x: Math.round(Math.max(0, Math.min(1, px)) * 100), y: Math.round(Math.max(0, Math.min(1, py)) * 100) });
  };
  const drag = (event) => {
    if (readOnly) return;
    event.preventDefault();
    move(event);
    const stop = () => { window.removeEventListener('pointermove', move); window.removeEventListener('pointerup', stop); };
    window.addEventListener('pointermove', move);
    window.addEventListener('pointerup', stop);
  };

  return (
    <div className="rsb-focus">
      <div ref={box} className="rsb-focus__image" style={{ aspectRatio: String(ratio) }} onPointerDown={drag}
           role="img" aria-label={`Visible part of the image: ${x}% from the left, ${y}% from the top`}>
        <img src={url} alt="" draggable={false} onLoad={(event) => setImage({ width: event.target.naturalWidth, height: event.target.naturalHeight })} />
        {frame
          ? <span className="rsb-focus__frame" style={{ width: `${frame.width * 100}%`, height: `${frame.height * 100}%`, left: `${(1 - frame.width) * x}%`, top: `${(1 - frame.height) * y}%` }} />
          : <span className="rsb-focus__point" style={{ left: `${x}%`, top: `${y}%` }} />}
      </div>
      <span className="rsb-description">
        {frame ? 'The frame has the shape of the component: drag it to the part of the image to show.' : 'Click where the image is anchored.'}
        {' '}{x}% / {y}%
      </span>
    </div>
  );
}

/** Background colour (from the theme's palette) and image. Value: `{ color, image, size, x, y }`. */
export function BackgroundWidget({ id, value, onChange, readOnly, field }) {
  const current = value || {};
  const set = (patch) => onChange({ ...current, ...patch });
  const url = imageUrl(current.image);
  const colour = field.colors.find((entry) => entry.value === current.color);
  return (
    <Feature field={field} changed={Boolean(colour || url)} summary={[colour?.label, url ? 'image' : ''].filter(Boolean).join(', ')}>
      <div className="rsb-feature__field">
        <span className="rsb-label">Colour</span>
        {field.colors.length === 0
          ? <span className="rsb-description">The theme declares no colours.</span>
          : (
            <div className="rsb-swatches" role="group" aria-label="Background colour">
              <button type="button" className={`rsb-swatch rsb-swatch--none${current.color ? '' : ' is-selected'}`} title="No colour" aria-pressed={!current.color}
                      disabled={readOnly} onClick={() => set({ color: '' })} />
              {field.colors.map((color) => (
                <button type="button" key={color.value} className={`rsb-swatch${current.color === color.value ? ' is-selected' : ''}`} title={color.label}
                        aria-pressed={current.color === color.value} style={{ backgroundColor: color.color }} disabled={readOnly} onClick={() => set({ color: color.value })} />
              ))}
            </div>
          )}
      </div>
      <div className="rsb-feature__field">
        <span className="rsb-label">Image</span>
        <ImagePicker id={id} value={current.image} onChange={(image) => set({ image })} readOnly={readOnly} styles={field.imageStyles} />
      </div>
      {url && (
        <>
          <div className="rsb-feature__field">
            <label className="rsb-label" htmlFor={`${id}-fill`}>Fill</label>
            <select id={`${id}-fill`} className="rsb-select" value={current.size || 'cover'} disabled={readOnly} onChange={(event) => set({ size: event.target.value })}>
              {FILLS.map(([fill, text]) => <option key={fill} value={fill}>{text}</option>)}
            </select>
          </div>
          <div className="rsb-feature__field">
            <span className="rsb-label">Visible part</span>
            <FocusPicker url={url} fill={current.size || 'cover'} x={current.x ?? 50} y={current.y ?? 50} readOnly={readOnly}
                         componentId={id.split('_custom_')[0]} onChange={set} />
          </div>
        </>
      )}
    </Feature>
  );
}

/** A colour of the theme's palette, for a prop of a component. Value: the name of the colour, or nothing. */
export function ColorWidget({ id, value, onChange, readOnly, field }) {
  return (
    <div className="rsb-form-item">
      <label className="rsb-label" htmlFor={id}>{field.label}</label>
      <div className="rsb-swatches" role="group" aria-label={field.label}>
        <button id={id} type="button" className={`rsb-swatch rsb-swatch--none${value ? '' : ' is-selected'}`} title="No colour" aria-pressed={!value} disabled={readOnly} onClick={() => onChange(null)} />
        {(field.colors || []).map((color) => (
          <button type="button" key={color.value} className={`rsb-swatch${value === color.value ? ' is-selected' : ''}`} title={color.label} aria-pressed={value === color.value}
                  disabled={readOnly} style={{ background: color.color }} onClick={() => onChange(color.value)} />
        ))}
      </div>
      {field.description && <div className="rsb-description">{field.description}</div>}
    </div>
  );
}

const usePuck = createUsePuck();

/**
 * The library of saved blocks, for the selected component. Three situations:
 * - an ordinary component: it can be saved to the library, and then follows the saved block;
 * - a saved block (linked): shown as it is saved, on every page. To change it here, a copy is made;
 * - a copy of a saved block: the page's own. It can become the new saved block, or be dropped for the saved one.
 */
export function LibraryWidget({ id, value, readOnly, field }) {
  const selected = usePuck((state) => state.selectedItem);
  const selector = usePuck((state) => state.appState.ui.itemSelector);
  const dispatch = usePuck((state) => state.dispatch);
  const [name, setName] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);
  const [confirming, setConfirming] = useState(false);
  if (!selected || !selector) return null;

  const linkedUuid = toDrupalType(selected.type).startsWith('drupal_saved:') ? toDrupalType(selected.type).slice('drupal_saved:'.length) : null;
  const origin = value?.uuid ? value : null;
  const saved = savedBlock(linkedUuid || origin?.uuid);
  const replace = (data) => dispatch({ type: 'replace', destinationIndex: selector.index, destinationZone: selector.zone || 'root:default-zone', data, recordHistory: true });
  const run = async (work) => {
    setBusy(true);
    setError(null);
    try { await work(); } catch (problem) { setError(problem.message || 'That did not work.'); }
    setBusy(false);
  };
  const send = async (body) => {
    const { ok, body: answer } = await postJson(settings.endpoints.library, body);
    if (!ok) throw new Error(answer?.errors?.join(' ') || 'The library could not be changed.');
    return answer;
  };
  const withoutOrigin = () => ({ ...selected, props: Object.fromEntries(Object.entries(selected.props).filter(([key]) => key !== '_library')) });

  const save = () => run(async () => {
    const answer = await send({ label: name.trim(), item: toSavedItem(withoutOrigin()) });
    putSavedBlock(answer.component);
    replace(linkedItem(answer.uuid, selected.props.id));
  });
  const update = () => run(async () => {
    const answer = await send({ uuid: origin.uuid, item: toSavedItem(withoutOrigin()) });
    putSavedBlock(answer.component);
    replace(linkedItem(origin.uuid, selected.props.id));
  });
  const copy = () => run(async () => {
    const answer = await getJson(settings.endpoints.library, { uuid: linkedUuid });
    const item = fromSavedItem(answer.item, selected.props.id);
    item.props._library = { uuid: linkedUuid, label: answer.label, overridden: true };
    replace(item);
  });
  const remove = () => run(async () => {
    await send({ uuid: linkedUuid, delete: true });
    dropSavedBlock(linkedUuid);
    setConfirming(false);
  });

  let summary = '';
  let body;
  if (linkedUuid) {
    summary = saved ? 'saved block' : 'deleted';
    body = saved ? (
      <>
        <p className="rsb-muted">Shows the saved block <strong>{saved.label}</strong> as it is saved now. A change to the saved block changes every page that uses it.</p>
        {!readOnly && (
          <div className="rsb-actions">
            <button id={id} type="button" className="rsb-button rsb-button--small" disabled={busy} onClick={copy} title="Replaces this with a copy that you can change on this page">Edit a copy here</button>
            {confirming
              ? <button type="button" className="rsb-button rsb-button--small rsb-button--danger" disabled={busy} onClick={remove}>Yes, delete it everywhere</button>
              : <button type="button" className="rsb-button rsb-button--small rsb-button--danger" disabled={busy} onClick={() => setConfirming(true)} title="Pages that use it show nothing in its place">Delete from library</button>}
          </div>
        )}
      </>
    ) : <p className="rsb-muted">This saved block was deleted from the library. Nothing is shown in its place; remove it.</p>;
  }
  else if (origin) {
    summary = 'changed copy';
    body = (
      <>
        <p className="rsb-muted">A copy of the saved block <strong>{saved?.label || origin.label}</strong>, changed on this page. It no longer follows the saved block{saved ? '' : ', which was deleted'}.</p>
        {!readOnly && saved && (
          <div className="rsb-actions">
            <button id={id} type="button" className="rsb-button rsb-button--small" disabled={busy} onClick={update} title="The saved block becomes what is here, on every page that uses it">Update the saved block</button>
            <button type="button" className="rsb-button rsb-button--small" disabled={busy} onClick={() => replace(linkedItem(origin.uuid, selected.props.id))} title="Drops what was changed here">Use the saved block again</button>
          </div>
        )}
      </>
    );
  }
  if (!body || (origin && !saved)) {
    body = (
      <>
        {body}
        {!readOnly && (
          <>
            <label className="rsb-label" htmlFor={id}>Save to library as</label>
            <div className="rsb-pair">
              <input id={id} className="rsb-input" type="text" placeholder="Name of the saved block" value={name} onChange={(event) => setName(event.target.value)} />
              <button type="button" className="rsb-button rsb-button--small" disabled={busy || !name.trim()} onClick={save}>Save</button>
            </div>
            <div className="rsb-description">Saves this component with everything in it, to use on other pages. Here it then follows the saved block.</div>
          </>
        )}
      </>
    );
  }
  return (
    <Feature field={field} changed={Boolean(linkedUuid || origin)} summary={summary}>
      {body}
      {error && <div className="rsb-form-error" role="alert">{error}</div>}
    </Feature>
  );
}

const WEIGHTS = [['', 'As the theme has it'], ['normal', 'Normal'], ['medium', 'Medium'], ['bold', 'Bold']];
const CASES = [['', 'As typed'], ['uppercase', 'UPPERCASE'], ['lowercase', 'lowercase'], ['capitalize', 'Each Word Capitalised']];
const LEADINGS = [['', 'As the theme has it'], ['tight', 'Tight'], ['normal', 'Normal'], ['loose', 'Loose']];

/**
 * The look of text: size (a step of the theme's type scale, or a custom length), weight, italic, case, line height
 * and a colour of the palette. Value: `{ size, custom, weight, italic, transform, leading, color }`. Used for the text
 * of a component (the "Text" feature) and for the label of a Field block.
 */
export function TextStyleWidget({ id, value, onChange, readOnly, field }) {
  const current = value || {};
  // Nothing set is no value at all, so the section shows as unchanged.
  const set = (patch) => {
    const next = Object.fromEntries(Object.entries({ ...current, ...patch }).filter(([, entry]) => entry !== '' && entry !== false && entry !== null && entry !== undefined));
    if (next.size !== 'custom') delete next.custom;
    onChange(Object.keys(next).length ? next : null);
  };
  const select = (key, label, options) => (
    <div className="rsb-feature__field">
      <label className="rsb-label" htmlFor={`${id}-${key}`}>{label}</label>
      <select id={`${id}-${key}`} className="rsb-select" value={current[key] || ''} disabled={readOnly} onChange={(event) => set({ [key]: event.target.value })}>
        {options.map(([option, text]) => <option key={option} value={option}>{text}</option>)}
      </select>
    </div>
  );
  const summary = [
    current.size === 'custom' ? current.custom : (current.size || '').toUpperCase(),
    current.weight, current.italic && 'italic', current.transform, current.color,
  ].filter(Boolean).join(', ');
  return (
    <Feature field={field} changed={Object.keys(current).length > 0} summary={summary}>
      <div className="rsb-feature__field">
        <label className="rsb-label" htmlFor={`${id}-size`}>Size</label>
        <LengthPicker id={`${id}-size`} label="Size" value={current} sizes={field.sizes || []} readOnly={readOnly} onChange={(next) => set({ size: next.size, custom: next.custom })} />
      </div>
      <div className="rsb-pair">
        {select('weight', 'Weight', WEIGHTS)}
        {select('leading', 'Line height', LEADINGS)}
      </div>
      {select('transform', 'Case', CASES)}
      <label className="rsb-checkbox">
        <input type="checkbox" checked={Boolean(current.italic)} disabled={readOnly} onChange={(event) => set({ italic: event.target.checked })} /> Italic
      </label>
      {(field.colors || []).length > 0 && (
        <div className="rsb-feature__field">
          <span className="rsb-label">Colour</span>
          <div className="rsb-swatches" role="group" aria-label="Text colour">
            <button type="button" className={`rsb-swatch rsb-swatch--none${current.color ? '' : ' is-selected'}`} title="As the theme has it" aria-pressed={!current.color} disabled={readOnly} onClick={() => set({ color: '' })} />
            {field.colors.map((color) => (
              <button type="button" key={color.value} className={`rsb-swatch${current.color === color.value ? ' is-selected' : ''}`} title={color.label} aria-pressed={current.color === color.value}
                      style={{ backgroundColor: color.color }} disabled={readOnly} onClick={() => set({ color: color.value })} />
            ))}
          </div>
        </div>
      )}
    </Feature>
  );
}
