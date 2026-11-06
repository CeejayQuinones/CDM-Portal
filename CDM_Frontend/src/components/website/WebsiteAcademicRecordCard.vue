<script setup>
defineProps({
  eyebrow: { type: String, default: '' },
  title: { type: String, required: true },
  detail: { type: String, default: '' },
  status: { type: String, default: '' },
  actionLabel: { type: String, default: '' },
  disabled: Boolean,
})
defineEmits(['open'])
</script>

<template>
  <article class="ui4-record-card">
    <div class="record-copy">
      <small v-if="eyebrow">{{ eyebrow }}</small>
      <h3>{{ title }}</h3>
      <p v-if="detail">{{ detail }}</p>
    </div>
    <div class="record-actions">
      <span v-if="status" class="record-status" :data-status="status.toLowerCase().replaceAll(' ', '_')">{{ status.replaceAll('_', ' ') }}</span>
      <button v-if="actionLabel" type="button" :disabled="disabled" @click="$emit('open')">{{ actionLabel }}</button>
    </div>
  </article>
</template>

<style scoped>
.ui4-record-card { display: flex; align-items: center; justify-content: space-between; gap: 20px; min-width: 0; padding: 17px 20px; border: 1px solid var(--website-border); border-radius: 10px; background: var(--website-card); box-shadow: 0 1px 3px rgb(15 23 42 / 5%); }
.record-copy { min-width: 0; }
.record-copy small { color: var(--website-muted); font-size: 11px; font-weight: 750; letter-spacing: .07em; text-transform: uppercase; }
.record-copy h3 { margin: 3px 0 0; color: var(--website-ink); font-size: 16px; line-height: 1.35; overflow-wrap: anywhere; }
.record-copy p { margin: 5px 0 0; color: var(--website-muted); font-size: 13px; line-height: 1.45; }
.record-actions { display: flex; align-items: center; gap: 12px; flex: 0 0 auto; }
.record-status { padding: 5px 9px; border: 1px solid var(--website-border); border-radius: 6px; color: var(--website-muted); font-size: 11px; font-weight: 750; text-transform: capitalize; }
.record-status[data-status='enrolled'], .record-status[data-status='completed'], .record-status[data-status='approved'] { color: var(--website-success); border-color: var(--website-success-border); background: var(--website-success-bg); }
.record-status[data-status='cancelled'], .record-status[data-status='rejected'] { color: var(--website-danger); border-color: var(--website-danger-border); background: var(--website-danger-bg); }
.record-status[data-status='draft'], .record-status[data-status='submitted'], .record-status[data-status='under_review'] { color: var(--website-warning); border-color: var(--website-warning-border); background: var(--website-warning-bg); }
.record-actions button { min-height: 40px; padding: 0 12px; border: 1px solid var(--website-border-strong); border-radius: 7px; background: var(--website-card); color: var(--website-ink); font-size: 12px; font-weight: 750; white-space: nowrap; }
.record-actions button:hover:not(:disabled) { border-color: var(--website-emerald); color: var(--website-success); background: var(--website-hover); }
.record-actions button::after { content: ' →'; }
.record-actions button:focus-visible { outline: 2px solid var(--website-focus); outline-offset: 2px; }
@media (max-width: 640px) { .ui4-record-card { flex-direction: column; align-items: stretch; gap: 14px; padding: 15px; } .record-actions { justify-content: space-between; flex-wrap: wrap; } .record-actions button { min-height: 44px; } }
@media (max-width: 360px) { .record-actions button { width: 100%; } }
</style>
