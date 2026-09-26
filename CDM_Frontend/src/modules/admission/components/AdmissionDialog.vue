<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'
const props = defineProps({ labelledby: { type: String, required: true }, busy: Boolean })
const emit = defineEmits(['cancel'])
const dialog = ref(null)
let trigger, previousOverflow
const inertSiblings = []
const controls = () => Array.from(dialog.value?.querySelectorAll?.('button:not(:disabled), input:not(:disabled), select:not(:disabled), textarea:not(:disabled), a[href], [tabindex="0"]') || []).filter(el => el.getClientRects().length)
function keydown(event) {
  if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); if (!props.busy) emit('cancel') }
  if (event.key !== 'Tab') return
  const items = controls(), first = items[0], last = items.at(-1)
  if (!first) { event.preventDefault(); dialog.value?.focus?.(); return }
  if (event.shiftKey && (document.activeElement === first || document.activeElement === dialog.value)) { event.preventDefault(); last.focus() }
  else if (!event.shiftKey && (document.activeElement === last || document.activeElement === dialog.value)) { event.preventDefault(); first.focus() }
}
onMounted(() => {
  trigger = document.activeElement
  if (document.body?.style) { previousOverflow = document.body.style.overflow; document.body.style.overflow = 'hidden' }
  // Keep the portal's separate password step-up overlay available above this dialog.
  if (typeof HTMLElement !== 'undefined') {
    let node = dialog.value?.parentElement
    while (node && node !== document.body) {
      for (const sibling of node.parentElement?.children || []) {
        if (sibling !== node && sibling instanceof HTMLElement && !sibling.matches('.step-up-backdrop')) {
          inertSiblings.push([sibling, sibling.inert]); sibling.inert = true
        }
      }
      node = node.parentElement
    }
  }
  dialog.value?.focus?.()
})
onBeforeUnmount(() => {
  for (const [element, inert] of inertSiblings) element.inert = inert
  if (previousOverflow !== undefined) document.body.style.overflow = previousOverflow
  if (trigger?.isConnected && !trigger.disabled) trigger.focus?.({ preventScroll: true })
})
</script>
<template>
  <div class="admission-dialog-backdrop">
    <div ref="dialog" class="admission-dialog" role="dialog" aria-modal="true" :aria-labelledby="labelledby" :aria-busy="busy" tabindex="-1" @keydown="keydown"><slot /></div>
  </div>
</template>
