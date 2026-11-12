import DefaultTheme from 'vitepress/theme';
import Video from './Video.vue';
import Shot from './Shot.vue';

export default {
  extends: DefaultTheme,
  enhanceApp({ app }) {
    app.component('Video', Video);
    app.component('Shot', Shot);
  },
};
