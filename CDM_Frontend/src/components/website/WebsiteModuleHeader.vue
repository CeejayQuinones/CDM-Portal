<script setup>
defineProps({
  eyebrow: { type: String, default: '' },
  title: { type: String, required: true },
  titleId: { type: String, default: '' },
  description: { type: String, default: '' },
  compact: Boolean,
})
</script>

<template>
  <header class="website-module-header" :class="{ compact }">
    <div class="website-module-heading">
      <p v-if="eyebrow">{{ eyebrow }}</p>
      <h1 :id="titleId || undefined"><slot name="title">{{ title }}</slot></h1>
      <span v-if="description">{{ description }}</span>
      <div v-if="$slots.meta" class="website-module-meta"><slot name="meta" /></div>
    </div>
    <div v-if="$slots.actions" class="website-module-actions"><slot name="actions" /></div>
  </header>
</template>

<style scoped>
.website-module-header { display: flex; align-items: flex-end; justify-content: space-between; gap: 28px; margin: 0 auto 24px; padding: 4px 2px 20px; border-bottom: 1px solid var(--website-border); }
.website-module-heading { min-width: 0; max-width: 820px; }
.website-module-heading > p { display: flex; align-items: center; gap: 8px; margin: 0 0 9px; color: var(--website-emerald); font-size: 12px; font-weight: 750; letter-spacing: .015em; }
.website-module-heading > p::before { content: ''; width: 7px; height: 7px; border-radius: 50%; background: var(--website-gold); }
h1 { margin: 0; color: var(--website-ink); font-size: clamp(26px,3vw,38px); line-height: 1.08; letter-spacing: -.035em; }
.website-module-heading > span { display: block; max-width: 720px; margin-top: 9px; color: var(--website-muted); font-size: 14px; line-height: 1.6; }
.website-module-meta { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 14px; }
.website-module-actions { display: flex; flex: 0 0 auto; flex-wrap: wrap; justify-content: flex-end; gap: 9px; }
.compact { margin-bottom: 18px; padding-bottom: 16px; }
.compact h1 { font-size: clamp(24px,2.5vw,32px); }
@media (max-width: 720px) { .website-module-header { align-items: stretch; flex-direction: column; gap: 16px; } .website-module-actions { justify-content: flex-start; } }
</style>
