---
layout: home
hero:
  name: Drupal React Scaffold
  text: React components as core Single Directory Components
  tagline: No contrib modules. Vite build. Data from Views through views_better_rest. Works with Drupal ajax, BigPipe and hidden tabs.
  image:
    src: /logo.svg
    alt: Drupal React Scaffold
  actions:
    - theme: brand
      text: Getting started
      link: /guide/getting-started
    - theme: alt
      text: Watch the demos
      link: /demos
    - theme: alt
      text: GitHub
      link: https://github.com/zviryatko/drupal-react-scaffold-theme
features:
  - title: Core SDC only
    details: Each React widget is a normal Single Directory Component (component.yml + twig). Place it with include, embed or a render array.
  - title: One React for all components
    details: React is loaded once as a global library and treated as an external by the build, so components stay small and independent.
  - title: Drupal-aware
    details: Components mount from Drupal.behaviors with once(), so they work after ajax, in modals, in hidden tabs and with BigPipe.
  - title: Views as the API
    details: A Better REST export display gives rows, exposed filters, sorts and pager as JSON. The UI is built from that, no hardcoded filter lists.
---

## See it working

Recipe explorer on the Umami demo: a Views REST export drives filters, sorting and pager. DevTools (Network, Fetch/XHR) shows the ajax calls.

<Video src="/media/recipe-explorer-demo.mp4" poster="/media/screenshots/09-recipe-explorer-devtools.png" />

More in [Demos](/demos).
