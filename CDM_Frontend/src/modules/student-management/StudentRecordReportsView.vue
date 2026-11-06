<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { apiClient } from '../../services/apiClient'
import { useAuthStore } from '../../stores/authStore'
import { readStudentRecordsCache, studentRecordsCacheScope, writeStudentRecordsCache } from './studentRecordsCache'

const REPORTS = [
  { value: 'master-list', label: 'Student Master List' },
  { value: 'academic-summary', label: 'Student Academic Summary' },
  { value: 'physical-records', label: 'Physical Records Status' },
  { value: 'activity-summary', label: 'Student Record Activity Summary' },
]

const reportType = ref('master-list')
const route = useRoute()
const auth = useAuthStore()
const options = reactive({ academic_years: [], semesters: [], courses: [], sections: [], cabinets: [], staff: [], action_types: [] })
const filters = reactive({ search: '', academic_year_id: '', semester_id: '', course_id: '', year_level: '', section_id: '', availability: '', cabinet_id: '', action_type: '', staff_user_id: '', date_from: '', date_to: '' })
const report = ref(null)
const loading = ref(true)
const exporting = ref('')
const error = ref('')
const moreFiltersOpen = ref(false)
const cacheScope = computed(() => studentRecordsCacheScope(auth.currentUser))
let reportRequestId = 0

const isActivity = computed(() => reportType.value === 'activity-summary')
const isPhysical = computed(() => reportType.value === 'physical-records')
const isAcademic = computed(() => reportType.value === 'academic-summary')
const pagination = computed(() => report.value?.pagination || { current_page: 1, last_page: 1, total: 0 })
const filteredSections = computed(() => options.sections.filter((section) =>
  (!filters.academic_year_id || section.academic_year_id === Number(filters.academic_year_id)) &&
  (!filters.semester_id || section.semester_id === Number(filters.semester_id)) &&
  (!filters.course_id || section.course_id === Number(filters.course_id)) &&
  (!filters.year_level || section.year_level === Number(filters.year_level))))
const summaryCards = computed(() => {
  const summary = report.value?.summary || {}
  if (isActivity.value) return [{ label: 'Record Activities', value: summary.total_activities ?? 0 }]
  return [
    { label: 'Total Students', value: summary.total_students ?? 0 },
    { label: 'Currently Enrolled', value: summary.currently_enrolled ?? 0 },
    { label: 'Physical Records Available', value: summary.physical_records_available ?? 0 },
    { label: 'Missing / Unavailable', value: summary.physical_records_unavailable ?? 0 },
  ]
})
const appliedFilters = computed(() => Object.values(report.value?.applied_filters || {}).filter(Boolean))

function params(page = 1) {
  return Object.fromEntries(Object.entries({ ...filters, page, per_page: 25 }).filter(([, value]) => value !== ''))
}

function reportCacheKey(page) {
  return `report:${reportType.value}:${JSON.stringify(params(page))}`
}

async function loadReport(page = 1, allowCached = false) {
  const requestId = ++reportRequestId
  const cacheKey = reportCacheKey(page)
  const cached = allowCached ? readStudentRecordsCache(cacheScope.value, cacheKey) : undefined
  if (cached) {
    report.value = cached
    loading.value = false
    return
  }
  loading.value = true
  error.value = ''
  try {
    const { data } = await apiClient.get(`/student-record-reports/${reportType.value}`, { params: params(page) })
    if (requestId !== reportRequestId) return
    report.value = data.data
    writeStudentRecordsCache(cacheScope.value, cacheKey, data.data)
  } catch (requestError) {
    if (requestId !== reportRequestId) return
    report.value = null
    error.value = requestError.response?.data?.message || 'Unable to load report.'
  } finally {
    if (requestId === reportRequestId) loading.value = false
  }
}

async function loadOptions(allowCached = false) {
  const cached = allowCached ? readStudentRecordsCache(cacheScope.value, 'report:options') : undefined
  if (cached) {
    Object.assign(options, cached)
    return
  }
  try {
    const { data } = await apiClient.get('/student-record-reports/options')
    Object.assign(options, data.data)
    writeStudentRecordsCache(cacheScope.value, 'report:options', data.data)
  } catch (requestError) {
    error.value = requestError.response?.data?.message || 'Unable to load report options.'
  }
}

function resetFilters() {
  Object.keys(filters).forEach((key) => { filters[key] = '' })
  loadReport(1)
}

async function exportFile(format) {
  exporting.value = format
  error.value = ''
  try {
    const suffix = format === 'csv' ? 'export' : `export/${format}`
    const response = await apiClient.get(`/student-record-reports/${reportType.value}/${suffix}`, { params: params(1), responseType: 'blob' })
    const disposition = response.headers['content-disposition'] || ''
    const filename = disposition.match(/filename="?([^";]+)"?/i)?.[1] || `${reportType.value}.${format === 'excel' ? 'xlsx' : format}`
    const url = URL.createObjectURL(response.data)
    const link = document.createElement('a')
    link.href = url
    link.download = filename
    link.click()
    URL.revokeObjectURL(url)
  } catch (requestError) {
    error.value = requestError.response?.data?.message || `Unable to export ${format === 'excel' ? 'Excel' : format.toUpperCase()}.`
  } finally {
    exporting.value = ''
  }
}

function printReport() {
  window.print()
}

function display(value) {
  if (value === null || value === undefined || value === '') return '—'
  return String(value).replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase())
}

watch(reportType, () => resetFilters())
onMounted(async () => {
  if (typeof route.query.search === 'string') filters.search = route.query.search
  await Promise.all([loadOptions(true), loadReport(1, true)])
})
</script>

<template>
  <main class="reports-page">
    <header class="page-header no-print">
      <p class="page-kicker">Registrar / Student Records</p>
      <h1 class="page-title">Student Records Reports</h1>
      <p class="page-description">Build and export authorized summaries from official student information.</p>
    </header>

    <section class="report-controls no-print" aria-label="Report controls">
      <form class="report-toolbar" @submit.prevent="loadReport(1)">
        <label class="report-selector"><span>Report type</span><select v-model="reportType"><option v-for="item in REPORTS" :key="item.value" :value="item.value">{{ item.label }}</option></select></label>
        <label class="report-search"><span>Search student</span><input v-model.trim="filters.search" type="search" placeholder="Student number or name" /></label>
        <label v-if="!isActivity"><span>Academic year</span><select v-model="filters.academic_year_id"><option value="">All years</option><option v-for="year in options.academic_years" :key="year.id" :value="year.id">{{ year.school_year }}</option></select></label>
        <label v-else><span>Date from</span><input v-model="filters.date_from" type="date" /></label>
        <div class="filter-actions"><button class="primary" type="submit" :disabled="loading">Apply filters</button><button class="more-filters" type="button" :aria-expanded="moreFiltersOpen" aria-controls="report-more-filters" @click="moreFiltersOpen = !moreFiltersOpen">More filters</button><button type="button" :disabled="loading" @click="resetFilters">Reset</button></div>
      </form>
      <div v-if="moreFiltersOpen" id="report-more-filters" class="filter-grid">
        <template v-if="!isActivity">
          <label><span>Semester</span><select v-model="filters.semester_id"><option value="">All semesters</option><option v-for="semester in options.semesters" :key="semester.id" :value="semester.id">{{ semester.semester_name }}</option></select></label>
          <label><span>Program</span><select v-model="filters.course_id"><option value="">All programs</option><option v-for="course in options.courses" :key="course.id" :value="course.id">{{ course.course_code }}</option></select></label>
          <label><span>Year level</span><select v-model="filters.year_level"><option value="">All year levels</option><option v-for="year in 4" :key="year" :value="year">Year {{ year }}</option></select></label>
          <label><span>Section</span><select v-model="filters.section_id"><option value="">All sections</option><option v-for="section in filteredSections" :key="section.id" :value="section.id">{{ section.section_name }}</option></select></label>
          <template v-if="isPhysical">
            <label><span>Availability</span><select v-model="filters.availability"><option value="">All statuses</option><option value="available">Available</option><option value="unavailable">Unavailable</option></select></label>
            <label><span>Cabinet</span><select v-model="filters.cabinet_id"><option value="">All cabinets</option><option v-for="cabinet in options.cabinets" :key="cabinet.id" :value="cabinet.id">{{ cabinet.cabinet_code }}</option></select></label>
          </template>
        </template>
        <template v-else>
          <label><span>Action type</span><select v-model="filters.action_type"><option value="">All actions</option><option v-for="action in options.action_types" :key="action" :value="action">{{ display(action) }}</option></select></label>
          <label><span>Staff user</span><select v-model="filters.staff_user_id"><option value="">All staff</option><option v-for="staff in options.staff" :key="staff.id" :value="staff.id">{{ staff.name }} · {{ staff.role }}</option></select></label>
          <label><span>Date to</span><input v-model="filters.date_to" type="date" /></label>
        </template>
      </div>
    </section>

    <section v-if="report" class="print-heading">
      <p>Colegio de Montalban</p><strong>Registrar Office</strong>
      <h2>{{ report.title }}</h2>
      <p>Generated {{ new Date(report.generated_at).toLocaleString() }}</p>
      <p v-if="appliedFilters.length">Applied filters: {{ appliedFilters.join(' · ') }}</p>
      <p>Record count: {{ pagination.total }}</p>
    </section>

    <section v-if="report" class="summary-grid" aria-label="Report summary">
      <article v-for="card in summaryCards" :key="card.label"><span>{{ card.label }}</span><strong>{{ card.value.toLocaleString() }}</strong></article>
    </section>

    <section class="report-results" aria-live="polite">
      <div v-if="report" class="result-actions no-print"><span><strong>{{ report.title }}</strong><small>{{ pagination.total.toLocaleString() }} record{{ pagination.total === 1 ? '' : 's' }}</small></span><div><details class="export-menu"><summary :aria-disabled="Boolean(exporting || !pagination.total)">Export</summary><div><button type="button" :disabled="exporting || !pagination.total" @click="exportFile('csv')">{{ exporting === 'csv' ? 'Preparing CSV…' : 'CSV' }}</button><button type="button" :disabled="exporting || !pagination.total" @click="exportFile('excel')">{{ exporting === 'excel' ? 'Preparing Excel…' : 'Excel' }}</button><button type="button" :disabled="exporting || !pagination.total" @click="exportFile('pdf')">{{ exporting === 'pdf' ? 'Preparing PDF…' : 'PDF' }}</button></div></details><button type="button" :disabled="!pagination.total" @click="printReport">Print report</button></div></div>
      <p v-if="error" class="state error" role="alert">{{ error }} <button type="button" @click="loadReport(pagination.current_page)">Retry</button></p>
      <p v-else-if="loading" class="state">Loading report...</p>
      <p v-else-if="!report?.rows.length" class="state">No records match the selected filters.</p>
      <div v-else class="table-scroll">
        <table>
          <thead><tr>
            <template v-if="isActivity"><th>Date / Time</th><th>Student Number</th><th>Student</th><th>Action</th><th>Source</th><th>Staff User</th></template>
            <template v-else-if="isPhysical"><th>Student Number</th><th>Student</th><th>Program</th><th>Status</th><th>Cabinet</th><th>Location</th><th>Documents</th><th>Missing</th></template>
            <template v-else><th>Student Number</th><th>Student</th><th>Program</th><th>Year</th><th>Section</th><th>Academic Year</th><th>Semester</th><th>Enrollment</th><template v-if="isAcademic"><th>Subjects</th><th>Published Results</th></template></template>
          </tr></thead>
          <tbody><tr v-for="(row, index) in report.rows" :key="`${row.student_id}-${row.activity_at || index}`">
            <template v-if="isActivity"><td>{{ new Date(row.activity_at).toLocaleString() }}</td><td>{{ display(row.student_number) }}</td><td>{{ row.student_name }}</td><td>{{ display(row.action_type) }}</td><td>{{ row.source }}</td><td>{{ row.staff_name || 'System / not recorded' }}<small v-if="row.staff_role">{{ row.staff_role }}</small></td></template>
            <template v-else-if="isPhysical"><td>{{ display(row.student_number) }}</td><td>{{ row.student_name }}</td><td>{{ display(row.program) }}</td><td><span class="status">{{ row.physical_record_status }}</span></td><td>{{ display(row.cabinet) }}</td><td>{{ display(row.location) }}</td><td>{{ row.document_count }}</td><td>{{ row.unavailable_document_count }}</td></template>
            <template v-else><td>{{ display(row.student_number) }}</td><td>{{ row.student_name }}</td><td>{{ display(row.program) }}</td><td>{{ display(row.year_level) }}</td><td>{{ display(row.section) }}</td><td>{{ display(row.academic_year) }}</td><td>{{ display(row.semester) }}</td><td>{{ display(row.enrollment_status) }}</td><template v-if="isAcademic"><td>{{ row.enrolled_subject_count }}</td><td>{{ row.published_subject_count }}</td></template></template>
          </tr></tbody>
        </table>
      </div>
      <nav v-if="report && pagination.last_page > 1" class="pagination no-print" aria-label="Report pages"><button type="button" :disabled="loading || pagination.current_page <= 1" @click="loadReport(pagination.current_page - 1)">Previous</button><span>Page {{ pagination.current_page }} of {{ pagination.last_page }}</span><button type="button" :disabled="loading || pagination.current_page >= pagination.last_page" @click="loadReport(pagination.current_page + 1)">Next</button></nav>
    </section>
  </main>
</template>

<style scoped>
.reports-page{display:grid;gap:14px;min-width:0}.report-controls,.report-results,.summary-grid article{border:1px solid var(--border-color,#d8e1dc);background:var(--bg-surface,var(--color-surface,#fff));color:var(--text-primary,#18332a);border-radius:12px;box-shadow:0 6px 18px rgb(10 55 37/6%)}.report-controls,.report-results{padding:14px}.report-toolbar{display:grid;grid-template-columns:minmax(180px,1.1fr) minmax(220px,1.5fr) minmax(150px,.8fr) auto;align-items:end;gap:10px}.report-toolbar label,.filter-grid label{display:grid;gap:5px;min-width:0;font-size:.78rem;font-weight:750;color:var(--text-muted,#5c7067)}.report-toolbar input,.report-toolbar select,.filter-grid input,.filter-grid select{width:100%;min-width:0;height:40px;padding:0 11px;border:1px solid var(--border-color,#cbd8d1);border-radius:8px;background:var(--bg-input,#fff);color:var(--text-primary,#18332a);font:inherit}.filter-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-top:12px;padding-top:12px;border-top:1px solid var(--border-color,#d8e1dc)}.filter-actions{display:flex;align-items:center;gap:8px}.filter-actions button,.result-actions button,.pagination button,.state button,.export-menu summary{height:40px;padding:0 12px;border:1px solid var(--border-color,#bdccc4);border-radius:8px;background:var(--bg-surface-alt,#f5f8f6);color:inherit;font-weight:750;cursor:pointer;white-space:nowrap}.filter-actions .primary{background:var(--accent,#176b46);color:var(--text-on-accent,#fff);border-color:var(--accent,#176b46)}.more-filters{color:var(--accent,#176b46)!important}.summary-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}.summary-grid article{display:grid;gap:4px;padding:13px 15px;border-left:3px solid color-mix(in srgb,var(--accent,#176b46) 55%,transparent)}.summary-grid span{font-size:.75rem;color:var(--text-muted,#5c7067)}.summary-grid strong{font-size:1.4rem}.result-actions{display:flex;align-items:center;justify-content:space-between;gap:1rem;margin-bottom:12px}.result-actions>span{display:grid;gap:2px}.result-actions small{color:var(--text-muted,#607069);font-weight:500}.result-actions>div{display:flex;gap:8px}.export-menu{position:relative}.export-menu summary{display:grid;place-items:center;list-style:none}.export-menu summary::-webkit-details-marker{display:none}.export-menu>div{position:absolute;right:0;top:44px;z-index:5;display:grid;min-width:130px;padding:6px;border:1px solid var(--border-color,#bdccc4);border-radius:9px;background:var(--bg-surface,#fff);box-shadow:0 10px 25px rgb(10 55 37/18%)}.export-menu>div button{border:0;background:transparent;text-align:left}.table-scroll{max-width:100%;overflow:auto;border:1px solid var(--border-color,#dde5e1);border-radius:9px}table{width:100%;border-collapse:collapse;white-space:nowrap}th,td{padding:10px 11px;text-align:left;border-bottom:1px solid var(--border-color,#dde5e1);font-size:.81rem}th{background:color-mix(in srgb,var(--accent,#176b46) 7%,var(--bg-surface,#fff));font-size:.71rem;text-transform:uppercase;letter-spacing:.035em}tbody tr:hover{background:color-mix(in srgb,var(--accent,#176b46) 3%,transparent)}td small{display:block;color:var(--text-muted,#607069)}.status{display:inline-flex;padding:.25rem .55rem;border-radius:999px;background:rgb(23 107 70/12%);color:var(--success,#176b46);font-weight:800}.state{padding:2rem;text-align:center;color:var(--text-muted,#607069)}.state.error{color:var(--danger,#a53030)}.pagination{display:flex;justify-content:center;align-items:center;gap:.8rem;margin-top:1rem}.print-heading{display:none}
@media(max-width:1180px){.report-toolbar{grid-template-columns:repeat(2,minmax(0,1fr))}.filter-actions{justify-content:flex-start}.filter-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.summary-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:700px){.report-toolbar,.filter-grid,.summary-grid{grid-template-columns:1fr}.filter-actions,.result-actions,.result-actions>div{align-items:stretch;flex-direction:column}.report-controls,.report-results{padding:11px}}
@media print{.no-print{display:none!important}:global(.sidebar),:global(.navbar),:global(.sidebar-backdrop),:global(.portal-animated-backdrop){display:none!important}:global(.main-content){margin:0!important;padding:0!important;width:100%!important}.reports-page{display:block;color:#000;background:#fff}.print-heading{display:block;text-align:center;margin-bottom:1rem}.print-heading h2{margin:.8rem 0 .3rem}.summary-grid{grid-template-columns:repeat(4,1fr);margin-bottom:1rem}.summary-grid article,.report-results{box-shadow:none;border:1px solid #bbb}.table-scroll{overflow:visible}table{white-space:normal}th,td{padding:.35rem;font-size:9pt}.report-results{padding:0}.state{display:none}}
</style>
