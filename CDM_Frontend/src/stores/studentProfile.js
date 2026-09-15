import { computed, ref, watch } from 'vue'
import { acceptHMRUpdate, defineStore } from 'pinia'
import { apiAssetUrl } from '../services/apiClient'
import { useAuthStore } from './authStore'

// Populated by the existing layout/settings requests; the sidebar never fetches.
export const useStudentProfileStore = defineStore('studentProfile', () => {
  const auth = useAuthStore()
  const avatarPath = ref(null)
  const preferredDisplayName = ref('')
  const revision = ref(0)
  const avatarUrl = computed(() => auth.currentRole === 'Student' ? apiAssetUrl(avatarPath.value) : '')

  watch([() => auth.currentRole, () => auth.currentUser?.id], () => {
    avatarPath.value = null
    preferredDisplayName.value = ''
    revision.value += 1
  }, { flush: 'sync' })

  function setProfile(profile, userId) {
    if (auth.currentRole !== 'Student' || auth.currentUser?.id !== userId) return
    avatarPath.value = profile?.avatar_url || null
    const name = profile?.preferred_display_name
    preferredDisplayName.value = typeof name === 'string' ? name.trim() : ''
    revision.value += 1
  }

  return { avatarPath, avatarUrl, preferredDisplayName, revision, setProfile }
})

if (import.meta.hot) {
  import.meta.hot.accept(acceptHMRUpdate(useStudentProfileStore, import.meta.hot))
}
