import { onBeforeUnmount, watch } from 'vue'

let activeLocks = 0

const setPageLock = (locked) => {
  document.documentElement.classList.toggle('event-modal-open', locked)
  document.body.classList.toggle('event-modal-open', locked)
}

export function useEventModalScrollLock(source) {
  let ownsLock = false

  const release = () => {
    if (!ownsLock) return
    ownsLock = false
    activeLocks = Math.max(0, activeLocks - 1)
    if (activeLocks === 0) setPageLock(false)
  }

  watch(source, (open) => {
    if (open && !ownsLock) {
      ownsLock = true
      activeLocks += 1
      setPageLock(true)
    } else if (!open) {
      release()
    }
  }, { immediate: true })

  onBeforeUnmount(release)
}
