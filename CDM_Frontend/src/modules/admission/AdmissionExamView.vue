<script setup>
import AdmissionDialog from './components/AdmissionDialog.vue'
import AdmissionExamRules from './components/AdmissionExamRules.vue'
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import { onBeforeRouteLeave } from 'vue-router'
import { useAuthStore } from '../../stores/authStore'
import { admissionApi, admissionError } from './services/workflowService'
import { createExamVisibilityGuard } from './examVisibilityGuard'
const auth = useAuthStore()
const state = ref(null), session = ref(null), loading = ref(true), busy = ref(false), error = ref(''), saveState = ref(''), confirming = ref(false), conflict = ref(false)
const rulesAcknowledged = ref(false), focusWarning = ref(''), interruptionDialog = ref(false)
const examRoot = ref(null), questionHeading = ref(null)
let navbarObserver, visibilityGuard
const position = ref(0), clock = ref(Date.now())
let clockOffset = 0, timer, saveTimer, generation = 0, dirty = false, deadlineRefreshSession = null
const question = computed(() => session.value?.questions[position.value])
const seconds = computed(() => session.value ? Math.max(0, Math.ceil((Date.parse(session.value.deadline) - clock.value - clockOffset) / 1000)) : 0)
const time = computed(() => Math.floor(seconds.value / 60) + ':' + String(seconds.value % 60).padStart(2,'0'))
const unanswered = computed(() => Object.values(session.value?.answers || {}).filter(v => !v).length)
const reasons = { application_required: 'Create your Admission application first.', bank_incomplete: 'The entrance exam bank is not ready. Please contact the school.', awaiting_publication: 'Your exam is submitted. Wait for the Registrar to publish your result.', retake_unavailable: 'A retake is not available for your published result.', attempts_exhausted: 'You have used all permitted exam attempts.', historical_read_only: 'Student accounts can view Admission history only.', active_session: 'Resume your saved examination.', unsupported_client: 'Examinations can only be taken through the CDM Portal website on a supported desktop or laptop.' }
const owner = () => auth.currentUser?.id
let mountedOwner
function adopt(value) {
  session.value = value
  position.value = value?.position || 0
  if (value) clockOffset = Date.parse(value.server_now) - Date.now()
  dirty = false
  if (!value || value.exam_completed) {
    interruptionDialog.value = false
    visibilityGuard?.reset()
  }
}
async function load() {
  const token = ++generation
  loading.value = true; error.value = ''; conflict.value = false
  try {
    const value = await admissionApi('get','exam')
    if (token !== generation || owner() !== mountedOwner) return
    state.value = value; adopt(value.session)
    saveState.value = value.session ? 'Saved answers loaded' : ''
  } catch (e) { if (token === generation) error.value = admissionError(e) }
  finally { if (token === generation) loading.value = false }
}
async function start() {
  if (busy.value || !rulesAcknowledged.value) return
  busy.value = true; error.value = ''
  const token = generation
  try {
    const value = await admissionApi('post','exam/start',{ rules_acknowledged: true })
    if (token === generation && owner() === mountedOwner) { adopt(value); saveState.value = 'All changes saved' }
  } catch (e) { error.value = admissionError(e) }
  finally { busy.value = false }
}
function changed() {
  dirty = true; saveState.value = 'Unsaved changes'
  clearTimeout(saveTimer)
  saveTimer = setTimeout(() => save(false), 400)
}
async function save(submit = false) {
  if (busy.value || conflict.value || !session.value || session.value.exam_completed) return
  if (!submit && !dirty) return
  busy.value = true; error.value = ''; saveState.value = 'Saving...'
  clearTimeout(saveTimer)
  const token = generation
  try {
    const value = await admissionApi(submit ? 'post' : 'put', 'exam/' + session.value.session_id + (submit ? '/submit' : '/answers'), { revision: session.value.revision, answers: { ...session.value.answers }, position: position.value })
    if (token !== generation || owner() !== mountedOwner) return
    adopt(value); confirming.value = false
    saveState.value = value.expired ? 'Deadline reached. Late changes were not accepted.' : 'All changes saved'
  } catch (e) {
    if (token !== generation) return
    error.value = admissionError(e); saveState.value = 'Not saved — reconnect and retry before the deadline'
    if (e?.response?.status === 409) conflict.value = true
  } finally { busy.value = false }
}
function move(index) {
  position.value = index; changed()
  nextTick(() => { questionHeading.value?.focus?.({ preventScroll: true }); questionHeading.value?.scrollIntoView?.({ block: 'center', behavior: window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth' }) })
}
function leave(event) { if (activeAttempt()) { event.preventDefault(); event.returnValue = '' } }
function activeAttempt() { return Boolean(session.value && !session.value.exam_completed) }
function observeInterruption(kind) {
  if (!activeAttempt()) return
  if (kind === 'blur') {
    focusWarning.value = 'The examination window lost focus. This event alone does not end your attempt; remain in the examination workspace.'
  }
  if (dirty && !busy.value) save(false)
}
function returnedToVisibleExam() {
  if (!activeAttempt()) return
  focusWarning.value = ''
  interruptionDialog.value = true
}
function acknowledgeInterruption() {
  interruptionDialog.value = false
  nextTick(() => questionHeading.value?.focus?.({ preventScroll: true }))
}
function visibilityChanged() { visibilityGuard?.visibilityChanged(document.visibilityState) }
function windowBlurred() { visibilityGuard?.windowBlurred() }
onBeforeRouteLeave(() => {
  if (!activeAttempt()) return true
  if (dirty && !busy.value) save(false)
  return window.confirm('Leave the examination page? The server timer will continue and only server-confirmed answers are preserved.')
})
onMounted(() => {
  const navbar = examRoot.value?.closest?.('.shell-content')?.querySelector('.navbar')
  if (navbar && typeof ResizeObserver !== 'undefined') {
    navbarObserver = new ResizeObserver(() => examRoot.value?.style.setProperty('--admission-navbar-height', navbar.getBoundingClientRect().height + 'px'))
    navbarObserver.observe(navbar)
  }
  mountedOwner = owner(); load()
  visibilityGuard = createExamVisibilityGuard({
    isActive: activeAttempt,
    onHidden: () => observeInterruption('hidden'),
    onReturned: returnedToVisibleExam,
    onBlur: () => observeInterruption('blur'),
  })
  window.addEventListener('beforeunload', leave)
  window.addEventListener('blur', windowBlurred)
  document.addEventListener?.('visibilitychange', visibilityChanged)
  timer = setInterval(() => {
    clock.value = Date.now()
    if (owner() !== mountedOwner) { generation++; session.value = null; return }
    if (session.value && !session.value.exam_completed && seconds.value === 0 && !busy.value && !loading.value
      && deadlineRefreshSession !== session.value.session_id) {
      deadlineRefreshSession = session.value.session_id
      load()
    }
  }, 1000)
})
onBeforeUnmount(() => { generation++; navbarObserver?.disconnect(); visibilityGuard?.reset(); clearInterval(timer); clearTimeout(saveTimer); window.removeEventListener('beforeunload',leave); window.removeEventListener('blur', windowBlurred); document.removeEventListener?.('visibilitychange', visibilityChanged) })
</script>
<template>
  <section ref="examRoot" class="admission-workflow admission-applicant">
    <header class="page-header"><p class="page-kicker"><router-link to="/admission">Admission home</router-link></p><h1 class="page-title">Entrance Exam</h1><p class="page-description">Read each question carefully. Your answers save while connected.</p></header><p v-if="loading" class="placeholder-panel panel status-block" role="status">Loading exam status...</p><p v-if="error" class="placeholder-panel panel status-block" role="alert">{{ error }}</p>
    <button v-if="error || conflict" :disabled="busy" @click="load">Reload saved answers</button>
    <div v-if="session?.exam_completed" class="placeholder-panel panel"><h2>{{ session.expired ? 'Exam expired' : 'Exam submitted' }}</h2><p>Your saved answers have been submitted. Results will appear after Registrar publication.</p><router-link to="/admission/result">View result status</router-link></div>
    <template v-else-if="session && question">
      <p v-if="focusWarning" class="exam-interruption-warning" role="alert">{{ focusWarning }}</p>
      <div class="placeholder-panel panel exam-status" aria-label="Exam progress">
        <div><strong>Attempt {{ session.attempt_number }} of {{ session.max_attempts }}</strong><span class="exam-position">Question {{ position + 1 }} / {{ session.questions.length }}</span></div>
        <span class="timer" role="timer" aria-label="Time remaining">{{ time }} <small>remaining</small></span>
        <div class="exam-save"><span :key="saveState" class="save-message" role="status">{{ saveState }}</span><span>{{ session.questions.length - unanswered }} answered · {{ unanswered }} unanswered</span></div>
      </div>
      <div class="exam-layout">
        <div class="exam-main">
          <div class="placeholder-panel panel question-card">
            <div :key="question.id" class="question-content">
              <p class="page-kicker">{{ question.topic }} · Question {{ position + 1 }} of {{ session.questions.length }}</p>
              <h2 ref="questionHeading" tabindex="-1" id="exam-question">{{ question.question_text }}</h2>
              <fieldset class="answer-options" aria-labelledby="exam-question"><legend class="visually-hidden">Choose one answer</legend>
                <label v-for="(text,key) in question.options" :key="key" class="answer" :class="{ answered: session.answers[question.id] === key, 'answer-disabled': busy || conflict || seconds === 0 }"><input v-model="session.answers[question.id]" type="radio" :name="'question-'+question.id" :value="key" :disabled="busy || conflict || seconds === 0" @change="changed"><span><strong>{{ key }}.</strong> {{ text }}</span></label>
              </fieldset>
            </div>
          </div>
          <nav class="placeholder-panel panel exam-controls" aria-label="Exam question controls"><button :disabled="busy || conflict || position === 0" @click="move(position-1)">Previous</button><button class="primary" :disabled="busy || conflict || position === session.questions.length - 1" @click="move(position+1)">Next</button><button :disabled="busy || conflict" @click="save(false)">Save answers</button></nav>
        </div>
        <aside class="exam-sidebar" aria-label="Question navigator and submission">
          <details class="placeholder-panel panel question-navigator"><summary>Question navigation — shaded questions are answered</summary><div class="question-nav"><button v-for="(q,index) in session.questions" :key="q.id" :class="{ answered: session.answers[q.id], selected: index === position }" :disabled="busy || conflict" :aria-current="index === position ? 'step' : undefined" :aria-label="'Question '+(index+1)+(session.answers[q.id] ? ', answered' : ', unanswered')" @click="move(index)">{{ index+1 }}</button></div></details>
          <div class="placeholder-panel panel submit-panel"><h2>Finished reviewing?</h2><p>Check unanswered questions before submitting. You cannot change answers afterward.</p><button :disabled="busy || conflict" @click="confirming = true">Submit exam</button></div>
        </aside>
      </div>
    </template>
    <div v-else-if="state && !loading" class="placeholder-panel panel">
      <template v-if="state.eligible"><h2>Exam instructions</h2><p>{{ state.question_count }} questions, five topics, {{ state.time_limit }} minutes. The timer continues through refreshes, sign-outs, or a disconnected device.</p>
      <AdmissionExamRules v-model:acknowledged="rulesAcknowledged" :disabled="busy" /></template><h2 v-if="!state.eligible">{{ state.reason === 'awaiting_publication' ? 'Waiting for Registrar Review' : 'Exam status' }}</h2><p>{{ state.attempts_submitted }} submitted attempt(s).</p>
      <p v-if="state.reason">{{ reasons[state.reason] || 'The exam is currently unavailable.' }}</p>
      <button v-if="state.eligible" class="primary" :disabled="busy || !rulesAcknowledged" @click="start">{{ state.attempts_submitted ? 'Start final retake' : 'Start Exam' }}</button>
      <button :disabled="busy" @click="load">Refresh eligibility</button>
    </div>
    <AdmissionDialog v-if="confirming" labelledby="exam-confirm" :busy="busy" @cancel="confirming = false"><h2 id="exam-confirm">Submit this attempt?</h2><p>{{ unanswered }} questions are unanswered. Submission is final.</p><div class="toolbar"><button :disabled="busy" @click="confirming = false">Cancel</button><button :disabled="busy" @click="save(true)">Confirm submission</button></div></AdmissionDialog>
    <AdmissionDialog v-if="interruptionDialog" labelledby="exam-interruption-title" describedby="exam-interruption-description" :dismissible="false">
      <section class="exam-interruption-dialog">
        <span class="exam-interruption-icon" aria-hidden="true">!</span>
        <div>
          <p class="page-kicker">Examination integrity reminder</p>
          <h2 id="exam-interruption-title">The examination tab was left or hidden</h2>
        </div>
        <div id="exam-interruption-description" class="exam-interruption-copy">
          <p>The portal detected that this examination tab was hidden. This can happen after switching tabs, minimizing the browser, or restoring the window.</p>
          <p><strong>Repeated interruptions may be reviewed by authorized school staff according to approved examination policy.</strong> This warning alone does not end or submit your attempt.</p>
          <p class="exam-interruption-state">Your answered questions remain in place. The server timer continued, and only server-confirmed answers are preserved. {{ saveState || 'Autosave status is unchanged.' }}</p>
        </div>
        <button class="primary" type="button" @click="acknowledgeInterruption">Acknowledge and return to examination</button>
      </section>
    </AdmissionDialog>
  </section>
</template>
<style src="./admission.css"></style>
