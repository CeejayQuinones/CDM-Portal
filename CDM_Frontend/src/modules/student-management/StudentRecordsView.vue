<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { apiClient } from '../../services/apiClient'

const students = ref([])
const loading = ref(false)
const error = ref('')
const selectedStudent = ref(null)
const detailLoading = ref(false)
const detailError = ref('')
const router = useRouter()
const pagination = reactive({ current_page: 1, last_page: 1, total: 0, from: 0, to: 0 })
const filters = reactive({ search: '', course: '', year_level: '', student_status: '' })

const statuses = ['regular', 'irregular', 'graduated', 'transferred', 'dropped', 'leave_of_absence']
const displayStatus = (value) => (value || '').replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase())
const displayedRange = computed(() => pagination.total ? `${pagination.from}–${pagination.to} of ${pagination.total}` : '0 records')

async function fetchStudents(page = 1) {
  loading.value = true
  error.value = ''

  try {
    const { data } = await apiClient.get('/students', {
      params: { ...filters, page },
    })
    students.value = data.data
    Object.assign(pagination, data.meta)
  } catch (requestError) {
    students.value = []
    error.value = requestError.response?.data?.message || 'Unable to load student records. Please try again.'
  } finally {
    loading.value = false
  }
}

function applyFilters() {
  fetchStudents(1)
}

function resetFilters() {
  Object.assign(filters, { search: '', course: '', year_level: '', student_status: '' })
  fetchStudents(1)
}

async function viewStudent(id) {
  router.push({ name: 'student-details', params: { id } })
}

onMounted(() => fetchStudents())
</script>

<template>
  <section class="page-header">
    <p class="page-kicker">Registrar</p>
    <h1 class="page-title">Student Records</h1>
    <p class="page-description">Search and review student records. This workspace is read-only in Phase 1.</p>
  </section>

  <section class="student-records-panel" aria-label="Student record filters">
    <form class="filters" @submit.prevent="applyFilters">
      <label class="search-field">
        <span>Search</span>
        <input v-model.trim="filters.search" type="search" placeholder="Student number, first name, or last name" />
      </label>
      <label>
        <span>Course</span>
        <input v-model.trim="filters.course" type="text" placeholder="Course ID or code" />
      </label>
      <label>
        <span>Year Level</span>
        <select v-model="filters.year_level">
          <option value="">All year levels</option>
          <option v-for="year in 4" :key="year" :value="year">Year {{ year }}</option>
        </select>
      </label>
      <label>
        <span>Student Status</span>
        <select v-model="filters.student_status">
          <option value="">All statuses</option>
          <option v-for="status in statuses" :key="status" :value="status">{{ displayStatus(status) }}</option>
        </select>
      </label>
      <div class="filter-actions">
        <button class="button button-primary" type="submit" :disabled="loading">Search</button>
        <button class="button button-secondary" type="button" :disabled="loading" @click="resetFilters">Reset</button>
      </div>
    </form>
  </section>

  <section class="student-records-panel records-panel" aria-live="polite">
    <div class="records-heading">
      <p>{{ loading ? 'Loading records…' : displayedRange }}</p>
    </div>

    <p v-if="error" class="notice notice-error">{{ error }}</p>
    <div v-else-if="loading" class="empty-state">Loading student records…</div>
    <div v-else-if="!students.length" class="empty-state">No student records match the selected search and filters.</div>
    <div v-else class="table-wrap">
      <table>
        <thead><tr><th>Student Number</th><th>Full Name</th><th>Course</th><th>Year Level</th><th>Student Status</th><th>Account Status</th><th>Actions</th></tr></thead>
        <tbody>
          <tr v-for="student in students" :key="student.id">
            <td data-label="Student Number">{{ student.student_number }}</td>
            <td data-label="Full Name">{{ student.full_name }}</td>
            <td data-label="Course">{{ student.course?.code || '—' }}</td>
            <td data-label="Year Level">Year {{ student.year_level }}</td>
            <td data-label="Student Status"><span class="status-badge">{{ displayStatus(student.student_status) }}</span></td>
            <td data-label="Account Status"><span class="status-badge">{{ displayStatus(student.account_status) }}</span></td>
            <td data-label="Actions"><button class="view-button" type="button" @click="viewStudent(student.id)">View</button></td>
          </tr>
        </tbody>
      </table>
    </div>

    <nav v-if="pagination.last_page > 1" class="pagination" aria-label="Student records pages">
      <button type="button" :disabled="pagination.current_page === 1 || loading" @click="fetchStudents(pagination.current_page - 1)">Previous</button>
      <span>Page {{ pagination.current_page }} of {{ pagination.last_page }}</span>
      <button type="button" :disabled="pagination.current_page === pagination.last_page || loading" @click="fetchStudents(pagination.current_page + 1)">Next</button>
    </nav>
  </section>

  <section v-if="detailLoading || selectedStudent || detailError" class="student-records-panel detail-panel" aria-live="polite">
    <div class="detail-heading"><h2>Student Record</h2><button type="button" class="close-button" @click="selectedStudent = null; detailError = ''">Close</button></div>
    <p v-if="detailLoading">Loading record…</p>
    <p v-else-if="detailError" class="notice notice-error">{{ detailError }}</p>
    <dl v-else class="record-details">
      <div><dt>Student Number</dt><dd>{{ selectedStudent.student_number }}</dd></div>
      <div><dt>Full Name</dt><dd>{{ selectedStudent.full_name }}</dd></div>
      <div><dt>Course</dt><dd>{{ selectedStudent.course?.code }} — {{ selectedStudent.course?.name }}</dd></div>
      <div><dt>Curriculum</dt><dd>{{ selectedStudent.curriculum?.name }}</dd></div>
      <div><dt>Year Level</dt><dd>Year {{ selectedStudent.year_level }}</dd></div>
      <div><dt>Student Status</dt><dd>{{ displayStatus(selectedStudent.student_status) }}</dd></div>
      <div><dt>Account Status</dt><dd>{{ displayStatus(selectedStudent.account_status) }}</dd></div>
    </dl>
  </section>
</template>

<style scoped>
.student-records-panel { background: var(--color-surface); border: 1px solid var(--color-border); border-radius: 10px; box-shadow: var(--shadow-soft); margin-bottom: 20px; padding: 20px; }
.filters { align-items: end; display: grid; gap: 14px; grid-template-columns: minmax(220px, 2fr) repeat(3, minmax(140px, 1fr)) auto; }
label { display: grid; gap: 6px; color: var(--color-muted); font-size: .82rem; font-weight: 700; }
input, select { border: 1px solid var(--color-border); border-radius: 6px; color: var(--color-eerie-black); min-height: 40px; padding: 8px 10px; width: 100%; }
.filter-actions, .pagination, .detail-heading { align-items: center; display: flex; gap: 8px; }
.button, .view-button, .pagination button, .close-button { border: 0; border-radius: 6px; cursor: pointer; min-height: 40px; padding: 8px 13px; }
.button-primary, .view-button { background: var(--color-dartmouth-green); color: white; }.button-secondary, .pagination button, .close-button { background: var(--color-green-tint); color: var(--color-dartmouth-green); }
button:disabled { cursor: not-allowed; opacity: .55; }.records-heading { color: var(--color-muted); font-size: .9rem; }.records-heading p { margin: 0 0 14px; }
.table-wrap { overflow-x: auto; } table { border-collapse: collapse; min-width: 880px; width: 100%; } th, td { border-bottom: 1px solid var(--color-border); padding: 13px 10px; text-align: left; } th { color: var(--color-muted); font-size: .75rem; text-transform: uppercase; } td { font-size: .9rem; }.status-badge { background: var(--color-green-tint); border-radius: 999px; color: var(--color-dartmouth-green); display: inline-block; font-size: .78rem; padding: 4px 8px; }.pagination { justify-content: flex-end; margin-top: 18px; }.pagination span { color: var(--color-muted); font-size: .88rem; }.empty-state, .notice { border-radius: 6px; padding: 24px; text-align: center; }.empty-state { background: var(--color-anti-flash-white); color: var(--color-muted); }.notice-error { background: #fce8e8; color: #9c2222; }.detail-heading { justify-content: space-between; }.detail-heading h2 { margin: 0; }.record-details { display: grid; gap: 14px; grid-template-columns: repeat(3, minmax(0, 1fr)); margin: 20px 0 0; }.record-details div { background: var(--color-anti-flash-white); border-radius: 6px; padding: 12px; }.record-details dt { color: var(--color-muted); font-size: .78rem; }.record-details dd { margin: 5px 0 0; }
@media (max-width: 1000px) { .filters { grid-template-columns: repeat(2, minmax(0, 1fr)); }.filter-actions { justify-content: flex-start; } }
@media (max-width: 620px) { .student-records-panel { padding: 14px; }.filters, .record-details { grid-template-columns: 1fr; }.filter-actions .button { flex: 1; }.table-wrap { overflow: visible; } table, thead, tbody, tr, th, td { display: block; } table { min-width: 0; } thead { display: none; } tr { border-bottom: 1px solid var(--color-border); padding: 10px 0; } td { border: 0; display: grid; grid-template-columns: 45% 55%; padding: 6px 0; } td::before { color: var(--color-muted); content: attr(data-label); font-size: .76rem; font-weight: 700; }.pagination { justify-content: space-between; }.pagination span { text-align: center; } }
</style>
