<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { gradingError, gradingService } from './gradingService'
import './grading.css'

const students = ref([])
const courses = ref([])
const loading = ref(true)
const detailLoading = ref(false)
const exporting = ref(false)
const error = ref('')
const selectedStudent = ref(null)
const grades = ref(null)
const gradeYear = ref('')
const gradeSemester = ref('')
const filters = reactive({ search: '', course: '', year_level: '', section: '' })
const pagination = reactive({ current_page: 1, last_page: 1, total: 0, from: 0, to: 0 })

const semesters = computed(() =>
  (grades.value?.terms || []).filter((term) => String(term.academic_year_id) === String(gradeYear.value)),
)
const resultLabel = computed(() =>
  pagination.total ? `${pagination.from}–${pagination.to} of ${pagination.total} students` : '0 students',
)
const label = (value) => String(value || '').replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase())
const formatDate = (value) => value ? new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(new Date(value)) : '—'

async function loadStudents(page = 1) {
  loading.value = true
  error.value = ''
  try {
    const response = await gradingService.students({ ...filters, page })
    students.value = response.data
    Object.assign(pagination, response.meta)
  } catch (requestError) {
    students.value = []
    error.value = gradingError(requestError)
  } finally {
    loading.value = false
  }
}

async function loadOptions() {
  try {
    const options = await gradingService.studentOptions()
    courses.value = options.courses || []
  } catch {
    courses.value = []
  }
}

function resetFilters() {
  Object.assign(filters, { search: '', course: '', year_level: '', section: '' })
  loadStudents(1)
}

function changeYear() {
  gradeSemester.value = String(semesters.value[0]?.semester_id || '')
}

async function openStudent(student, preserveTerm = false) {
  selectedStudent.value = student
  detailLoading.value = true
  error.value = ''
  try {
    const params = preserveTerm
      ? { academic_year_id: gradeYear.value || undefined, semester_id: gradeSemester.value || undefined }
      : {}
    grades.value = await gradingService.staffStudentGrades(student.id, params)
    gradeYear.value = String(grades.value.selected_term?.academic_year_id || '')
    gradeSemester.value = String(grades.value.selected_term?.semester_id || '')
  } catch (requestError) {
    grades.value = null
    error.value = gradingError(requestError)
  } finally {
    detailLoading.value = false
  }
}

function closeStudent() {
  selectedStudent.value = null
  grades.value = null
  gradeYear.value = ''
  gradeSemester.value = ''
}

async function exportGrades() {
  if (!selectedStudent.value) return
  exporting.value = true
  error.value = ''
  try {
    const response = await gradingService.staffStudentExport(selectedStudent.value.id, {
      academic_year_id: gradeYear.value || undefined,
      semester_id: gradeSemester.value || undefined,
    })
    const url = URL.createObjectURL(response.data)
    const anchor = document.createElement('a')
    anchor.href = url
    anchor.download = response.headers['content-disposition']?.match(/filename="?([^";]+)"?/)?.[1]
      || 'student-grade-history.csv'
    anchor.click()
    URL.revokeObjectURL(url)
  } catch (requestError) {
    error.value = gradingError(requestError)
  } finally {
    exporting.value = false
  }
}

onMounted(() => Promise.all([loadStudents(), loadOptions()]))
</script>

<template>
  <main class="grading-page grade-history-page">
    <header class="grading-hero">
      <div>
        <p class="grading-eyebrow">Official published records</p>
        <h1>Student Grade History</h1>
        <p>Find a student and review released grades from immutable grading snapshots.</p>
      </div>
    </header>

    <p v-if="error" class="grading-alert error" role="alert">{{ error }}</p>

    <template v-if="!selectedStudent">
      <form class="grading-review-filters grade-history-directory-filters" @submit.prevent="loadStudents(1)">
        <label class="grade-history-search">Student name / number
          <input v-model.trim="filters.search" type="search" placeholder="Search student name or number">
        </label>
        <label>Program
          <select v-model="filters.course">
            <option value="">All programs</option>
            <option v-for="course in courses" :key="course.id" :value="course.id">
              {{ course.course_code }} — {{ course.course_name }}
            </option>
          </select>
        </label>
        <label>Year Level
          <select v-model="filters.year_level">
            <option value="">All year levels</option>
            <option v-for="level in 4" :key="level" :value="level">Year {{ level }}</option>
          </select>
        </label>
        <label>Section
          <input v-model.trim="filters.section" placeholder="Section name">
        </label>
        <div class="grade-history-filter-actions">
          <button class="grading-button primary" :disabled="loading">Search</button>
          <button class="grading-button secondary" type="button" :disabled="loading" @click="resetFilters">Reset</button>
        </div>
      </form>

      <section class="grade-history-directory" aria-live="polite">
        <header><strong>{{ loading ? 'Loading students…' : resultLabel }}</strong></header>
        <div v-if="loading" class="grading-skeleton grade-history-list-skeleton" aria-label="Loading student grade history">
          <span v-for="item in 5" :key="item" class="grading-skeleton-line"></span>
        </div>
        <div v-else-if="!students.length" class="grading-state">
          <strong>No matching students</strong>
          <span>Try changing the student, program, year-level, or section filters.</span>
        </div>
        <div v-else class="grading-table-wrap grade-history-directory-table">
          <table>
            <thead><tr><th>Student Number</th><th>Student Name</th><th>Program</th><th>Year Level</th><th>Current / Latest Section</th><th>Action</th></tr></thead>
            <tbody>
              <tr v-for="student in students" :key="student.id">
                <td>{{ student.student_number }}</td>
                <td><strong>{{ student.full_name }}</strong></td>
                <td>{{ student.current_enrollment?.course?.code || student.course?.code || 'Not assigned' }}</td>
                <td>Year {{ student.current_enrollment?.year_level || student.year_level }}</td>
                <td>{{ student.current_section || 'Not assigned' }}</td>
                <td><button class="grade-history-open" type="button" @click="openStudent(student)">View Grades</button></td>
              </tr>
            </tbody>
          </table>
        </div>
        <footer v-if="pagination.last_page > 1" class="grade-history-pagination">
          <button type="button" :disabled="loading || pagination.current_page <= 1" @click="loadStudents(pagination.current_page - 1)">Previous</button>
          <span>Page {{ pagination.current_page }} of {{ pagination.last_page }}</span>
          <button type="button" :disabled="loading || pagination.current_page >= pagination.last_page" @click="loadStudents(pagination.current_page + 1)">Next</button>
        </footer>
      </section>
    </template>

    <template v-else>
      <button class="grading-back" type="button" @click="closeStudent">← Back to student search</button>
      <section class="grade-history-student-heading">
        <div>
          <p class="grading-eyebrow">Published grade history</p>
          <h2>{{ selectedStudent.full_name }}</h2>
          <p>{{ selectedStudent.student_number }} · {{ selectedStudent.current_enrollment?.course?.code || selectedStudent.course?.code || 'Program not assigned' }}</p>
        </div>
        <button class="grading-button secondary" type="button" :disabled="exporting || !grades?.grades?.length" @click="exportGrades">
          {{ exporting ? 'Preparing…' : 'Export CSV' }}
        </button>
      </section>

      <form v-if="grades?.terms?.length" class="grading-review-filters student-term-filter" @submit.prevent="openStudent(selectedStudent, true)">
        <label>Academic Year
          <select v-model="gradeYear" @change="changeYear">
            <option v-for="term in grades.terms" :key="`year-${term.academic_year_id}`" :value="term.academic_year_id">{{ term.academic_year }}</option>
          </select>
        </label>
        <label>Semester
          <select v-model="gradeSemester">
            <option v-for="term in semesters" :key="term.semester_id" :value="term.semester_id">{{ term.semester }}</option>
          </select>
        </label>
        <button class="grading-button primary" :disabled="detailLoading">View Term</button>
      </form>

      <div v-if="detailLoading" class="grading-state">Loading published grades…</div>
      <div v-else-if="!grades?.grades?.length" class="grading-state">
        <strong>No published grades</strong>
        <span>No official released grades are available for this student and term.</span>
      </div>
      <template v-else>
        <section class="grading-summary grade-history-summary">
          <div><small>Academic Year</small><strong>{{ grades.selected_term?.academic_year }}</strong></div>
          <div><small>Semester</small><strong>{{ grades.selected_term?.semester }}</strong></div>
          <div><small>Published / Enrolled</small><strong>{{ grades.summary.published_subjects }} / {{ grades.summary.expected_subjects }}</strong></div>
          <div><small>Completion</small><strong>{{ label(grades.summary.completion) }}</strong></div>
        </section>
        <div class="grading-table-wrap grade-history-detail-table">
          <table>
            <thead><tr><th>Subject Code</th><th>Subject Name</th><th>Units</th><th>Professor</th><th>Section</th><th>Midterm</th><th>Finals</th><th>Final Grade</th><th>Grade Point</th><th>Remarks</th><th>Published At</th></tr></thead>
            <tbody>
              <tr v-for="grade in grades.grades" :key="grade.grade_sheet_id">
                <td><strong>{{ grade.subject_code }}</strong></td><td>{{ grade.subject_name }}</td><td>{{ grade.units }}</td><td>{{ grade.professor }}</td><td>{{ grade.section }}</td><td>{{ grade.midterm_grade }}</td><td>{{ grade.finals_grade }}</td><td><strong>{{ grade.final_grade }}</strong></td><td>{{ grade.grade_point ?? 'N/A' }}</td><td>{{ grade.remarks ?? 'Not configured' }}</td><td>{{ formatDate(grade.published_at) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </template>
    </template>
  </main>
</template>
