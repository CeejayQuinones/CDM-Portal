<script setup>
defineProps({
  eyebrow: { type: String, required: true },
  title: { type: String, required: true },
  description: { type: String, default: '' },
  titleId: { type: String, default: undefined },
  variant: { type: String, default: 'page' },
})
</script>

<template>
  <header :class="variant === 'page' ? 'page-header' : undefined">
    <slot name="leading" />
    <div v-if="variant === 'settings'">
      <p><slot name="eyebrow">{{ eyebrow }}</slot></p>
      <h1 :id="titleId">{{ title }}</h1>
      <p v-if="description" class="page-description">{{ description }}</p>
    </div>
    <template v-else>
      <p class="page-kicker"><slot name="eyebrow">{{ eyebrow }}</slot></p>
      <h1 :id="titleId" class="page-title">{{ title }}</h1>
      <p v-if="description" class="page-description">{{ description }}</p>
    </template>
    <div v-if="$slots.actions" class="academic-page-actions"><slot name="actions" /></div>
  </header>
</template>

<style scoped>
.academic-page-actions { align-items: center; display: flex; gap: 8px; margin-left: auto; }
@media (max-width: 640px) {
  .academic-page-actions { align-items: stretch; margin-left: 0; width: 100%; }
}
</style>
