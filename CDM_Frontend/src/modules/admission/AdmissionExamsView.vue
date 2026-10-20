<script setup>
import AdmissionStaffWorkspace from './components/AdmissionStaffWorkspace.vue'
import AdmissionDialog from './components/AdmissionDialog.vue'
import { computed, onMounted, ref } from 'vue'
import AdmissionAdminView from './AdmissionAdminView.vue'
import { admissionApi, admissionError, topics } from './services/workflowService'
const exams = ref([]), selected = ref(''), form = ref(null), busy = ref(false), error = ref(''), saved = ref('')
const current = computed(() => exams.value.find(e => e.cycle_id === Number(selected.value)))
const policy = computed(() => current.value?.policy || null)
const total = computed(() => Object.values((form.value || policy.value)?.category_counts || {}).reduce((sum, n) => sum + Number(n || 0), 0))
function edit() { form.value = current.value ? JSON.parse(JSON.stringify({ ...current.value.policy, version: current.value.version })) : null; saved.value = '' }
async function load() {
  busy.value = true; error.value = ''
  try { exams.value = (await admissionApi('get', 'admin/exams')).exams; selected.value ||= exams.value[0]?.cycle_id || ''; form.value = null }
  catch (e) { error.value = admissionError(e) }
  finally { busy.value = false }
}
async function save() {
  if (busy.value) return
  busy.value = true; error.value = ''; saved.value = ''
  try { await admissionApi('put', 'admin/exams/' + selected.value, form.value); await load(); saved.value = 'Exam configuration saved. Existing attempts and retakes retain their original policy.' }
  catch (e) { error.value = admissionError(e) }
  finally { busy.value = false }
}
onMounted(load)
</script>
<template>
<AdmissionStaffWorkspace title="Exams" description="One general entrance exam per cycle. Every program uses the same general question bank.">
  <p v-if="error" role="alert" class="placeholder-panel panel">{{ error }}</p><p v-if="saved" role="status" class="placeholder-panel panel">{{ saved }}</p>
  <div class="admission-search-row exam-cycle-row"><label>Admission cycle<select v-model="selected" :disabled="busy" @change="form = null"><option v-for="exam in exams" :key="exam.cycle_id" :value="exam.cycle_id">{{ exam.cycle }} · {{ exam.cycle_status }}</option></select></label><button :disabled="busy" @click="load">Reload</button></div>
  <p v-if="!busy && !exams.length" class="placeholder-panel panel">Create an Admission cycle below to configure its exam.</p>
  <section v-if="policy" class="placeholder-panel panel exam-summary"><div class="card-heading"><div><p class="page-kicker">General entrance exam</p><h2>{{ policy.title }}</h2></div><span class="badge" :class="{ positive: policy.status === 'active' }">{{ policy.status }}</span></div><dl class="exam-policy-facts"><div><dt>Duration</dt><dd>{{ policy.duration_minutes }} min</dd></div><div><dt>Passing score</dt><dd>{{ policy.passing_score }}%</dd></div><div><dt>Maximum attempts</dt><dd>{{ policy.max_attempts }}</dd></div></dl><button class="primary" type="button" :disabled="busy" @click="edit">Edit Exam Settings</button><div class="exam-requirements-summary"><h3>Question Requirement Summary</h3><div class="requirement-list"><p v-for="topic in topics" :key="topic"><span>{{ topic }}</span><strong>{{ policy.category_counts[topic] }}</strong></p></div><p>Total: <strong>{{ total }} questions</strong> · <span class="badge" :class="{ positive: current?.readiness.ready }">{{ current?.readiness.ready ? 'READY' : 'NOT READY' }}</span></p></div></section>
  <AdmissionDialog v-if="form" labelledby="exam-settings-title" :busy="busy" wide @cancel="form = null"><form class="admission-editor" @submit.prevent="save">
    <header class="admission-dialog-header"><div><p class="page-kicker">General entrance exam</p><h2 id="exam-settings-title">Edit Exam Settings</h2></div><button type="button" :disabled="busy" aria-label="Close exam settings" @click="form = null">Close</button></header><div class="admission-dialog-body">
    <div class="grid"><label>Exam title<input v-model="form.title" required maxlength="255"></label><label>Status<select v-model="form.status"><option>draft</option><option>active</option><option>inactive</option></select></label><label>Duration (minutes)<input v-model.number="form.duration_minutes" required type="number" min="1" max="240"></label><label>Passing score (%)<input v-model.number="form.passing_score" required type="number" min="1" max="100"></label><label>Maximum attempts<input v-model.number="form.max_attempts" required type="number" min="1" max="2"></label></div>
    <h2>Required questions</h2><div class="grid"><label v-for="topic in topics" :key="topic">{{ topic }}<input v-model.number="form.category_counts[topic]" required type="number" min="1" max="100"><small>{{ current?.readiness.counts[topic] || 0 }} active questions available</small></label></div>
    <p>Total: <strong>{{ total }} questions</strong> · <span class="badge">{{ topics.every(t => (current?.readiness.counts[t] || 0) >= form.category_counts[t]) ? 'READY' : 'NOT READY' }}</span></p>
    <p class="muted">Readiness requires enough active questions in every category. Draft and inactive exams cannot start. Changes apply to new applicants’ first attempts; saved attempts and their retakes retain their original rules. No third attempt is allowed.</p>
    <div class="toolbar"><button type="button" :disabled="busy" @click="form = null">Cancel</button><button class="primary" :disabled="busy">Save exam configuration</button></div>
    </div></form></AdmissionDialog>
  <details class="placeholder-panel panel"><summary>Manage Admission cycles</summary><AdmissionAdminView title="Admission cycles" mode="cycles" embedded @saved="load" /></details>
</AdmissionStaffWorkspace>
</template>
<style src="./admission.css"></style>
