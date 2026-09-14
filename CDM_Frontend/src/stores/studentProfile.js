import { computed, ref, watch } from 'vue'
import { defineStore } from 'pinia'
import { apiAssetUrl } from '../services/apiClient'
import { useAuthStore } from './authStore'

// Populated by the existing layout/settings requests; the sidebar never fetches.
export const useStudentProfileStore = defineStore('studentProfile', () => {
  const auth = useAuthStore()
  const avatarPath = ref(null)
  const revision = ref(0)
  const avatarUrl = computed(() => auth.currentRole === 'Student' ? apiAssetUrl(avatarPath.value) : '')

  watch([() => auth.currentRole, () => auth.currentUser?.id], () => {
    avatarPath.value = null
    revision.value += 1
  }, { flush: 'sync' })

  function setAvatar(path, userId) {
    if (auth.currentRole !== 'Student' || auth.currentUser?.id !== userId) return
    avatarPath.value = path || null
    revision.value += 1
  }

  return { avatarPath, avatarUrl, revision, setAvatar }
})
