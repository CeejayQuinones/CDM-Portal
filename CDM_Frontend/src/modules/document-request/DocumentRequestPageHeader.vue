<script setup>
defineProps({
  eyebrow: { type: String, default: '' },
  title: { type: String, required: true },
  description: { type: String, required: true },
})
</script>

<template>
  <header class="dr-page-header">
    <div>
      <p v-if="eyebrow" class="dr-page-eyebrow">{{ eyebrow }}</p>
      <h1>{{ title }}</h1>
      <p>{{ description }}</p>
    </div>
    <div v-if="$slots.actions" class="dr-page-actions"><slot name="actions" /></div>
  </header>
</template>

<style scoped src="./documentRequestPrimitives.css"></style>
