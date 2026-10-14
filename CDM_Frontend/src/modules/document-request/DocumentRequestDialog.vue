<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'

const props = defineProps({
  labelledby: { type: String, required: true },
  busy: Boolean,
  wide: Boolean,
  panelClass: { type: [String, Array, Object], default: '' },
  closeOnBackdrop: { type: Boolean, default: true },
})
const emit = defineEmits(['cancel'])
const dialog = ref(null)
let trigger
let previousOverflow
const inertSiblings = []

const controls = () =>
  Array.from(
    dialog.value?.querySelectorAll?.(
      'button:not(:disabled), input:not(:disabled), select:not(:disabled), textarea:not(:disabled), a[href], [tabindex="0"]',
    ) || [],
  ).filter((element) => element.getClientRects().length)

function cancel() {
  if (!props.busy) emit('cancel')
}

function keydown(event) {
  if (event.key === 'Escape') {
    event.preventDefault()
    event.stopPropagation()
    cancel()
    return
  }
  if (event.key !== 'Tab') return

  const items = controls()
  const first = items[0]
  const last = items.at(-1)
  if (!first) {
    event.preventDefault()
    dialog.value?.focus?.()
  } else if (event.shiftKey && (document.activeElement === first || document.activeElement === dialog.value)) {
    event.preventDefault()
    last.focus()
  } else if (!event.shiftKey && (document.activeElement === last || document.activeElement === dialog.value)) {
    event.preventDefault()
    first.focus()
  }
}

function backdrop(event) {
  if (props.closeOnBackdrop && event.target === event.currentTarget) cancel()
}

onMounted(() => {
  trigger = document.activeElement
  if (document.body?.style) {
    previousOverflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'
  }
  if (typeof HTMLElement !== 'undefined') {
    const overlay = dialog.value?.parentElement
    for (const sibling of document.body?.children || []) {
      if (sibling !== overlay && sibling instanceof HTMLElement && !sibling.matches('.step-up-backdrop')) {
        inertSiblings.push([sibling, sibling.inert])
        sibling.inert = true
      }
    }
  }
  dialog.value?.focus?.()
})

onBeforeUnmount(() => {
  for (const [element, inert] of inertSiblings) element.inert = inert
  if (previousOverflow !== undefined && document.body?.style) document.body.style.overflow = previousOverflow
  if (trigger?.isConnected && !trigger.disabled) trigger.focus?.({ preventScroll: true })
})
</script>

<template>
  <Teleport to="body">
    <Transition name="dr-modal" appear>
      <div class="dr-dialog-overlay" @mousedown.self="backdrop">
        <section
          ref="dialog"
          class="dr-dialog"
          :class="[panelClass, { 'dr-dialog--wide': wide }]"
          role="dialog"
          aria-modal="true"
          :aria-labelledby="labelledby"
          :aria-busy="busy"
          tabindex="-1"
          @keydown="keydown"
        >
          <slot />
        </section>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped src="./documentRequestPrimitives.css"></style>
