import DefaultTheme from 'vitepress/theme';
import Video from './Video.vue';

export default {
  extends: DefaultTheme,
  enhanceApp({ app }) {
    app.component('Video', Video);
  },
};
