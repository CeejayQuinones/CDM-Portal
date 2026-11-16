import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { persistPortalAppearance } from './portalAppearance'

export function usePortalThemeToggle(role, account, onChange) {
  const selectedTheme = ref('system')
  const systemDark = ref(false)
  const effectiveDark = computed(() => selectedTheme.value === 'dark' || (selectedTheme.value === 'system' && systemDark.value))
  const themeAction = computed(() => effectiveDark.value ? 'Switch to Light Mode' : 'Switch to Dark Mode')
  let observer
  let media

  const syncTheme = () => { selectedTheme.value = document.documentElement.dataset.studentTheme || 'system' }
  const syncSystemTheme = event => { systemDark.value = event.matches }
  const toggleTheme = () => {
    const next = effectiveDark.value ? 'light' : 'dark'
    selectedTheme.value = persistPortalAppearance(next, role.value, account.value) || selectedTheme.value
    if (selectedTheme.value === next) onChange?.(next)
  }

  onMounted(() => {
    if (typeof document === 'undefined') return
    syncTheme()
    observer = new MutationObserver(syncTheme)
    observer.observe(document.documentElement, { attributes: true, attributeFilter: ['data-student-theme'] })
    media = window.matchMedia?.('(prefers-color-scheme: dark)')
    if (media) {
      systemDark.value = media.matches
      media.addEventListener?.('change', syncSystemTheme)
    }
  })
  onBeforeUnmount(() => {
    observer?.disconnect()
    media?.removeEventListener?.('change', syncSystemTheme)
  })

  return { effectiveDark, themeAction, toggleTheme }
}
