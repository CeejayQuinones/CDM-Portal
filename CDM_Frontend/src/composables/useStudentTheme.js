import { onUnmounted, watch } from 'vue'
import { apiClient } from '../services/apiClient'
import { useStudentProfileStore } from '../stores/studentProfile'

const storageKey = 'cdm-student-appearance'
let revision = 0
let studentSession = false

export function applyStudentAppearance(appearance) {
  if (!studentSession) return
  const theme = ['light', 'dark', 'system'].includes(appearance) ? appearance : 'system'
  document.documentElement.dataset.studentTheme = theme
  localStorage.setItem(storageKey, theme)
  revision += 1
}

export function useStudentTheme(auth) {
  const studentProfile = useStudentProfileStore()
  watch([() => auth.currentRole, () => auth.currentUser?.id], async ([role, userId], _, onCleanup) => {
    let active = true
    onCleanup(() => { active = false })
    studentSession = role === 'Student'
    if (role !== 'Student') {
      delete document.documentElement.dataset.studentTheme
      return
    }

    applyStudentAppearance(localStorage.getItem(storageKey) || 'system')
    const startedAt = revision
    const profileRevision = studentProfile.revision
    try {
      const { data } = await apiClient.get('/student/settings')
      // Reuse this response for the sidebar, without overwriting newer profile changes.
      if (active && studentProfile.revision === profileRevision) studentProfile.setProfile(data.data.profile, userId)
      // A late response must not override a new selection or another session.
      if (active && revision === startedAt) applyStudentAppearance(data.data.appearance)
    } catch {
      // Keep the cached choice when settings are temporarily unavailable.
    }
  }, { immediate: true })

  onUnmounted(() => {
    studentSession = false
    delete document.documentElement.dataset.studentTheme
  })
}
