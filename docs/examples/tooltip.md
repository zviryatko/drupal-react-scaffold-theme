# Tooltip: the smallest component

`components/react-tooltip`. Wraps the page title in a react-tippy tooltip. It is the minimal reference for the pattern.

## Files

```
react-tooltip/
  react-tooltip.component.yml     # prop `text`, slot `content`, libraryOverrides
  react-tooltip.twig              # <span class="react-tooltip" data-text="..."> + slot
  index.jsx                       # behavior: reads data-text and innerText, renders TextWithTooltip
  TextWithTooltip.jsx             # <Tooltip html={...}>{content}</Tooltip>
  react-tooltip.scss              # Umami look for the bubble
  __tests__/react-tooltip.test.js # snapshot test
```

## `index.jsx`

```jsx
import 'react-tippy/dist/tippy.css';       // library CSS must be imported, it is not automatic
import { TextWithTooltip } from './TextWithTooltip';
import './react-tooltip.scss';
import { createRoot } from 'react-dom/client';

(function (Drupal, once) {
  const attachTooltip = (element) => {
    createRoot(element).render(
      <TextWithTooltip text={element.dataset.text} content={element.innerText}/>
    );
  };

  Drupal.behaviors.reactTooltip = {
    attach(context) {
      once('react', '.react-tooltip', context)
        .forEach((element) => executeWhenVisible(element, attachTooltip, 'tooltip'));
    },
  };
})(Drupal, once);
```

## Used from PHP

`react_scaffold.theme` wraps the page title in the component, so every page title gets the tooltip:

```php
$variables['title'] = [
  '#type' => 'component',
  '#component' => 'react_scaffold:react-tooltip',
  '#props' => ['text' => 'Tooltip text'],
  '#slots' => ['content' => $title],
];
```

Hover the title on `/recipe-explorer` to see it:

![Tooltip](/media/screenshots/07-title-tooltip.png)

::: tip react-tippy
`animateFill={false}` is set because tippy's fill animation paints a dark circle over a themed bubble background.
:::
