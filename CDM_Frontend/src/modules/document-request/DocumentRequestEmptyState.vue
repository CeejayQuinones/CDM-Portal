<script setup>
defineProps({ message: { type: String, required: true }, loading: Boolean })
</script>

<template>
  <p class="dr-empty-state" :aria-live="loading ? 'polite' : undefined" :aria-busy="loading">
    {{ message }}
  </p>
</template>

<style scoped src="./documentRequestPrimitives.css"></style>
