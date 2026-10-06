<script setup>
import { ref, watch } from 'vue'
import { apiClient } from '../../services/apiClient'
import { gradingService } from '../grading/gradingService'

const props = defineProps({ studentId: { type: Number, required: true } })
const data = ref(null)
const error = ref('')
const tab = ref('overview')
const grades = ref(null)
const gradeError = ref('')
const gradeLoading = ref(false)
const exporting = ref(false)
const gradeYear = ref('')
const gradeSemester = ref('')
let request = 0
watch(() => props.studentId, async id => {
  const current = ++request
  data.value = null
  error.value = ''
  grades.value = null
  gradeError.value = ''
  try {
    const response = await apiClient.get(`/students/${id}/history`)
    if (current === request) {
      data.value = response.data.data
      try {
        const gradeResponse = await gradingService.staffStudentGrades(id)
        if (current === request) {
          grades.value = gradeResponse
          gradeYear.value = String(gradeResponse.selected_term?.academic_year_id || '')
          gradeSemester.value = String(gradeResponse.selected_term?.semester_id || '')
        }
      } catch {
        if (current === request) gradeError.value = 'Unable to load published grades.'
      }
    }
  } catch {
    if (current === request) error.value = 'Unable to load academic history.'
  }
}, { immediate: true })
const label = value => String(value || 'Not available').replaceAll('_', ' ').replace(/\b\w/g, c => c.toUpperCase())
async function loadGrades(){gradeLoading.value=true;gradeError.value='';try{grades.value=await gradingService.staffStudentGrades(props.studentId,{academic_year_id:gradeYear.value||undefined,semester_id:gradeSemester.value||undefined});gradeYear.value=String(grades.value.selected_term?.academic_year_id||'');gradeSemester.value=String(grades.value.selected_term?.semester_id||'')}catch(e){gradeError.value=e.response?.data?.message||'Unable to load published grades.'}finally{gradeLoading.value=false}}
async function exportGrades(){exporting.value=true;gradeError.value='';try{const response=await gradingService.staffStudentExport(props.studentId,{academic_year_id:gradeYear.value||undefined,semester_id:gradeSemester.value||undefined});const url=URL.createObjectURL(response.data),anchor=document.createElement('a');anchor.href=url;anchor.download=response.headers['content-disposition']?.match(/filename="?([^";]+)"?/)?.[1]||'academic-grade-summary.csv';anchor.click();URL.revokeObjectURL(url)}catch(e){gradeError.value=e.response?.data?.message||'Unable to export published grades.'}finally{exporting.value=false}}
</script>

<template>
  <section class="record-card" aria-label="Connected academic history">
    <h2>Admission and Enrollment</h2>
    <p v-if="error" role="alert">{{ error }}</p>
    <p v-else-if="!data">Loading connected history…</p>
    <template v-else>
      <nav class="history-tabs" aria-label="Academic record sections">
        <button v-for="item in ['overview', 'admission', 'enrollment', 'academic history', 'grades']" :key="item" type="button" :aria-current="tab === item ? 'page' : undefined" @click="tab = item">{{ label(item) }}</button>
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
      <div v-else-if="tab === 'academic history'">
        <p v-if="!data.academic_history.length">No academic enrollment history is available.</p>
        <article v-for="term in data.academic_history" :key="term.id" class="history-entry">
          <h3>{{ term.academic_year }} · {{ term.semester }}</h3>
          <p>{{ term.section }} · {{ label(term.status) }} · {{ term.total_units }} units</p>
          <p v-if="term.subjects.length">{{ term.subjects.map(subject => subject.code).join(', ') }}</p>
        </article>
      </div>
      <div v-else class="registrar-grade-history">
        <div class="grade-history-heading"><div><h3>Published Academic Grades</h3><p>Official released submission snapshots only.</p></div><button type="button" :disabled="exporting || !grades?.grades?.length" @click="exportGrades">{{ exporting ? 'Preparing…' : 'Export CSV' }}</button></div>
        <p v-if="gradeError" role="alert">{{gradeError}}</p>
        <form v-if="grades?.terms?.length" class="grade-history-filter" @submit.prevent="loadGrades"><label>Academic Year<select v-model="gradeYear"><option v-for="term in grades.terms" :key="`year-${term.academic_year_id}`" :value="term.academic_year_id">{{term.academic_year}}</option></select></label><label>Semester<select v-model="gradeSemester"><option v-for="term in grades.terms.filter(item=>String(item.academic_year_id)===String(gradeYear))" :key="term.semester_id" :value="term.semester_id">{{term.semester}}</option></select></label><button type="submit">View Term</button></form>
        <p v-if="gradeLoading">Loading published grades…</p>
        <p v-else-if="!grades?.grades?.length">No published grades are available for this term.</p>
        <template v-else><p><strong>{{label(grades.summary.completion)}}</strong> · {{grades.summary.published_subjects}} / {{grades.summary.expected_subjects}} subjects · {{grades.summary.total_units}} units · GWA: {{grades.summary.gwa.message}}</p><div class="grade-history-table"><table><thead><tr><th>Subject</th><th>Professor</th><th>Section</th><th>Units</th><th>Midterm</th><th>Finals</th><th>Final Grade</th><th>Grade Point</th><th>Remarks</th><th>Published</th></tr></thead><tbody><tr v-for="grade in grades.grades" :key="grade.grade_sheet_id"><td><strong>{{grade.subject_code}}</strong><small>{{grade.subject_name}}</small></td><td>{{grade.professor}}</td><td>{{grade.section}}</td><td>{{grade.units}}</td><td>{{grade.midterm_grade}}</td><td>{{grade.finals_grade}}</td><td><strong>{{grade.final_grade}}</strong></td><td>{{grade.grade_point??'N/A'}}</td><td>{{grade.remarks??'Not configured'}}</td><td>{{grade.published_at?new Date(grade.published_at).toLocaleDateString():'—'}}</td></tr></tbody></table></div></template>
      </div>
    </template>
  </section>
</template>

<style scoped>
.history-tabs { display: flex; flex-wrap: wrap; gap: .5rem; margin: 1rem 0; }
.history-tabs button { border: 1px solid var(--color-dartmouth-green); border-radius: .5rem; background: transparent; color: var(--color-dartmouth-green); padding: .45rem .7rem; cursor: pointer; }
.history-tabs button[aria-current="page"] { background: var(--color-dartmouth-green); color: white; }
.history-entry { padding: .75rem 0; border-top: 1px solid #ddd; }
.grade-history-heading { align-items: center; display: flex; justify-content: space-between; gap: 1rem; }
.grade-history-heading h3,.grade-history-heading p { margin: .25rem 0; }
.grade-history-filter { display: flex; flex-wrap: wrap; gap: .75rem; margin: 1rem 0; }
.grade-history-filter label { display: grid; gap: .25rem; }
.grade-history-filter select,.grade-history-filter button,.grade-history-heading button { background: var(--surface-primary,#fff); border: 1px solid var(--border-color,#ccd5df); border-radius: .5rem; color: inherit; padding: .55rem .7rem; }
.grade-history-table { overflow: auto; }
.grade-history-table table { border-collapse: collapse; min-width: 850px; width: 100%; }
.grade-history-table th,.grade-history-table td { border-bottom: 1px solid var(--border-color,#ddd); padding: .6rem; text-align: left; }
.grade-history-table small { color: var(--text-secondary,#667085); display: block; }
</style>
