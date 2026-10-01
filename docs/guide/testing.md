# Testing

React parts are tested with Jest and Testing Library, run `npm test`.

Setup (already in the repo):

| File | Purpose |
|---|---|
| `jest.config.cjs` | jsdom environment, `moduleNameMapper` for `Components/*`, `setupTests.js`. It is `.cjs` because `package.json` has `"type": "module"` |
| `.babelrc` | `@babel/preset-env` + `@babel/preset-react` for test files |
| `setupTests.js` | jest-dom matchers, global `React`, stubs for `Drupal.t` and `drupalSettings`, `matchMedia` |

## Example

Components rely on globals that Drupal provides (`Drupal`, `apiClient`), so stub them in `beforeEach`:

```jsx
import { RecipeExplorer } from '../RecipeExplorer';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';

beforeEach(() => {
  window.Drupal = {
    t: (s, args = {}) => Object.entries(args).reduce((r, [k, v]) => r.replace(k, v), s),
    formatPlural: (n, one, many) => (n === 1 ? one : many.replace('@count', n)),
  };
  window.apiClient = jest.fn(() => Promise.resolve(response([recipe])));
  global.apiClient = window.apiClient;
});

it('requests the endpoint again when a filter changes', async () => {
  render(<RecipeExplorer endpoint="/api/recipes" heading="Recipes" />);
  await screen.findByText('Soup');
  fireEvent.change(screen.getByLabelText('Difficulty'), { target: { value: 'hard' } });
  await waitFor(() => expect(apiClient).toHaveBeenLastCalledWith('/api/recipes', { difficulty: 'hard' }));
});
```

Test the React component (`RecipeExplorer.jsx`), not `index.jsx`: the behavior wrapper only wires Drupal to React.

## What is not covered

- Drupal-side rendering (SDC props validation, library attachment) needs a running site or core's `ComponentKernelTestBase`.
- The demo recorder (`npm run demo`) doubles as a smoke test: it fails with exit code 1 if the browser logs a console error or page error.
