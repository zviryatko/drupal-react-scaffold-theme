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

## Full source

That is the whole component. Click the tabs.

::: code-group

<<< ../../components/react-tooltip/react-tooltip.component.yml{yaml} [react-tooltip.component.yml]

<<< ../../components/react-tooltip/react-tooltip.twig{twig} [react-tooltip.twig]

<<< ../../components/react-tooltip/index.jsx{jsx} [index.jsx]

<<< ../../components/react-tooltip/TextWithTooltip.jsx{jsx} [TextWithTooltip.jsx]

<<< ../../components/react-tooltip/react-tooltip.scss{scss} [react-tooltip.scss]

:::

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
