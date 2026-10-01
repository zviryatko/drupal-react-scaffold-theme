import '../apiClient';

describe('apiClient()', () => {
  beforeEach(() => {
    window.drupalSettings = { csrfToken: 'token-123' };
    global.fetch = jest.fn(() => Promise.resolve({ json: () => Promise.resolve({ ok: true }) }));
  });

  it('sends the CSRF token and JSON headers and returns parsed JSON', async () => {
    await expect(apiClient('/api/x')).resolves.toEqual({ ok: true });
    const [, params] = fetch.mock.calls[0];
    expect(params.method).toBe('GET');
    expect(params.headers['X-CSRF-Token']).toBe('token-123');
    expect(params.headers.Accept).toBe('application/vnd.api+json');
  });

  it('appends the query and drops null values', async () => {
    await apiClient('/api/x', { a: '1', b: null, c: undefined });
    expect(fetch.mock.calls[0][0]).toBe('/api/x?a=1');
  });

  it('keeps an existing query string', async () => {
    await apiClient('/api/x?format=json', { a: '1' });
    expect(fetch.mock.calls[0][0]).toBe('/api/x?format=json&a=1');
  });

  it('passes request params through', async () => {
    await apiClient('/api/x', null, { method: 'POST', body: '{}' });
    expect(fetch.mock.calls[0][1]).toMatchObject({ method: 'POST', body: '{}' });
  });
});
