<script setup>
import { withBase } from 'vitepress';
defineProps({ src: String, title: String });
</script>

<template>
  <figure class="shot">
    <a :href="withBase(src)" target="_blank" rel="noreferrer" :title="`${title}: open the full image`">
      <img :src="withBase(src)" :alt="title" loading="lazy" />
    </a>
    <figcaption><strong>{{ title }}</strong><span><slot /></span></figcaption>
  </figure>
</template>

<style>
.shots {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(min(100%, 20rem), 1fr));
  gap: 1.25rem;
  margin: 1.25rem 0 2rem;
}
.shot {
  margin: 0;
}
.shot img {
  display: block;
  width: 100%;
  border: 1px solid var(--vp-c-divider);
  border-radius: 8px;
}
.shot a:hover img {
  border-color: var(--vp-c-brand-1);
}
.shot figcaption {
  margin-top: 0.5rem;
  font-size: 0.875rem;
  line-height: 1.45;
  color: var(--vp-c-text-2);
}
.shot figcaption strong {
  display: block;
  color: var(--vp-c-text-1);
}
</style>
