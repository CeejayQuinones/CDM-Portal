<script setup>
import { onMounted, ref } from 'vue'
import { approveAllSubmittedGrades, approveSubmittedGrade, fetchSubmittedGrades } from '../services/monitoringApi'

const loading = ref(true)
const approvingId = ref(null)
const approvingAll = ref(false)
const error = ref('')
const notice = ref('')
const grades = ref([])
const canApprove = ref(false)

async function load() {
  loading.value = true
  error.value = ''
  try {
    const payload = await fetchSubmittedGrades()
    grades.value = payload.grades || []
    canApprove.value = Boolean(payload.can_approve)
  } catch (err) {
    error.value = err.response?.data?.message || 'Unable to load grades waiting for approval.'
  } finally {
    loading.value = false
  }
}

async function approveAll() {
  approvingAll.value = true
  error.value = ''
  notice.value = ''
  try {
    const payload = await approveAllSubmittedGrades()
    const count = payload.approved || 0
    grades.value = []
    notice.value = count
      ? `${count} grade${count === 1 ? '' : 's'} now on the official record. Monitoring can use them.`
      : 'No submitted grades were waiting.'
  } catch (err) {
    error.value = err.response?.data?.message || 'Unable to approve the waiting grades.'
  } finally {
    approvingAll.value = false
  }
}

async function approve(grade) {
  approvingId.value = grade.id
  error.value = ''
  notice.value = ''
  try {
    await approveSubmittedGrade(grade.id)
    grades.value = grades.value.filter((row) => row.id !== grade.id)
    notice.value = `${grade.subject_code || 'That grade'} is now on the official record.`
  } catch (err) {
    error.value = err.response?.data?.message || 'Unable to approve that grade.'
  } finally {
    approvingId.value = null
  }
}

onMounted(load)
</script>

<template>
  <section class="queue">
    <header>
      <p class="kicker">Registrar record</p>
      <h3>Grades waiting for approval</h3>
    </header>
    <p class="lead">
      A professor submits the midterm or final. Check the student, subject, and period, then approve that grade
      or approve every grade waiting in this list. Until then, monitoring does not use it.
    </p>
    <button
      v-if="canApprove && grades.length"
      type="button"
      class="all"
      :disabled="approvingAll"
      @click="approveAll"
    >
      {{ approvingAll ? 'Approving…' : 'Approve all' }}
    </button>
    <p v-if="error" class="error" role="alert">{{ error }}</p>
    <p v-if="notice" class="notice">{{ notice }}</p>
    <p v-if="loading" class="muted">Loading the queue…</p>
    <p v-else-if="!grades.length" class="muted">No submitted grades are waiting. Approved grades are already on the official record.</p>
    <article v-for="grade in grades" :key="grade.id" class="row">
      <div>
        <strong>{{ grade.student_name }}</strong>
        <span>{{ grade.student_number || '—' }} · {{ grade.subject_code }} · {{ grade.period }}</span>
      </div>
      <em>{{ grade.grade ?? '—' }}</em>
      <button v-if="canApprove" type="button" :disabled="approvingId === grade.id" @click="approve(grade)">
        {{ approvingId === grade.id ? 'Approving…' : 'Approve' }}
      </button>
    </article>
  </section>
</template>

<style scoped>
.queue { display: grid; gap: 10px; }
.kicker { color: var(--student-accent, #106a2e); font-size: 0.72rem; font-weight: 700; letter-spacing: 0.08em; margin: 0; text-transform: uppercase; }
h3 { margin: 2px 0 0; }
.lead, .muted, .row span { color: var(--student-muted, #66706a); }
.lead { margin: 0; }
.error { color: #9b2c2c; margin: 0; }
.notice { color: #106a2e; margin: 0; }
.row { align-items: center; border: 1px solid var(--student-border, #d7dfd9); border-radius: 8px; display: grid; gap: 8px; grid-template-columns: 1fr auto auto; padding: 10px 12px; }
.row strong, .row span { display: block; }
button { background: #106a2e; border: 0; border-radius: 8px; color: #fff; cursor: pointer; font-weight: 700; min-height: 36px; padding: 0 12px; }
button.all { justify-self: start; }
button:disabled { opacity: 0.6; }
</style>
