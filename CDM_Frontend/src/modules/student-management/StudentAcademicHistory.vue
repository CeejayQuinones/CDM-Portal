<script setup>
import { ref, watch } from 'vue'
import { apiClient } from '../../services/apiClient'

const props = defineProps({ studentId: { type: Number, required: true } })
const data = ref(null)
const error = ref('')
const tab = ref('overview')
let request = 0
watch(() => props.studentId, async id => {
  const current = ++request
  data.value = null
  error.value = ''
  try {
    const response = await apiClient.get(`/students/${id}/history`)
    if (current === request) data.value = response.data.data
  } catch {
    if (current === request) error.value = 'Unable to load academic history.'
  }
}, { immediate: true })
const label = value => String(value || 'Not available').replaceAll('_', ' ').replace(/\b\w/g, c => c.toUpperCase())
</script>

<template>
  <section class="record-card" aria-label="Connected academic history">
    <h2>Admission and Enrollment</h2>
    <p v-if="error" role="alert">{{ error }}</p>
    <p v-else-if="!data">Loading connected history…</p>
    <template v-else>
      <nav class="history-tabs" aria-label="Academic record sections">
        <button v-for="item in ['overview', 'admission', 'enrollment', 'academic history']" :key="item" type="button" :aria-current="tab === item ? 'page' : undefined" @click="tab = item">{{ label(item) }}</button>
      </nav>
      <div v-if="tab === 'overview'" class="information-grid">
        <div><strong>Student number</strong><p>{{ data.overview.student_number }}</p></div>
        <div><strong>Course</strong><p>{{ data.overview.course || 'Not available' }}</p></div>
        <div><strong>Curriculum</strong><p>{{ data.overview.curriculum || 'Not available' }}</p></div>
        <div><strong>Year level</strong><p>{{ data.overview.year_level ? `Year ${data.overview.year_level}` : 'Not available' }}</p></div>
        <div><strong>{{ data.overview.section_state === 'official' ? 'Current Section' : data.overview.section_state === 'assigned_pending_finalization' ? 'Assigned Section' : 'Section' }}</strong><p>{{ data.overview.section || 'Not assigned' }}{{ data.overview.section_state === 'assigned_pending_finalization' ? ' · Pending finalization' : '' }}</p></div>
        <div><strong>Academic status</strong><p>{{ label(data.overview.academic_status) }}</p></div>
        <div><strong>Entry classification</strong><p>{{ data.entry_classification }}</p></div>
        <div><strong>Admission record</strong><p>{{ data.admission ? 'Available' : 'No linked Admission record' }}</p></div>
        <div><strong>Enrollment applications</strong><p>{{ data.applications.length }}</p></div>
        <div><strong>Academic terms</strong><p>{{ data.academic_history.length }}</p></div>
      </div>
      <div v-else-if="tab === 'admission'">
        <p v-if="!data.admission">No linked Admission record. This does not imply an unsuccessful application.</p>
        <dl v-else class="information-grid">
          <div><dt>Applicant number</dt><dd>{{ data.admission.applicant_number }}</dd></div>
          <div><dt>Cycle</dt><dd>{{ data.admission.cycle }} · {{ data.admission.cycle_year }}</dd></div>
          <div><dt>Application</dt><dd>{{ label(data.admission.status) }}</dd></div>
          <div><dt>Exam attempt / result</dt><dd>{{ data.admission.exam_attempt || '—' }} · {{ data.admission.result }}</dd></div>
          <div><dt>Recommendation</dt><dd>{{ label(data.admission.recommendation) }}</dd></div>
          <div><dt>Accepted Course</dt><dd>{{ data.admission.accepted_course || '—' }}</dd></div>
          <div><dt>Decision</dt><dd>{{ label(data.admission.decision) }}</dd></div>
          <div><dt>Converted</dt><dd>{{ data.admission.converted_at || '—' }}</dd></div>
        </dl>
      </div>
      <div v-else-if="tab === 'enrollment'">
        <p v-if="!data.applications.length">No Enrollment application is linked.</p>
        <article v-for="application in data.applications" :key="application.id" class="history-entry">
          <h3>{{ application.academic_year }} · {{ application.semester }}</h3>
          <p>{{ label(application.classification) }} · {{ label(application.status) }} · {{ application.section || 'Section unassigned' }}</p>
          <p>Course: {{ application.course }} · Finalized: {{ application.finalized_at || 'Not finalized' }}</p>
          <p v-if="application.subjects.length">{{ application.subjects.map(subject => subject.code).join(', ') }} · {{ application.total_units }} units</p>
        </article>
      </div>
      <div v-else>
        <p v-if="!data.academic_history.length">No academic enrollment history is available.</p>
        <article v-for="term in data.academic_history" :key="term.id" class="history-entry">
          <h3>{{ term.academic_year }} · {{ term.semester }}</h3>
          <p>{{ term.section }} · {{ label(term.status) }} · {{ term.total_units }} units</p>
          <p v-if="term.subjects.length">{{ term.subjects.map(subject => subject.code).join(', ') }}</p>
        </article>
      </div>
    </template>
  </section>
</template>

<style scoped>
.history-tabs { display: flex; flex-wrap: wrap; gap: .5rem; margin: 1rem 0; }
.history-tabs button { border: 1px solid var(--color-dartmouth-green); border-radius: .5rem; background: transparent; color: var(--color-dartmouth-green); padding: .45rem .7rem; cursor: pointer; }
.history-tabs button[aria-current="page"] { background: var(--color-dartmouth-green); color: white; }
.history-entry { padding: .75rem 0; border-top: 1px solid #ddd; }
</style>
