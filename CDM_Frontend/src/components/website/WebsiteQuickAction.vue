<script setup>
import { RouterLink } from 'vue-router'
defineProps({ to: { type: [String, Object], required: true }, title: { type: String, required: true }, description: { type: String, default: '' } })
</script>
<template><RouterLink :to="to" class="website-quick-action"><span><strong>{{ title }}</strong><small>{{ description }}</small></span><b aria-hidden="true">→</b></RouterLink></template>
<style scoped>
.website-quick-action { min-height: 88px; display: flex; align-items: center; justify-content: space-between; gap: 14px; padding: 16px 18px; border: 1px solid var(--website-border); border-radius: 16px; background: var(--website-card); color: var(--website-ink); text-decoration: none; transition: transform 140ms ease, border-color 140ms ease, box-shadow 140ms ease, background-color 140ms ease; }
.website-quick-action:hover { transform: translateY(-2px); border-color: var(--website-success-border); background: color-mix(in srgb,var(--website-hover) 48%,var(--website-card)); box-shadow: var(--website-shadow); }
.website-quick-action:active { transform: translateY(0); }
.website-quick-action:focus-visible { outline: 3px solid var(--website-focus); outline-offset: 2px; }
span { display: grid; gap: 5px; }
strong { font-size: 14px; }
small { color: var(--website-muted); font-size: 12px; line-height: 1.35; }
b { width: 38px; height: 38px; flex: 0 0 38px; display: grid; place-items: center; border-radius: 12px; background: var(--website-success-bg); color: var(--website-success); font-size: 21px; }
@media (prefers-reduced-motion: reduce) { .website-quick-action { transition: none; } .website-quick-action:hover,.website-quick-action:active { transform: none; } }
</style>
