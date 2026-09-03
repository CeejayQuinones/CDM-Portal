<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import PaginationControls from '../../components/PaginationControls.vue'
import { isStepUpCancelled, useStepUpAuth } from '../../composables/useStepUpAuth'
import { apiClient } from '../../services/apiClient'

const router = useRouter()
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
const bulkOptions = reactive({ actions: [], courses: [], cabinets: [], document_types: [] })
const pagination = reactive({ current_page: 1, last_page: 1, total: 0, from: 0, to: 0 })
const filters = reactive({ search: '', course: '', year_level: '', student_status: '' })
let bulkOptionsLoaded = false
let bulkOptionsRequest = null

const statuses = ['regular', 'irregular', 'graduated', 'transferred', 'dropped', 'leave_of_absence']
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
const bulkPayloadValue = computed(() => {
  if (bulkAction.value === 'change_year_level' || bulkAction.value === 'change_course') {
    return bulkValue.value ? Number(bulkValue.value) : null
  }
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
  if (bulkAction.value === 'change_year_level') return bulkValue.value ? `Year ${bulkValue.value}` : ''
  if (bulkAction.value === 'change_course') {
    const course = bulkOptions.courses.find((item) => item.id === Number(bulkValue.value))
    return course ? `${course.course_code} — ${course.course_name}` : ''
  }
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
    Object.assign(pagination, data.meta)
  } catch (requestError) {
    students.value = []
    error.value = requestError.response?.data?.message || 'Unable to load student records. Please try again.'
  } finally {
    loading.value = false
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
  Object.assign(filters, { search: '', course: '', year_level: '', student_status: '' })
  clearSelection()
  fetchStudents(1)
}

function viewStudent(id) {
  router.push({ name: 'student-details', params: { id } })
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

onMounted(fetchStudents)
</script>

<template>
  <section class="page-header">
    <p class="page-kicker">Registrar</p>
    <h1 class="page-title">Student Records</h1>
    <p class="page-description">Search, review, and manage official student records.</p>
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
      <label v-else-if="bulkAction === 'change_year_level'">
        <span>Value</span>
        <select v-model="bulkValue" :disabled="bulkSaving">
          <option value="">Choose a year level</option>
          <option v-for="year in 4" :key="year" :value="year">Year {{ year }}</option>
        </select>
      </label>
      <label v-else-if="bulkAction === 'change_course'">
        <span>Value</span>
        <select v-model="bulkValue" :disabled="bulkSaving">
          <option value="">Choose a course</option>
          <option v-for="course in bulkOptions.courses" :key="course.id" :value="course.id">
            {{ course.course_code }} — {{ course.course_name }}
          </option>
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

    <p v-if="bulkError" class="notice notice-error" role="alert">{{ bulkError }}</p>
    <p v-if="error" class="notice notice-error">{{ error }}</p>
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
            <th>Student Number</th>
            <th>Full Name</th>
            <th>Course</th>
            <th>Year Level</th>
            <th>Student Status</th>
            <th>Account Status</th>
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
            <td data-label="Student Number">{{ student.student_number }}</td>
            <td data-label="Full Name">{{ student.full_name }}</td>
            <td data-label="Course">{{ student.course?.code || '—' }}</td>
            <td data-label="Year Level">Year {{ student.year_level }}</td>
            <td data-label="Student Status">
              <span class="status-badge">{{ displayStatus(student.student_status) }}</span>
            </td>
            <td data-label="Account Status">
              <span class="status-badge">{{ displayStatus(student.account_status) }}</span>
            </td>
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
  color: white;
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
  background: linear-gradient(100deg, #e8f4e6, #f6f9dc);
  border: 1px solid #c7dca4;
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
  color: white;
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
.row-selected {
  background: #f4f8e8;
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
  background: #fce8e8;
  color: #9c2222;
}
.notice-success {
  background: #e6f5e7;
  color: #176b32;
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
  background: white;
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
