<script setup>
defineProps({
  title: { type: String, required: true },
  description: { type: String, default: 'This service is being prepared.' },
})
</script>

<template>
  <section class="page-header">
    <p class="page-kicker">Coming Soon</p>
    <h1 class="page-title">{{ title }}</h1>
    <p class="page-description">{{ description }}</p>
  </section>
  <section class="placeholder-panel">
    <h2>Not available yet</h2>
    <p>This page is reserved for its assigned module team.</p>
  </section>
</template>
