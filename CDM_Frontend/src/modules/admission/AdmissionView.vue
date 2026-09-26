<script setup>
import AdmissionDialog from './components/AdmissionDialog.vue'
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { admissionApi } from './services/workflowService'
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
const exam = ref(null), result = ref(null), workflowFailed = ref(false)
const nextStep = computed(() => {
  if (application.value?.is_converted) return { stage: 4, text: 'You are now an academic Student. Your Admission history is read-only.', label: 'View Recommendation', to: '/admission/recommendation' }
  if (workflowFailed.value) return { stage: 0, text: 'Unable to load your next step. Please refresh.' }
  if (auth.currentRole === ROLES.GUEST && exam.value?.session && !exam.value.session.exam_completed) return { stage: 1, text: 'Your exam is in progress.', label: 'Resume Exam', to: '/admission/exam' }
  if (result.value?.published) {
    if (result.value.result.retake_eligible && auth.currentRole === ROLES.GUEST) return { stage: 1, text: 'Your result is published. You are eligible for one retake.', label: 'Take Retake', to: '/admission/exam' }
    return { stage: 3, text: 'Your result is published. Explore your program guidance.', label: 'View Recommendation', to: '/admission/recommendation' }
  }
  if (exam.value?.reason === 'awaiting_publication' || exam.value?.latest_completed || exam.value?.session?.exam_completed) return { stage: 2, text: 'Waiting for Registrar Review. Your exam has been submitted.' }
  if (exam.value?.eligible && auth.currentRole === ROLES.GUEST) return { stage: 1, text: 'Your application is created. Read the instructions before starting.', label: 'Take Entrance Exam', to: '/admission/exam' }
  return { stage: 0, text: exam.value?.reason === 'bank_incomplete' ? 'The entrance exam is not available yet. Please check again later.' : 'No exam action is currently available. Contact the Registrar for guidance.' }
})
async function loadWorkflow(version) {
  const values = await Promise.allSettled([admissionApi('get', 'exam'), admissionApi('get', 'result')])
  if (version !== requestVersion) return
  workflowFailed.value = values.some(v => v.status === 'rejected')
  exam.value = values[0].status === 'fulfilled' ? values[0].value : null
  result.value = values[1].status === 'fulfilled' ? values[1].value : null
}
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
  exam.value = null; result.value = null; workflowFailed.value = false
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
    if (identity.application) await loadWorkflow(version)
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
    if (identity.application) await loadWorkflow(version)
    if (version !== requestVersion) return
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
  <section v-else class="admission-workflow admission-applicant" aria-labelledby="admission-page-title" :aria-busy="loading">
    <header class="page-header">
      <p class="page-kicker">Admission</p>
      <h1 id="admission-page-title" class="page-title">{{ title }}</h1>
      <p class="page-description">Your application, exam, and next steps in one place.</p>
    </header>
    <div class="admission-home-content">
      <p v-if="loading" class="placeholder-panel panel status-block" role="status">Loading admission information...</p>
      <div v-else-if="failed" class="placeholder-panel panel status-block" role="alert">
        <h2>Unable to load admission information</h2>
        <p>Please try again later.</p>
        <button class="primary" type="button" @click="loadIdentity">Try again</button>
      </div>
      <div v-else-if="!application" class="placeholder-panel panel status-block">
        <h2>No admission application yet</h2>
        <p>No admission application is linked to your account. Start an application when an admission cycle is open.</p>
        <template v-if="auth.currentRole === ROLES.GUEST">
          <button v-if="availability?.allowed" class="primary" type="button" :disabled="submitting" @click="confirming = true">Start Admission Application</button>
          <p v-else-if="availability" role="status">{{ admissionCreationMessage(availability.reason) }}</p>
        </template>
      </div>
      <div v-else>
        <div class="placeholder-panel panel identity-card"><h2>Your application</h2><dl class="admission-details">
          <div><dt>Applicant Number</dt><dd>{{ application.applicant_number }}</dd></div>
          <div><dt>Admission Cycle</dt><dd>{{ application.cycle.name }} <span class="admission-cycle-code">({{ application.cycle.code }})</span></dd></div>
          <div><dt>Application record status</dt><dd><span class="badge">{{ status[0] }}</span></dd></div>
        </dl></div>
        <ol class="progress-steps" aria-label="Admission progress"><li v-for="(step,index) in ['Application','Exam','Review','Result','Recommendation']" :key="step" :aria-current="index === nextStep.stage ? 'step' : undefined" :class="{ current: index === nextStep.stage, complete: index < nextStep.stage }"><span class="step-number" aria-hidden="true">{{ index < nextStep.stage ? '✓' : index + 1 }}</span><span>{{ step }}<small>{{ index < nextStep.stage ? 'Completed' : index === nextStep.stage ? 'Current step' : 'Upcoming' }}</small></span></li></ol>
        <div class="placeholder-panel panel next-step"><h2>Your next step</h2><p role="status">{{ nextStep.text }}</p><router-link v-if="nextStep.label" class="link-button primary" :to="nextStep.to">{{ nextStep.label }}</router-link><button v-else type="button" @click="loadIdentity">Refresh status</button><p v-if="result?.published"><router-link to="/admission/result">View published result</router-link></p></div>
        <details class="placeholder-panel panel"><summary>Application details</summary><dl class="admission-details">
          <div><dt>Exam</dt><dd>{{ exam?.session && !exam.session.exam_completed ? 'In progress' : exam?.attempts_submitted ? exam.attempts_submitted + ' of ' + (exam.max_attempts || 2) + ' attempts submitted' : 'Not started' }}</dd></div>
          <div><dt>Result</dt><dd>{{ workflowFailed ? 'Status unavailable' : result?.published ? (result.result.outcome === 'PASSED' ? 'Published · Passed' : 'Published · Not Passed') : 'Not published' }}</dd></div>
          <div><dt>Created</dt><dd>{{ formatDate(application.created_at) }}</dd></div>
          <div v-if="application.submitted_at"><dt>Submitted</dt><dd>{{ formatDate(application.submitted_at) }}</dd></div>
          <div v-if="application.is_converted && application.converted_at"><dt>Converted</dt><dd>{{ formatDate(application.converted_at) }}</dd></div>
        </dl></details>
      </div>
      <p v-if="creationMessage" class="placeholder-panel panel status-block" role="alert">{{ creationMessage }}</p>
      <AdmissionDialog v-if="confirming" labelledby="admission-confirm-title" :busy="submitting" @cancel="confirming = false">
        <h2 id="admission-confirm-title">Start your admission application?</h2>
        <p>An applicant number will be generated using your existing portal profile. Your application will be saved as a draft.</p>
        <button type="button" :disabled="submitting" @click="confirming = false">Cancel</button>
        <button class="primary" type="button" :disabled="submitting" @click="createApplication">{{ submitting ? 'Creating...' : 'Confirm application' }}</button>
      </AdmissionDialog>
    </div>
  </section>
</template>

<style src="./admission.css"></style>
