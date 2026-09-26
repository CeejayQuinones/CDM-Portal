<script setup>
import AdmissionDialog from './components/AdmissionDialog.vue'
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import { useAuthStore } from '../../stores/authStore'
import { admissionApi, admissionError } from './services/workflowService'
const auth = useAuthStore()
const state = ref(null), session = ref(null), loading = ref(true), busy = ref(false), error = ref(''), saveState = ref(''), confirming = ref(false), conflict = ref(false)
const examRoot = ref(null), questionHeading = ref(null)
let navbarObserver
const position = ref(0), clock = ref(Date.now())
let clockOffset = 0, timer, saveTimer, generation = 0, dirty = false
const question = computed(() => session.value?.questions[position.value])
const seconds = computed(() => session.value ? Math.max(0, Math.ceil((Date.parse(session.value.deadline) - clock.value - clockOffset) / 1000)) : 0)
const time = computed(() => Math.floor(seconds.value / 60) + ':' + String(seconds.value % 60).padStart(2,'0'))
const unanswered = computed(() => Object.values(session.value?.answers || {}).filter(v => !v).length)
const reasons = { application_required: 'Create your Admission application first.', bank_incomplete: 'The entrance exam bank is not ready. Please contact the school.', awaiting_publication: 'Your exam is submitted. Wait for the Registrar to publish your result.', retake_unavailable: 'A retake is not available for your published result.', attempts_exhausted: 'You have used both exam attempts.', historical_read_only: 'Student accounts can view Admission history only.', active_session: 'Resume your saved examination.' }
const owner = () => auth.currentUser?.id
let mountedOwner
function adopt(value) {
  session.value = value
  position.value = value?.position || 0
  if (value) clockOffset = Date.parse(value.server_now) - Date.now()
  dirty = false
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
  if (busy.value) return
  busy.value = true; error.value = ''
  const token = generation
  try {
    const value = await admissionApi('post','exam/start',{})
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
function leave(event) { if (dirty) { event.preventDefault(); event.returnValue = '' } }
onMounted(() => {
  const navbar = examRoot.value?.closest?.('.shell-content')?.querySelector('.navbar')
  if (navbar && typeof ResizeObserver !== 'undefined') {
    navbarObserver = new ResizeObserver(() => examRoot.value?.style.setProperty('--admission-navbar-height', navbar.getBoundingClientRect().height + 'px'))
    navbarObserver.observe(navbar)
  }
  mountedOwner = owner(); load()
  window.addEventListener('beforeunload', leave)
  timer = setInterval(() => {
    clock.value = Date.now()
    if (owner() !== mountedOwner) { generation++; session.value = null; return }
    if (session.value && !session.value.exam_completed && seconds.value === 0 && !busy.value && !loading.value) load()
  }, 1000)
})
onBeforeUnmount(() => { generation++; navbarObserver?.disconnect(); clearInterval(timer); clearTimeout(saveTimer); window.removeEventListener('beforeunload',leave) })
</script>
<template>
  <section ref="examRoot" class="admission-workflow admission-applicant">
    <header class="page-header"><p class="page-kicker"><router-link to="/admission">Admission home</router-link></p><h1 class="page-title">Entrance Exam</h1><p class="page-description">Read each question carefully. Your answers save while connected.</p></header><p v-if="loading" class="placeholder-panel panel status-block" role="status">Loading exam status...</p><p v-if="error" class="placeholder-panel panel status-block" role="alert">{{ error }}</p>
    <button v-if="error || conflict" :disabled="busy" @click="load">Reload saved answers</button>
    <div v-if="session?.exam_completed" class="placeholder-panel panel"><h2>{{ session.expired ? 'Exam expired' : 'Exam submitted' }}</h2><p>Your saved answers have been submitted. Results will appear after Registrar publication.</p><router-link to="/admission/result">View result status</router-link></div>
    <template v-else-if="session && question">
      <div class="placeholder-panel panel exam-status" aria-label="Exam progress">
        <div><strong>Attempt {{ session.attempt_number }} of 2</strong><span class="exam-position">Question {{ position + 1 }} / {{ session.questions.length }}</span></div>
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
      <template v-if="state.eligible"><h2>Exam instructions</h2><p>100 questions, five topics, 120 minutes. The timer continues through refreshes, sign-outs, or a disconnected device.</p>
      <p>Answers save while connected. Keep this page open until the save indicator confirms success. Only answers received before the deadline count. Use one tab at a time.</p>
      <p>Maximum two attempts. A second attempt requires a published first failure.</p></template><h2 v-if="!state.eligible">{{ state.reason === 'awaiting_publication' ? 'Waiting for Registrar Review' : 'Exam status' }}</h2><p>{{ state.attempts_submitted }} submitted attempt(s).</p>
      <p v-if="state.reason">{{ reasons[state.reason] || 'The exam is currently unavailable.' }}</p>
      <button v-if="state.eligible" class="primary" :disabled="busy" @click="start">{{ state.attempts_submitted ? 'Start retake' : 'Start Exam' }}</button>
      <button :disabled="busy" @click="load">Refresh eligibility</button>
    </div>
    <AdmissionDialog v-if="confirming" labelledby="exam-confirm" :busy="busy" @cancel="confirming = false"><h2 id="exam-confirm">Submit this attempt?</h2><p>{{ unanswered }} questions are unanswered. Submission is final.</p><div class="toolbar"><button :disabled="busy" @click="confirming = false">Cancel</button><button :disabled="busy" @click="save(true)">Confirm submission</button></div></AdmissionDialog>
  </section>
</template>
<style src="./admission.css"></style>
