<script setup>
import { computed, ref, watch } from 'vue'
import { instituteBrandFor, instituteThemeStyle } from '../../config/instituteBranding'

const props = defineProps({
  institute: { type: Object, required: true },
  description: { type: String, default: '' },
  backLabel: { type: String, default: 'Back to Institutes' },
})
defineEmits(['back'])

const brand = computed(() => instituteBrandFor(props.institute?.code))
const themeStyle = computed(() => instituteThemeStyle(props.institute?.code))
const imageFailed = ref(false)
watch(() => props.institute?.code, () => { imageFailed.value = false })
</script>

<template>
  <section
    class="desktop-institute-hero"
    :data-institute="brand?.code || institute.code"
    :data-tone="brand?.tone || 'institutional'"
    :style="themeStyle"
    :aria-labelledby="`institute-hero-${institute.code}`"
  >
    <div class="desktop-institute-watermark-zone" aria-hidden="true">
      <div class="desktop-institute-watermark">
        <img v-if="brand?.logo && !imageFailed" :src="brand.logo" alt="" @error="imageFailed = true">
        <span v-else>{{ institute.code }}</span>
      </div>
    </div>
    <div class="desktop-institute-hero-copy">
      <p>Registrar / Grade Management / {{ institute.code }}</p>
      <h1 :id="`institute-hero-${institute.code}`">{{ institute.name }} Grade Sheets</h1>
      <span>{{ description }}</span>
    </div>
    <div class="desktop-institute-hero-actions">
      <span class="desktop-institute-identity"><i aria-hidden="true"/>{{ institute.code }} workspace</span>
      <button type="button" class="desktop-institute-back" @click="$emit('back')">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m15 18-6-6 6-6"/><path d="M9 12h10"/></svg>
        {{ backLabel }}
      </button>
    </div>
  </section>
</template>

<style scoped>
.desktop-institute-hero {
  --institute-accent: #166534;
  --institute-tint: #e6f3eb;
  --institute-secondary: #b08728;
  --institute-watermark-position: 50% 50%;
  --institute-watermark-scale: 1;
  align-items: center;
  background:
    linear-gradient(112deg, var(--desk-surface, var(--bg-surface)) 0 58%, color-mix(in srgb, var(--institute-tint) 74%, var(--desk-surface, var(--bg-surface))));
  border: 1px solid color-mix(in srgb, var(--institute-accent) 22%, var(--desk-border, var(--border-color)));
  border-radius: 14px;
  box-shadow: 0 8px 24px color-mix(in srgb, var(--institute-accent) 8%, transparent);
  display: grid;
  gap: 20px;
  grid-template-columns: minmax(0, 1fr) auto;
  isolation: isolate;
  min-height: 154px;
  overflow: hidden;
  padding: 24px 26px;
  position: relative;
}
.desktop-institute-hero::before { background: linear-gradient(180deg, var(--institute-secondary), var(--institute-accent)); border-radius: 999px; content: ''; inset: 22px auto 22px 0; position: absolute; width: 4px; z-index: 2; }
.desktop-institute-hero-copy { display: grid; gap: 6px; max-width: min(720px, calc(100% - 220px)); min-width: 0; position: relative; z-index: 2; }
.desktop-institute-hero-copy p { color: var(--institute-accent); font-size: 11px; font-weight: 750; letter-spacing: .065em; margin: 0; text-transform: uppercase; }
.desktop-institute-hero-copy h1 { color: var(--desk-ink, var(--text-primary)); font-size: clamp(21px, 2.1vw, 25px); font-weight: 730; letter-spacing: -.018em; line-height: 1.24; margin: 0; max-width: 720px; }
.desktop-institute-hero-copy span { color: var(--desk-text-muted, var(--text-secondary)); font-size: 13px; line-height: 1.5; max-width: 680px; }
.desktop-institute-watermark-zone { inset: 0 0 0 auto; mask-image: linear-gradient(90deg, transparent 0, rgb(0 0 0 / 48%) 22%, #000 52%); -webkit-mask-image: linear-gradient(90deg, transparent 0, rgb(0 0 0 / 48%) 22%, #000 52%); overflow: hidden; pointer-events: none; position: absolute; width: clamp(250px, 31%, 360px); z-index: 0; }
.desktop-institute-watermark { align-items: center; border: 1px solid color-mix(in srgb, var(--institute-accent) 18%, transparent); border-radius: 50%; display: flex; height: 196px; justify-content: center; opacity: .065; overflow: hidden; position: absolute; right: 32px; top: 50%; transform: translateY(-50%); width: 196px; }
.desktop-institute-watermark img { display: block; height: 100%; object-fit: cover; object-position: var(--institute-watermark-position); transform: scale(var(--institute-watermark-scale)); width: 100%; }
.desktop-institute-watermark span { color: var(--institute-accent); font-size: 64px; font-weight: 850; letter-spacing: .04em; }
.desktop-institute-hero-actions { align-items: flex-end; align-self: stretch; display: flex; flex-direction: column; justify-content: space-between; min-width: 178px; position: relative; z-index: 2; }
.desktop-institute-identity { align-items: center; background: color-mix(in srgb, var(--institute-tint) 56%, var(--desk-surface, var(--bg-surface))); border: 1px solid color-mix(in srgb, var(--institute-accent) 19%, transparent); border-radius: 999px; color: var(--institute-accent); display: inline-flex; font-size: 11px; font-weight: 750; gap: 7px; letter-spacing: .035em; padding: 5px 9px; text-transform: uppercase; }
.desktop-institute-identity i { background: var(--institute-secondary); border-radius: 50%; height: 6px; width: 6px; }
.desktop-institute-back { align-items: center; align-self: start; background: color-mix(in srgb, var(--desk-surface, var(--bg-surface)) 90%, transparent); border: 1px solid color-mix(in srgb, var(--institute-accent) 30%, var(--desk-border, var(--border-color))); border-radius: 8px; color: var(--institute-accent); display: inline-flex; font-size: 13px; font-weight: 700; gap: 7px; min-height: 38px; padding: 8px 12px; position: relative; white-space: nowrap; z-index: 2; }
.desktop-institute-back:hover { background: color-mix(in srgb, var(--institute-accent) 10%, var(--desk-surface, var(--bg-surface))); }
.desktop-institute-back:focus-visible { outline: 2px solid var(--institute-accent); outline-offset: 2px; }
.desktop-institute-back svg { height: 17px; width: 17px; }
@media (max-width: 820px) {
  .desktop-institute-hero { align-items: start; gap: 16px; grid-template-columns: 1fr; min-height: 178px; padding: 20px; }
  .desktop-institute-hero-copy { max-width: calc(100% - 76px); }
  .desktop-institute-hero-actions { align-items: flex-start; gap: 10px; min-width: 0; }
  .desktop-institute-identity { display: none; }
  .desktop-institute-watermark-zone { width: 190px; }
  .desktop-institute-watermark { height: 150px; right: -24px; width: 150px; }
}
@media (prefers-reduced-motion: reduce) {
  .desktop-institute-back { transition: none; }
}
</style>
