<script setup>
import { withBase } from 'vitepress';
defineProps({ src: String, poster: String });
</script>

<template>
  <video controls muted loop playsinline preload="metadata" :src="withBase(src)" :poster="poster && withBase(poster)" style="width: 100%; border-radius: 8px" />
</template>
