# Drupal React Scaffold theme

Hello, this is a Drupal theme that uses React and Webpack to build the front-end.

## Installation

Just copy structure to your custom Drupal theme, replace `react_scaffold` with your theme name.

## Usage

See `react_scaffold.theme` for example.

As render array:

```php
  [
    '#type' => 'pattern',
    '#id' => 'react_tooltip',
    '#fields' => [
      'text' => 'Tooltip text',
      'content' => $title,
    ],
  ]
```

or in twig:

```twig
  {{ pattern('react_tooltip', {
    text: 'Tooltip text',
    content: 'Text',
  }) }}
```

## Architecture

* Drupal is used as main index point, so it is NOT fully decoupled.
* React is loaded statically to allow components to be independent.
* Components connected to Drupal via ui_patterns module.
* NPM and Webpack is used to build the front-end.

## Dependencies

* `composer require drupal/ui_patterns`
* npm
* webpack

## Build

Install the dependencies:
```bash
docker run --rm -it -v $(pwd):/src -w /src node npm install
```

Build the theme:

```bash
docker run --rm -it -v $(pwd):/src -w /src node npm run dist
```

Or run watcher for development:

```bash
docker run --rm -it -v $(pwd):/src -w /src node npm run watch
```

## Testing

React components can be tested with Jest framework.

```bash
docker run --rm -it -v $(pwd):/src -w /src node npm run test
```

# Components
As per component-driven philosophy, components are standardized, interchangeable building blocks of UIs. They encapsulate the appearance and function of UI pieces.
```
react_scalfold
    |__ components
          |__ component-1
          |__ component-2
          |__ component-3
```
WFPSPA components are developed following [BEM](http://getbem.com/) rules and [Atomic Design](https://bradfrost.com/blog/post/atomic-web-design/) paradigm as base.

Each component folder must contain all assets needed for the component itself, for example:
```
component-1
    |__ images
          |__ img-1.png
          |__ img-2.svg
    |__ component-1.js
    |__ component-1.scss
    |__ component-1.ui_patterns.yml
    |__ pattern-component-1.html.twig
```
A component could be an **ui_pattern** (from [ui_patterns](https://www.drupal.org/project/ui_patterns) Drupal module) like the example above, but not necessarily. Take the pager as example for a very simple component:
```
pager
    |__ _pager.scss
```
Here's also an example of a React component:
```
react-tooltip
    |__ __tests__
          |__ __snapshots__
                 |__ react-tooltip.test.js.snap
          |__ react-tooltip.test.js
    |__ index.js
    |__ pattern-react-tooltip.html.twig
    |__ react-tooltip.scss
    |__ react-tooltip.ui_patterns.yml
    |__ TextWithTooltip.jsx
```

# Credits
- Scaffolded by [zviryatko](https://github.com/zviryatko)
- Developed by [Nuvole.org](https://nuvole.org)
- The initial theme scaffolding was inspired by project of [Massimo Altafini](massimo@nuvole.org)