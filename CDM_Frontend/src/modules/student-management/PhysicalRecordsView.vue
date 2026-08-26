<script setup>
import { computed, nextTick, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import PaginationControls from '../../components/PaginationControls.vue'
import { isStepUpCancelled, useStepUpAuth } from '../../composables/useStepUpAuth'
import { physicalRecordsService as api } from './physicalRecordsService'

const route = useRoute()
const router = useRouter()
const { runWithStepUp } = useStepUpAuth()
const cabinets = ref([])
const loading = ref(false)
const creating = ref(false)
const showCreateForm = ref(false)
const error = ref('')
const success = ref('')
const selectedSlot = ref(null)
const detailLoading = ref(false)
const detailError = ref('')
const detailPanel = ref(null)
const formErrors = ref({})
const showSlotEdit = ref(false)
const slotSaving = ref(false)
const slotFormErrors = ref({})
const slotEditError = ref('')
const form = reactive({
  cabinet_code: '',
  rows: 2,
  columns: 3,
  slot_capacity: '',
  description: '',
})
const slotForm = reactive({
  slot_code: '',
  capacity: '',
  size: 'small',
  description: '',
  status: 'active',
})

const students = computed(() => selectedSlot.value?.students?.data || [])
const pagination = computed(() => ({
  currentPage: selectedSlot.value?.students?.current_page || 1,
  lastPage: selectedSlot.value?.students?.last_page || 1,
  total: selectedSlot.value?.students?.total || 0,
}))

const errorMessage = (requestError, fallback) => requestError.response?.data?.message || fallback
const title = (value) =>
  value
    ? String(value)
        .replaceAll('_', ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase())
    : 'Not available'
const cabinetName = (cabinet) => cabinet?.cabinet_code || 'Cabinet'
const courseLabel = (student) => {
  const code = student.course?.code || student.course?.course_code
  const name = student.course?.name || student.course?.course_name
  return [code, name].filter(Boolean).join(' — ') || 'Not available'
}
const slotCount = (slot) => Number(slot.record_count ?? slot.student_record_locations_count ?? 0)
const availableDocuments = (student) =>
  student.available_documents ||
  (student.documents || []).filter((document) => (document.availability_status || document.status) === 'available')
const missingDocuments = (student) =>
  student.missing_documents ||
  (student.documents || []).filter((document) => (document.availability_status || document.status) !== 'available')
const documentName = (document) => document.name || document.document_type?.document_name || 'Unnamed document'

async function loadCabinets() {
  loading.value = true
  error.value = ''
  try {
    const data = await api.cabinets()
    cabinets.value = Array.isArray(data) ? data : []
  } catch (requestError) {
    error.value = errorMessage(requestError, 'Unable to load physical record cabinets.')
  } finally {
    loading.value = false
  }
}

function resetForm() {
  Object.assign(form, {
    cabinet_code: '',
    rows: 2,
    columns: 3,
    slot_capacity: '',
    description: '',
  })
  formErrors.value = {}
}

async function createCabinet() {
  creating.value = true
  error.value = ''
  success.value = ''
  formErrors.value = {}
  try {
    await api.createCabinet({
      cabinet_code: form.cabinet_code.trim(),
      rows: Number(form.rows),
      columns: Number(form.columns),
      slot_capacity: form.slot_capacity === '' ? null : Number(form.slot_capacity),
      description: form.description.trim() || null,
    })
    success.value = `Cabinet ${form.cabinet_code.trim().toUpperCase()} was created with ${Number(form.rows) * Number(form.columns)} slots.`
    resetForm()
    showCreateForm.value = false
    await loadCabinets()
  } catch (requestError) {
    formErrors.value = requestError.response?.data?.errors || {}
    error.value = errorMessage(requestError, 'Unable to create the cabinet.')
  } finally {
    creating.value = false
  }
}

async function openSlot(slotId, page = 1, updateUrl = true) {
  detailLoading.value = true
  detailError.value = ''
  if (updateUrl) {
    const containingCabinet = cabinets.value.find((cabinet) =>
      cabinet.slots?.some((slot) => Number(slot.id) === Number(slotId)),
    )
    await router.replace({
      name: 'physical-records',
      query: {
        cabinet: containingCabinet?.id || route.query.cabinet,
        slot: slotId,
      },
    })
  }
  try {
    selectedSlot.value = await api.cabinetSlot(slotId, page)
    await nextTick()
    detailPanel.value?.scrollIntoView({ behavior: 'smooth', block: 'start' })
  } catch (requestError) {
    selectedSlot.value = null
    detailError.value = errorMessage(requestError, 'Unable to load this cabinet slot.')
  } finally {
    detailLoading.value = false
  }
}

async function closeSlot() {
  selectedSlot.value = null
  detailError.value = ''
  const query = { ...route.query }
  delete query.cabinet
  delete query.slot
  await router.replace({ name: 'physical-records', query })
}

function viewStudent(studentId) {
  router.push({ name: 'student-details', params: { id: studentId } })
}

function editSlot() {
  if (!selectedSlot.value) return
  Object.assign(slotForm, {
    slot_code: selectedSlot.value.slot_code || '',
    capacity: selectedSlot.value.capacity ?? '',
    size: selectedSlot.value.size || 'small',
    description: selectedSlot.value.description || '',
    status: selectedSlot.value.status || 'active',
  })
  slotFormErrors.value = {}
  slotEditError.value = ''
  showSlotEdit.value = true
}

function closeSlotEdit() {
  if (slotSaving.value) return
  showSlotEdit.value = false
  slotFormErrors.value = {}
  slotEditError.value = ''
}

async function updateSlot() {
  if (!selectedSlot.value) return
  slotSaving.value = true
  slotFormErrors.value = {}
  slotEditError.value = ''
  success.value = ''

  try {
    const updatedSlot = await runWithStepUp(() =>
      api.updateCabinetSlot(selectedSlot.value.id, {
        slot_code: slotForm.slot_code.trim(),
        capacity: slotForm.capacity === '' ? null : Number(slotForm.capacity),
        size: slotForm.size,
        description: slotForm.description.trim() || null,
        status: slotForm.status,
      }),
    )
    selectedSlot.value = { ...selectedSlot.value, ...updatedSlot }
    showSlotEdit.value = false
    success.value = `Slot ${updatedSlot.slot_code} was updated successfully.`
    await loadCabinets()
  } catch (requestError) {
    if (isStepUpCancelled(requestError)) return
    slotFormErrors.value = requestError.response?.data?.errors || {}
    slotEditError.value = errorMessage(requestError, 'Unable to update this cabinet slot.')
  } finally {
    slotSaving.value = false
  }
}

onMounted(async () => {
  await loadCabinets()
  if (route.query.slot) await openSlot(route.query.slot, 1, false)
})
</script>

<template>
  <section class="page-header physical-records-header">
    <div>
      <p class="page-kicker">Registrar / Student Management</p>
      <h1 class="page-title">Physical Records</h1>
      <p class="page-description">Locate and manage student paper records by cabinet and storage slot.</p>
    </div>
    <button class="primary-button" type="button" @click="showCreateForm = !showCreateForm">
      {{ showCreateForm ? 'Close Form' : 'Create Cabinet' }}
    </button>
  </section>

  <p v-if="success" class="notice success" role="status">{{ success }}</p>
  <p v-if="error" class="notice error" role="alert">{{ error }}</p>

  <section v-if="showCreateForm" class="records-panel create-panel">
    <div class="section-heading">
      <div>
        <p class="eyebrow">New storage</p>
        <h2>Create Cabinet</h2>
      </div>
      <span>{{ Number(form.rows || 0) * Number(form.columns || 0) }} slots</span>
    </div>
    <form class="cabinet-form" @submit.prevent="createCabinet">
      <label>
        Cabinet Name/Code
        <input v-model.trim="form.cabinet_code" maxlength="50" required placeholder="Example: A" />
        <small v-if="formErrors.cabinet_code">{{ formErrors.cabinet_code[0] }}</small>
      </label>
      <label>
        Rows
        <input v-model.number="form.rows" type="number" min="1" max="50" required />
        <small v-if="formErrors.rows">{{ formErrors.rows[0] }}</small>
      </label>
      <label>
        Columns
        <input v-model.number="form.columns" type="number" min="1" max="50" required />
        <small v-if="formErrors.columns">{{ formErrors.columns[0] }}</small>
      </label>
      <label>
        Capacity per slot
        <span>(optional)</span>
        <input v-model.number="form.slot_capacity" type="number" min="1" placeholder="Example: 10" />
        <small v-if="formErrors.slot_capacity">{{ formErrors.slot_capacity[0] }}</small>
      </label>
      <label class="description-field">
        Description
        <span>(optional)</span>
        <textarea
          v-model.trim="form.description"
          rows="3"
          maxlength="500"
          placeholder="Storage notes or location"
        ></textarea>
        <small v-if="formErrors.description">{{ formErrors.description[0] }}</small>
      </label>
      <div class="form-actions">
        <button class="secondary-button" type="button" :disabled="creating" @click="resetForm">Reset</button>
        <button class="primary-button" type="submit" :disabled="creating">
          {{ creating ? 'Creating…' : 'Create Cabinet' }}
        </button>
      </div>
    </form>
  </section>

  <section v-if="loading" class="records-panel state">Loading cabinets…</section>
  <section v-else-if="!cabinets.length && !error" class="records-panel state">
    <h2>No cabinets yet</h2>
    <p>Create the first cabinet to begin assigning physical student records.</p>
  </section>
  <div v-else class="cabinet-list">
    <article v-for="cabinet in cabinets" :key="cabinet.id" class="cabinet-card">
      <header class="cabinet-heading">
        <div>
          <p class="eyebrow">Storage Cabinet</p>
          <h2>Cabinet {{ cabinetName(cabinet) }}</h2>
          <p v-if="cabinet.description">{{ cabinet.description }}</p>
        </div>
        <div class="cabinet-summary">
          <strong>
            {{ cabinet.occupied_slots_count || 0 }} / {{ cabinet.slots_count ?? cabinet.slots?.length ?? 0 }}
          </strong>
          <span>occupied slots</span>
        </div>
      </header>
      <div class="cabinet-grid">
        <button
          v-for="slot in cabinet.slots"
          :key="slot.id"
          class="slot-drawer"
          :class="{
            occupied: slotCount(slot) > 0,
            selected: selectedSlot?.id === slot.id,
            inactive: slot.status === 'inactive',
            [`size-${slot.size || 'small'}`]: true,
          }"
          type="button"
          @click="openSlot(slot.id)"
        >
          <span class="drawer-handle" aria-hidden="true"></span>
          <strong>{{ slot.slot_code }}</strong>
          <small v-if="slot.capacity">{{ slotCount(slot) }} / {{ slot.capacity }} records</small>
          <small v-else>{{ slotCount(slot) }} {{ slotCount(slot) === 1 ? 'record' : 'records' }}</small>
          <span v-if="slot.status === 'inactive'" class="slot-status">Inactive</span>
        </button>
      </div>
    </article>
  </div>

  <section
    v-if="detailLoading || detailError || selectedSlot"
    ref="detailPanel"
    class="records-panel slot-detail"
    aria-live="polite"
  >
    <div class="slot-detail-heading">
      <div>
        <p class="eyebrow">Cabinet {{ cabinetName(selectedSlot?.cabinet) }}</p>
        <h2>Slot {{ selectedSlot?.slot_code || '' }}</h2>
        <p v-if="selectedSlot">
          {{ selectedSlot.record_count }} stored
          {{ selectedSlot.record_count === 1 ? 'student record' : 'student records' }}
        </p>
        <p v-if="selectedSlot?.description">{{ selectedSlot.description }}</p>
      </div>
      <div class="slot-detail-actions">
        <button v-if="selectedSlot" class="primary-button" type="button" @click="editSlot">Edit Slot</button>
        <button class="secondary-button" type="button" @click="closeSlot">Close</button>
      </div>
    </div>

    <p v-if="detailLoading" class="state">Loading stored student records…</p>
    <p v-else-if="detailError" class="notice error" role="alert">
      {{ detailError }}
    </p>
    <p v-else-if="!students.length" class="empty-state">No student records are assigned to this slot.</p>
    <div v-else class="stored-students">
      <article v-for="student in students" :key="student.id" class="student-record-card">
        <div class="student-summary">
          <div class="student-photo">
            <img v-if="student.profile_photo" :src="student.profile_photo" :alt="`${student.full_name} photo`" />
            <span v-else>
              {{
                student.full_name
                  ?.split(' ')
                  .map((part) => part[0])
                  .slice(0, 2)
                  .join('') || 'ST'
              }}
            </span>
          </div>
          <div>
            <h3>{{ student.full_name }}</h3>
            <p>
              <strong>{{ student.student_number }}</strong>
              ·
              {{ courseLabel(student) }}
            </p>
            <p>
              Year {{ student.year_level }} ·
              {{ title(student.student_status) }}
            </p>
          </div>
          <button class="primary-button" type="button" @click="viewStudent(student.id)">View Student Record</button>
        </div>
        <p v-if="student.location_remarks" class="location-remarks">
          <strong>Location remarks:</strong>
          {{ student.location_remarks }}
        </p>
        <div class="document-columns">
          <div>
            <h4>Available Documents</h4>
            <p v-if="!availableDocuments(student).length" class="empty-list">None marked available.</p>
            <ul v-else>
              <li v-for="document in availableDocuments(student)" :key="document.id">
                {{ documentName(document) }}
              </li>
            </ul>
          </div>
          <div>
            <h4>Missing Documents</h4>
            <p v-if="!missingDocuments(student).length" class="empty-list">None marked missing.</p>
            <ul v-else>
              <li v-for="document in missingDocuments(student)" :key="document.id">
                {{ documentName(document) }}
              </li>
            </ul>
          </div>
        </div>
      </article>
    </div>

    <PaginationControls
      v-if="selectedSlot"
      :current-page="pagination.currentPage"
      :last-page="pagination.lastPage"
      :total="pagination.total"
      :busy="detailLoading"
      total-label="records"
      aria-label="Stored student pages"
      @page-change="(nextPage) => openSlot(selectedSlot.id, nextPage)"
    />
  </section>

  <div v-if="showSlotEdit" class="modal-backdrop" role="presentation" @mousedown.self="closeSlotEdit">
    <section
      class="slot-edit-modal"
      role="dialog"
      aria-modal="true"
      aria-labelledby="slot-edit-title"
      aria-describedby="slot-edit-description"
    >
      <p class="eyebrow">Cabinet {{ cabinetName(selectedSlot?.cabinet) }}</p>
      <h2 id="slot-edit-title">Edit Slot</h2>
      <p id="slot-edit-description">Update this storage slot without changing its assigned student records.</p>

      <p v-if="slotEditError" class="notice error" role="alert">{{ slotEditError }}</p>

      <form class="slot-edit-form" @submit.prevent="updateSlot">
        <label>
          Slot Name / Code
          <input v-model="slotForm.slot_code" required maxlength="100" autocomplete="off" />
          <small v-if="slotFormErrors.slot_code">{{ slotFormErrors.slot_code[0] }}</small>
        </label>
        <label>
          Capacity
          <input v-model="slotForm.capacity" type="number" min="1" placeholder="No limit" />
          <small v-if="slotFormErrors.capacity">{{ slotFormErrors.capacity[0] }}</small>
        </label>
        <label>
          Visual Size
          <select v-model="slotForm.size" required>
            <option value="small">Small</option>
            <option value="medium">Medium</option>
            <option value="large">Large</option>
            <option value="wide">Wide</option>
          </select>
          <small v-if="slotFormErrors.size">{{ slotFormErrors.size[0] }}</small>
        </label>
        <label>
          Status
          <select v-model="slotForm.status" required>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
          </select>
          <small v-if="slotFormErrors.status">{{ slotFormErrors.status[0] }}</small>
        </label>
        <label class="slot-description-field">
          Description <span>(optional)</span>
          <textarea v-model="slotForm.description" rows="3" maxlength="1000"></textarea>
          <small v-if="slotFormErrors.description">{{ slotFormErrors.description[0] }}</small>
        </label>
        <p class="security-message">Saving structural slot changes may require password verification.</p>
        <div class="modal-actions">
          <button class="secondary-button" type="button" :disabled="slotSaving" @click="closeSlotEdit">Cancel</button>
          <button class="primary-button" type="submit" :disabled="slotSaving">
            {{ slotSaving ? 'Saving…' : 'Save Slot' }}
          </button>
        </div>
      </form>
    </section>
  </div>
</template>

<style scoped>
.physical-records-header,
.cabinet-heading,
.slot-detail-heading,
.student-summary,
.section-heading {
  align-items: center;
  display: flex;
  gap: 18px;
  justify-content: space-between;
}
.primary-button,
.secondary-button {
  border: 0;
  border-radius: 9px;
  cursor: pointer;
  font-weight: 700;
  min-height: 40px;
  padding: 9px 14px;
}
.primary-button {
  background: var(--color-dartmouth-green);
  color: #fff;
}
.secondary-button {
  background: var(--color-green-tint);
  color: var(--color-dartmouth-green);
}
button:disabled {
  cursor: not-allowed;
  opacity: 0.55;
}
.records-panel,
.cabinet-card {
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: 15px;
  box-shadow: var(--shadow-soft);
  margin-bottom: 20px;
  padding: 22px;
}
.notice {
  border-radius: 9px;
  margin: 0 0 16px;
  padding: 12px 14px;
}
.notice.success {
  background: #e9f8ee;
  color: #166534;
}
.notice.error {
  background: #fff0f0;
  color: #b42318;
}
.section-heading h2,
.cabinet-heading h2,
.slot-detail-heading h2 {
  margin: 2px 0;
}
.section-heading > span {
  background: #fff6ce;
  border-radius: 999px;
  color: #725400;
  font-size: 0.82rem;
  font-weight: 800;
  padding: 6px 10px;
}
.eyebrow {
  color: var(--color-dark-spring-green);
  font-size: 0.72rem;
  font-weight: 800;
  letter-spacing: 0.1em;
  margin: 0;
  text-transform: uppercase;
}
.cabinet-form {
  display: grid;
  gap: 14px;
  grid-template-columns: 1.5fr 1fr 1fr;
  margin-top: 18px;
}
.cabinet-form label {
  color: var(--color-muted);
  display: grid;
  font-size: 0.82rem;
  font-weight: 700;
  gap: 6px;
}
.cabinet-form label > span {
  font-weight: 400;
}
.cabinet-form input,
.cabinet-form textarea {
  width: 100%;
}
.cabinet-form small {
  color: #b42318;
}
.description-field {
  grid-column: 1 / -1;
}
.form-actions {
  display: flex;
  gap: 9px;
  grid-column: 1 / -1;
  justify-content: flex-end;
}
.cabinet-list {
  display: grid;
  gap: 20px;
}
.cabinet-list,
.cabinet-card,
.cabinet-grid {
  max-width: 100%;
  min-width: 0;
}
.cabinet-heading {
  border-bottom: 1px solid var(--color-border);
  margin: -2px 0 18px;
  padding-bottom: 16px;
}
.cabinet-heading > div > p:last-child,
.slot-detail-heading p {
  color: var(--color-muted);
  margin: 5px 0 0;
}
.cabinet-summary {
  background: var(--color-green-tint);
  border-radius: 10px;
  min-width: 112px;
  padding: 10px 12px;
  text-align: center;
}
.cabinet-summary strong,
.cabinet-summary span {
  display: block;
}
.cabinet-summary strong {
  color: var(--color-dartmouth-green);
}
.cabinet-summary span {
  color: var(--color-muted);
  font-size: 0.72rem;
  margin-top: 2px;
}
.cabinet-grid {
  display: grid;
  gap: 12px;
  grid-auto-flow: dense;
  grid-auto-rows: minmax(104px, auto);
  grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
  padding: 3px;
}
.slot-drawer {
  background: linear-gradient(180deg, #f8faf8, #e7eee8);
  border: 2px solid #cad8cd;
  border-radius: 8px;
  box-shadow:
    inset 0 -5px 0 rgba(16, 106, 46, 0.07),
    0 3px 7px rgba(31, 31, 31, 0.08);
  color: var(--color-eerie-black);
  cursor: pointer;
  display: grid;
  height: 100%;
  justify-items: center;
  max-width: 100%;
  min-height: 104px;
  min-width: 0;
  padding: 14px 10px 11px;
  position: relative;
}
.slot-drawer.size-small {
  grid-column: span 1;
}
.slot-drawer.size-medium {
  grid-column: span 2;
}
.slot-drawer.size-large {
  grid-column: span 2;
  grid-row: span 2;
}
.slot-drawer.size-wide {
  grid-column: span 3;
}
.slot-drawer:hover,
.slot-drawer.selected {
  border-color: var(--color-naples-yellow);
  box-shadow:
    0 0 0 3px rgba(244, 211, 94, 0.25),
    0 5px 11px rgba(31, 31, 31, 0.1);
  transform: translateY(-1px);
}
.slot-drawer.occupied {
  background: linear-gradient(180deg, #f2faf4, #dbeadf);
}
.slot-drawer.inactive {
  background: #f1f2f1;
  border-style: dashed;
  opacity: 0.72;
}
.slot-drawer strong {
  color: var(--color-dartmouth-green);
  font-size: 1.05rem;
  margin-top: 7px;
  max-width: 100%;
  overflow-wrap: anywhere;
}
.slot-drawer small {
  color: var(--color-muted);
  max-width: 100%;
  overflow-wrap: anywhere;
  text-align: center;
}
.drawer-handle {
  background: #809084;
  border-radius: 999px;
  height: 5px;
  width: 34px;
}
.slot-status {
  background: #e2e4e2;
  border-radius: 999px;
  color: #555f57;
  font-size: 0.68rem;
  font-weight: 800;
  padding: 3px 7px;
  text-transform: uppercase;
}
.slot-detail {
  scroll-margin-top: 16px;
}
.slot-detail-actions {
  display: flex;
  gap: 9px;
}
.slot-detail-heading {
  border-bottom: 1px solid var(--color-border);
  padding-bottom: 15px;
}
.state,
.empty-state {
  color: var(--color-muted);
  padding: 28px;
  text-align: center;
}
.state h2 {
  color: var(--color-eerie-black);
  margin-top: 0;
}
.stored-students {
  display: grid;
  gap: 15px;
  margin-top: 18px;
}
.student-record-card {
  border: 1px solid var(--color-border);
  border-radius: 12px;
  overflow: hidden;
}
.student-summary {
  background: linear-gradient(110deg, #f4faf6, #fffdf4);
  padding: 15px;
}
.student-summary > div:nth-child(2) {
  flex: 1;
}
.student-summary h3 {
  margin: 0 0 4px;
}
.student-summary p {
  color: var(--color-muted);
  margin: 3px 0;
}
.student-photo {
  align-items: center;
  background: var(--color-dartmouth-green);
  border-radius: 10px;
  color: #fff;
  display: flex;
  font-weight: 800;
  height: 66px;
  justify-content: center;
  overflow: hidden;
  width: 56px;
}
.student-photo img {
  height: 100%;
  object-fit: cover;
  width: 100%;
}
.location-remarks {
  border-bottom: 1px solid var(--color-border);
  margin: 0;
  padding: 11px 15px;
}
.document-columns {
  display: grid;
  gap: 14px;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  padding: 15px;
}
.document-columns > div {
  background: var(--color-anti-flash-white);
  border-radius: 9px;
  padding: 12px;
}
.document-columns h4 {
  margin: 0 0 7px;
}
.document-columns ul {
  margin: 0;
  padding-left: 20px;
}
.document-columns li {
  margin: 5px 0;
}
.empty-list {
  color: var(--color-muted);
  margin: 0;
}
.modal-backdrop {
  align-items: center;
  background: rgb(7 24 13 / 58%);
  display: flex;
  inset: 0;
  justify-content: center;
  overflow-y: auto;
  padding: 20px;
  position: fixed;
  z-index: 1200;
}
.slot-edit-modal {
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: 15px;
  box-shadow: 0 24px 70px rgb(0 0 0 / 24%);
  max-width: 620px;
  padding: 26px;
  width: 100%;
}
.slot-edit-modal h2 {
  margin: 4px 0 5px;
}
.slot-edit-modal > p:not(.eyebrow, .notice) {
  color: var(--color-muted);
  margin: 0 0 18px;
}
.slot-edit-form {
  display: grid;
  gap: 14px;
  grid-template-columns: repeat(2, minmax(0, 1fr));
}
.slot-edit-form label {
  color: var(--color-muted);
  display: grid;
  font-size: 0.82rem;
  font-weight: 700;
  gap: 6px;
}
.slot-edit-form label > span {
  font-weight: 400;
}
.slot-edit-form input,
.slot-edit-form select,
.slot-edit-form textarea {
  border: 1px solid var(--color-border);
  border-radius: 8px;
  color: var(--color-eerie-black);
  min-height: 42px;
  padding: 9px 11px;
  width: 100%;
}
.slot-edit-form textarea {
  resize: vertical;
}
.slot-edit-form small {
  color: #b42318;
}
.slot-description-field,
.security-message,
.modal-actions {
  grid-column: 1 / -1;
}
.security-message {
  background: var(--color-green-tint);
  border-radius: 8px;
  color: var(--color-dartmouth-green);
  font-size: 0.82rem;
  margin: 0;
  padding: 10px 12px;
}
.modal-actions {
  display: flex;
  gap: 9px;
  justify-content: flex-end;
}
@media (max-width: 900px) {
  .slot-drawer.size-wide {
    grid-column: span 2;
  }
}
@media (max-width: 700px) {
  .physical-records-header,
  .cabinet-heading,
  .slot-detail-heading,
  .student-summary {
    align-items: stretch;
    flex-direction: column;
  }
  .physical-records-header .primary-button,
  .student-summary .primary-button {
    width: 100%;
  }
  .slot-detail-actions {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
  .cabinet-form,
  .document-columns {
    grid-template-columns: 1fr;
  }
  .description-field {
    grid-column: auto;
  }
  .cabinet-summary {
    text-align: left;
  }
  .student-photo {
    height: 78px;
    width: 66px;
  }
  .slot-drawer.size-small,
  .slot-drawer.size-medium,
  .slot-drawer.size-large,
  .slot-drawer.size-wide {
    grid-column: span 1;
    grid-row: span 1;
  }
  .slot-edit-form {
    grid-template-columns: 1fr;
  }
  .slot-description-field,
  .security-message,
  .modal-actions {
    grid-column: auto;
  }
}
</style>
