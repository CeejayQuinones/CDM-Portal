<script setup>
import { reactive, ref, watch } from 'vue'
import { generateGradeStudyPlan, sendGradeStudyPlan } from '../services/monitoringApi'

const props = defineProps({
  studentId: { type: Number, required: true },
  subjects: { type: Array, default: () => [] },
  viewerRole: { type: String, default: 'professor' }, // professor | staff
})

const emit = defineEmits(['sent'])

const generatingCode = ref('')
const sendingCode = ref('')
const error = ref('')
const notice = ref('')
const draftPlans = reactive({})

watch(
  () => props.studentId,
  () => {
    error.value = ''
    notice.value = ''
    Object.keys(draftPlans).forEach((key) => delete draftPlans[key])
  },
)

function periodText(subject) {
  const periods = subject.periods || {}
  const show = (value) => (value == null || value === '' ? '—' : value)
  return `Prelim ${show(periods.Prelim)} · Midterm ${show(periods.Midterm)} · Final ${show(periods.Final)}`
}

async function generatePlan(subject) {
  generatingCode.value = subject.subject_code
  error.value = ''
  notice.value = ''
  try {
    const plan = await generateGradeStudyPlan(props.studentId, subject.subject_code)
    draftPlans[subject.subject_code] = {
      title: plan.title,
      plan_body: plan.plan_body,
    }
    notice.value = 'Study plan drafted from this subject’s approved grades. Review it, then send to the student.'
  } catch (err) {
    error.value = err.response?.data?.message || 'Unable to generate a study plan.'
  } finally {
    generatingCode.value = ''
  }
}

async function sendPlan(subject) {
  const draft = draftPlans[subject.subject_code]
  if (!draft?.plan_body) return
  sendingCode.value = subject.subject_code
  error.value = ''
  notice.value = ''
  try {
    await sendGradeStudyPlan(props.studentId, {
      subject_code: subject.subject_code,
      title: draft.title,
      plan_body: draft.plan_body,
    })
    notice.value = 'Study plan sent to the student account.'
    emit('sent')
  } catch (err) {
    error.value = err.response?.data?.message || 'Unable to send the study plan.'
  } finally {
    sendingCode.value = ''
  }
}
</script>

<template>
  <section class="record-panel">
    <header>
      <div>
        <p class="kicker">Academic record</p>
        <h3>Approved grades</h3>
      </div>
    </header>
    <p class="lead">
      These cards are the student’s approved prelim, midterm, and final grades. Study files and sample quizzes are not listed here.
    </p>

    <p v-if="error" class="error" role="alert">{{ error }}</p>
    <p v-if="notice" class="notice">{{ notice }}</p>

    <div class="record-list">
      <article v-for="subject in subjects" :key="subject.subject_code" class="record-card">
        <header>
          <div>
            <strong>{{ subject.subject_code }}</strong>
            <span>{{ subject.subject_name }}</span>
          </div>
          <em v-if="subject.average_grade != null">{{ subject.average_grade }} / 100</em>
        </header>
        <p>{{ periodText(subject) }}</p>
        <p class="muted">{{ subject.risk_label }} · {{ subject.trend }}</p>
        <div class="actions">
          <button type="button" :disabled="generatingCode === subject.subject_code" @click="generatePlan(subject)">
            {{ generatingCode === subject.subject_code ? 'Generating…' : 'Generate AI plan' }}
          </button>
          <button
            v-if="viewerRole === 'professor'"
            type="button"
            class="primary"
            :disabled="!draftPlans[subject.subject_code] || sendingCode === subject.subject_code"
            @click="sendPlan(subject)"
          >
            {{ sendingCode === subject.subject_code ? 'Sending…' : 'Send to student' }}
          </button>
        </div>
        <textarea
          v-if="draftPlans[subject.subject_code]"
          v-model="draftPlans[subject.subject_code].plan_body"
          rows="8"
          aria-label="Draft study plan"
        />
      </article>
      <p v-if="!subjects.length" class="muted">No approved grades yet for this student.</p>
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
