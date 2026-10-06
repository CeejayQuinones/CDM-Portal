import { onUnmounted, watch } from 'vue'
import { apiClient } from '../services/apiClient'
import { useStudentProfileStore } from '../stores/studentProfile'

const storageKeys = {
  Student: 'cdm-student-appearance',
  'Registrar Staff': 'cdm-registrar-appearance',
  Admin: 'cdm-admin-appearance',
}
let revision = 0
let activeRole = null

export function storedPortalAppearance(role) {
  return localStorage.getItem(storageKeys[role]) || 'system'
}

export function applyPortalAppearance(appearance, role = activeRole) {
  if (!storageKeys[role]) return
  const theme = ['light', 'dark', 'system'].includes(appearance) ? appearance : 'system'
  document.documentElement.dataset.studentTheme = theme
  localStorage.setItem(storageKeys[role], theme)
  revision += 1
}

export const applyStudentAppearance = applyPortalAppearance

export function useStudentTheme(auth) {
  const studentProfile = useStudentProfileStore()
  watch([() => auth.currentRole, () => auth.currentUser?.id], async ([role, userId], _, onCleanup) => {
    let active = true
    onCleanup(() => { active = false })
    activeRole = storageKeys[role] ? role : null
    if (!activeRole) {
      delete document.documentElement.dataset.studentTheme
      return
    }

    applyPortalAppearance(storedPortalAppearance(role), role)
    if (role !== 'Student') return

    const startedAt = revision
    const profileRevision = studentProfile.revision
    try {
      const { data } = await apiClient.get('/student/settings')
      // Reuse this response for the sidebar, without overwriting newer profile changes.
      if (active && studentProfile.revision === profileRevision) studentProfile.setProfile(data.data.profile, userId)
      // A late response must not override a new selection or another session.
      if (active && revision === startedAt) applyPortalAppearance(data.data.appearance, role)
    } catch {
      // Keep the cached choice when settings are temporarily unavailable.
    }
  }, { immediate: true })

  onUnmounted(() => {
    activeRole = null
    delete document.documentElement.dataset.studentTheme
  })
}
