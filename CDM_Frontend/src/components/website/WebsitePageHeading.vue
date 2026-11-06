<script setup>
defineProps({ eyebrow: { type: String, default: '' }, title: { type: String, required: true }, description: { type: String, default: '' } })
</script>
<template><header class="website-page-heading" data-buddy-obstacle="true"><div><p v-if="eyebrow">{{ eyebrow }}</p><h1><slot name="title">{{ title }}</slot></h1><span v-if="description">{{ description }}</span></div><div v-if="$slots.actions" class="heading-actions"><slot name="actions" /></div></header></template>
<style scoped>
.website-page-heading { display: flex; align-items: flex-end; justify-content: space-between; gap: 24px; margin-bottom: 26px; }
p { display: flex; align-items: center; gap: 8px; margin: 0 0 9px; color: var(--website-emerald); font-size: 12px; font-weight: 750; letter-spacing: .015em; }
p::before { content: ''; width: 7px; height: 7px; border-radius: 50%; background: var(--website-gold); }
h1 { margin: 0; color: var(--website-ink); font-size: clamp(27px,3vw,38px); line-height: 1.1; letter-spacing: -.035em; text-wrap: balance; }
span { display: block; max-width: 720px; margin-top: 9px; color: var(--website-muted); font-size: 14px; line-height: 1.55; }
.heading-actions { display: flex; gap: 9px; }
@media (max-width: 680px) { .website-page-heading { align-items: stretch; flex-direction: column; gap: 14px; } }
</style>
