// Settings from Drupal and the two fetch helpers of the editor.
// Undefined in the frames of the node form and in unit tests, which only use the token helpers.
export const settings = window.drupalSettings?.frameBuilder;

export async function getJson(url, query = {}) {
  const response = await fetch(`${url}?${new URLSearchParams(query)}`, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
  if (!response.ok) throw new Error(`Request failed: ${response.status}`);
  return response.json();
}

/** POST with the CSRF header Drupal checks. Resolves with `{ ok, status, body }`, also for 4xx answers. */
export async function postJson(url, body) {
  const response = await fetch(url, {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': settings.csrfToken },
    body: JSON.stringify(body),
  });
  let parsed = null;
  try { parsed = await response.json(); } catch { /* not JSON: an error page */ }
  return { ok: response.ok, status: response.status, body: parsed };
}
