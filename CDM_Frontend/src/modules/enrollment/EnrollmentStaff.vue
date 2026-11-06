<script setup>
import { computed, onMounted, onBeforeUnmount, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import EnrollmentLoad from './EnrollmentLoad.vue'
import EnrollmentDialog from './EnrollmentDialog.vue'
import { apiClient } from '../../services/apiClient'
import { classifications, label, termLabel, safeError } from './enrollment'
import './registrarEnrollmentUi4.css'

const route = useRoute()
const periodsPage = computed(() => route.name === 'enrollment-periods')
const data = ref(null), busy = ref(false), error = ref(''), notice = ref('')
const detail = ref(null), requirements = ref([]), notes = ref(''), confirmation = ref('')
const form = ref(null), yearForm = ref(null), page = ref(1)
const filters = ref({ search: '', course_id: '', classification: '', status: '', period_id: '', sort: 'newest' })
const showFilters = ref(false), selectedYearId = ref(''), periodConfirmation = ref(false), originalPeriodEnabled = ref(false)
const yearConfirmation = ref(false), originalYearStatus = ref('')
const statusTabs = [
  { value: '', text: 'All' }, { value: 'submitted', text: 'Pending' },
  { value: 'under_review', text: 'Under review' }, { value: 'approved', text: 'Approved' },
  { value: 'cancelled', text: 'Cancelled' },
]
let epoch = 0
const rows = computed(() => periodsPage.value ? data.value?.periods : data.value?.applications)
const selectedYear = computed(() => data.value?.academic_years?.find(year => String(year.id) === String(selectedYearId.value)))
const visiblePeriods = computed(() => rows.value?.data?.filter(period => String(period.academic_year_id) === String(selectedYearId.value)) || [])
const activeFilters = computed(() => [
  filters.value.course_id && 'Course', filters.value.classification && 'Classification',
  filters.value.period_id && 'Term', filters.value.status && !statusTabs.some(tab => tab.value === filters.value.status) && 'Status',
].filter(Boolean))
const profileOf = item => item?.student?.user_profile || item?.owner?.profile
const nameOf = item => [profileOf(item)?.first_name, profileOf(item)?.last_name].filter(Boolean).join(' ') || 'Applicant'
const identityOf = item => item?.student?.student_number || item?.admission_applicant?.applicant_number || 'Student number pending'
const applicantType = item => item?.student ? 'Student' : 'First-time admitted Guest'
const displayDate = value => value ? new Date(value).toLocaleDateString() : 'Not set'
const displayTime = value => value ? new Date(value).toLocaleString() : 'Not set'

async function load() {
  const v = ++epoch; busy.value = true; error.value = ''
  try {
    const response = await apiClient.get(periodsPage.value ? '/enrollment/periods' : '/enrollment/applications', { params: { ...filters.value, page: page.value } })
    if (v !== epoch) return
    data.value = response.data.data
    if (periodsPage.value && !data.value.academic_years?.some(year => String(year.id) === String(selectedYearId.value))) {
      selectedYearId.value = String(data.value.academic_years?.find(year => year.status === 'active')?.id || data.value.academic_years?.[0]?.id || '')
    }
  } catch (reason) { if (v === epoch) error.value = safeError(reason) }
  finally { if (v === epoch) busy.value = false }
}
async function inspect(application) {
  const v = ++epoch; busy.value = true; error.value = ''
  try {
    const response = await apiClient.get(`/enrollment/applications/${application.id}`)
    if (v !== epoch) return
    detail.value = response.data.data.application
    requirements.value = response.data.data.requirements
    notes.value = detail.value.review_notes || ''
  } catch (reason) { if (v === epoch) error.value = safeError(reason) }
  finally { if (v === epoch) busy.value = false }
}
async function act() {
  if (busy.value) return
  const action = confirmation.value; confirmation.value = ''
  const v = ++epoch; busy.value = true; error.value = ''
  try {
    await apiClient.post(`/enrollment/applications/${detail.value.id}/${action}`, { version: detail.value.version, notes: notes.value, confirmed: true })
    if (v !== epoch) return
    notice.value = 'Application updated.'
    await inspect(detail.value); await load()
  } catch (reason) { if (v === epoch) error.value = safeError(reason) }
  finally { if (v === epoch) busy.value = false }
}
function closeDetail() { if (busy.value) return; detail.value = null; requirements.value = []; notes.value = ''; confirmation.value = '' }
async function refreshDetail() { if (!detail.value) return; await inspect(detail.value); await load() }
const localDate = value => { if (!value) return ''; const date = new Date(value); return new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, 16) }
const requirementConfig = value => Array.isArray(value) ? { required: [...value], optional: [] } : { required: [...(value?.required || [])], optional: [...(value?.optional || [])] }
const normalizeRequirements = requirements => Object.fromEntries(classifications.map(classification => [classification.value, requirementConfig(requirements?.[classification.value])]))
function edit(period) {
  periodConfirmation.value = false; originalPeriodEnabled.value = Boolean(period?.enabled)
  form.value = period ? { ...period, opens_at: localDate(period.opens_at), closes_at: localDate(period.closes_at), document_requirements: normalizeRequirements(period.document_requirements) }
    : { academic_year_id: selectedYearId.value || '', semester_id: '', opens_at: '', closes_at: '', enabled: false, document_requirements: normalizeRequirements({}) }
}
function editYear(year = null) { yearConfirmation.value = false; originalYearStatus.value = year?.status || 'upcoming'; yearForm.value = year ? { ...year, start_date: String(year.start_date).slice(0, 10), end_date: String(year.end_date).slice(0, 10) } : { school_year: '', start_date: '', end_date: '', status: 'upcoming' } }
function requestYearSave() { if (!yearForm.value) return; if (yearForm.value.status !== originalYearStatus.value && ['active', 'completed'].includes(yearForm.value.status)) yearConfirmation.value = true; else saveYear() }
async function saveYear() {
  if (busy.value) return
  yearConfirmation.value = false
  const v = ++epoch; busy.value = true; error.value = ''
  try {
    const payload = { ...yearForm.value }, id = payload.id
    delete payload.id; delete payload.created_at; delete payload.updated_at
    if (id) await apiClient.put(`/enrollment/academic-years/${id}`, payload)
    else await apiClient.post('/enrollment/academic-years', payload)
    if (v !== epoch) return
    yearForm.value = null; notice.value = 'Academic year saved.'; await load()
  } catch (reason) { if (v === epoch) error.value = safeError(reason) }
  finally { if (v === epoch) busy.value = false }
}
async function savePeriod() {
  if (busy.value) return
  periodConfirmation.value = false
  const v = ++epoch; busy.value = true; error.value = ''
  try {
    const payload = { ...form.value, academic_year_id: Number(form.value.academic_year_id), semester_id: Number(form.value.semester_id), opens_at: new Date(form.value.opens_at).toISOString(), closes_at: new Date(form.value.closes_at).toISOString() }
    if (form.value.id) await apiClient.put(`/enrollment/periods/${form.value.id}`, payload)
    else await apiClient.post('/enrollment/periods', payload)
    if (v !== epoch) return
    form.value = null; notice.value = 'Enrollment period saved.'; await load()
  } catch (reason) { if (v === epoch) error.value = safeError(reason) }
  finally { if (v === epoch) busy.value = false }
}
function requestPeriodSave() { if (!form.value) return; if (Boolean(form.value.enabled) !== originalPeriodEnabled.value) periodConfirmation.value = true; else savePeriod() }
async function download(file) {
  const v = epoch; error.value = ''
  try {
    const response = await apiClient.get(`/enrollment/applications/${detail.value.id}/documents/${file.document_id}`, { responseType: 'blob' })
    if (v !== epoch) return
    const url = URL.createObjectURL(response.data), link = document.createElement('a')
    link.href = url; link.download = `${file.name}.${({ 'application/pdf': 'pdf', 'image/png': 'png', 'image/jpeg': 'jpg' })[response.data.type] || 'bin'}`
    link.click(); setTimeout(() => URL.revokeObjectURL(url), 1000)
  } catch (reason) { if (v === epoch) error.value = safeError(reason) }
}
function selectStatus(status) { filters.value.status = status; page.value = 1; load() }
function applyFilters() { page.value = 1; load() }
function resetFilters() { filters.value = { search: '', course_id: '', classification: '', status: '', period_id: '', sort: 'newest' }; showFilters.value = false; page.value = 1; load() }
watch(() => route.name, () => { data.value = null; detail.value = null; form.value = null; yearForm.value = null; confirmation.value = ''; page.value = 1; load() })
onMounted(load)
onBeforeUnmount(() => { epoch++ })
</script>

<template>
  <div class="ui4-staff">
    <header class="ui4-staff-header">
      <div><p class="ui4-kicker">Enrollment management</p><h1>{{ periodsPage ? 'Enrollment Periods' : 'Enrollment Applications' }}</h1><p>{{ periodsPage ? 'Manage academic years and their enrollment windows.' : 'Find applications, review evidence, and take authorized decisions.' }}</p></div>
      <div v-if="periodsPage && data" class="ui4-header-actions">
        <button type="button" class="ui4-button ui4-outline" :disabled="busy" @click="editYear()">Add academic year</button>
        <button type="button" class="ui4-button ui4-primary" :disabled="busy || !data.academic_years.length" @click="edit(null)">Create period</button>
      </div>
    </header>

    <div v-if="error" class="ui4-feedback ui4-error" role="alert"><span>{{ error }}</span><button type="button" class="ui4-button ui4-outline" :disabled="busy" @click="detail?inspect(detail):load()">Reload</button></div>
    <p v-if="notice" class="ui4-feedback ui4-success" role="status">{{ notice }}</p>
    <p v-if="busy" class="ui4-loading" role="status">Loading enrollment records…</p>

    <template v-if="data && !periodsPage">
      <section class="ui4-panel ui4-records-panel" aria-label="Enrollment application records">
        <div class="ui4-panel-head"><div><h2>Application records</h2><p>{{ rows?.total ?? 0 }} {{ filters.status ? label(filters.status).toLowerCase() : 'total' }} records match the current query.</p></div><span class="ui4-result-count">Page {{ rows?.current_page }} of {{ rows?.last_page }}</span></div>
        <div class="ui4-status-tabs" role="group" aria-label="Filter by application status">
          <button v-for="tab in statusTabs" :key="tab.text" type="button" :aria-pressed="filters.status===tab.value" :disabled="busy" @click="selectStatus(tab.value)">{{ tab.text }}</button>
        </div>
        <form class="ui4-records-toolbar" @submit.prevent="applyFilters">
          <label class="ui4-search">Applicant name or number<input v-model.trim="filters.search" type="search" maxlength="100" placeholder="Search applicants" autocomplete="off"></label>
          <button type="submit" class="ui4-button ui4-primary" :disabled="busy">Search</button>
          <label class="ui4-sort">Sort<select v-model="filters.sort" @change="applyFilters"><option value="newest">Newest first</option><option value="oldest">Oldest first</option></select></label>
          <button type="button" class="ui4-button ui4-outline" :aria-expanded="showFilters" aria-controls="ui4-extra-filters" @click="showFilters=!showFilters">Filters{{ activeFilters.length ? ` (${activeFilters.length})` : '' }}</button>
          <button type="button" class="ui4-button ui4-ghost" :disabled="busy" @click="resetFilters">Clear</button>
        </form>
        <form v-if="showFilters" id="ui4-extra-filters" class="ui4-extra-filters" @submit.prevent="applyFilters">
          <label>Course<select v-model="filters.course_id"><option value="">All courses</option><option v-for="course in data.courses" :key="course.id" :value="course.id">{{ course.course_name }}</option></select></label>
          <label>Classification<select v-model="filters.classification"><option value="">All classifications</option><option v-for="item in classifications" :key="item.value" :value="item.value">{{ item.label }}</option></select></label>
          <label>Status<select v-model="filters.status"><option value="">All statuses</option><option v-for="status in ['draft','submitted','under_review','approved','enrolled','rejected','cancelled']" :key="status" :value="status">{{ label(status) }}</option></select></label>
          <label>Term<select v-model="filters.period_id"><option value="">All terms</option><option v-for="period in data.periods" :key="period.id" :value="period.id">{{ termLabel(period) }}</option></select></label>
          <button type="submit" class="ui4-button ui4-primary" :disabled="busy">Apply filters</button>
        </form>
        <p v-if="activeFilters.length" class="ui4-filter-summary">Selected: {{ activeFilters.join(', ') }}</p>
        <div class="ui4-table-scroll" role="region" aria-label="Application results" tabindex="0">
          <table class="ui4-records-table"><thead><tr><th scope="col">Applicant</th><th scope="col">Program and term</th><th scope="col">Classification</th><th scope="col">Status</th><th scope="col"><span class="ui4-sr-only">Review</span></th></tr></thead>
            <tbody><tr v-for="application in rows?.data" :key="application.id" :class="{ 'ui4-selected-row': detail?.id===application.id }">
              <td><strong>{{ nameOf(application) }}</strong><small>{{ identityOf(application) }} · {{ applicantType(application) }}</small></td>
              <td><strong>{{ application.course?.course_name || 'Program not assigned' }}</strong><small>{{ termLabel(application.period) }}</small></td>
              <td>{{ label(application.classification) }}</td>
              <td><span class="ui4-status" :data-status="application.status">{{ label(application.status) }}</span></td>
              <td><button type="button" class="ui4-button ui4-ghost ui4-review" :disabled="busy" :aria-label="`Review application ${application.id} for ${nameOf(application)}`" @click="inspect(application)">Review</button></td>
            </tr><tr v-if="!rows?.data?.length"><td colspan="5" class="ui4-empty">No applications match these filters. Adjust or clear the filters to try again.</td></tr></tbody>
          </table>
        </div>
        <footer class="ui4-panel-footer"><span>{{ rows?.total ?? 0 }} matching applications</span><div class="ui4-pagination"><button type="button" class="ui4-button ui4-outline" :disabled="busy || page<=1" @click="page--;load()">Previous</button><span>{{ rows?.current_page }} / {{ rows?.last_page }}</span><button type="button" class="ui4-button ui4-outline" :disabled="busy || page>=rows?.last_page" @click="page++;load()">Next</button></div></footer>
      </section>
    </template>

    <template v-else-if="data && periodsPage">
      <div class="ui4-period-layout">
        <section class="ui4-panel ui4-year-panel" aria-label="Academic calendar">
          <div class="ui4-panel-head"><div><h2>Academic calendar</h2><p>Select a year to inspect its enrollment windows.</p></div></div>
          <div v-if="!data.academic_years.length" class="ui4-empty">No academic years configured.</div>
          <div v-else class="ui4-year-list">
            <article v-for="year in data.academic_years" :key="year.id" class="ui4-year-row" :class="{ 'is-selected': String(selectedYearId)===String(year.id) }">
              <button type="button" class="ui4-year-select" :aria-pressed="String(selectedYearId)===String(year.id)" @click="selectedYearId=String(year.id)"><strong>{{ year.school_year }}</strong><small>{{ displayDate(year.start_date) }} – {{ displayDate(year.end_date) }}</small><span class="ui4-status" :data-status="year.status">{{ label(year.status) }}</span></button>
              <button type="button" class="ui4-button ui4-ghost" :disabled="busy" :aria-label="`Edit academic year ${year.school_year}`" @click="editYear(year)">Edit</button>
            </article>
          </div>
        </section>
        <section class="ui4-panel ui4-window-panel" aria-label="Enrollment windows">
          <div class="ui4-panel-head"><div><h2>{{ selectedYear?.school_year || 'Select an academic year' }}</h2><p>Enrollment windows for the selected academic year on this results page.</p></div><span v-if="selectedYear" class="ui4-status" :data-status="selectedYear.status">{{ label(selectedYear.status) }}</span></div>
          <div v-if="selectedYear && !visiblePeriods.length" class="ui4-empty">No windows for this year on page {{ rows?.current_page }}. Use pagination to check other pages.</div>
          <div v-else class="ui4-window-list"><article v-for="period in visiblePeriods" :key="period.id" class="ui4-window-row">
            <div><small>Academic term</small><h3>{{ termLabel(period) }}</h3><span class="ui4-status" :data-status="period.state">{{ label(period.state) }}</span></div>
            <dl><div><dt>Opens</dt><dd>{{ displayTime(period.opens_at) }}</dd></div><div><dt>Closes</dt><dd>{{ displayTime(period.closes_at) }}</dd></div></dl>
            <button type="button" class="ui4-button ui4-outline" :disabled="busy" @click="edit(period)">Manage window</button>
          </article></div>
          <footer class="ui4-panel-footer"><span>{{ rows?.total ?? 0 }} windows across all years</span><div class="ui4-pagination"><button type="button" class="ui4-button ui4-outline" :disabled="busy || page<=1" @click="page--;load()">Previous</button><span>{{ rows?.current_page }} / {{ rows?.last_page }}</span><button type="button" class="ui4-button ui4-outline" :disabled="busy || page>=rows?.last_page" @click="page++;load()">Next</button></div></footer>
        </section>
      </div>
    </template>

    <EnrollmentDialog v-if="detail && !periodsPage" labelledby="ui4-application-detail" :busy="busy" wide @cancel="closeDetail">
      <div class="ui4-detail-workspace"><header class="ui4-detail-header"><div><p class="ui4-kicker">{{ ['approved','enrolled'].includes(detail.status) ? 'Academic enrollment' : 'Application review' }}</p><h2 id="ui4-application-detail">{{ ['approved','enrolled'].includes(detail.status) ? 'Enrollment Application' : `Application #${detail.id}` }}</h2><p>{{ nameOf(detail) }} · {{ identityOf(detail) }} · {{ applicantType(detail) }}</p></div><button type="button" class="ui4-button ui4-ghost" :disabled="busy" aria-label="Close application details" @click="closeDetail">Close</button></header><p v-if="error" class="ui4-dialog-error" role="alert">{{ error }}</p>
        <EnrollmentLoad v-if="['approved','enrolled'].includes(detail.status)" :application-id="detail.id" @changed="refreshDetail"/>
        <div v-else class="ui4-detail-grid"><div class="ui4-detail-main">
          <section class="ui4-detail-section"><h3>Applicant and academic path</h3><dl class="ui4-facts"><div><dt>Applicant</dt><dd>{{ nameOf(detail) }}</dd></div><div><dt>Identifier</dt><dd>{{ identityOf(detail) }}</dd></div><div><dt>Course and curriculum</dt><dd>{{ detail.course?.course_name || 'Not assigned' }} · {{ detail.curriculum?.curriculum_name || 'Not assigned' }}</dd></div><div><dt>Year level</dt><dd>{{ detail.year_level ?? 'Not available' }}</dd></div><div><dt>Academic term</dt><dd>{{ termLabel(detail.period) }}</dd></div><div><dt>Classification</dt><dd>{{ label(detail.classification) }}</dd></div></dl><p>{{ classifications.find(item=>item.value===detail.classification)?.description }}</p></section>
          <section class="ui4-detail-section"><h3>Submitted requirements</h3><p v-if="!requirements.length" class="ui4-empty">No additional documents required.</p><div v-for="document in requirements" :key="document.id" class="ui4-requirement"><div><strong>{{ document.name }}</strong><small>{{ document.required ? 'Required' : 'Optional' }} · {{ document.attached ? 'Attached' : document.reusable ? 'Verified existing record' : document.required ? 'Missing' : 'Not supplied' }}</small></div><button v-if="document.document_id" type="button" class="ui4-button ui4-outline" :disabled="busy" @click="download(document)">Download {{ document.name }}</button></div></section>
        </div><aside class="ui4-detail-side"><section class="ui4-detail-section"><h3>Review status</h3><span class="ui4-status" :data-status="detail.status">{{ label(detail.status) }}</span><p>Historical cancelled applications remain available for inspection.</p></section><section class="ui4-detail-section"><h3>Registrar review</h3><label>Review notes<textarea v-model="notes" maxlength="3000" :disabled="busy || !['submitted','under_review'].includes(detail.status)" rows="5"></textarea></label><p>Approval completes application review. It does not assign subjects, sections, or mark the Student academically enrolled.</p><div class="ui4-review-actions"><button v-if="detail.status==='submitted'" type="button" class="ui4-button ui4-primary" :disabled="busy" @click="confirmation='review'">Start Review</button><button v-if="detail.status==='under_review'" type="button" class="ui4-button ui4-primary" :disabled="busy" @click="confirmation='approve'">Approve</button><button v-if="['submitted','under_review'].includes(detail.status)" type="button" class="ui4-button ui4-danger" :disabled="busy || !notes.trim()" @click="confirmation='reject'">Reject</button></div></section></aside></div>
      </div>
    </EnrollmentDialog>

    <EnrollmentDialog v-if="confirmation" labelledby="ui4-review-confirm" :busy="busy" @cancel="confirmation=''">
      <div class="ui4-form-dialog"><header><p class="ui4-kicker">Registrar decision</p><h2 id="ui4-review-confirm">Confirm {{ label(confirmation) }}</h2></header><p>Application #{{ detail.id }} · {{ identityOf(detail) }} · {{ detail.course?.course_name }}</p><p v-if="notes">{{ notes }}</p><footer><button type="button" class="ui4-button ui4-outline" :disabled="busy" @click="confirmation=''">Go back</button><button type="button" class="ui4-button" :class="confirmation==='reject'?'ui4-danger':'ui4-primary'" :disabled="busy" @click="act">Confirm</button></footer></div>
    </EnrollmentDialog>

    <EnrollmentDialog v-if="yearForm" labelledby="ui4-year-form" :busy="busy" @cancel="yearForm=null;yearConfirmation=false">
      <form class="ui4-form-dialog" @submit.prevent="requestYearSave"><header><p class="ui4-kicker">Academic calendar</p><h2 id="ui4-year-form">{{ yearForm.id ? 'Edit academic year' : 'Add academic year' }}</h2></header><p v-if="error" class="ui4-dialog-error" role="alert">{{ error }}</p><div class="ui4-form-grid"><label>School year<input v-model.trim="yearForm.school_year" pattern="\d{4}-\d{4}" placeholder="2027-2028" required></label><label>Status<select v-model="yearForm.status" required><option value="upcoming">Upcoming</option><option value="active">Active</option><option value="completed">Completed</option></select></label><label>Starts<input v-model="yearForm.start_date" type="date" required></label><label>Ends<input v-model="yearForm.end_date" type="date" required></label></div><p>Only one academic year can be active. Referenced year labels and dates remain historical.</p><div v-if="yearConfirmation" class="ui4-consequence" role="alert"><strong>Confirm {{ label(yearForm.status) }} academic year</strong><p>This changes the official academic-year status. The backend will enforce the one-active-year rule.</p><div><button type="button" class="ui4-button ui4-outline" @click="yearConfirmation=false">Go back</button><button type="button" class="ui4-button ui4-primary" :disabled="busy" @click="saveYear">Confirm year change</button></div></div><footer v-else><button type="button" class="ui4-button ui4-outline" :disabled="busy" @click="yearForm=null">Cancel</button><button type="submit" class="ui4-button ui4-primary" :disabled="busy">{{ busy ? 'Saving…' : 'Save academic year' }}</button></footer></form>
    </EnrollmentDialog>

    <EnrollmentDialog v-if="form" labelledby="ui4-period-form" :busy="busy" wide @cancel="form=null;periodConfirmation=false">
      <form class="ui4-form-dialog" @submit.prevent="requestPeriodSave"><header><p class="ui4-kicker">Enrollment window</p><h2 id="ui4-period-form">{{ form.id ? 'Manage enrollment period' : 'Create enrollment period' }}</h2></header><p v-if="error" class="ui4-dialog-error" role="alert">{{ error }}</p><div class="ui4-form-grid"><label>Academic year<select v-model="form.academic_year_id" :disabled="!!form.id || busy" required><option value="">Choose year</option><option v-for="year in data.academic_years" :key="year.id" :value="year.id">{{ year.school_year }}</option></select></label><label>Semester<select v-model="form.semester_id" :disabled="!!form.id || busy" required><option value="">Choose semester</option><option v-for="semester in data.semesters" :key="semester.id" :value="semester.id">{{ semester.semester_name }}</option></select></label><label>Opens at (your local time)<input v-model="form.opens_at" type="datetime-local" required :disabled="busy"></label><label>Closes at (your local time)<input v-model="form.closes_at" type="datetime-local" required :disabled="busy"></label></div>
        <label class="ui4-enable"><input v-model="form.enabled" type="checkbox" :disabled="busy"> Enable period</label><p>Enabled periods open and close according to backend time. Disabling closes a period immediately. The backend enforces all opening rules.</p>
        <section class="ui4-requirement-config"><h3>Documents by classification</h3><p>Required evidence blocks submission; Optional evidence remains available. Configuration freezes once applications exist.</p><div class="ui4-classification-grid"><fieldset v-for="classification in classifications" :key="classification.value"><legend>{{ classification.label }}</legend><div v-for="document in data.document_types" :key="document.id" class="ui4-document-option"><span>{{ document.document_name }}</span><label><input v-model="form.document_requirements[classification.value].required" type="checkbox" :value="document.id" :disabled="busy || form.document_requirements[classification.value].optional.includes(document.id)">Required</label><label><input v-model="form.document_requirements[classification.value].optional" type="checkbox" :value="document.id" :disabled="busy || form.document_requirements[classification.value].required.includes(document.id)">Optional</label></div><p v-if="!data.document_types.length">No active document types configured.</p></fieldset></div></section>
        <div v-if="periodConfirmation" class="ui4-consequence" role="alert"><strong>{{ form.enabled ? 'Open this enrollment window?' : 'Close this enrollment window?' }}</strong><p>{{ form.enabled ? 'The backend will permit applications only while this enabled window is open.' : 'Disabling the period closes it immediately to new applications.' }}</p><div><button type="button" class="ui4-button ui4-outline" @click="periodConfirmation=false">Go back</button><button type="button" class="ui4-button ui4-primary" :disabled="busy" @click="savePeriod">Confirm period change</button></div></div>
        <footer v-else><button type="button" class="ui4-button ui4-outline" :disabled="busy" @click="form=null">Cancel</button><button type="submit" class="ui4-button ui4-primary" :disabled="busy">{{ busy ? 'Saving…' : 'Save period' }}</button></footer>
      </form>
    </EnrollmentDialog>
  </div>
</template>
