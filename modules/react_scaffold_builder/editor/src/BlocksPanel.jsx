// The "Blocks" panel: everything that can be placed, by group, with a search that forgives typos.
import { useMemo, useState } from 'react';
import { Drawer } from '@puckeditor/core';
import { settings } from './api';
import { search } from './fuzzy';
import { BlockIcon } from './icons';
import { toPuckType } from './types';
import { useLibraryVersion } from './library';

function Item({ component, disabled, group }) {
  return (
    <Drawer.Item name={toPuckType(component.id)} label={component.label} isDragDisabled={disabled}>
      {() => (
        <div className={`rsb-block${disabled ? ' is-disabled' : ''}`} title={component.description || component.label}>
          <BlockIcon icon={component.icon} />
          <span className="rsb-block__label">{component.label}{group && <small>{group}</small>}</span>
          <svg className="rsb-block__grip" width="8" height="14" viewBox="0 0 8 14" aria-hidden="true">
            {[1, 7].flatMap((x) => [1.5, 7, 12.5].map((y) => <circle key={`${x}-${y}`} cx={x} cy={y} r="1.2" fill="currentColor" />))}
          </svg>
        </div>
      )}
    </Drawer.Item>
  );
}

/** @param {{ disabled: boolean }} props `disabled`: nothing can be added (a translation that shares its structure). */
export function BlocksPanel({ disabled }) {
  const [query, setQuery] = useState('');
  // Blocks saved to, or deleted from, the library since the editor was opened.
  const library = useLibraryVersion();
  const offered = useMemo(() => settings.components.filter((component) => !component.deleted && !component.hidden), [library]);
  const groups = useMemo(() => {
    const byGroup = new Map();
    offered.forEach((component) => {
      if (!byGroup.has(component.group)) byGroup.set(component.group, { title: component.group, collapsed: Boolean(component.collapsed), components: [] });
      byGroup.get(component.group).components.push(component);
    });
    return [...byGroup.values()];
  }, [offered]);
  // Name first, then where it is and what it is: a match in the name ranks higher.
  const found = useMemo(() => search(offered, query, (component) => [component.label, component.group, component.description]), [query, offered]);
  const searching = query.trim() !== '';

  return (
    <div className="rsb-blocks">
      <div className="rsb-blocks__search">
        <input className="rsb-input" type="search" placeholder="Search blocks" aria-label="Search blocks" value={query}
               onChange={(event) => setQuery(event.target.value)} />
      </div>
      {disabled && <p className="rsb-muted rsb-blocks__note">This translation shares the structure of the original: blocks are added there.</p>}
      <Drawer>
        {searching && found.length === 0 && <p className="rsb-muted rsb-blocks__note">Nothing matches “{query.trim()}”.</p>}
        {searching && found.map((component) => <Item key={component.id} component={component} disabled={disabled} group={component.group} />)}
        {!searching && groups.map((group) => (
          <details key={group.title} className="rsb-blocks__group" open={!group.collapsed}>
            <summary>{group.title}<span>{group.components.length}</span></summary>
            {group.components.map((component) => <Item key={component.id} component={component} disabled={disabled} />)}
          </details>
        ))}
      </Drawer>
    </div>
  );
}
