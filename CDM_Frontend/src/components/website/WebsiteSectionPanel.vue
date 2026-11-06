<script setup>
defineProps({ title: { type: String, default: '' }, description: { type: String, default: '' }, labelledby: { type: String, default: '' }, flush: Boolean })
</script>

<template>
  <section class="website-section-panel" :class="{ flush }" :aria-labelledby="labelledby || undefined">
    <header v-if="title || description || $slots.actions" class="website-section-heading">
      <div><h2 v-if="title" :id="labelledby || undefined">{{ title }}</h2><p v-if="description">{{ description }}</p></div>
      <div v-if="$slots.actions" class="website-section-actions"><slot name="actions" /></div>
    </header>
    <div class="website-section-content"><slot /></div>
    <footer v-if="$slots.footer" class="website-section-footer"><slot name="footer" /></footer>
  </section>
</template>

<style scoped>
.website-section-panel { min-width: 0; overflow: hidden; border: 1px solid var(--website-border); border-radius: var(--website-radius); background: var(--website-card); box-shadow: 0 10px 28px rgba(15,23,42,.05); }
.website-section-heading { display: flex; align-items: flex-start; justify-content: space-between; gap: 20px; padding: 20px 22px; border-bottom: 1px solid var(--website-border); }
h2 { margin: 0; color: var(--website-ink); font-size: 18px; letter-spacing: -.015em; }
p { margin: 5px 0 0; color: var(--website-muted); font-size: 13px; line-height: 1.5; }
.website-section-actions { display: flex; flex-wrap: wrap; gap: 8px; }
.website-section-content { min-width: 0; padding: 22px; }
.website-section-footer { padding: 14px 22px; border-top: 1px solid var(--website-border); background: color-mix(in srgb,var(--website-canvas) 70%,var(--website-card)); }
.flush .website-section-content { padding: 0; }
@media (max-width: 640px) { .website-section-heading { flex-direction: column; padding: 17px; } .website-section-content { padding: 17px; } }
</style>
