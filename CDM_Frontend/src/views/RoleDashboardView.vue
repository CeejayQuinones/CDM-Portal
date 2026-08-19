<script setup>
defineProps({
  role: { type: String, required: true },
})
</script>

<template>
  <section class="page-header">
    <p class="page-kicker">{{ role }} Portal</p>
    <h1 class="page-title">{{ role }} Dashboard</h1>
    <p class="page-description">Your role-specific dashboard is ready for its module widgets.</p>
  </section>
</template>
