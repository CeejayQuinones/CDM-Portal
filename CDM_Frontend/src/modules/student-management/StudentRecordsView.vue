<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import PaginationControls from '../../components/PaginationControls.vue'
import { isStepUpCancelled, useStepUpAuth } from '../../composables/useStepUpAuth'
import { apiClient } from '../../services/apiClient'
import { useAuthStore } from '../../stores/authStore'
import { profilePhotoUrl } from '../../utils/profilePhoto'

const router = useRouter()
const auth = useAuthStore()
const { runWithStepUp } = useStepUpAuth()
const students = ref([])
const loading = ref(false)
const error = ref('')
const success = ref('')
const selectedIds = ref(new Set())
const optionsLoading = ref(false)
const bulkSaving = ref(false)
const bulkError = ref('')
const confirmationOpen = ref(false)
const bulkAction = ref('')
const bulkValue = ref('')
const bulkDocumentType = ref('')
const bulkDocumentAvailability = ref('')
const bulkOptions = reactive({ actions: [], cabinets: [], document_types: [] })
const pagination = reactive({ current_page: 1, last_page: 1, total: 0, from: 0, to: 0 })
const filters = reactive({ search: '', course: '', year_level: '', section: '', student_status: '', attention: '', physical_record_status: '', sort: 'student_number', direction: 'asc' })
const failedPhotos = ref(new Set())
const quickSearchInput = ref(null)
const quickQuery = ref('')
const quickResults = ref([])
const quickLoading = ref(false)
const quickError = ref('')
const quickOpen = ref(false)
const productivityLoading = ref(true)
const productivityError = ref('')
const productivity = reactive({ recent_students: [], saved_filters: [], visible_columns: [], attention: { total: 0, students: [] }, today: {} })
const savedFilterId = ref('')
const savedFilterName = ref('')
const columnOptions = [
  ['student_number', 'Student Number'], ['name', 'Name'], ['program', 'Program'], ['year_level', 'Year'],
  ['section', 'Section'], ['student_status', 'Record Status'], ['physical_record_status', 'Physical Record'],
]
const visibleColumns = ref(columnOptions.map(([key]) => key))
const sessionKey = computed(() => `student-records-session:${auth.currentUser?.id || 'unknown'}`)
let bulkOptionsLoaded = false
let bulkOptionsRequest = null

const statuses = ['regular', 'irregular', 'graduated', 'transferred', 'dropped', 'leave_of_absence']
const showColumn = (column) => ['student_number', 'name'].includes(column) || visibleColumns.value.includes(column)
const displayStatus = (value) => (value || '').replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase())
const displayedRange = computed(() =>
  pagination.total ? `${pagination.from}–${pagination.to} of ${pagination.total}` : '0 records',
)
const selectedCount = computed(() => selectedIds.value.size)
const currentPageIds = computed(() => students.value.map((student) => student.id))
const currentPageSelectedCount = computed(
  () => currentPageIds.value.filter((studentId) => selectedIds.value.has(studentId)).length,
)
const allCurrentPageSelected = computed(
  () => currentPageIds.value.length > 0 && currentPageSelectedCount.value === currentPageIds.value.length,
)
const cabinetSlots = computed(() =>
  bulkOptions.cabinets.flatMap((cabinet) => cabinet.slots.map((slot) => ({ ...slot, cabinet_code: cabinet.code }))),
)
const selectedAction = computed(() => bulkOptions.actions.find((action) => action.value === bulkAction.value))
const initials = (name) => name?.split(' ').filter(Boolean).map(part => part[0]).slice(0, 2).join('').toUpperCase() || 'ST'
const studentPhoto = (student) => failedPhotos.value.has(student.id) ? '' : profilePhotoUrl(student.profile)
const markPhotoFailed = (studentId) => {
  failedPhotos.value = new Set([...failedPhotos.value, studentId])
}
const bulkPayloadValue = computed(() => {
  if (bulkAction.value === 'assign_record_location') {
    return bulkValue.value ? { cabinet_slot_id: Number(bulkValue.value) } : null
  }
  if (bulkAction.value === 'update_document_availability') {
    return bulkDocumentType.value && bulkDocumentAvailability.value
      ? {
          document_type_id: Number(bulkDocumentType.value),
          availability_status: bulkDocumentAvailability.value,
        }
      : null
  }
  return bulkValue.value || null
})
const canApplyBulkAction = computed(
  () => selectedCount.value > 0 && bulkAction.value && bulkPayloadValue.value !== null && !bulkSaving.value,
)
const selectedValueLabel = computed(() => {
  if (bulkAction.value === 'change_status') return displayStatus(bulkValue.value)
  if (bulkAction.value === 'assign_record_location') {
    const slot = cabinetSlots.value.find((item) => item.id === Number(bulkValue.value))
    return slot ? `${slot.cabinet_code} / ${slot.code}` : ''
  }
  if (bulkAction.value === 'update_document_availability') {
    const documentType = bulkOptions.document_types.find((item) => item.id === Number(bulkDocumentType.value))
    return documentType ? `${documentType.document_name} to ${displayStatus(bulkDocumentAvailability.value)}` : ''
  }
  return ''
})
const confirmationMessage = computed(
  () =>
    `You are about to apply “${selectedAction.value?.label || 'Bulk Action'}” (${selectedValueLabel.value}) to ${selectedCount.value} selected student record${selectedCount.value === 1 ? '' : 's'}.`,
)

async function fetchStudents(page = 1) {
  loading.value = true
  error.value = ''
  try {
    const { data } = await apiClient.get('/students', { params: { ...filters, page } })
    students.value = data.data
    failedPhotos.value = new Set()
    Object.assign(pagination, data.meta)
  } catch (requestError) {
    students.value = []
    error.value = requestError.response?.data?.message || 'Unable to load student records. Please try again.'
  } finally {
    loading.value = false
  }
}

async function runQuickSearch() {
  if (quickQuery.value.trim().length < 2) {
    quickError.value = 'Enter at least two characters.'
    return
  }
  quickLoading.value = true
  quickError.value = ''
  quickOpen.value = true
  try {
    const { data } = await apiClient.get('/students/quick-search', { params: { q: quickQuery.value.trim() } })
    quickResults.value = data.data.results
    if (data.data.exact && quickResults.value.length === 1) viewStudent(quickResults.value[0].id)
  } catch (requestError) {
    quickResults.value = []
    quickError.value = requestError.response?.data?.message || 'Unable to search Student records.'
  } finally {
    quickLoading.value = false
  }
}

async function fetchProductivity() {
  productivityLoading.value = true
  productivityError.value = ''
  try {
    const { data } = await apiClient.get('/student-records/productivity')
    Object.assign(productivity, data.data)
    visibleColumns.value = [...(data.data.visible_columns || columnOptions.map(([key]) => key))]
  } catch (requestError) {
    productivityError.value = requestError.response?.data?.message || 'Unable to load Registrar productivity tools.'
  } finally {
    productivityLoading.value = false
  }
}

async function fetchBulkOptions(force = false) {
  if (bulkOptionsLoaded && !force) return
  if (bulkOptionsRequest) return bulkOptionsRequest
  optionsLoading.value = true
  bulkError.value = ''
  bulkOptionsRequest = apiClient.get('/students/bulk-options')

  try {
    const { data } = await bulkOptionsRequest
    Object.assign(bulkOptions, data.data)
    bulkOptionsLoaded = true
  } catch (requestError) {
    bulkError.value = requestError.response?.data?.message || 'Unable to load bulk action options.'
  } finally {
    bulkOptionsRequest = null
    optionsLoading.value = false
  }
}

function applyFilters() {
  clearSelection()
  fetchStudents(1)
}

function resetFilters() {
  Object.assign(filters, { search: '', course: '', year_level: '', section: '', student_status: '', attention: '', physical_record_status: '', sort: 'student_number', direction: 'asc' })
  savedFilterId.value = ''
  clearSelection()
  fetchStudents(1)
}

function viewStudent(id) {
  persistSession()
  router.push({ name: 'student-details', params: { id } })
}

function editableFilters() {
  return Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== ''))
}

async function saveFilter() {
  const name = savedFilterName.value.trim()
  if (!name) return
  try {
    const payload = { name, filters: editableFilters() }
    if (savedFilterId.value) await apiClient.put(`/student-records/saved-filters/${savedFilterId.value}`, payload)
    else await apiClient.post('/student-records/saved-filters', payload)
    success.value = savedFilterId.value ? 'Saved filter renamed and updated.' : 'Filter preset saved.'
    savedFilterId.value = ''
    savedFilterName.value = ''
    await fetchProductivity()
  } catch (requestError) {
    error.value = firstValidationMessage(requestError) || requestError.response?.data?.message || 'Unable to save this filter.'
  }
}

function applySavedFilter() {
  const preset = productivity.saved_filters.find((item) => item.id === Number(savedFilterId.value))
  if (!preset) return
  Object.assign(filters, { search: '', course: '', year_level: '', section: '', student_status: '', attention: '', physical_record_status: '', sort: 'student_number', direction: 'asc' })
  Object.assign(filters, preset.filters)
  savedFilterName.value = preset.name
  savedFilterId.value = String(preset.id)
  applyFilters()
}

async function deleteSavedFilter() {
  if (!savedFilterId.value) return
  try {
    await apiClient.delete(`/student-records/saved-filters/${savedFilterId.value}`)
    savedFilterId.value = ''
    savedFilterName.value = ''
    success.value = 'Saved filter deleted.'
    await fetchProductivity()
  } catch (requestError) {
    error.value = requestError.response?.data?.message || 'Unable to delete this saved filter.'
  }
}

async function updateColumns(column, checked) {
  if (['student_number', 'name'].includes(column)) return
  const previous = [...visibleColumns.value]
  visibleColumns.value = checked ? [...new Set([...visibleColumns.value, column])] : visibleColumns.value.filter((item) => item !== column)
  try {
    await apiClient.put('/student-records/preferences', { visible_columns: [...new Set(['student_number', 'name', ...visibleColumns.value])] })
  } catch (requestError) {
    visibleColumns.value = previous
    error.value = requestError.response?.data?.message || 'Unable to save column preferences.'
  }
}

function persistSession() {
  sessionStorage.setItem(sessionKey.value, JSON.stringify({ filters: { ...filters }, page: pagination.current_page, saved_filter_id: savedFilterId.value }))
}

function restoreSession() {
  try {
    const saved = JSON.parse(sessionStorage.getItem(sessionKey.value))
    if (saved?.filters) Object.assign(filters, saved.filters)
    if (saved?.saved_filter_id) savedFilterId.value = saved.saved_filter_id
    return Number(saved?.page) || 1
  } catch {
    sessionStorage.removeItem(sessionKey.value)
    return 1
  }
}

function handleKeyboard(event) {
  if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
    event.preventDefault()
    quickSearchInput.value?.focus()
  }
  if (event.key === 'Escape') {
    quickOpen.value = false
    closeConfirmation()
  }
}

function setStudentSelected(studentId, checked) {
  const nextSelection = new Set(selectedIds.value)
  if (checked) nextSelection.add(studentId)
  else nextSelection.delete(studentId)
  selectedIds.value = nextSelection
}

function toggleCurrentPage(checked) {
  const nextSelection = new Set(selectedIds.value)
  currentPageIds.value.forEach((studentId) => {
    if (checked) nextSelection.add(studentId)
    else nextSelection.delete(studentId)
  })
  selectedIds.value = nextSelection
}

function clearSelection() {
  selectedIds.value = new Set()
  confirmationOpen.value = false
}

function resetBulkValue() {
  bulkValue.value = ''
  bulkDocumentType.value = ''
  bulkDocumentAvailability.value = ''
  bulkError.value = ''
}

function openConfirmation() {
  bulkError.value = ''
  if (canApplyBulkAction.value) confirmationOpen.value = true
}

function closeConfirmation() {
  if (!bulkSaving.value) confirmationOpen.value = false
}

function firstValidationMessage(requestError) {
  return Object.values(requestError.response?.data?.errors || {}).flat()[0]
}

async function applyBulkUpdate() {
  if (!canApplyBulkAction.value) return
  confirmationOpen.value = false
  bulkSaving.value = true
  bulkError.value = ''
  success.value = ''

  try {
    const { data } = await runWithStepUp(() =>
      apiClient.patch('/students/bulk', {
        student_ids: [...selectedIds.value],
        action: bulkAction.value,
        value: bulkPayloadValue.value,
      }),
    )
    success.value = data.message
    clearSelection()
    bulkAction.value = ''
    resetBulkValue()
    await Promise.all([fetchStudents(pagination.current_page), fetchBulkOptions(true)])
  } catch (requestError) {
    if (isStepUpCancelled(requestError)) return
    bulkError.value =
      firstValidationMessage(requestError) ||
      requestError.response?.data?.message ||
      'Unable to update the selected student records.'
  } finally {
    bulkSaving.value = false
  }
}

watch(selectedCount, (count) => {
  if (count > 0) fetchBulkOptions()
})
watch([filters, () => pagination.current_page, savedFilterId], persistSession, { deep: true })

onMounted(() => {
  const page = restoreSession()
  window.addEventListener('keydown', handleKeyboard)
  Promise.all([fetchStudents(page), fetchProductivity()])
})
onBeforeUnmount(() => window.removeEventListener('keydown', handleKeyboard))
</script>

<template>
  <section class="page-header">
    <p class="page-kicker">Registrar</p>
    <h1 class="page-title">Student Records</h1>
    <p class="page-description">Search, review, and manage official student records.</p>
  </section>

  <section class="quick-search-panel" aria-label="Quick Student search">
    <div><p class="page-kicker">Quick access</p><h2>Find a Student</h2><p>Search an exact or partial Student number, or any part of a name.</p></div>
    <form class="quick-search" role="search" @submit.prevent="runQuickSearch">
      <label for="student-quick-search">Student number or name</label>
      <div><input id="student-quick-search" ref="quickSearchInput" v-model.trim="quickQuery" type="search" autocomplete="off" placeholder="Press Ctrl/Cmd + K" @keydown.esc="quickOpen = false" /><button type="submit" :disabled="quickLoading">{{ quickLoading ? 'Searching…' : 'Search' }}</button></div>
    </form>
    <div v-if="quickOpen" class="quick-results" aria-live="polite">
      <p v-if="quickError" role="alert">{{ quickError }}</p>
      <p v-else-if="quickLoading">Searching Student records…</p>
      <p v-else-if="!quickResults.length">No matching Students found.</p>
      <template v-else>
        <button v-for="student in quickResults" :key="student.id" type="button" @click="viewStudent(student.id)"><strong>{{ student.student_number }} · {{ student.name }}</strong><span>{{ student.program || 'Program unavailable' }} · {{ student.section || 'Section unavailable' }}</span></button>
      </template>
    </div>
  </section>

  <p v-if="productivityError" class="notice notice-error" role="alert">{{ productivityError }} <button type="button" @click="fetchProductivity">Retry</button></p>
  <section v-else class="productivity-grid" aria-label="Registrar Student Records productivity">
    <article class="productivity-card today-card"><div><p class="page-kicker">Today</p><h2>Activity</h2></div><div class="activity-stats"><span><strong>{{ productivity.today.students_viewed || 0 }}</strong> viewed</span><span><strong>{{ productivity.today.records_updated || 0 }}</strong> updated</span><span><strong>{{ productivity.today.physical_records_moved || 0 }}</strong> moved</span><span><strong>{{ productivity.today.documents_updated || 0 }}</strong> documents</span></div></article>
    <article class="productivity-card"><div class="card-heading"><div><p class="page-kicker">Your workspace</p><h2>Recently Viewed</h2></div><span>{{ productivityLoading ? 'Loading…' : `${productivity.recent_students.length} records` }}</span></div><p v-if="!productivityLoading && !productivity.recent_students.length" class="empty-state">No recently viewed Students.</p><div class="compact-records"><button v-for="student in productivity.recent_students" :key="student.id" type="button" @click="viewStudent(student.id)"><strong>{{ student.student_number }} · {{ student.name }}</strong><span>{{ student.program || 'No program' }} · {{ student.section || 'No Section' }} · {{ new Date(student.viewed_at).toLocaleString() }}</span></button></div></article>
    <article class="productivity-card"><div class="card-heading"><div><p class="page-kicker">Operational queue</p><h2>Needs Attention</h2></div><strong>{{ productivity.attention.total || 0 }}</strong></div><p v-if="!productivityLoading && !productivity.attention.students.length" class="empty-state">No Student records currently need attention.</p><div class="compact-records"><button v-for="student in productivity.attention.students" :key="student.id" type="button" @click="viewStudent(student.id)"><strong>{{ student.student_number }} · {{ student.name }}</strong><span>{{ student.completeness.label }} · {{ student.completeness.issues.map(issue => issue.label).join(' ') }}</span></button></div></article>
  </section>

  <section class="student-records-panel" aria-label="Student record filters">
    <div class="preset-toolbar">
      <label><span>Saved filter</span><select v-model="savedFilterId" @change="applySavedFilter"><option value="">Choose a preset</option><option v-for="preset in productivity.saved_filters" :key="preset.id" :value="preset.id">{{ preset.name }}</option></select></label>
      <label><span>Preset name</span><input v-model.trim="savedFilterName" maxlength="80" placeholder="Example: Missing Physical Record" /></label>
      <button type="button" :disabled="!savedFilterName" @click="saveFilter">{{ savedFilterId ? 'Rename / Update' : 'Save Current Filters' }}</button>
      <button type="button" :disabled="!savedFilterId" @click="deleteSavedFilter">Delete</button>
      <details class="column-chooser"><summary>Visible Columns</summary><label v-for="option in columnOptions" :key="option[0]"><input type="checkbox" :checked="showColumn(option[0])" :disabled="['student_number', 'name'].includes(option[0])" @change="updateColumns(option[0], $event.target.checked)" />{{ option[1] }}</label></details>
    </div>
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
      <label><span>Section</span><input v-model.trim="filters.section" type="text" placeholder="Example: BSIT-3A" /></label>
      <label><span>Attention</span><select v-model="filters.attention"><option value="">All records</option><option value="any">Any attention item</option><option value="profile">Profile information</option><option value="physical_record">Missing physical location</option><option value="documents">Document metadata</option></select></label>
      <label><span>Physical Record</span><select v-model="filters.physical_record_status"><option value="">All statuses</option><option value="available">Available</option><option value="unavailable">Unavailable</option></select></label>
      <label><span>Sort</span><select v-model="filters.sort"><option value="student_number">Student number</option><option value="name">Name</option><option value="program">Program</option><option value="year_level">Year level</option><option value="student_status">Record status</option></select></label>
      <label><span>Direction</span><select v-model="filters.direction"><option value="asc">Ascending</option><option value="desc">Descending</option></select></label>
      <div class="filter-actions">
        <button class="button button-primary" type="submit" :disabled="loading">Search</button>
        <button class="button button-secondary" type="button" :disabled="loading" @click="resetFilters">Reset</button>
      </div>
    </form>
  </section>

  <p v-if="success" class="notice notice-success" role="status">{{ success }}</p>

  <section class="student-records-panel records-panel" aria-live="polite">
    <div class="records-heading">
      <p>{{ loading ? 'Loading records…' : displayedRange }}</p>
      <div class="selection-controls">
        <button
          class="selection-button"
          type="button"
          :disabled="loading || !students.length || allCurrentPageSelected"
          @click="toggleCurrentPage(true)"
        >
          Select All on Current Page
        </button>
        <button class="selection-button" type="button" :disabled="selectedCount === 0" @click="clearSelection">
          Clear Selection
        </button>
        <strong>{{ selectedCount }} student{{ selectedCount === 1 ? '' : 's' }} selected</strong>
      </div>
    </div>

    <div v-if="selectedCount" class="bulk-toolbar" aria-label="Bulk student actions">
      <div class="bulk-count" aria-live="polite">
        <span aria-hidden="true">✓</span>
        <strong>{{ selectedCount }} selected</strong>
      </div>
      <label>
        <span>Action</span>
        <select v-model="bulkAction" :disabled="optionsLoading || bulkSaving" @change="resetBulkValue">
          <option value="">Choose an action</option>
          <option v-for="action in bulkOptions.actions" :key="action.value" :value="action.value">
            {{ action.label }}
          </option>
        </select>
      </label>

      <label v-if="bulkAction === 'change_status'">
        <span>Value</span>
        <select v-model="bulkValue" :disabled="bulkSaving">
          <option value="">Choose a status</option>
          <option v-for="status in statuses" :key="status" :value="status">{{ displayStatus(status) }}</option>
        </select>
      </label>
      <label v-else-if="bulkAction === 'assign_record_location'">
        <span>Cabinet Slot</span>
        <select v-model="bulkValue" :disabled="bulkSaving">
          <option value="">Choose a cabinet slot</option>
          <option v-for="slot in cabinetSlots" :key="slot.id" :value="slot.id">
            {{ slot.cabinet_code }} / {{ slot.code }} — {{ slot.record_count
            }}{{ slot.capacity ? ` / ${slot.capacity}` : '' }} records
          </option>
        </select>
      </label>
      <template v-else-if="bulkAction === 'update_document_availability'">
        <label>
          <span>Document</span>
          <select v-model="bulkDocumentType" :disabled="bulkSaving">
            <option value="">Choose a document</option>
            <option v-for="documentType in bulkOptions.document_types" :key="documentType.id" :value="documentType.id">
              {{ documentType.document_name }}
            </option>
          </select>
        </label>
        <label>
          <span>Availability</span>
          <select v-model="bulkDocumentAvailability" :disabled="bulkSaving">
            <option value="">Choose availability</option>
            <option value="available">Available</option>
            <option value="missing">Missing</option>
          </select>
        </label>
      </template>

      <button class="button button-accent" type="button" :disabled="!canApplyBulkAction" @click="openConfirmation">
        {{ bulkSaving ? 'Applying…' : 'Apply Changes' }}
      </button>
    </div>

    <p v-if="bulkError" class="notice notice-error" role="alert">{{ bulkError }} <button type="button" @click="fetchBulkOptions(true)">Retry</button></p>
    <p v-if="error" class="notice notice-error" role="alert">{{ error }} <button type="button" @click="fetchStudents(pagination.current_page || 1)">Retry</button></p>
    <div v-else-if="loading" class="empty-state">Loading student records…</div>
    <div v-else-if="!students.length" class="empty-state">
      No student records match the selected search and filters.
    </div>
    <div v-else class="table-wrap">
      <table>
        <thead>
          <tr>
            <th class="select-column">
              <input
                type="checkbox"
                :checked="allCurrentPageSelected"
                aria-label="Select all students on the current page"
                @change="toggleCurrentPage($event.target.checked)"
              />
            </th>
            <th v-if="showColumn('student_number')">Student Number</th>
            <th v-if="showColumn('name')">Full Name</th>
            <th v-if="showColumn('program')">Program</th>
            <th v-if="showColumn('year_level')">Year Level</th>
            <th v-if="showColumn('section')">Section</th>
            <th v-if="showColumn('student_status')">Record Status</th>
            <th v-if="showColumn('physical_record_status')">Physical Record</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="student in students" :key="student.id" :class="{ 'row-selected': selectedIds.has(student.id) }">
            <td class="select-column" data-label="Select">
              <input
                type="checkbox"
                :checked="selectedIds.has(student.id)"
                :aria-label="`Select ${student.full_name}`"
                @change="setStudentSelected(student.id, $event.target.checked)"
              />
            </td>
            <td v-if="showColumn('student_number')" data-label="Student Number">{{ student.student_number }}</td>
            <td v-if="showColumn('name')" data-label="Full Name">
              <span class="student-identity">
                <span class="record-avatar">
                  <img v-if="studentPhoto(student)" :src="studentPhoto(student)" :alt="`${student.full_name} photo`" @error="markPhotoFailed(student.id)" />
                  <span v-else>{{ initials(student.full_name) }}</span>
                </span>
                {{ student.full_name }}
              </span>
            </td>
            <td v-if="showColumn('program')" data-label="Program">{{ student.current_enrollment?.course?.code || student.course?.code || '—' }}</td>
            <td v-if="showColumn('year_level')" data-label="Year Level">Year {{ student.current_enrollment?.year_level || student.year_level }}</td>
            <td v-if="showColumn('section')" data-label="Section">
              {{ student.current_section
                ? `${student.current_section}${student.current_enrollment?.assignment_state === 'assigned_pending_finalization' ? ' · Assigned / Pending Finalization' : student.current_enrollment?.record_scope === 'latest' ? ' · Latest Section' : ''}`
                : 'Not assigned' }}
            </td>
            <td v-if="showColumn('student_status')" data-label="Record Status">
              <span class="status-badge">{{ displayStatus(student.student_status) }}</span>
            </td>
            <td v-if="showColumn('physical_record_status')" data-label="Physical Record"><span class="status-badge">{{ displayStatus(student.physical_record_status) }}</span></td>
            <td data-label="Actions">
              <button class="view-button" type="button" @click="viewStudent(student.id)">View</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <PaginationControls
      :current-page="pagination.current_page"
      :last-page="pagination.last_page"
      :busy="loading"
      aria-label="Student records pages"
      @page-change="fetchStudents"
    />
  </section>

  <div v-if="confirmationOpen" class="modal-backdrop" role="presentation" @mousedown.self="closeConfirmation">
    <section
      class="confirmation-modal"
      role="dialog"
      aria-modal="true"
      aria-labelledby="bulk-confirmation-title"
      aria-describedby="bulk-confirmation-description"
    >
      <p class="modal-kicker">Confirm bulk update</p>
      <h2 id="bulk-confirmation-title">
        Apply changes to {{ selectedCount }} student record{{ selectedCount === 1 ? '' : 's' }}?
      </h2>
      <p id="bulk-confirmation-description">{{ confirmationMessage }}</p>
      <p class="security-note">You may be asked to verify your password before this sensitive change is applied.</p>
      <div class="modal-actions">
        <button class="button button-secondary" type="button" :disabled="bulkSaving" @click="closeConfirmation">
          Cancel
        </button>
        <button class="button button-primary" type="button" :disabled="bulkSaving" @click="applyBulkUpdate">
          {{ bulkSaving ? 'Applying…' : 'Continue' }}
        </button>
      </div>
    </section>
  </div>
</template>

<style scoped>
.quick-search-panel,
.productivity-card {
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: 12px;
  box-shadow: var(--shadow-soft);
}
.quick-search-panel {
  align-items: end;
  display: grid;
  gap: 20px;
  grid-template-columns: minmax(220px, 0.8fr) minmax(320px, 1.2fr);
  margin-bottom: 18px;
  padding: 20px;
  position: relative;
}
.quick-search-panel h2,
.productivity-card h2 { color: var(--color-eerie-black); margin: 0; }
.quick-search-panel p { color: var(--color-muted); margin: 5px 0 0; }
.quick-search div { display: flex; gap: 8px; }
.quick-search button,
.preset-toolbar > button {
  background: var(--color-dartmouth-green);
  border: 0;
  border-radius: 6px;
  color: var(--text-on-accent);
  cursor: pointer;
  min-height: 40px;
  padding: 8px 13px;
}
.quick-results {
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: 8px;
  box-shadow: var(--shadow-medium);
  display: grid;
  grid-column: 2;
  left: 0;
  max-height: 320px;
  overflow-y: auto;
  padding: 6px;
  position: absolute;
  right: 20px;
  top: calc(100% - 8px);
  z-index: 20;
}
.quick-results button,
.compact-records button {
  background: transparent;
  border: 0;
  border-radius: 6px;
  color: var(--color-eerie-black);
  cursor: pointer;
  display: grid;
  gap: 3px;
  padding: 10px;
  text-align: left;
}
.quick-results button:hover,
.quick-results button:focus-visible,
.compact-records button:hover,
.compact-records button:focus-visible { background: var(--color-green-tint); outline: 2px solid transparent; }
.quick-results span,
.compact-records span { color: var(--color-muted); font-size: 0.78rem; }
.productivity-grid { display: grid; gap: 16px; grid-template-columns: repeat(2, minmax(0, 1fr)); margin-bottom: 18px; }
.productivity-card { min-width: 0; padding: 18px; }
.today-card { grid-column: 1 / -1; }
.card-heading { align-items: start; display: flex; gap: 12px; justify-content: space-between; }
.activity-stats { display: grid; gap: 10px; grid-template-columns: repeat(4, minmax(0, 1fr)); margin-top: 14px; }
.activity-stats span { background: var(--color-green-tint); border-radius: 8px; color: var(--color-muted); display: grid; padding: 12px; }
.activity-stats strong { color: var(--color-dartmouth-green); font-size: 1.35rem; }
.compact-records { display: grid; margin-top: 10px; max-height: 245px; overflow-y: auto; }
.preset-toolbar { align-items: end; display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 16px; }
.preset-toolbar label { flex: 1 1 190px; }
.column-chooser { align-self: center; position: relative; }
.column-chooser summary { color: var(--color-dartmouth-green); cursor: pointer; font-weight: 800; padding: 8px; }
.column-chooser[open] { background: var(--color-surface); border: 1px solid var(--color-border); border-radius: 8px; box-shadow: var(--shadow-medium); padding: 6px 10px 10px; }
.column-chooser label { align-items: center; display: flex; font-weight: 600; margin-top: 7px; }
.student-records-panel {
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: 10px;
  box-shadow: var(--shadow-soft);
  margin-bottom: 20px;
  padding: 20px;
}
.filters {
  align-items: end;
  display: grid;
  gap: 14px;
  grid-template-columns: minmax(220px, 2fr) repeat(3, minmax(140px, 1fr)) auto;
}
label {
  color: var(--color-muted);
  display: grid;
  font-size: 0.82rem;
  font-weight: 700;
  gap: 6px;
}
input,
select {
  border: 1px solid var(--color-border);
  border-radius: 6px;
  color: var(--color-eerie-black);
  min-height: 40px;
  padding: 8px 10px;
  width: 100%;
}
input[type='checkbox'] {
  accent-color: var(--color-dartmouth-green);
  cursor: pointer;
  min-height: 17px;
  padding: 0;
  width: 17px;
}
.filter-actions,
.selection-controls,
.bulk-count,
.modal-actions {
  align-items: center;
  display: flex;
  gap: 8px;
}
.button,
.view-button,
.selection-button {
  border: 0;
  border-radius: 6px;
  cursor: pointer;
  min-height: 40px;
  padding: 8px 13px;
}
.button-primary,
.view-button {
  background: var(--color-dartmouth-green);
  color: var(--text-on-accent);
}
.button-secondary,
.selection-button {
  background: var(--color-green-tint);
  color: var(--color-dartmouth-green);
}
.button-accent {
  align-self: end;
  background: #d7e83f;
  color: #173d24;
  font-weight: 800;
  white-space: nowrap;
}
button:disabled {
  cursor: not-allowed;
  opacity: 0.55;
}
.records-heading {
  align-items: center;
  color: var(--color-muted);
  display: flex;
  font-size: 0.9rem;
  gap: 16px;
  justify-content: space-between;
  margin-bottom: 14px;
}
.records-heading p {
  margin: 0;
}
.selection-controls {
  flex-wrap: wrap;
  justify-content: flex-end;
}
.selection-button {
  min-height: 34px;
  padding: 6px 10px;
}
.selection-controls strong {
  color: var(--color-dartmouth-green);
  white-space: nowrap;
}
.bulk-toolbar {
  align-items: end;
  background: var(--bg-surface-alt);
  border: 1px solid var(--border-color);
  border-radius: 9px;
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  margin-bottom: 16px;
  padding: 14px;
}
.bulk-toolbar label {
  flex: 1 1 190px;
}
.bulk-count {
  align-self: center;
  color: var(--color-dartmouth-green);
  min-width: 120px;
}
.bulk-count span {
  align-items: center;
  background: var(--color-dartmouth-green);
  border-radius: 50%;
  color: var(--text-on-accent);
  display: inline-flex;
  height: 23px;
  justify-content: center;
  width: 23px;
}
.table-wrap {
  overflow-x: auto;
}
table {
  border-collapse: collapse;
  min-width: 930px;
  width: 100%;
}
th,
td {
  border-bottom: 1px solid var(--color-border);
  padding: 13px 10px;
  text-align: left;
}
th {
  color: var(--color-muted);
  font-size: 0.75rem;
  text-transform: uppercase;
}
td {
  font-size: 0.9rem;
}
.select-column {
  text-align: center;
  width: 44px;
}
.student-identity { display: flex; align-items: center; gap: 9px; font-weight: 700; }
.record-avatar { display: grid; place-items: center; flex: 0 0 34px; width: 34px; height: 34px; overflow: hidden; border-radius: 50%; background: var(--color-green-tint); color: var(--color-dartmouth-green); font-size: .72rem; font-weight: 800; }
.record-avatar img { width: 100%; height: 100%; object-fit: cover; }
.row-selected {
  background: var(--bg-hover);
}
.status-badge {
  background: var(--color-green-tint);
  border-radius: 999px;
  color: var(--color-dartmouth-green);
  display: inline-block;
  font-size: 0.78rem;
  padding: 4px 8px;
}
.empty-state,
.notice {
  border-radius: 6px;
  padding: 24px;
  text-align: center;
}
.empty-state {
  background: var(--color-anti-flash-white);
  color: var(--color-muted);
}
.notice-error {
  background: var(--danger-bg);
  color: var(--danger);
}
.notice-success {
  background: var(--success-bg);
  color: var(--success);
  margin: 0 0 20px;
}
.modal-backdrop {
  align-items: center;
  background: rgb(7 24 13 / 58%);
  display: flex;
  inset: 0;
  justify-content: center;
  padding: 20px;
  position: fixed;
  z-index: 1200;
}
.confirmation-modal {
  background: var(--bg-surface);
  border: 1px solid var(--border-color);
  color: var(--text-primary);
  border-radius: 12px;
  box-shadow: 0 24px 70px rgb(0 0 0 / 24%);
  max-width: 520px;
  padding: 26px;
  width: 100%;
}
.modal-kicker {
  color: var(--color-dartmouth-green);
  font-size: 0.76rem;
  font-weight: 800;
  letter-spacing: 0.08em;
  margin: 0 0 7px;
  text-transform: uppercase;
}
.confirmation-modal h2 {
  color: var(--color-eerie-black);
  font-size: 1.25rem;
  margin: 0 0 12px;
}
.confirmation-modal p:not(.modal-kicker) {
  color: var(--color-muted);
  line-height: 1.55;
}
.security-note {
  background: var(--color-green-tint);
  border-radius: 7px;
  font-size: 0.84rem;
  padding: 10px;
}
.modal-actions {
  justify-content: flex-end;
  margin-top: 20px;
}

@media (max-width: 1000px) {
  .quick-search-panel { grid-template-columns: 1fr; }
  .quick-results { grid-column: 1; left: 20px; }
  .activity-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .filters {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
  .filter-actions {
    justify-content: flex-start;
  }
  .records-heading {
    align-items: flex-start;
    flex-direction: column;
  }
  .selection-controls {
    justify-content: flex-start;
  }
}

@media (max-width: 620px) {
  .quick-search-panel { align-items: stretch; padding: 14px; }
  .quick-search div { display: grid; }
  .quick-results { left: 14px; right: 14px; }
  .productivity-grid { grid-template-columns: 1fr; }
  .today-card { grid-column: auto; }
  .activity-stats { grid-template-columns: 1fr 1fr; }
  .preset-toolbar { align-items: stretch; display: grid; }
  .student-records-panel {
    padding: 14px;
  }
  .filters {
    grid-template-columns: 1fr;
  }
  .filter-actions .button,
  .bulk-toolbar .button {
    flex: 1;
  }
  .bulk-toolbar {
    align-items: stretch;
    display: grid;
    grid-template-columns: 1fr;
  }
  .table-wrap {
    overflow: visible;
  }
  table,
  thead,
  tbody,
  tr,
  th,
  td {
    display: block;
  }
  table {
    min-width: 0;
  }
  thead {
    display: none;
  }
  tr {
    border-bottom: 1px solid var(--color-border);
    padding: 10px 0;
  }
  td {
    border: 0;
    display: grid;
    grid-template-columns: 45% 55%;
    padding: 6px 0;
  }
  td::before {
    color: var(--color-muted);
    content: attr(data-label);
    font-size: 0.76rem;
    font-weight: 700;
  }
  td.select-column {
    text-align: left;
    width: auto;
  }
  .confirmation-modal {
    padding: 20px;
  }
}
</style>
