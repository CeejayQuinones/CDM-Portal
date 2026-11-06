<script setup>
import { computed } from 'vue'
const props = defineProps({ status: { type: String, default: 'unknown' }, label: { type: String, default: '' } })
const normalized = computed(() => String(props.status || 'unknown').toLowerCase().replaceAll(' ', '_'))
const text = computed(() => props.label || normalized.value.replaceAll('_', ' ').replace(/\b\w/g, value => value.toUpperCase()))
</script>
<template><span class="website-status-badge" :data-status="normalized" role="status"><span aria-hidden="true"></span>{{ text }}</span></template>
<style scoped>
.website-status-badge { display: inline-flex; align-items: center; gap: 7px; width: max-content; max-width: 100%; padding: 6px 10px; border: 1px solid var(--website-border-strong); border-radius: 999px; background: var(--website-surface-soft); color: var(--website-muted); font-size: 12px; font-weight: 780; line-height: 1; }
.website-status-badge > span { width: 7px; height: 7px; flex: 0 0 auto; border-radius: 50%; background: currentColor; }
.website-status-badge[data-status="approved"],.website-status-badge[data-status="accepted"],.website-status-badge[data-status="enrolled"],.website-status-badge[data-status="completed"],.website-status-badge[data-status="published"],.website-status-badge[data-status="active"],.website-status-badge[data-status="open"] { border-color: var(--website-success-border); background: var(--website-success-bg); color: var(--website-success); }
.website-status-badge[data-status="submitted"],.website-status-badge[data-status="under_review"],.website-status-badge[data-status="pending"],.website-status-badge[data-status="draft"] { border-color: var(--website-warning-border); background: var(--website-warning-bg); color: var(--website-warning); }
.website-status-badge[data-status="rejected"],.website-status-badge[data-status="cancelled"],.website-status-badge[data-status="returned"],.website-status-badge[data-status="closed"] { border-color: var(--website-danger-border); background: var(--website-danger-bg); color: var(--website-danger); }
</style>
