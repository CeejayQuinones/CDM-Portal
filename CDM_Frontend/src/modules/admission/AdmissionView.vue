<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { ROLES } from '../../config/accessControl.js'
import { useAuthStore } from '../../stores/authStore'
import AdmissionPlaceholder from './components/AdmissionPlaceholder.vue'
import { fetchAdmissionIdentity, fetchAdmissionAvailability, createAdmissionApplication, admissionCreationMessage } from './services/admissionService.js'

defineProps({ title: { type: String, default: 'Admission Status' } })
const auth = useAuthStore()
const canReadIdentity = computed(() => [ROLES.GUEST, ROLES.STUDENT].includes(auth.currentRole))
const application = ref(null)
const loading = ref(true)
const failed = ref(false)
const availability = ref(null)
const confirming = ref(false)
const submitting = ref(false)
const creationMessage = ref('')
let requestVersion = 0

const statuses = {
  draft: ['Draft', 'Your application is saved as a draft and has not been submitted.'],
  submitted: ['Submitted', 'Your application has been submitted. Check here for status updates.'],
  under_review: ['Under review', 'Your application is under review. Check here for status updates.'],
  accepted: ['Accepted', 'Your application is accepted. Contact the Registrar for the next steps.'],
  rejected: ['Rejected', 'Your application was rejected. Contact the Registrar if you need clarification.'],
  withdrawn: ['Withdrawn', 'Your application has been withdrawn.'],
  expired: ['Expired', 'Your application has expired. Contact the Registrar if you need clarification.'],
  converted: ['Converted', 'Your application has been linked to an academic Student record.'],
}
const status = computed(() => statuses[application.value?.status] || ['Status unavailable', 'Contact the Registrar for clarification of this application status.'])
const formatDate = (value) => {
  if (!value) return 'Not recorded'
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? 'Not recorded' : new Intl.DateTimeFormat('en-PH', { dateStyle: 'medium', timeStyle: 'short' }).format(date)
}

async function loadIdentity() {
  const version = ++requestVersion
  application.value = null
  availability.value = null
  confirming.value = false
  creationMessage.value = ''
  submitting.value = false
  failed.value = false
  loading.value = true
  if (!canReadIdentity.value) {
    loading.value = false
    return
  }
  try {
    const identity = await fetchAdmissionIdentity()
    if (version !== requestVersion) return
    application.value = identity.application
    if (!identity.application && auth.currentRole === ROLES.GUEST) {
      try {
        const value = await fetchAdmissionAvailability()
        if (version === requestVersion) availability.value = value
      } catch {
        if (version === requestVersion) availability.value = { allowed: false, reason: 'cycle_unavailable' }
      }
    }
  } catch {
    if (version === requestVersion) failed.value = true
  } finally {
    if (version === requestVersion) loading.value = false
  }
}

async function createApplication() {
  if (submitting.value || !confirming.value || !availability.value?.allowed || auth.currentRole !== ROLES.GUEST) return
  const version = requestVersion
  submitting.value = true
  creationMessage.value = ''
  try {
    const identity = await createAdmissionApplication()
    if (version !== requestVersion) return
    application.value = identity.application
    confirming.value = false
    availability.value = null
  } catch (error) {
    if (version !== requestVersion) return
    const message = admissionCreationMessage(error)
    confirming.value = false
    if (error?.response?.status === 409) {
      await loadIdentity()
      if (requestVersion !== version + 1) return
    }
    creationMessage.value = message
  } finally {
    if (version === requestVersion) submitting.value = false
  }
}

onMounted(loadIdentity)
watch(() => [auth.currentUser?.id, auth.currentRole], loadIdentity)
onBeforeUnmount(() => { requestVersion++ })
</script>

<template>
  <!-- Preserve staff's legacy landing URL without calling the self-service API. -->
  <AdmissionPlaceholder v-if="!canReadIdentity" :title="title" />
  <section v-else aria-labelledby="admission-page-title" :aria-busy="loading">
    <header class="page-header">
      <p class="page-kicker">Admission</p>
      <h1 id="admission-page-title" class="page-title">{{ title }}</h1>
      <p class="page-description">View your admission identity and current application status.</p>
    </header>
    <div class="placeholder-panel">
      <p v-if="loading" role="status">Loading admission information...</p>
      <div v-else-if="failed" role="alert">
        <h2>Unable to load admission information</h2>
        <p>Please try again later.</p>
        <button class="admission-retry" type="button" @click="loadIdentity">Try again</button>
      </div>
      <div v-else-if="!application">
        <h2>No admission application yet</h2>
        <p>No admission application is linked to your account. Start an application when an admission cycle is open.</p>
        <template v-if="auth.currentRole === ROLES.GUEST">
          <button v-if="availability?.allowed" class="admission-retry" type="button" :disabled="submitting" @click="confirming = true">Start Admission Application</button>
          <p v-else-if="availability" role="status">{{ admissionCreationMessage(availability.reason) }}</p>
        </template>
      </div>
      <div v-else>
        <dl class="admission-details">
          <div><dt>Applicant Number</dt><dd>{{ application.applicant_number }}</dd></div>
          <div><dt>Admission Cycle</dt><dd>{{ application.cycle.name }} <span class="admission-cycle-code">({{ application.cycle.code }})</span></dd></div>
          <div><dt>Current Status</dt><dd><span class="admission-status">{{ status[0] }}</span></dd></div>
          <div><dt>Created</dt><dd>{{ formatDate(application.created_at) }}</dd></div>
          <div v-if="application.submitted_at"><dt>Submitted</dt><dd>{{ formatDate(application.submitted_at) }}</dd></div>
          <div v-if="application.is_converted && application.converted_at"><dt>Converted</dt><dd>{{ formatDate(application.converted_at) }}</dd></div>
        </dl>
        <p class="admission-guidance">{{ status[1] }}</p>
      </div>
      <p v-if="creationMessage" role="alert">{{ creationMessage }}</p>
      <dialog v-if="confirming" open aria-labelledby="admission-confirm-title" class="admission-confirm" @cancel.prevent="!submitting && (confirming = false)">
        <h2 id="admission-confirm-title">Start your admission application?</h2>
        <p>An applicant number will be generated using your existing portal profile. Your application will be saved as a draft.</p>
        <button class="admission-retry" type="button" :disabled="submitting" @click="confirming = false">Cancel</button>
        <button class="admission-retry" type="button" :disabled="submitting" @click="createApplication">{{ submitting ? 'Creating...' : 'Confirm application' }}</button>
      </dialog>
    </div>
  </section>
</template>

<style scoped>
.admission-confirm { position: fixed; inset: 0; margin: auto; max-width: min(440px, 90vw); border: 1px solid var(--color-border); border-radius: 12px; padding: 24px; background: var(--color-surface); color: inherit; z-index: 20; }
.admission-retry:disabled { opacity: 0.6; cursor: wait; }
.admission-details { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 24px; margin: 0; }
.admission-details dt { color: var(--color-muted); font-size: 0.85rem; margin-bottom: 8px; }
.admission-details dd { margin: 0; overflow-wrap: anywhere; font-weight: 600; }
.admission-cycle-code { color: var(--color-muted); font-weight: 400; }
.admission-status { display: inline-block; padding: 5px 10px; border: 1px solid var(--color-border); border-radius: 999px; background: var(--color-anti-flash-white); font-size: 0.85rem; }
.admission-guidance { margin: 24px 0 0; padding-top: 20px; border-top: 1px solid var(--color-border); color: var(--color-muted); line-height: 1.6; }
.admission-retry { padding: 8px 14px; background: var(--color-surface); color: var(--color-dartmouth-green); border: 1px solid var(--color-border); border-radius: 8px; font: inherit; }
.admission-retry:focus-visible { outline: 2px solid var(--color-dartmouth-green); outline-offset: 3px; }
@media (max-width: 600px) { .admission-details { grid-template-columns: minmax(0, 1fr); gap: 20px; } }
</style>
