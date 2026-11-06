import { onUnmounted, watch } from 'vue'
import { apiClient } from '../services/apiClient'
import { useStudentProfileStore } from '../stores/studentProfile'
import {
  bootstrapPortalAppearance,
  persistPortalAppearance,
  portalAppearanceStorageKey,
  portalAppearanceStorageKeys,
  previewPortalAppearance,
  storedPortalAppearance,
} from './portalAppearance.js'

const storageKeys = portalAppearanceStorageKeys
let revision = 0
let activeRole = null
let activeAccount = null

export function applyPortalAppearance(appearance, role = activeRole, account = activeAccount, storage = localStorage, target = document.documentElement) {
  const theme = persistPortalAppearance(appearance, role, account, storage, target)
  if (!theme) return null
  revision += 1
  return theme
}

export const applyStudentAppearance = applyPortalAppearance

export { bootstrapPortalAppearance, portalAppearanceStorageKey, previewPortalAppearance, storedPortalAppearance }

export function useStudentTheme(auth) {
  const studentProfile = useStudentProfileStore()
  watch([() => auth.currentRole, () => auth.currentUser?.id, () => auth.currentUser?.username], async ([role, userId, username], _, onCleanup) => {
    let active = true
    onCleanup(() => { active = false })
    activeRole = storageKeys[role] ? role : null
    activeAccount = userId || username || null
    if (!activeRole) {
      delete document.documentElement.dataset.studentTheme
      return
    }

    applyPortalAppearance(storedPortalAppearance(role, activeAccount), role, activeAccount)
    if (role !== 'Student') return

    const startedAt = revision
    const profileRevision = studentProfile.revision
    try {
      const { data } = await apiClient.get('/student/settings')
      // Reuse this response for the sidebar, without overwriting newer profile changes.
      if (active && studentProfile.revision === profileRevision) studentProfile.setProfile(data.data.profile, userId)
      // A late response must not override a new selection or another session.
      if (active && revision === startedAt) applyPortalAppearance(data.data.appearance, role, activeAccount)
    } catch {
      // Keep the cached choice when settings are temporarily unavailable.
    }
  }, { immediate: true })

  onUnmounted(() => {
    activeRole = null
    activeAccount = null
    delete document.documentElement.dataset.studentTheme
  })
}
