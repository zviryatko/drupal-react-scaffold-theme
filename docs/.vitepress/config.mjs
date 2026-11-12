import { defineConfig } from 'vitepress';

// Deployed to https://zviryatko.github.io/frame/
export default defineConfig({
  title: 'Frame',
  description: 'Drupal theme scaffold: React components as core Single Directory Components, Vite build, Views REST data.',
  base: '/frame/',
  cleanUrls: true,
  lastUpdated: true,
  head: [['link', { rel: 'icon', href: '/frame/logo.svg' }]],
  themeConfig: {
    logo: '/logo.svg',
    nav: [
      { text: 'Guide', link: '/guide/getting-started' },
      { text: 'Examples', link: '/examples/recipe-explorer' },
      { text: 'Demos', link: '/demos' },
      { text: 'GitHub', link: 'https://github.com/zviryatko/frame' },
    ],
    sidebar: [
      {
        text: 'Guide',
        items: [
          { text: 'What is this', link: '/guide/what-is-this' },
          { text: 'Getting started', link: '/guide/getting-started' },
          { text: 'Base theme and subthemes', link: '/guide/base-theme' },
          { text: 'Build a site', link: '/guide/build-a-site' },
          { text: 'Create a subtheme', link: '/guide/create-your-theme' },
          { text: 'Creating a component', link: '/guide/components' },
          { text: 'Placing components in Twig', link: '/guide/twig' },
          { text: 'React + Drupal behaviors', link: '/guide/react-behaviors' },
          { text: 'Ajax calls and HTML responses', link: '/guide/ajax' },
          { text: 'Translations', link: '/guide/translations' },
          { text: 'Build: Vite and webpack', link: '/guide/build' },
          { text: 'Testing', link: '/guide/testing' },
          { text: 'Troubleshooting', link: '/guide/troubleshooting' },
        ],
      },
      {
        text: 'Page builder',
        items: [
          { text: 'Overview', link: '/guide/page-builder' },
          { text: 'Gallery', link: '/builder/gallery' },
          { text: 'Recipes', link: '/builder/recipes' },
          { text: 'Components', link: '/builder/components' },
          { text: 'Views', link: '/builder/views' },
          { text: 'Tokens, features and styling', link: '/builder/styling' },
          { text: 'Components of the base theme', link: '/builder/base-components' },
          { text: 'Layouts', link: '/builder/layouts' },
          { text: 'Library of saved blocks', link: '/builder/library' },
          { text: 'Paragraphs', link: '/builder/paragraphs' },
          { text: 'AI assistant', link: '/builder/ai' },
          { text: 'Architecture', link: '/builder/architecture' },
        ],
      },
      {
        text: 'Examples',
        items: [
          { text: 'Recipe explorer (Views REST)', link: '/examples/recipe-explorer' },
          { text: 'Node list (table + modal)', link: '/examples/node-list' },
          { text: 'Tooltip (minimal)', link: '/examples/tooltip' },
        ],
      },
      { text: 'Demos (video)', link: '/demos' },
    ],
    socialLinks: [{ icon: 'github', link: 'https://github.com/zviryatko/frame' }],
    search: { provider: 'local' },
    editLink: { pattern: 'https://github.com/zviryatko/frame/edit/main/docs/:path' },
    outline: [2, 3],
  },
});
