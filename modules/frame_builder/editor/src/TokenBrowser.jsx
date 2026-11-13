// The tokens that can be inserted into a field: the tree of the Token module (name, token, description) in a dialog,
// opened from the T button of a text field.
import { useEffect, useState } from 'react';
import { Modal } from './ui';
import { loadTokens } from './tokens';

function Rows({ tree, typeName, prefix, depth, expanded, toggle, onPick }) {
  const type = tree.types[typeName];
  if (!type) return null;
  return type.tokens.map((token) => {
    const text = `[${prefix}:${token.name}${token.dynamic ? ':?' : ''}]`;
    const key = `${prefix}:${token.name}`;
    const open = expanded.has(key);
    return [
      <tr key={key}>
        <td style={{ paddingLeft: 12 + depth * 20 }}>
          {token.type
            ? <button type="button" className="frame-builder-tree-toggle" aria-expanded={open} onClick={() => toggle(key)}>{open ? '▾' : '▸'}</button>
            : <span className="frame-builder-tree-toggle" />}
          {token.label}
        </td>
        <td>
          <button type="button" className="frame-builder-link" title="Insert this token" onClick={() => onPick(token.dynamic ? text.replace(':?]', ':') : text)}>{text}</button>
        </td>
        <td className="frame-builder-muted">{token.description}</td>
      </tr>,
      open && <Rows key={`${key}/`} tree={tree} typeName={token.type} prefix={key} depth={depth + 1} expanded={expanded} toggle={toggle} onPick={onPick} />,
    ];
  });
}

export function TokenBrowser({ onPick, onClose }) {
  const [tree, setTree] = useState(null);
  const [error, setError] = useState(false);
  const [expanded, setExpanded] = useState(() => new Set());
  const [search, setSearch] = useState('');
  useEffect(() => { loadTokens().then(setTree).catch(() => setError(true)); }, []);

  const toggle = (key) => setExpanded((current) => {
    const next = new Set(current);
    next.has(key) ? next.delete(key) : next.add(key);
    return next;
  });

  // Every word typed must be in the token, its name or its description: "site name" finds [site:name].
  const words = search.trim().toLowerCase().split(/[\s:[\]]+/).filter(Boolean);
  // Searching lists matching tokens of the first level of every type, flat.
  const found = tree && words.length
    ? tree.roots.flatMap((root) => tree.types[root].tokens
      .filter((token) => {
        const text = `${root}:${token.name} ${tree.types[root].label} ${token.label} ${token.description || ''}`.toLowerCase();
        return words.every((word) => text.includes(word));
      })
      .map((token) => ({ root, token })))
    : null;

  return (
    <Modal title="Available tokens" onClose={onClose} wide>
      <p className="frame-builder-muted">Click a token to insert it into the field. Tokens are replaced when the page is rendered.</p>
      <input className="frame-builder-input" type="search" placeholder="Filter tokens" value={search} aria-label="Filter tokens"
             onChange={(event) => setSearch(event.target.value)} />
      {error && <div className="frame-builder-messages frame-builder-messages--error">The token list could not be loaded.</div>}
      {!tree && !error && <p className="frame-builder-muted">Loading…</p>}
      {tree && (
        <table className="frame-builder-table">
          <thead><tr><th>Name</th><th>Token</th><th>Description</th></tr></thead>
          <tbody>
            {found && found.length === 0 && <tr><td colSpan={3} className="frame-builder-muted">No tokens match.</td></tr>}
            {found && found.map(({ root, token }) => {
              const text = `[${root}:${token.name}${token.dynamic ? ':' : ']'}`;
              return (
                <tr key={text}>
                  <td>{tree.types[root].label}: {token.label}</td>
                  <td><button type="button" className="frame-builder-link" onClick={() => onPick(text)}>{text}</button></td>
                  <td className="frame-builder-muted">{token.description}</td>
                </tr>
              );
            })}
            {!found && tree.roots.map((root) => {
              const open = expanded.has(root);
              return [
                <tr key={root} className="frame-builder-table__group">
                  <td>
                    <button type="button" className="frame-builder-tree-toggle" aria-expanded={open} onClick={() => toggle(root)}>{open ? '▾' : '▸'}</button>
                    <button type="button" className="frame-builder-tree-name" onClick={() => toggle(root)}>{tree.types[root].label}</button>
                  </td>
                  <td className="frame-builder-muted">[{root}:…]</td>
                  <td className="frame-builder-muted">{tree.types[root].description}</td>
                </tr>,
                open && <Rows key={`${root}/`} tree={tree} typeName={root} prefix={root} depth={1} expanded={expanded} toggle={toggle} onPick={onPick} />,
              ];
            })}
          </tbody>
        </table>
      )}
    </Modal>
  );
}
