<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { apiClient } from '../../services/apiClient'
import { useAuthStore } from '../../stores/authStore'
import { ROLES } from '../../config/accessControl'
import { isStepUpCancelled, useStepUpAuth } from '../../composables/useStepUpAuth'
import { documentRequestReturnContext } from '../document-request/documentRequestNavigation'
import { physicalRecordsService } from './physicalRecordsService'

const route = useRoute()
const router = useRouter()
const authStore = useAuthStore()
const { runWithStepUp } = useStepUpAuth()
const student = ref(null)
const loading = ref(false)
const error = ref('')
const editMode = ref(false)
const saving = ref(false)
const success = ref('')
const validationErrors = ref({})
const form = reactive({})
const cabinets = ref([])
const locationEditing = ref(false)
const locationLoading = ref(false)
const locationSaving = ref(false)
const locationError = ref('')
const locationForm = reactive({ cabinet_slot_id: '', remarks: '' })
const text = (value) =>
  value
    ? String(value)
        .replaceAll('_', ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase())
    : 'Not available'
const date = (value) =>
  value
    ? new Intl.DateTimeFormat('en-PH', { dateStyle: 'long' }).format(new Date(`${String(value).slice(0, 10)}T00:00:00`))
    : 'Not available'
const initials = computed(
  () =>
    student.value?.full_name
      ?.split(' ')
      .map((part) => part[0])
      .slice(0, 2)
      .join('')
      .toUpperCase() || 'ST',
)
const physicalLocation = computed(() => student.value?.physical_record_location || null)
const returnContext = computed(() => documentRequestReturnContext(route.query))
const canManagePhysicalRecords = computed(() => authStore.currentRole === ROLES.REGISTRAR_STAFF)
const activeCabinets = computed(() =>
  cabinets.value
    .map((cabinet) => ({
      ...cabinet,
      slots: (cabinet.slots || []).filter((slot) => (slot.status || 'active') === 'active'),
    }))
    .filter((cabinet) => cabinet.slots.length),
)
const slotOptionLabel = (slot) =>
  slot.capacity
    ? `${slot.slot_code} — ${slot.record_count} / ${slot.capacity} records`
    : `${slot.slot_code} — ${slot.record_count} records`

async function loadProfile() {
  loading.value = true
  error.value = ''
  student.value = null
  locationEditing.value = false
  locationError.value = ''
  try {
    const { data } = await apiClient.get(`/students/${route.params.id}`)
    student.value = data.data
  } catch (requestError) {
    error.value =
      requestError.response?.status === 404
        ? 'This student record could not be found.'
        : requestError.response?.data?.message || 'Unable to load the student profile.'
  } finally {
    loading.value = false
  }
}

async function startLocationEdit() {
  locationEditing.value = true
  locationError.value = ''
  Object.assign(locationForm, {
    cabinet_slot_id: physicalLocation.value?.cabinet_slot?.id || '',
    remarks: physicalLocation.value?.remarks || '',
  })

  locationLoading.value = true
  try {
    const data = await physicalRecordsService.cabinets()
    cabinets.value = Array.isArray(data) ? data : []
  } catch (requestError) {
    locationError.value = requestError.response?.data?.message || 'Unable to load cabinet slots.'
  } finally {
    locationLoading.value = false
  }
}

function cancelLocationEdit() {
  locationEditing.value = false
  locationError.value = ''
}

async function saveLocation() {
  if (!locationForm.cabinet_slot_id) {
    locationError.value = 'Select a cabinet slot.'
    return
  }

  locationSaving.value = true
  locationError.value = ''
  success.value = ''
  try {
    const location = await runWithStepUp(() =>
      physicalRecordsService.assignStudentLocation(student.value.id, {
        cabinet_slot_id: Number(locationForm.cabinet_slot_id),
        remarks: locationForm.remarks.trim() || null,
      }),
    )
    student.value = { ...student.value, physical_record_location: location }
    locationEditing.value = false
    success.value = 'Physical record location updated.'
  } catch (requestError) {
    if (isStepUpCancelled(requestError)) return
    locationError.value =
      requestError.response?.data?.errors?.cabinet_slot_id?.[0] ||
      requestError.response?.data?.message ||
      'Unable to update the physical record location.'
  } finally {
    locationSaving.value = false
  }
}

function viewCabinet() {
  if (!physicalLocation.value) return
  router.push({
    name: 'physical-records',
    query: {
      cabinet: physicalLocation.value.cabinet_slot.cabinet.id,
      slot: physicalLocation.value.cabinet_slot.id,
    },
  })
}

function returnToSource() {
  if (returnContext.value) router.push(returnContext.value.to)
}

function startEdit() {
  Object.assign(form, {
    student_number: student.value.student_number || '',
    first_name: student.value.profile?.first_name || '',
    middle_name: student.value.profile?.middle_name || '',
    last_name: student.value.profile?.last_name || '',
    suffix: student.value.profile?.suffix || '',
    birth_date: student.value.profile?.birth_date || '',
    gender: student.value.profile?.gender || '',
    civil_status: student.value.profile?.civil_status || '',
    nationality: student.value.profile?.nationality || '',
    contact_number: student.value.profile?.contact_number || '',
    address: student.value.profile?.address || '',
    course_id: student.value.course?.id || '',
    year_level: student.value.year_level,
    student_status: student.value.student_status,
  })
  validationErrors.value = {}
  success.value = ''
  editMode.value = true
}

function cancelEdit() {
  editMode.value = false
  validationErrors.value = {}
}

async function saveChanges() {
  saving.value = true
  validationErrors.value = {}
  success.value = ''
  error.value = ''
  try {
    const { data } = await runWithStepUp(() => apiClient.patch(`/students/${route.params.id}`, form))
    student.value = data.data
    editMode.value = false
    success.value = data.message
  } catch (requestError) {
    if (isStepUpCancelled(requestError)) return
    validationErrors.value = requestError.response?.data?.errors || {}
    error.value = requestError.response?.data?.message || 'Unable to save the student record.'
  } finally {
    saving.value = false
  }
}

watch(() => route.params.id, loadProfile)
onMounted(loadProfile)
</script>

<template>
  <section class="page-header">
    <button v-if="returnContext" class="contextual-back" type="button" @click="returnToSource">
      {{ returnContext.label }}
    </button>
    <p class="page-kicker">Registrar / Student Management</p>
    <h1 class="page-title">Student Profile</h1>
    <p class="page-description">Official Student Management record and registrar activity.</p>
  </section>

  <section v-if="loading" class="record-card state">Loading student profile…</section>
  <section v-else-if="error && !student" class="record-card state error" role="alert">
    {{ error }}
  </section>

  <template v-else-if="student">
    <section class="student-id-card" aria-label="Student identification card">
      <div class="id-card-banner">
        <span>CDM Portal</span>
        <strong>Student Record</strong>
      </div>
      <div class="id-card-body">
        <div class="id-photo-frame">
          <img
            v-if="student.profile?.profile_photo"
            :src="student.profile.profile_photo"
            :alt="`${student.full_name} photo`"
          />
          <div v-else class="photo-placeholder" aria-label="Student photo placeholder">
            {{ initials }}
          </div>
        </div>
        <div class="id-identity">
          <p class="id-label">Full Name</p>
          <h2>{{ student.full_name }}</h2>
          <p class="id-number">{{ student.student_number }}</p>
          <div class="id-facts">
            <div>
              <span>Course</span>
              <strong>{{ student.course?.code }} — {{ student.course?.name }}</strong>
            </div>
            <div>
              <span>Year Level</span>
              <strong>Year {{ student.year_level }}</strong>
            </div>
            <div>
              <span>Student Status</span>
              <strong>
                <span class="badge student-status">{{ text(student.student_status) }}</span>
              </strong>
            </div>
          </div>
        </div>
        <div class="id-account">
          <span>Account</span>
          <strong>{{ text(student.account_status) }}</strong>
        </div>
      </div>
    </section>

    <p v-if="success" class="success" role="status">{{ success }}</p>
    <p v-if="editMode && error" class="save-error" role="alert">{{ error }}</p>
    <p v-if="editMode" class="security-note">Saving official record changes may require password verification.</p>

    <nav class="action-bar" aria-label="Student profile actions">
      <button v-if="!editMode && !returnContext" class="back" type="button" @click="router.push({ name: 'student-management' })">
        ← Back to Student List
      </button>
      <button v-if="!editMode" type="button" @click="startEdit">✏ Edit Student</button>
      <button
        v-if="!editMode"
        type="button"
        @click="router.push({ name: 'student-documents', params: { id: student.id } })"
      >
        📁 Documents
      </button>
      <button v-if="!editMode" type="button" disabled>🖨 Print</button>
      <button v-if="editMode" type="button" @click="cancelEdit">Cancel</button>
      <button v-if="editMode" class="back" type="button" :disabled="saving" @click="saveChanges">
        {{ saving ? 'Saving…' : 'Save Changes' }}
      </button>
    </nav>

    <section class="record-card">
      <h2>Personal Information</h2>
      <dl class="information-grid">
        <div>
          <dt>First Name</dt>
          <dd>
            <input v-if="editMode" v-model.trim="form.first_name" />
            <template v-else>{{ text(student.profile?.first_name) }}</template>
            <small v-if="validationErrors.first_name">{{ validationErrors.first_name[0] }}</small>
          </dd>
        </div>
        <div>
          <dt>Middle Name</dt>
          <dd>
            <input v-if="editMode" v-model.trim="form.middle_name" />
            <template v-else>{{ text(student.profile?.middle_name) }}</template>
          </dd>
        </div>
        <div>
          <dt>Last Name</dt>
          <dd>
            <input v-if="editMode" v-model.trim="form.last_name" />
            <template v-else>{{ text(student.profile?.last_name) }}</template>
            <small v-if="validationErrors.last_name">{{ validationErrors.last_name[0] }}</small>
          </dd>
        </div>
        <div>
          <dt>Suffix</dt>
          <dd>
            <input v-if="editMode" v-model.trim="form.suffix" />
            <template v-else>{{ text(student.profile?.suffix) }}</template>
          </dd>
        </div>
        <div>
          <dt>Birth Date</dt>
          <dd>
            <input v-if="editMode" v-model="form.birth_date" type="date" />
            <template v-else>{{ date(student.profile?.birth_date) }}</template>
          </dd>
        </div>
        <div>
          <dt>Gender</dt>
          <dd>
            <input v-if="editMode" v-model.trim="form.gender" />
            <template v-else>{{ text(student.profile?.gender) }}</template>
          </dd>
        </div>
        <div>
          <dt>Civil Status</dt>
          <dd>
            <input v-if="editMode" v-model.trim="form.civil_status" />
            <template v-else>{{ text(student.profile?.civil_status) }}</template>
          </dd>
        </div>
        <div>
          <dt>Nationality</dt>
          <dd>
            <input v-if="editMode" v-model.trim="form.nationality" />
            <template v-else>{{ text(student.profile?.nationality) }}</template>
          </dd>
        </div>
      </dl>
    </section>

    <section class="record-card">
      <h2>Contact Information</h2>
      <dl class="information-grid">
        <div>
          <dt>Email</dt>
          <dd>{{ text(student.profile?.email) }}</dd>
        </div>
        <div>
          <dt>Contact Number</dt>
          <dd>
            <input v-if="editMode" v-model.trim="form.contact_number" />
            <template v-else>{{ text(student.profile?.contact_number) }}</template>
          </dd>
        </div>
        <div class="wide">
          <dt>Complete Address</dt>
          <dd>
            <textarea v-if="editMode" v-model.trim="form.address" rows="2"></textarea>
            <template v-else>{{ text(student.profile?.address) }}</template>
          </dd>
        </div>
      </dl>
    </section>

    <section class="record-card">
      <h2>Academic Information</h2>
      <dl class="information-grid">
        <div>
          <dt>Student Number</dt>
          <dd>
            <input v-if="editMode" v-model.trim="form.student_number" maxlength="20" />
            <template v-else>{{ student.student_number }}</template>
            <small v-if="validationErrors.student_number">{{ validationErrors.student_number[0] }}</small>
          </dd>
        </div>
        <div>
          <dt>Course</dt>
          <dd>
            <input v-if="editMode" v-model.number="form.course_id" type="number" min="1" />
            <template v-else>{{ student.course?.code }} — {{ student.course?.name }}</template>
            <small v-if="validationErrors.course_id">{{ validationErrors.course_id[0] }}</small>
          </dd>
        </div>
        <div>
          <dt>Curriculum</dt>
          <dd>{{ student.curriculum?.code }} — {{ student.curriculum?.name }}</dd>
        </div>
        <div>
          <dt>Department</dt>
          <dd>{{ student.department?.code }} — {{ student.department?.name }}</dd>
        </div>
        <div>
          <dt>Year Level</dt>
          <dd>
            <input v-if="editMode" v-model.number="form.year_level" type="number" min="1" max="20" />
            <template v-else>Year {{ student.year_level }}</template>
            <small v-if="validationErrors.year_level">{{ validationErrors.year_level[0] }}</small>
          </dd>
        </div>
        <div>
          <dt>Student Status</dt>
          <dd>
            <select v-if="editMode" v-model="form.student_status">
              <option value="regular">Regular</option>
              <option value="irregular">Irregular</option>
              <option value="graduated">Graduated</option>
              <option value="transferred">Transferred</option>
              <option value="dropped">Dropped</option>
              <option value="leave_of_absence">Leave of absence</option>
            </select>
            <template v-else>{{ text(student.student_status) }}</template>
            <small v-if="validationErrors.student_status">{{ validationErrors.student_status[0] }}</small>
          </dd>
        </div>
        <div>
          <dt>Semester</dt>
          <dd>{{ text(student.semester?.name) }}</dd>
        </div>
        <div>
          <dt>Academic Year</dt>
          <dd>{{ text(student.academic_year?.school_year) }}</dd>
        </div>
        <div>
          <dt>Admission Date</dt>
          <dd>{{ date(student.admission_date) }}</dd>
        </div>
        <div>
          <dt>Enrollment Status</dt>
          <dd>{{ text(student.enrollment_status) }}</dd>
        </div>
      </dl>
    </section>

    <section class="record-card">
      <h2>Account Information</h2>
      <dl class="information-grid">
        <div>
          <dt>Username</dt>
          <dd>{{ text(student.account?.username) }}</dd>
        </div>
        <div>
          <dt>Account Role</dt>
          <dd>{{ text(student.account?.role) }}</dd>
        </div>
        <div>
          <dt>Linked Account Status</dt>
          <dd>{{ student.account?.linked ? 'Linked' : 'Not linked' }}</dd>
        </div>
        <div>
          <dt>Last Login</dt>
          <dd>{{ date(student.account?.last_login) }}</dd>
        </div>
        <div>
          <dt>First Login Required</dt>
          <dd>{{ student.account?.is_first_login ? 'Yes' : 'No' }}</dd>
        </div>
      </dl>
    </section>

    <section class="record-card">
      <h2>Registrar Information</h2>
      <dl class="information-grid">
        <div>
          <dt>Record Created</dt>
          <dd>{{ date(student.record_created_at) }}</dd>
        </div>
        <div>
          <dt>Last Updated</dt>
          <dd>{{ date(student.record_updated_at) }}</dd>
        </div>
        <div>
          <dt>Created By</dt>
          <dd>{{ text(student.created_by) }}</dd>
        </div>
      </dl>
    </section>

    <section class="record-card physical-location-card">
      <div class="section-heading">
        <div>
          <p class="section-kicker">Paper records storage</p>
          <h2>Physical Record Location</h2>
        </div>
        <div v-if="canManagePhysicalRecords && !locationEditing" class="location-actions">
          <button v-if="physicalLocation" type="button" @click="viewCabinet">View Cabinet</button>
          <button type="button" @click="startLocationEdit">
            {{ physicalLocation ? 'Change Location' : 'Assign Location' }}
          </button>
        </div>
      </div>

      <div v-if="physicalLocation && !locationEditing" class="location-summary">
        <div>
          <span>Cabinet</span>
          <strong>{{ physicalLocation.cabinet_slot.cabinet.cabinet_code }}</strong>
        </div>
        <div>
          <span>Slot</span>
          <strong>{{ physicalLocation.cabinet_slot.slot_code }}</strong>
        </div>
        <div>
          <span>Assigned</span>
          <strong>{{ date(physicalLocation.assigned_at) }}</strong>
        </div>
        <div class="location-remarks">
          <span>Remarks</span>
          <strong>{{ physicalLocation.remarks || 'No remarks' }}</strong>
        </div>
      </div>
      <p v-else-if="!locationEditing" class="empty-state">No physical record location assigned.</p>

      <form v-if="locationEditing" class="location-form" @submit.prevent="saveLocation">
        <p class="security-note">Changing the physical record location may require password verification.</p>
        <p v-if="locationError" class="save-error" role="alert">
          {{ locationError }}
        </p>
        <p v-if="locationLoading" class="empty-state">Loading cabinet slots…</p>
        <template v-else>
          <label>
            Cabinet Slot
            <select v-model="locationForm.cabinet_slot_id" required>
              <option value="" disabled>Select a cabinet slot</option>
              <optgroup v-for="cabinet in activeCabinets" :key="cabinet.id" :label="`Cabinet ${cabinet.cabinet_code}`">
                <option v-for="slot in cabinet.slots" :key="slot.id" :value="slot.id">
                  {{ slotOptionLabel(slot) }}
                </option>
              </optgroup>
            </select>
          </label>
          <label>
            Registrar Remarks
            <span>(optional)</span>
            <textarea v-model.trim="locationForm.remarks" rows="2" maxlength="500"></textarea>
          </label>
          <p v-if="!activeCabinets.length" class="empty-state">
            No cabinets are available. Create a cabinet from Physical Records first.
          </p>
          <div class="location-form-actions">
            <button type="button" :disabled="locationSaving" @click="cancelLocationEdit">Cancel</button>
            <button class="location-save" type="submit" :disabled="locationSaving || !activeCabinets.length">
              {{ locationSaving ? 'Saving…' : 'Change Physical Record Location' }}
            </button>
          </div>
        </template>
      </form>
    </section>

    <section class="record-card">
      <div class="section-heading">
        <h2>Document Records</h2>
        <button
          type="button"
          @click="
            router.push({
              name: 'student-documents',
              params: { id: student.id },
            })
          "
        >
          View document page
        </button>
      </div>
      <p v-if="!student.documents?.length" class="empty-state">No StudentDocument records exist for this student.</p>
      <div v-else class="data-table document-records" role="table">
        <div class="data-row data-head" role="row">
          <strong>Document Name</strong>
          <strong>Status</strong>
          <strong>Remarks</strong>
          <strong>Received Date</strong>
        </div>
        <div v-for="document in student.documents" :key="document.id" class="data-row" role="row">
          <span>{{ document.name }}</span>
          <span>
            <span class="badge" :class="document.status">{{ text(document.status) }}</span>
          </span>
          <span>{{ document.remarks || '—' }}</span>
          <span>{{ date(document.received_at) }}</span>
        </div>
      </div>
    </section>

    <section class="record-card">
      <h2>Recent Document Requests</h2>
      <p v-if="!student.recent_document_requests?.length" class="empty-state">
        No document requests recorded for this student.
      </p>
      <div v-else class="data-table request-records" role="table">
        <div class="data-row data-head" role="row">
          <strong>Request</strong>
          <strong>Document</strong>
          <strong>Status</strong>
          <strong>Request Date</strong>
          <strong>Remarks</strong>
        </div>
        <div v-for="request in student.recent_document_requests" :key="request.id" class="data-row" role="row">
          <span>#{{ request.id }}</span>
          <span>{{ request.document }}</span>
          <span>
            <span class="badge" :class="request.status">{{ text(request.status) }}</span>
          </span>
          <span>{{ date(request.request_date) }}</span>
          <span>{{ request.remarks || '—' }}</span>
        </div>
      </div>
    </section>
  </template>
</template>

<style scoped>
.contextual-back {
  align-items: center;
  align-self: flex-start;
  background: transparent;
  border: 0;
  color: var(--color-dartmouth-green);
  cursor: pointer;
  display: inline-flex;
  font-weight: 800;
  margin: 0 0 12px;
  padding: 0;
}
.contextual-back:hover {
  text-decoration: underline;
}
.record-card {
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: 12px;
  box-shadow: var(--shadow-soft);
  margin-bottom: 20px;
  padding: 24px;
}
.student-id-card {
  background: #fff;
  border: 1px solid rgba(0, 0, 0, 0.12);
  border-radius: 18px;
  box-shadow: 0 14px 32px rgba(16, 86, 50, 0.14);
  margin-bottom: 20px;
  overflow: hidden;
}
.id-card-banner {
  align-items: center;
  background: linear-gradient(90deg, var(--color-dartmouth-green), var(--color-dark-spring-green));
  color: #fff;
  display: flex;
  justify-content: space-between;
  padding: 11px 20px;
}
.id-card-banner span {
  font-weight: 800;
  letter-spacing: 0.04em;
}
.id-card-banner strong {
  color: var(--color-naples-yellow);
  font-size: 0.8rem;
  letter-spacing: 0.12em;
  text-transform: uppercase;
}
.id-card-body {
  align-items: center;
  background: linear-gradient(135deg, #fff 0%, #f0f8f2 72%, #fff7d6 100%);
  display: grid;
  gap: 24px;
  grid-template-columns: auto 1fr auto;
  padding: 26px;
}
.id-photo-frame {
  background: #fff;
  border: 5px solid #fff;
  border-radius: 14px;
  box-shadow: 0 5px 18px rgba(0, 0, 0, 0.16);
  height: 145px;
  overflow: hidden;
  width: 118px;
}
.id-photo-frame img,
.photo-placeholder {
  height: 100%;
  object-fit: cover;
  width: 100%;
}
.photo-placeholder {
  align-items: center;
  background: var(--color-dartmouth-green);
  color: #fff;
  display: flex;
  font-size: 2rem;
  font-weight: 800;
  justify-content: center;
}
.id-label {
  color: var(--color-muted);
  font-size: 0.72rem;
  font-weight: 800;
  letter-spacing: 0.1em;
  margin: 0;
  text-transform: uppercase;
}
.id-identity h2 {
  color: var(--color-eerie-black);
  font-size: clamp(1.55rem, 3vw, 2.25rem);
  margin: 4px 0;
}
.id-number {
  color: var(--color-dartmouth-green);
  font-size: 1rem;
  font-weight: 800;
  margin: 0 0 15px;
}
.id-facts {
  display: grid;
  gap: 10px;
  grid-template-columns: minmax(180px, 2fr) minmax(90px, 0.7fr) minmax(120px, 0.8fr);
}
.id-facts div {
  border-left: 3px solid var(--color-naples-yellow);
  padding-left: 9px;
}
.id-facts span,
.id-account span {
  color: var(--color-muted);
  display: block;
  font-size: 0.7rem;
  font-weight: 800;
  margin-bottom: 3px;
  text-transform: uppercase;
}
.id-facts strong {
  font-size: 0.86rem;
}
.id-account {
  align-self: start;
  background: #fff;
  border: 1px solid var(--color-border);
  border-radius: 9px;
  padding: 9px 12px;
  text-align: right;
}
.id-account strong {
  color: var(--color-dartmouth-green);
  font-size: 0.85rem;
}
.badge {
  border-radius: 999px;
  display: inline-block;
  font-size: 0.78rem;
  font-weight: 700;
  padding: 5px 9px;
}
.student-status,
.badge.available,
.badge.released {
  background: #dcfce7;
  color: #166534;
}
.badge.missing,
.badge.rejected,
.badge.cancelled {
  background: #fee2e2;
  color: #991b1b;
}
.badge.pending {
  background: #fff4cc;
  color: #8a6100;
}
.badge.processing,
.badge.ready_for_release {
  background: #e0f2fe;
  color: #075985;
}
.action-bar {
  display: flex;
  flex-wrap: wrap;
  gap: 9px;
  margin: 0 0 20px;
}
.action-bar button,
.section-heading button {
  background: var(--color-green-tint);
  border: 1px solid transparent;
  border-radius: 7px;
  color: var(--color-dartmouth-green);
  cursor: pointer;
  min-height: 40px;
  padding: 8px 13px;
}
.action-bar .back {
  background: var(--color-dartmouth-green);
  color: #fff;
}
.action-bar button:disabled {
  cursor: not-allowed;
  opacity: 0.58;
}
.record-card h2 {
  font-size: 1.1rem;
  margin: 0 0 18px;
}
.information-grid {
  display: grid;
  gap: 12px;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  margin: 0;
}
.information-grid div {
  background: var(--color-anti-flash-white);
  border-radius: 7px;
  min-width: 0;
  padding: 12px;
}
.information-grid dt {
  color: var(--color-muted);
  font-size: 0.75rem;
  font-weight: 700;
}
.information-grid dd {
  margin: 5px 0 0;
  overflow-wrap: anywhere;
}
.information-grid input,
.information-grid select,
.information-grid textarea {
  border: 1px solid var(--color-border);
  border-radius: 5px;
  padding: 7px;
  width: 100%;
}
.information-grid small {
  color: #9c2222;
  display: block;
  margin-top: 5px;
}
.wide {
  grid-column: span 2;
}
.section-heading {
  align-items: center;
  display: flex;
  gap: 12px;
  justify-content: space-between;
}
.section-heading h2 {
  margin-bottom: 0;
}
.section-heading > div h2 {
  margin: 2px 0 0;
}
.section-kicker {
  color: var(--color-dark-spring-green);
  font-size: 0.72rem;
  font-weight: 800;
  letter-spacing: 0.09em;
  margin: 0;
  text-transform: uppercase;
}
.location-actions,
.location-form-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 9px;
}
.location-summary {
  display: grid;
  gap: 12px;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  margin-top: 18px;
}
.location-summary > div {
  background: linear-gradient(135deg, #f4faf6, #fffdf3);
  border: 1px solid var(--color-border);
  border-radius: 9px;
  padding: 13px;
}
.location-summary span {
  color: var(--color-muted);
  display: block;
  font-size: 0.72rem;
  font-weight: 800;
  margin-bottom: 5px;
  text-transform: uppercase;
}
.location-summary strong {
  color: var(--color-dartmouth-green);
}
.location-summary .location-remarks {
  grid-column: 1 / -1;
}
.location-form {
  display: grid;
  gap: 13px;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  margin-top: 18px;
}
.location-form label {
  color: var(--color-muted);
  display: grid;
  font-size: 0.8rem;
  font-weight: 700;
  gap: 6px;
}
.location-form label span {
  font-weight: 400;
}
.location-form select,
.location-form textarea {
  width: 100%;
}
.location-form .save-error,
.location-form .empty-state,
.location-form .security-note,
.location-form-actions {
  grid-column: 1 / -1;
}
.location-form-actions {
  justify-content: flex-end;
}
.location-form-actions button {
  background: var(--color-green-tint);
  border: 0;
  border-radius: 7px;
  color: var(--color-dartmouth-green);
  cursor: pointer;
  min-height: 40px;
  padding: 8px 13px;
}
.location-form-actions .location-save {
  background: var(--color-dartmouth-green);
  color: #fff;
}
.data-table {
  overflow-x: auto;
}
.data-row {
  align-items: center;
  border-top: 1px solid var(--color-border);
  display: grid;
  gap: 12px;
  min-width: 760px;
  padding: 12px 4px;
}
.document-records .data-row {
  grid-template-columns: 1.3fr 0.7fr 1.7fr 1fr;
}
.request-records .data-row {
  grid-template-columns: 0.5fr 1.4fr 0.9fr 1fr 1.5fr;
}
.data-head {
  border-top: 0;
  color: var(--color-muted);
  font-size: 0.75rem;
  text-transform: uppercase;
}
.empty-state {
  color: var(--color-muted);
  margin: 0;
}
.security-note {
  color: var(--color-muted);
  font-size: 0.82rem;
  margin: -6px 0 14px;
}
.location-form .security-note {
  margin: 0;
}
.success,
.save-error {
  border-radius: 7px;
  margin: 0 0 16px;
  padding: 12px;
}
.success {
  background: #e2f4e7;
  color: #106a2e;
}
.save-error,
.error {
  background: #fce8e8;
  color: #9c2222;
}
.state {
  color: var(--color-muted);
  text-align: center;
}

@media (max-width: 900px) {
  .id-card-body {
    align-items: start;
    grid-template-columns: auto 1fr;
  }
  .id-account {
    grid-column: 1 / -1;
    justify-self: stretch;
    text-align: left;
  }
  .id-facts {
    grid-template-columns: 1fr 1fr;
  }
}

@media (max-width: 720px) {
  .information-grid,
  .location-summary,
  .location-form {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (max-width: 520px) {
  .student-id-card,
  .record-card {
    border-radius: 12px;
  }
  .id-card-body {
    grid-template-columns: 1fr;
    padding: 20px;
  }
  .id-photo-frame {
    height: 132px;
    width: 106px;
  }
  .id-facts,
  .information-grid,
  .location-summary,
  .location-form {
    grid-template-columns: 1fr;
  }
  .wide {
    grid-column: auto;
  }
  .action-bar button {
    flex: 1;
  }
  .section-heading {
    align-items: stretch;
    flex-direction: column;
  }
  .location-summary .location-remarks,
  .location-form .save-error,
  .location-form .empty-state,
  .location-form-actions {
    grid-column: auto;
  }
  .location-actions button,
  .location-form-actions button {
    flex: 1;
  }
}
</style>
