// Search that forgives typos, for the list of blocks.

const normalize = (text) => text.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]+/g, ' ').trim();

/** Edits (insert, delete, replace, swap of neighbours) to turn `a` into `b`, giving up beyond `limit`. */
export function distance(a, b, limit = 2) {
  if (Math.abs(a.length - b.length) > limit) return limit + 1;
  let previous = null;
  let current = Array.from({ length: b.length + 1 }, (_, index) => index);
  for (let i = 1; i <= a.length; i++) {
    const next = [i];
    let best = i;
    for (let j = 1; j <= b.length; j++) {
      const cost = a[i - 1] === b[j - 1] ? 0 : 1;
      let value = Math.min(current[j] + 1, next[j - 1] + 1, current[j - 1] + cost);
      if (previous && i > 1 && j > 1 && a[i - 1] === b[j - 2] && a[i - 2] === b[j - 1]) value = Math.min(value, previous[j - 2] + 1);
      next[j] = value;
      best = Math.min(best, value);
    }
    if (best > limit) return limit + 1;
    previous = current;
    current = next;
  }
  return current[b.length];
}

/**
 * How well a query matches a text: 0 is no match, higher is better.
 *
 * Every word of the query must be found: as the start of a word of the text (best), inside one, or with a typo or
 * two (one for short words, two from six letters on). A word of the text is compared at the length of what was
 * typed, so "colu" already finds "Columns" and "colmuns" still does.
 */
export function score(query, text) {
  const words = normalize(text).split(' ').filter(Boolean);
  const wanted = normalize(query).split(' ').filter(Boolean);
  if (!wanted.length) return 1;
  let total = 0;
  for (const part of wanted) {
    let best = 0;
    for (const word of words) {
      if (word.startsWith(part)) best = Math.max(best, word === part ? 10 : 8);
      else if (word.includes(part)) best = Math.max(best, 6);
      else if (part.length >= 3) {
        const allowed = part.length >= 6 ? 2 : 1;
        // The typed part against the word, and against its beginning: the word may not be finished yet.
        const edits = Math.min(distance(part, word, allowed), distance(part, word.slice(0, part.length), allowed), distance(part, word.slice(0, part.length + 1), allowed));
        if (edits <= allowed) best = Math.max(best, 4 - edits);
      }
    }
    if (!best) return 0;
    total += best;
  }
  return total / wanted.length;
}

/** The items that match, best first. `text` gives what is searched for an item: an array, most important first. */
export function search(items, query, text) {
  if (!normalize(query)) return items;
  return items
    .map((item, index) => {
      const fields = text(item).map((field) => field || '');
      // The words of the query can be spread over the fields ("card content"); a match in the first field, the
      // name, counts on top.
      const anywhere = score(query, fields.join(' '));
      const best = anywhere ? anywhere + score(query, fields[0]) : 0;
      return { item, best, index };
    })
    .filter((entry) => entry.best > 0)
    .sort((a, b) => b.best - a.best || a.index - b.index)
    .map((entry) => entry.item);
}
