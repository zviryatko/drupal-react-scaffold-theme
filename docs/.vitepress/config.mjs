import { defineConfig } from 'vitepress';

// Deployed to https://zviryatko.github.io/drupal-react-scaffold-theme/
export default defineConfig({
  title: 'Drupal React Scaffold',
  description: 'Drupal theme scaffold: React components as core Single Directory Components, Vite build, Views REST data.',
  base: '/drupal-react-scaffold-theme/',
  cleanUrls: true,
  lastUpdated: true,
  head: [['link', { rel: 'icon', href: '/drupal-react-scaffold-theme/logo.svg' }]],
  themeConfig: {
    logo: '/logo.svg',
    nav: [
      { text: 'Guide', link: '/guide/getting-started' },
      { text: 'Examples', link: '/examples/recipe-explorer' },
      { text: 'Demos', link: '/demos' },
      { text: 'GitHub', link: 'https://github.com/zviryatko/drupal-react-scaffold-theme' },
    ],
    sidebar: [
      {
        text: 'Guide',
        items: [
          { text: 'What is this', link: '/guide/what-is-this' },
          { text: 'Getting started', link: '/guide/getting-started' },
          { text: 'Create your own theme', link: '/guide/create-your-theme' },
          { text: 'Components (SDC)', link: '/guide/components' },
          { text: 'Placing components in Twig', link: '/guide/twig' },
          { text: 'React + Drupal behaviors', link: '/guide/react-behaviors' },
          { text: 'Ajax calls and HTML responses', link: '/guide/ajax' },
          { text: 'Build: Vite and webpack', link: '/guide/build' },
          { text: 'Testing', link: '/guide/testing' },
          { text: 'Troubleshooting', link: '/guide/troubleshooting' },
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
    socialLinks: [{ icon: 'github', link: 'https://github.com/zviryatko/drupal-react-scaffold-theme' }],
    search: { provider: 'local' },
    editLink: { pattern: 'https://github.com/zviryatko/drupal-react-scaffold-theme/edit/v2/docs/:path' },
    outline: [2, 3],
  },
});
