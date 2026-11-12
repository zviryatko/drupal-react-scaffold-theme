// Token tree from Drupal and the logic of the "[" autocomplete.
import { getJson, settings } from './api';

let request = null;

/** `{ roots: [type], types: { type: { label, description, tokens: [{ name, label, description, type, dynamic }] } } }` */
export function loadTokens() {
  // In the editor, or in a frame of the node form (frameTokens.js).
  const url = settings?.endpoints.tokens || window.drupalSettings.frameBuilderFrame?.tokens;
  request ||= getJson(url).catch((error) => { request = null; throw error; });
  return request;
}

/**
 * The token being typed before the caret: text after the last "[" that has no "]" or whitespace in it.
 * @returns {{ start: number, segments: string[] } | null} `start` is the index of the "[".
 */
export function openToken(text, caret) {
  const match = /\[([^\[\]\s]*)$/.exec(text.slice(0, caret));
  return match ? { start: match.index, segments: match[1].split(':') } : null;
}

/**
 * Suggestions for the part being typed.
 *
 * Every suggestion has the text that replaces the open token and says whether typing continues (`open`): a type or a
 * token that leads to more tokens (`[node:author:`) stays open, a final token is closed (`[node:title]`).
 */
export function suggest(tree, segments) {
  const path = segments.slice(0, -1);
  const typed = segments[segments.length - 1].toLowerCase();
  const matches = (item) => !typed || item.name.toLowerCase().includes(typed) || item.label.toLowerCase().includes(typed);

  if (path.length === 0) {
    return tree.roots
      .map((type) => ({ name: type, label: tree.types[type].label, description: tree.types[type].description }))
      .filter(matches)
      .map((item) => ({ ...item, insert: `[${item.name}:`, open: true }));
  }

  // Walk the chain: [node:author:mail] is type node -> token author (type user) -> token mail.
  let type = tree.types[path[0]];
  for (const name of path.slice(1)) {
    const token = type?.tokens.find((item) => item.name === name);
    type = token?.type ? tree.types[token.type] : null;
  }
  if (!type) return [];

  const prefix = `[${path.join(':')}`;
  const items = type.tokens.filter(matches).map((token) => ({
    name: token.name,
    label: token.label,
    description: token.description,
    insert: token.type ? `${prefix}:${token.name}:` : `${prefix}:${token.name}${token.dynamic ? ':' : ']'}`,
    open: Boolean(token.type) || token.dynamic,
  }));
  // After [node:author: the chained token itself is also a valid token.
  if (path.length > 1 && !typed) {
    items.unshift({ name: path[path.length - 1], label: `Use ${prefix}]`, description: 'The value itself, without going deeper.', insert: `${prefix}]`, open: false });
  }
  return items;
}
