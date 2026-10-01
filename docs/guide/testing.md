# Testing

React parts are tested with Jest and Testing Library, run `npm test`.

The configuration is shared: a subtheme's `jest.config.cjs` is three lines.

```js
const path = require('node:path');
const baseTheme = require('./base-theme.cjs');   // finds the base theme folder
module.exports = require(path.join(baseTheme, 'jest.base.cjs'))(__dirname);
```

| What the shared config does | |
|---|---|
| jsdom environment | resolved from the base theme |
| Babel (`preset-env`, `preset-react` classic) | presets resolved from the base theme, so no `.babelrc` is needed |
| `Components/...` alias | maps to `<theme>/components` |
| CSS/SCSS imports | mocked |
| One React | `react` / `react-dom` always resolve to the base theme copy, even if a dependency (rsuite) installed another |
| `@testing-library/*` | importable from the base theme location |
| setup file | jest-dom matchers, global `React`, stubs for `Drupal.t` and `drupalSettings`, `matchMedia` |

Pass overrides as the second argument: `require(...)(__dirname, { testPathIgnorePatterns: [...] })`.
The base theme's own tests (`src/__tests__`) cover `apiClient` and `executeWhenVisible`.

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
