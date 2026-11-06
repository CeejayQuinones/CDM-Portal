<script setup>
defineProps({ label: { type: String, required: true }, value: { type: [String, Number], default: '—' }, note: { type: String, default: '' }, tone: { type: String, default: 'emerald' }, loading: Boolean })
</script>
<template><article class="website-stat-card" :class="`tone-${tone}`"><span>{{ label }}</span><strong>{{ loading ? '—' : value }}</strong><small v-if="note">{{ note }}</small></article></template>
<style scoped>
.website-stat-card { position: relative; min-width: 0; min-height: 142px; display: grid; align-content: space-between; gap: 8px; padding: 19px 21px; overflow: hidden; border: 1px solid var(--website-border); border-radius: var(--website-radius); background: var(--website-card); box-shadow: var(--website-shadow); transition: border-color 150ms ease, box-shadow 150ms ease; }
.website-stat-card::before { content: ''; width: 34px; height: 4px; border-radius: 9px; background: var(--website-emerald); }
.website-stat-card::after { content: ''; position: absolute; width: 84px; height: 84px; right: -42px; bottom: -42px; border: 14px solid color-mix(in srgb,var(--website-emerald) 10%,transparent); border-radius: 50%; pointer-events: none; }
.tone-gold { border-color: color-mix(in srgb,var(--website-gold) 38%,var(--website-border)); background: linear-gradient(145deg,var(--website-card),color-mix(in srgb,var(--website-gold) 10%,var(--website-card))); }
.tone-gold::before { background: var(--website-gold); }
.tone-gold::after { border-color: color-mix(in srgb,var(--website-gold) 15%,transparent); }
span { color: var(--website-muted); font-size: 12px; font-weight: 720; }
strong { position: relative; z-index: 1; overflow-wrap: anywhere; color: var(--website-ink); font-size: clamp(22px,2.3vw,31px); line-height: 1.08; letter-spacing: -.025em; font-variant-numeric: tabular-nums; }
small { position: relative; z-index: 1; color: var(--website-muted); font-size: 11px; line-height: 1.4; }
@media (prefers-reduced-motion: reduce) { .website-stat-card { transition: none; } }
</style>
