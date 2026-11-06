import { onBeforeUnmount, ref, unref, watch } from 'vue'

export const BUDDY_GREETING_DELAY = 750
export const BUDDY_RETRY_DELAY = 600
export const BUDDY_MODULE_COOLDOWN = 5000

export function createSingleTrigger(callback) {
  let fired = false
  return {
    fire(...args) {
      if (fired) return false
      fired = true
      callback(...args)
      return true
    },
    reset() { fired = false },
    get fired() { return fired },
  }
}

export function useBuddyProactive({ accountKey, enabled, muted, moduleKey, isBusy, onOpen }) {
  const pending = ref(false)
  const lastShown = new Map()
  let routeTimer
  let interactionTimer
  let listenersAttached = false
  let transitionGeneration = 0

  function removeInteractionListeners() {
    if (!listenersAttached) return
    window.removeEventListener('pointermove', onInteraction)
    window.removeEventListener('pointerdown', onInteraction)
    window.removeEventListener('keydown', onInteraction)
    listenersAttached = false
  }

  function cancel() {
    transitionGeneration += 1
    pending.value = false
    window.clearTimeout(routeTimer)
    window.clearTimeout(interactionTimer)
    removeInteractionListeners()
  }

  function complete(module, reason, generation) {
    if (generation !== transitionGeneration || !pending.value) return false
    pending.value = false
    lastShown.set(module, Date.now())
    window.clearTimeout(routeTimer)
    window.clearTimeout(interactionTimer)
    removeInteractionListeners()
    onOpen(module, reason)
    return true
  }

  function attempt(module, reason, generation) {
    if (generation !== transitionGeneration || !pending.value || unref(muted) || !unref(enabled)) return false
    if (document.hidden || isBusy()) {
      window.clearTimeout(routeTimer)
      routeTimer = window.setTimeout(() => attempt(module, reason, generation), BUDDY_RETRY_DELAY)
      return false
    }
    return complete(module, reason, generation)
  }

  function onInteraction(event) {
    if (!pending.value || (event.type === 'keydown' && (event.metaKey || event.ctrlKey || event.altKey))) return
    const module = unref(moduleKey)
    const generation = transitionGeneration
    window.clearTimeout(interactionTimer)
    interactionTimer = window.setTimeout(() => attempt(module, 'interaction', generation), 120)
  }

  function schedule({ force = false } = {}) {
    cancel()
    const module = unref(moduleKey)
    if (!module || !unref(enabled) || unref(muted)) return
    if (!force && Date.now() - (lastShown.get(module) || 0) < BUDDY_MODULE_COOLDOWN) return

    const generation = transitionGeneration
    pending.value = true
    routeTimer = window.setTimeout(() => attempt(module, 'navigation', generation), BUDDY_GREETING_DELAY)
    window.addEventListener('pointermove', onInteraction, { passive: true })
    window.addEventListener('pointerdown', onInteraction, { passive: true })
    window.addEventListener('keydown', onInteraction)
    listenersAttached = true
  }

  function resetForAccount() {
    lastShown.clear()
    schedule({ force: true })
  }

  watch(moduleKey, (module, previous) => {
    if (module && module !== previous) schedule()
    else if (!module) cancel()
  })
  watch(enabled, value => { if (value) schedule(); else cancel() })
  watch(muted, value => { if (value) cancel(); else schedule({ force: true }) })
  watch(accountKey, resetForAccount)
  onBeforeUnmount(cancel)

  return { pending, schedule, attempt, cancel, resetForAccount }
}
