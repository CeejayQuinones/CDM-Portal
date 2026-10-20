<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
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
const attentionCategories = [
  { value: 'all', label: 'All' }, { value: 'digital_missing', label: 'Digital Missing' },
  { value: 'awaiting_review', label: 'Awaiting Review' }, { value: 'needs_resubmission', label: 'Needs Resubmission' },
  { value: 'hard_copy_required', label: 'Hard Copy Required' }, { value: 'physical_records', label: 'Physical Records' },
]
const productivity = reactive({ recent_students: [], saved_filters: [], visible_columns: [], attention: { total: 0, students: [], items: [], categories: attentionCategories }, today: {} })
const attentionCategory = ref('all')
const documentRequirementsOpen = ref(false)
const documentRequirementsDialog = ref(null)
const documentRequirementsClose = ref(null)
const filtersOpen = ref(false)
const documentRequirementCount = computed(() => productivity.attention.requirements_total || 0)
const attentionItems = computed(() => attentionCategory.value === 'all'
  ? (productivity.attention.items || [])
  : (productivity.attention.items || []).filter(item => item.attention_reasons?.includes(attentionCategory.value)))
const physicalAttentionStudents = computed(() => (productivity.attention.students || []).filter(student =>
  student.completeness?.issues?.some(issue => issue.area === 'physical_record')))
const nonDocumentAttentionStudents = computed(() => (productivity.attention.students || []).map(student => ({
  ...student,
  completeness: {
    ...student.completeness,
    issues: (student.completeness?.issues || []).filter(issue => issue.area !== 'documents'),
  },
})).filter(student => student.completeness.issues.length))
const activeAdvancedFilterCount = computed(() => [
  filters.course,
  filters.year_level,
  filters.section,
  filters.student_status,
  filters.attention,
  filters.physical_record_status,
  filters.sort !== 'student_number' ? filters.sort : '',
  filters.direction !== 'asc' ? filters.direction : '',
].filter(Boolean).length)
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
let documentRequirementsTrigger = null
let previousBodyOverflow = ''

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

function clearAdvancedFilters() {
  Object.assign(filters, { course: '', year_level: '', section: '', student_status: '', attention: '', physical_record_status: '', sort: 'student_number', direction: 'asc' })
  savedFilterId.value = ''
  clearSelection()
  fetchStudents(1)
}

function viewStudent(id) {
  persistSession()
  router.push({ name: 'student-details', params: { id } })
}
function reviewRequirement(item) {
  closeDocumentRequirements(false)
  persistSession()
  router.push({ name: 'student-documents', params: { id: item.student_id }, query: { document: item.document_type_id } })
}

async function openDocumentRequirements(event) {
  documentRequirementsTrigger = event?.currentTarget || null
  previousBodyOverflow = document.body.style.overflow
  document.body.style.overflow = 'hidden'
  documentRequirementsOpen.value = true
  await nextTick()
  documentRequirementsClose.value?.focus()
}

function closeDocumentRequirements(restoreFocus = true) {
  if (!documentRequirementsOpen.value) return
  documentRequirementsOpen.value = false
  document.body.style.overflow = previousBodyOverflow
  if (restoreFocus) nextTick(() => documentRequirementsTrigger?.focus())
}

function trapDocumentRequirementsFocus(event) {
  if (event.key !== 'Tab') return
  const focusable = [...(documentRequirementsDialog.value?.querySelectorAll(
    'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])',
  ) || [])]
  if (!focusable.length) return
  const first = focusable[0]
  const last = focusable.at(-1)
  if (event.shiftKey && document.activeElement === first) {
    event.preventDefault()
    last.focus()
  } else if (!event.shiftKey && document.activeElement === last) {
    event.preventDefault()
    first.focus()
  }
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
    closeDocumentRequirements()
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
onBeforeUnmount(() => {
  window.removeEventListener('keydown', handleKeyboard)
  if (documentRequirementsOpen.value) document.body.style.overflow = previousBodyOverflow
})
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
  <section v-else class="productivity-grid" :class="{ 'has-attention': nonDocumentAttentionStudents.length }" aria-label="Registrar Student Records productivity">
    <article class="productivity-card recent-card"><div class="card-heading"><div><p class="page-kicker">Your workspace</p><h2>Recently Viewed</h2></div><span>{{ productivityLoading ? 'Loading…' : `${productivity.recent_students.length} records` }}</span></div><p v-if="!productivityLoading && !productivity.recent_students.length" class="empty-state">No recently viewed Students.</p><div class="compact-records"><button v-for="student in productivity.recent_students" :key="student.id" type="button" @click="viewStudent(student.id)"><strong>{{ student.student_number }} · {{ student.name }}</strong><span>{{ student.program || 'No program' }} · {{ student.section || 'No Section' }} · {{ new Date(student.viewed_at).toLocaleString() }}</span></button></div></article>
    <article class="productivity-card document-requirements-card">
      <button class="document-requirements-button" type="button" aria-haspopup="dialog" aria-controls="document-requirements-dialog" :disabled="productivityLoading || !documentRequirementCount" @click="openDocumentRequirements">
        <span class="attention-alert-icon" aria-hidden="true">!</span>
        <span class="attention-alert-copy"><span class="page-kicker">Records checklist</span><strong>Document Requirements</strong><small>{{ productivityLoading ? 'Loading…' : documentRequirementCount ? `${documentRequirementCount} unresolved` : 'No unresolved requirements' }}</small></span>
        <span class="attention-toggle-label">Open</span>
      </button>
    </article>
    <article class="productivity-card today-card"><div class="card-heading"><div><p class="page-kicker">Today</p><h2>Activity</h2></div></div><div class="activity-stats"><span><strong>{{ productivity.today.students_viewed || 0 }}</strong> viewed</span><span><strong>{{ productivity.today.records_updated || 0 }}</strong> updated</span><span><strong>{{ productivity.today.physical_records_moved || 0 }}</strong> moved</span><span><strong>{{ productivity.today.documents_updated || 0 }}</strong> documents</span></div></article>
    <article v-if="nonDocumentAttentionStudents.length" class="productivity-card attention-card">
      <div class="card-heading"><div><p class="page-kicker">Operational queue</p><h2>Needs Attention</h2></div><span>{{ nonDocumentAttentionStudents.length }} records</span></div>
      <div class="compact-records"><button v-for="student in nonDocumentAttentionStudents" :key="student.id" type="button" @click="viewStudent(student.id)"><strong>{{ student.student_number }} · {{ student.name }}</strong><span>{{ student.completeness.issues.map(issue => issue.label).join(' · ') }}</span></button></div>
    </article>
  </section>

  <section class="student-records-panel" aria-label="Student record filters">
    <form class="listing-search" role="search" @submit.prevent="applyFilters">
      <label class="search-field">
        <span>Search</span>
        <input v-model.trim="filters.search" type="search" placeholder="Search student number or name" />
      </label>
      <button class="button button-primary" type="submit" :disabled="loading">Search</button>
      <button class="button button-secondary filters-toggle" type="button" aria-controls="advanced-student-filters" :aria-expanded="filtersOpen" @click="filtersOpen = !filtersOpen">Filters<span v-if="activeAdvancedFilterCount" class="filter-count">{{ activeAdvancedFilterCount }}</span></button>
      <button v-if="activeAdvancedFilterCount" class="clear-filters" type="button" :disabled="loading" @click="clearAdvancedFilters">Clear filters</button>
    </form>
    <Transition name="advanced-filters">
      <div v-if="filtersOpen" id="advanced-student-filters" class="advanced-filters">
        <form class="filters" @submit.prevent="applyFilters">
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
        <button class="button button-primary" type="submit" :disabled="loading">Apply Filters</button>
        <button class="button button-secondary" type="button" :disabled="loading || !activeAdvancedFilterCount" @click="clearAdvancedFilters">Clear</button>
      </div>
        </form>
        <div class="preset-toolbar">
          <label><span>Saved filter</span><select v-model="savedFilterId" @change="applySavedFilter"><option value="">Choose a preset</option><option v-for="preset in productivity.saved_filters" :key="preset.id" :value="preset.id">{{ preset.name }}</option></select></label>
          <label><span>Preset name</span><input v-model.trim="savedFilterName" maxlength="80" placeholder="Example: Missing Physical Record" /></label>
          <button type="button" :disabled="!savedFilterName" @click="saveFilter">{{ savedFilterId ? 'Rename / Update' : 'Save Current Filters' }}</button>
          <button type="button" :disabled="!savedFilterId" @click="deleteSavedFilter">Delete</button>
          <details class="column-chooser"><summary>Visible Columns</summary><label v-for="option in columnOptions" :key="option[0]"><input type="checkbox" :checked="showColumn(option[0])" :disabled="['student_number', 'name'].includes(option[0])" @change="updateColumns(option[0], $event.target.checked)" />{{ option[1] }}</label></details>
        </div>
      </div>
    </Transition>
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

  <Teleport to="body">
    <Transition name="modal-fade">
      <div v-if="documentRequirementsOpen" class="modal-backdrop" role="presentation" @mousedown.self="closeDocumentRequirements">
        <section
          id="document-requirements-dialog"
          ref="documentRequirementsDialog"
          class="document-requirements-modal"
          role="dialog"
          aria-modal="true"
          aria-labelledby="document-requirements-title"
          @keydown="trapDocumentRequirementsFocus"
        >
          <header class="document-requirements-header">
            <div><p class="modal-kicker">Records checklist</p><h2 id="document-requirements-title">Document Requirements</h2><p>{{ documentRequirementCount }} unresolved requirement{{ documentRequirementCount === 1 ? '' : 's' }}</p></div>
            <button ref="documentRequirementsClose" class="modal-close" type="button" aria-label="Close Document Requirements" @click="closeDocumentRequirements">×</button>
          </header>
          <div class="document-requirements-body">
            <div class="attention-filters" aria-label="Document requirement categories"><button v-for="category in productivity.attention.categories" :key="category.value" type="button" :class="{ active: attentionCategory === category.value }" @click="attentionCategory = category.value">{{ category.label }}</button></div>
            <p v-if="!attentionItems.length && attentionCategory !== 'physical_records'" class="empty-state">No document requirements match this filter.</p>
            <div v-if="attentionItems.length" class="attention-table-wrap">
              <table class="attention-table">
                <thead><tr><th>Student</th><th>Document</th><th>Digital Status</th><th>Physical Status</th><th>Attention Reason</th><th>Action</th></tr></thead>
                <tbody><tr v-for="item in attentionItems" :key="item.key"><td data-label="Student"><strong>{{ item.student_number }}</strong><span>{{ item.student_name }}</span></td><td data-label="Document">{{ item.document_type }}</td><td data-label="Digital Status"><span class="attention-status" :data-status="item.digital_status">{{ displayStatus(item.digital_status) }}</span></td><td data-label="Physical Status"><span class="attention-status" :data-status="item.physical_status">{{ item.physical_status === 'submitted' ? 'Received' : displayStatus(item.physical_status) }}</span></td><td data-label="Attention Reason">{{ item.attention_reason }}</td><td data-label="Action"><button type="button" @click="reviewRequirement(item)">Review</button></td></tr></tbody>
              </table>
            </div>
            <div v-if="attentionCategory === 'physical_records'" class="compact-records"><button v-for="student in physicalAttentionStudents" :key="student.id" type="button" @click="viewStudent(student.id)"><strong>{{ student.student_number }} · {{ student.name }}</strong><span>Physical Record Issue · {{ student.completeness.issues.filter(issue => issue.area === 'physical_record').map(issue => issue.label).join(' ') }}</span></button><p v-if="!physicalAttentionStudents.length" class="empty-state">No physical Student Records need attention.</p></div>
          </div>
        </section>
      </div>
    </Transition>
  </Teleport>

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
.document-requirements-card { align-self: start; grid-area: documents; max-width: 100%; overflow: hidden; padding: 0; transition: border-color 180ms ease, box-shadow 180ms ease, transform 180ms ease; }
.document-requirements-card:has(.document-requirements-button:not(:disabled)):hover { border-color: color-mix(in srgb, var(--color-primary) 42%, var(--color-border)); box-shadow: var(--shadow-medium); transform: translateY(-1px); }
.document-requirements-button { display: grid; grid-template-columns: auto minmax(0, 1fr) auto; align-items: center; gap: 11px; width: 100%; min-height: 86px; padding: 13px 14px; border: 0; background: color-mix(in srgb, var(--color-primary) 7%, var(--color-surface)); color: var(--color-text); text-align: left; }
.document-requirements-button:not(:disabled) { cursor: pointer; }
.document-requirements-button:focus-visible { outline: 3px solid var(--focus-ring, color-mix(in srgb, var(--color-primary) 35%, transparent)); outline-offset: -3px; }
.attention-alert-icon { display: grid; place-items: center; width: 24px; height: 24px; border-radius: 50%; background: color-mix(in srgb, var(--color-warning, #c58a15) 18%, transparent); color: var(--color-warning, #9a6500); font-weight: 900; }
.attention-alert-copy { display: grid; gap: 1px; min-width: 0; }
.attention-alert-copy small { color: var(--color-text-muted); }
.attention-alert-count { color: var(--color-warning, #9a6500); font-size: .82rem; font-weight: 800; white-space: nowrap; }
.attention-toggle-label { color: var(--color-primary); font-weight: 800; }
.attention-filters { display: flex; flex-wrap: wrap; gap: 7px; margin: 10px 0; }
.attention-filters button { border: 1px solid var(--color-border); border-radius: 999px; padding: 6px 10px; background: var(--color-surface); color: var(--color-text); }
.attention-filters button.active { border-color: var(--color-primary); background: var(--color-primary); color: var(--color-white); }
.attention-table-wrap { max-width: 100%; overflow-x: auto; border: 1px solid var(--color-border); border-radius: 9px; }
.attention-table { width: 100%; min-width: 820px; border-collapse: collapse; color: var(--color-text); }
.attention-table th, .attention-table td { padding: 9px 10px; border-bottom: 1px solid var(--color-border); text-align: left; vertical-align: middle; }
.attention-table th { background: color-mix(in srgb, var(--color-primary) 6%, var(--color-surface)); color: var(--color-text-muted); font-size: .76rem; text-transform: uppercase; letter-spacing: .03em; }
.attention-table tbody tr:last-child td { border-bottom: 0; }
.attention-table td:first-child { display: grid; gap: 2px; }
.attention-table td:first-child span { color: var(--color-text-muted); font-size: .82rem; }
.attention-table td:last-child button { min-height: 34px; padding: 6px 10px; border: 1px solid var(--color-primary); border-radius: 6px; background: transparent; color: var(--color-primary); font-weight: 800; }
.attention-status { display: inline-flex; align-items: center; padding: 3px 7px; border: 1px solid var(--color-border); border-radius: 999px; background: var(--color-surface); font-size: .76rem; font-weight: 800; white-space: nowrap; }
.attention-status[data-status="missing"], .attention-status[data-status="needs_resubmission"], .attention-status[data-status="required"] { border-color: color-mix(in srgb, var(--color-warning, #c58a15) 45%, var(--color-border)); color: var(--color-warning, #9a6500); }
.attention-status[data-status="verified"], .attention-status[data-status="submitted"] { border-color: color-mix(in srgb, var(--color-primary) 45%, var(--color-border)); color: var(--color-primary); }
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
.productivity-grid { display: grid; gap: 16px; grid-template-areas: "recent documents" "recent activity"; grid-template-columns: minmax(0, 2fr) minmax(250px, .8fr); margin-bottom: 18px; }
.productivity-grid.has-attention { grid-template-areas: "recent documents" "recent activity" "attention attention"; }
.productivity-card { min-width: 0; padding: 18px; }
.recent-card { grid-area: recent; }
.today-card { grid-area: activity; padding: 14px; }
.attention-card { grid-area: attention; }
.card-heading { align-items: start; display: flex; gap: 12px; justify-content: space-between; }
.activity-stats { display: grid; gap: 7px; grid-template-columns: repeat(2, minmax(0, 1fr)); margin-top: 10px; }
.activity-stats span { background: var(--color-green-tint); border-radius: 7px; color: var(--color-muted); display: grid; font-size: .76rem; padding: 8px 9px; }
.activity-stats strong { color: var(--color-dartmouth-green); font-size: 1.05rem; }
.compact-records { display: grid; margin-top: 10px; max-height: 245px; overflow-y: auto; }
.preset-toolbar { align-items: end; display: flex; flex-wrap: wrap; gap: 10px; padding-top: 16px; border-top: 1px solid var(--color-border); }
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
.listing-search { align-items: end; display: grid; gap: 10px; grid-template-columns: minmax(240px, 1fr) auto auto auto; }
.listing-search .search-field { min-width: 0; }
.filters-toggle { align-items: center; display: inline-flex; gap: 7px; justify-content: center; }
.filter-count { align-items: center; background: var(--color-dartmouth-green); border-radius: 999px; color: var(--text-on-accent); display: inline-flex; font-size: .72rem; height: 21px; justify-content: center; min-width: 21px; padding: 0 6px; }
.clear-filters { align-self: center; background: transparent; border: 0; color: var(--color-dartmouth-green); cursor: pointer; font-weight: 800; padding: 8px 4px; }
.advanced-filters { border-top: 1px solid var(--color-border); margin-top: 16px; padding-top: 16px; }
.advanced-filters-enter-active, .advanced-filters-leave-active { transition: opacity 200ms ease, transform 200ms ease; }
.advanced-filters-enter-from, .advanced-filters-leave-to { opacity: 0; transform: translateY(-5px); }
.filters {
  align-items: end;
  display: grid;
  gap: 14px;
  grid-template-columns: repeat(4, minmax(140px, 1fr));
  margin-bottom: 16px;
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
.modal-fade-enter-active,
.modal-fade-leave-active { transition: opacity 180ms ease; }
.modal-fade-enter-active .document-requirements-modal,
.modal-fade-leave-active .document-requirements-modal { transition: transform 180ms ease; }
.modal-fade-enter-from,
.modal-fade-leave-to { opacity: 0; }
.modal-fade-enter-from .document-requirements-modal,
.modal-fade-leave-to .document-requirements-modal { transform: translateY(5px) scale(.985); }
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
.document-requirements-modal {
  background: var(--bg-surface);
  border: 1px solid var(--border-color);
  border-radius: 12px;
  box-shadow: 0 24px 70px rgb(0 0 0 / 24%);
  color: var(--text-primary);
  display: flex;
  flex-direction: column;
  max-height: calc(100vh - 40px);
  max-width: 1080px;
  overflow: hidden;
  width: 100%;
}
.document-requirements-header { align-items: start; border-bottom: 1px solid var(--border-color); display: flex; gap: 20px; justify-content: space-between; padding: 22px 24px 18px; }
.document-requirements-header h2 { color: var(--color-eerie-black); margin: 0; }
.document-requirements-header p:last-child { color: var(--color-muted); margin: 5px 0 0; }
.document-requirements-body { overflow: auto; padding: 10px 24px 24px; }
.modal-close { align-items: center; background: var(--color-green-tint); border: 0; border-radius: 50%; color: var(--color-dartmouth-green); cursor: pointer; display: inline-flex; flex: 0 0 38px; font-size: 1.35rem; height: 38px; justify-content: center; }
.modal-close:focus-visible { outline: 3px solid var(--focus-ring, color-mix(in srgb, var(--color-primary) 35%, transparent)); outline-offset: 2px; }
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
  .productivity-grid { grid-template-areas: "recent" "documents" "activity"; grid-template-columns: 1fr; }
  .productivity-grid.has-attention { grid-template-areas: "recent" "documents" "attention" "activity"; }
  .activity-stats { grid-template-columns: 1fr 1fr; }
  .document-requirements-button { grid-template-columns: auto minmax(0, 1fr) auto; }
  .attention-alert-count { grid-column: 2; }
  .attention-toggle-label { grid-column: 3; grid-row: 1 / span 2; }
  .attention-table-wrap { overflow: visible; border: 0; }
  .attention-table { min-width: 0; }
  .attention-table tr { padding: 10px; border: 1px solid var(--color-border); border-radius: 8px; margin-bottom: 8px; }
  .attention-table td { grid-template-columns: minmax(100px, 38%) minmax(0, 62%); }
  .preset-toolbar { align-items: stretch; display: grid; }
  .listing-search { grid-template-columns: minmax(0, 1fr) auto; }
  .listing-search .search-field { grid-column: 1 / -1; }
  .clear-filters { grid-column: 1 / -1; justify-self: start; }
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
  .modal-backdrop { padding: 10px; }
  .document-requirements-modal { max-height: calc(100vh - 20px); }
  .document-requirements-header { padding: 18px 16px 14px; }
  .document-requirements-body { padding: 8px 16px 18px; }
}

@media (prefers-reduced-motion: reduce) {
  .advanced-filters-enter-active, .advanced-filters-leave-active { transition: none; }
  .document-requirements-card,
  .modal-fade-enter-active,
  .modal-fade-leave-active,
  .modal-fade-enter-active .document-requirements-modal,
  .modal-fade-leave-active .document-requirements-modal { transition: none; }
}
</style>
