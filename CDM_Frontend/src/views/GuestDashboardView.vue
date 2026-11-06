<script setup>
import { computed, defineAsyncComponent, onMounted, ref } from 'vue'
import EmptyPage from '../components/EmptyPage.vue'
import { clientPlatform } from '../config/clientPlatform'
import { useAuthStore } from '../stores/authStore'
import { fetchAdmissionIdentity } from '../modules/admission/services/admissionService'
import { admissionApi } from '../modules/admission/services/workflowService'
import { getPortalNotices } from '../services/portalNoticeService'

const authStore = useAuthStore()
const GuestWebsiteDashboard = defineAsyncComponent(() => import('../modules/dashboard/GuestWebsiteDashboard.vue'))
const isWebsitePresentation = clientPlatform === 'web'
const displayName = computed(() => {
  const profile = authStore.currentUser?.profile
  return [profile?.first_name, profile?.last_name].filter(Boolean).join(' ')
    || authStore.currentUser?.username
    || 'Applicant'
})
const admissionAccess = computed(() => authStore.currentUser?.admission || {})
const canContinueEnrollment = computed(() => Boolean(admissionAccess.value.admitted_guest))
const identity = ref(null)
const workflow = ref({ exam: null, result: null })
const notices = ref([])
const loading = ref(false)
const error = ref('')

async function loadDashboard() {
  if (!isWebsitePresentation) return
  loading.value = true
  error.value = ''
  const values = await Promise.allSettled([fetchAdmissionIdentity(), getPortalNotices()])
  if (values[0].status === 'fulfilled') {
    identity.value = values[0].value
    if (identity.value?.has_application && !identity.value.application?.admission_completed) {
      const workflowValues = await Promise.allSettled([admissionApi('get', 'exam'), admissionApi('get', 'result')])
      workflow.value = {
        exam: workflowValues[0].status === 'fulfilled' ? workflowValues[0].value : null,
        result: workflowValues[1].status === 'fulfilled' ? workflowValues[1].value : null,
      }
    }
  } else error.value = 'Your Admission dashboard could not be loaded. Please try again.'
  notices.value = values[1].status === 'fulfilled' && Array.isArray(values[1].value?.notices) ? values[1].value.notices : []
  loading.value = false
}

onMounted(loadDashboard)
</script>

<template>
  <GuestWebsiteDashboard v-if="isWebsitePresentation" :display-name="displayName" :account-name="authStore.currentUser?.username" :admitted="canContinueEnrollment" :identity="identity" :workflow="workflow" :notices="notices" :loading="loading" :error="error" @retry="loadDashboard" />
  <EmptyPage v-else />
</template>
