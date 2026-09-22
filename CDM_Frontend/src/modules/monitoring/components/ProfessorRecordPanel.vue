<script setup>
import { reactive, ref, watch } from 'vue'
import {
  createPerformanceRecord,
  fetchPerformanceRecords,
  generateRecordStudyPlan,
  sendRecordStudyPlan,
} from '../services/monitoringApi'

const props = defineProps({
  studentId: { type: Number, required: true },
  subjects: { type: Array, default: () => [] },
})

const emit = defineEmits(['sent'])

const records = ref([])
const loading = ref(false)
const saving = ref(false)
const generatingId = ref(null)
const sendingId = ref(null)
const error = ref('')
const notice = ref('')
const draftPlans = reactive({})

const form = reactive({
  subject_code: '',
  subject_name: '',
  assessment_name: '',
  topic: '',
  score: '',
  max_score: '',
  notes: '',
  attachment: null,
})

watch(
  () => props.studentId,
  () => {
    resetForm()
    loadRecords()
  },
  { immediate: true },
)

watch(
  () => props.subjects,
  (list) => {
    if (!form.subject_code && list?.[0]) {
      form.subject_code = list[0].subject_code || ''
      form.subject_name = list[0].subject_name || ''
    }
  },
  { immediate: true },
)

function resetForm() {
  form.subject_code = props.subjects?.[0]?.subject_code || ''
  form.subject_name = props.subjects?.[0]?.subject_name || ''
  form.assessment_name = ''
  form.topic = ''
  form.score = ''
  form.max_score = ''
  form.notes = ''
  form.attachment = null
  error.value = ''
  notice.value = ''
}

function onSubjectChange() {
  const match = props.subjects.find((s) => s.subject_code === form.subject_code)
  form.subject_name = match?.subject_name || form.subject_name
}

function onFileChange(event) {
  form.attachment = event.target.files?.[0] || null
}

async function loadRecords() {
  if (!props.studentId) return
  loading.value = true
  error.value = ''
  try {
    const payload = await fetchPerformanceRecords(props.studentId)
    records.value = payload.records || []
  } catch (err) {
    error.value = err.response?.data?.message || 'Unable to load performance records.'
  } finally {
    loading.value = false
  }
}

async function saveRecord() {
  if (saving.value) return
  saving.value = true
  error.value = ''
  notice.value = ''
  try {
    const body = new FormData()
    body.append('subject_code', form.subject_code)
    if (form.subject_name) body.append('subject_name', form.subject_name)
    body.append('assessment_name', form.assessment_name)
    body.append('topic', form.topic)
    if (form.score !== '') body.append('score', form.score)
    if (form.max_score !== '') body.append('max_score', form.max_score)
    if (form.notes) body.append('notes', form.notes)
    if (form.attachment) body.append('attachment', form.attachment)

    await createPerformanceRecord(props.studentId, body)
    notice.value = 'Performance record saved.'
    resetForm()
    await loadRecords()
  } catch (err) {
    error.value = err.response?.data?.message || 'Unable to save the performance record.'
  } finally {
    saving.value = false
  }
}

async function generatePlan(record) {
  generatingId.value = record.id
  error.value = ''
  notice.value = ''
  try {
    const plan = await generateRecordStudyPlan(props.studentId, record.id)
    draftPlans[record.id] = {
      title: plan.title,
      plan_body: plan.plan_body,
    }
    notice.value = 'Study plan drafted from this record. Review it, then send to the student.'
  } catch (err) {
    error.value = err.response?.data?.message || 'Unable to generate a study plan.'
  } finally {
    generatingId.value = null
  }
}

async function sendPlan(record) {
  const draft = draftPlans[record.id]
  if (!draft?.plan_body) return
  sendingId.value = record.id
  error.value = ''
  notice.value = ''
  try {
    await sendRecordStudyPlan(props.studentId, record.id, draft)
    notice.value = 'Study plan sent to the student account.'
    emit('sent')
  } catch (err) {
    error.value = err.response?.data?.message || 'Unable to send the study plan.'
  } finally {
    sendingId.value = null
  }
}
</script>

<template>
  <section class="record-panel">
    <header>
      <div>
        <p class="kicker">Instructor records</p>
        <h3>Quiz / topic interventions</h3>
      </div>
    </header>
    <p class="lead">
      Log a weak assessment (example: Quiz 2 on loops). Gemini uses that topic to draft a study plan you can send to the
      student.
    </p>

    <form class="record-form" @submit.prevent="saveRecord">
      <label>
        Subject
        <select v-model="form.subject_code" required @change="onSubjectChange">
          <option disabled value="">Select subject</option>
          <option v-for="subject in subjects" :key="subject.subject_code" :value="subject.subject_code">
            {{ subject.subject_code }} · {{ subject.subject_name }}
          </option>
          <option v-if="!subjects.length" value="GEN">GEN · General</option>
        </select>
      </label>
      <label>
        Assessment
        <input v-model="form.assessment_name" required maxlength="120" placeholder="Quiz 2" />
      </label>
      <label class="wide">
        Weak topic
        <input v-model="form.topic" required maxlength="255" placeholder="Nested loops / control structures" />
      </label>
      <label>
        Score
        <input v-model="form.score" type="number" min="0" step="0.01" placeholder="12" />
      </label>
      <label>
        Max score
        <input v-model="form.max_score" type="number" min="0" step="0.01" placeholder="20" />
      </label>
      <label class="wide">
        Notes
        <textarea v-model="form.notes" rows="2" maxlength="2000" placeholder="Student struggled with nested loop tracing." />
      </label>
      <label class="wide">
        Optional file
        <input type="file" @change="onFileChange" />
      </label>
      <button type="submit" class="primary" :disabled="saving">
        {{ saving ? 'Saving…' : 'Save record' }}
      </button>
    </form>

    <p v-if="error" class="error" role="alert">{{ error }}</p>
    <p v-if="notice" class="notice">{{ notice }}</p>
    <p v-if="loading" class="muted">Loading records…</p>

    <div v-else class="record-list">
      <article v-for="record in records" :key="record.id" class="record-card">
        <header>
          <div>
            <strong>{{ record.assessment_name }}</strong>
            <span>{{ record.subject_code }} · {{ record.topic }}</span>
          </div>
          <em v-if="record.score != null">{{ record.score }}{{ record.max_score != null ? ` / ${record.max_score}` : '' }}</em>
        </header>
        <p v-if="record.notes">{{ record.notes }}</p>
        <p v-if="record.has_attachment" class="muted">Attachment: {{ record.attachment_name }}</p>
        <div class="actions">
          <button type="button" :disabled="generatingId === record.id" @click="generatePlan(record)">
            {{ generatingId === record.id ? 'Generating…' : 'Generate AI plan' }}
          </button>
          <button
            type="button"
            class="primary"
            :disabled="!draftPlans[record.id] || sendingId === record.id"
            @click="sendPlan(record)"
          >
            {{ sendingId === record.id ? 'Sending…' : 'Send to student' }}
          </button>
        </div>
        <textarea
          v-if="draftPlans[record.id]"
          v-model="draftPlans[record.id].plan_body"
          rows="8"
          aria-label="Draft study plan"
        />
      </article>
      <p v-if="!records.length" class="muted">No intervention records yet for this student.</p>
    </div>
  </section>
</template>

<style scoped>
.record-panel {
  --ink: #1a2b22;
  --muted: #5f6b64;
  --border: #d4ddd7;
  --soft: #f3f6f4;
  --accent: #0f6b3d;
  background: #fff;
  border: 1px solid var(--border);
  border-radius: 12px;
  display: grid;
  gap: 12px;
  padding: 16px;
}

.kicker {
  color: var(--accent);
  font-size: 0.7rem;
  font-weight: 700;
  letter-spacing: 0.08em;
  margin: 0 0 2px;
  text-transform: uppercase;
}

h3 {
  color: var(--ink);
  font-size: 1rem;
  margin: 0;
}

.lead,
.muted,
.error,
.notice {
  margin: 0;
}

.lead,
.muted {
  color: var(--muted);
  font-size: 0.9rem;
  line-height: 1.5;
}

.error {
  color: #9f2d25;
}

.notice {
  background: #eef5f0;
  border: 1px solid #c9dbcf;
  border-radius: 8px;
  color: var(--ink);
  padding: 8px 10px;
}

.record-form {
  display: grid;
  gap: 10px;
  grid-template-columns: repeat(2, minmax(0, 1fr));
}

.record-form label {
  color: var(--ink);
  display: grid;
  font-size: 0.82rem;
  font-weight: 650;
  gap: 5px;
}

.record-form .wide,
.record-form button {
  grid-column: 1 / -1;
}

input,
select,
textarea {
  border: 1px solid var(--border);
  border-radius: 8px;
  font: inherit;
  font-weight: 400;
  padding: 9px 10px;
}

.actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

button {
  background: #fff;
  border: 1px solid var(--border);
  border-radius: 8px;
  color: var(--accent);
  cursor: pointer;
  font-weight: 700;
  min-height: 36px;
  padding: 0 12px;
}

button.primary {
  background: #0f6b3d;
  border-color: #0c5732;
  color: #fff;
}

button:disabled {
  cursor: not-allowed;
  opacity: 0.55;
}

.record-list {
  display: grid;
  gap: 10px;
}

.record-card {
  background: var(--soft);
  border: 1px solid var(--border);
  border-radius: 10px;
  display: grid;
  gap: 8px;
  padding: 12px;
}

.record-card header {
  display: flex;
  gap: 10px;
  justify-content: space-between;
}

.record-card strong,
.record-card em {
  color: var(--ink);
}

.record-card span,
.record-card p {
  color: var(--muted);
  display: block;
  font-size: 0.86rem;
  margin: 2px 0 0;
}

.record-card textarea {
  background: #fff;
  width: 100%;
}

@media (max-width: 720px) {
  .record-form {
    grid-template-columns: 1fr;
  }
}
</style>
