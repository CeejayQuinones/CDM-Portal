<script setup>
defineProps({ title: { type: String, required: true }, eyebrow: { type: String, default: '' }, empty: { type: String, default: '' }, busy: Boolean })
</script>
<template><section class="website-activity-panel" :aria-busy="busy"><header><p v-if="eyebrow">{{ eyebrow }}</p><h2>{{ title }}</h2><slot name="action" /></header><div class="activity-content"><slot><p class="activity-empty">{{ empty }}</p></slot></div></section></template>
<style scoped>
.website-activity-panel { min-width: 0; border: 1px solid var(--website-border); border-radius: var(--website-radius); background: var(--website-card); box-shadow: var(--website-shadow); overflow: hidden; }
header { display: flex; align-items: end; justify-content: space-between; gap: 14px; padding: 19px 20px 15px; border-bottom: 1px solid var(--website-border); }
header p { margin: 0 0 4px; color: var(--website-emerald); font-size: 10px; font-weight: 800; letter-spacing: .12em; text-transform: uppercase; }
h2 { margin: 0; color: var(--website-ink); font-size: 17px; }
.activity-content { padding: 18px 20px; }
.activity-empty { margin: 0; padding: 16px; border-radius: 12px; background: var(--website-surface-soft); color: var(--website-muted); font-size: 13px; line-height: 1.5; }
</style>
