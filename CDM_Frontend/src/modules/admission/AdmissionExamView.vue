<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useAuthStore } from '../../stores/authStore'
import { admissionApi, admissionError } from './services/workflowService'
const auth = useAuthStore()
const state = ref(null), session = ref(null), loading = ref(true), busy = ref(false), error = ref(''), saveState = ref(''), confirming = ref(false), conflict = ref(false)
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
function move(index) { position.value = index; changed() }
function leave(event) { if (dirty) { event.preventDefault(); event.returnValue = '' } }
onMounted(() => {
  mountedOwner = owner(); load()
  window.addEventListener('beforeunload', leave)
  timer = setInterval(() => {
    clock.value = Date.now()
    if (owner() !== mountedOwner) { generation++; session.value = null; return }
    if (session.value && !session.value.exam_completed && seconds.value === 0 && !busy.value && !loading.value) load()
  }, 1000)
})
onBeforeUnmount(() => { generation++; clearInterval(timer); clearTimeout(saveTimer); window.removeEventListener('beforeunload',leave) })
</script>
<template>
  <section class="admission-workflow">
    <p><router-link to="/admission">Admission home</router-link></p><h1>Entrance Exam</h1><p v-if="loading" role="status">Loading exam status...</p><p v-if="error" role="alert">{{ error }}</p>
    <button v-if="error || conflict" :disabled="busy" @click="load">Reload saved answers</button>
    <div v-if="session?.exam_completed" class="panel"><h2>{{ session.expired ? 'Exam expired' : 'Exam submitted' }}</h2><p>Your saved answers have been submitted. Results will appear after Registrar publication.</p><router-link to="/admission/result">View result status</router-link></div>
    <template v-else-if="session && question">
      <div class="panel toolbar exam-status"><strong>Attempt {{ session.attempt_number }} of 2</strong><span class="timer" role="timer">{{ time }} remaining</span><span role="status">{{ saveState }}</span><span>{{ session.questions.length - unanswered }} answered · {{ unanswered }} unanswered</span></div>
      <div class="panel">
        <p>{{ question.topic }} · Question {{ position + 1 }} of {{ session.questions.length }}</p><h2>{{ question.question_text }}</h2>
        <label v-for="(text,key) in question.options" :key="key" class="answer" :class="{ answered: session.answers[question.id] === key }"><input v-model="session.answers[question.id]" type="radio" :name="'question-'+question.id" :value="key" :disabled="busy || conflict || seconds === 0" @change="changed">{{ key }}. {{ text }}</label>
        <div class="toolbar"><button :disabled="busy || conflict || position === 0" @click="move(position-1)">Previous</button><button :disabled="busy || conflict || position === session.questions.length - 1" @click="move(position+1)">Next</button><button :disabled="busy || conflict" @click="save(false)">Save answers</button></div>
      </div>
      <div class="panel submit-panel"><h2>Finished reviewing?</h2><p>Check unanswered questions before submitting. You cannot change answers afterward.</p><button class="primary" :disabled="busy || conflict" @click="confirming = true">Submit exam</button></div>
      <details class="panel"><summary>Question navigation — shaded questions are answered</summary><div class="question-nav"><button v-for="(q,index) in session.questions" :key="q.id" :class="{ answered: session.answers[q.id], selected: index === position }" :disabled="busy || conflict" :aria-label="'Question '+(index+1)+(session.answers[q.id] ? ', answered' : ', unanswered')" @click="move(index)">{{ index+1 }}</button></div></details>
    </template>
    <div v-else-if="state && !loading" class="panel">
      <template v-if="state.eligible"><h2>Exam instructions</h2><p>100 questions, five topics, 120 minutes. The timer continues through refreshes, sign-outs, or a disconnected device.</p>
      <p>Answers save while connected. Keep this page open until the save indicator confirms success. Only answers received before the deadline count. Use one tab at a time.</p>
      <p>Maximum two attempts. A second attempt requires a published first failure.</p></template><h2 v-if="!state.eligible">{{ state.reason === 'awaiting_publication' ? 'Waiting for Registrar Review' : 'Exam status' }}</h2><p>{{ state.attempts_submitted }} submitted attempt(s).</p>
      <p v-if="state.reason">{{ reasons[state.reason] || 'The exam is currently unavailable.' }}</p>
      <button v-if="state.eligible" class="primary" :disabled="busy" @click="start">{{ state.attempts_submitted ? 'Start retake' : 'Start Exam' }}</button>
      <button :disabled="busy" @click="load">Refresh eligibility</button>
    </div>
    <dialog v-if="confirming" open aria-labelledby="exam-confirm" @cancel.prevent="!busy && (confirming = false)"><h2 id="exam-confirm">Submit this attempt?</h2><p>{{ unanswered }} questions are unanswered. Submission is final.</p><div class="toolbar"><button :disabled="busy" @click="confirming = false">Cancel</button><button :disabled="busy" @click="save(true)">Confirm submission</button></div></dialog>
  </section>
</template>
<style src="./admission.css"></style>
