<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { apiClient } from '../../services/apiClient'

const route = useRoute()
const router = useRouter()
const student = ref(null)
const loading = ref(false)
const error = ref('')
const editMode = ref(false)
const saving = ref(false)
const success = ref('')
const validationErrors = ref({})
const form = reactive({})
const text = (value) => value ? String(value).replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase()) : 'Not available'
const date = (value) => value ? new Intl.DateTimeFormat('en-PH', { dateStyle: 'long' }).format(new Date(`${value.slice(0, 10)}T00:00:00`)) : 'Not available'
const initials = computed(() => student.value?.full_name?.split(' ').map((part) => part[0]).slice(0, 2).join('') || 'ST')

async function loadProfile() {
  loading.value = true
  error.value = ''
  student.value = null
  try {
    const { data } = await apiClient.get(`/students/${route.params.id}`)
    student.value = data.data
  } catch (requestError) {
    error.value = requestError.response?.status === 404 ? 'This student record could not be found.' : requestError.response?.data?.message || 'Unable to load the student profile.'
  } finally {
    loading.value = false
  }
}

function startEdit() {
  Object.assign(form, {
    first_name: student.value.profile?.first_name || '', middle_name: student.value.profile?.middle_name || '', last_name: student.value.profile?.last_name || '', suffix: student.value.profile?.suffix || '', birth_date: student.value.profile?.birth_date || '', gender: student.value.profile?.gender || '', civil_status: student.value.profile?.civil_status || '', nationality: student.value.profile?.nationality || '', contact_number: student.value.profile?.contact_number || '', address: student.value.profile?.address || '', course_id: student.value.course?.id || '', year_level: student.value.year_level, student_status: student.value.student_status,
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
  try {
    const { data } = await apiClient.patch(`/students/${route.params.id}`, form)
    student.value = data.data
    editMode.value = false
    success.value = data.message
  } catch (requestError) {
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
  <section class="page-header"><p class="page-kicker">Registrar / Student Management</p><h1 class="page-title">Student Profile</h1><p class="page-description">Official registrar record — read-only access.</p></section>
  <section v-if="loading" class="record-card state">Loading student profile…</section>
  <section v-else-if="error" class="record-card state error" role="alert">{{ error }}</section>
  <template v-else-if="student">
    <section class="profile-card">
      <div class="photo-frame"><img v-if="student.profile?.profile_photo" :src="student.profile.profile_photo" :alt="`${student.full_name} photo`"><div v-else class="photo-placeholder" aria-label="Student photo placeholder">{{ initials }}</div></div>
      <div class="identity"><p class="label">Student Number</p><p class="student-number">{{ student.student_number }}</p><h2>{{ student.full_name }}</h2><p class="course">{{ student.course?.code }} · {{ student.course?.name }}</p><p class="year">Year {{ student.year_level }}</p></div>
      <div class="status-column"><span class="badge student-status">{{ text(student.student_status) }}</span><span class="badge account-status">{{ text(student.account_status) }}</span></div>
    </section>

    <p v-if="success" class="success" role="status">{{ success }}</p>
    <p v-if="editMode && error" class="save-error" role="alert">{{ error }}</p>
    <nav class="action-bar" aria-label="Student profile actions"><button v-if="!editMode" class="back" type="button" @click="router.push({ name: 'student-management' })">← Back to Student List</button><button v-if="!editMode" type="button" @click="startEdit">✏ Edit Student</button><button v-if="!editMode" type="button" @click="router.push({ name: 'student-documents', params: { id: student.id } })">📁 Documents</button><button v-if="!editMode" type="button" disabled>🖨 Print</button><button v-if="editMode" type="button" @click="cancelEdit">Cancel</button><button v-if="editMode" class="back" type="button" :disabled="saving" @click="saveChanges">{{ saving ? 'Saving…' : 'Save Changes' }}</button></nav>

    <section class="record-card"><h2>Personal Information</h2><dl class="information-grid">
      <div><dt>First Name</dt><dd><input v-if="editMode" v-model.trim="form.first_name"><template v-else>{{ text(student.profile?.first_name) }}</template><small v-if="validationErrors.first_name">{{ validationErrors.first_name[0] }}</small></dd></div><div><dt>Middle Name</dt><dd><input v-if="editMode" v-model.trim="form.middle_name"><template v-else>{{ text(student.profile?.middle_name) }}</template></dd></div><div><dt>Last Name</dt><dd><input v-if="editMode" v-model.trim="form.last_name"><template v-else>{{ text(student.profile?.last_name) }}</template><small v-if="validationErrors.last_name">{{ validationErrors.last_name[0] }}</small></dd></div><div><dt>Suffix</dt><dd><input v-if="editMode" v-model.trim="form.suffix"><template v-else>{{ text(student.profile?.suffix) }}</template></dd></div><div><dt>Birth Date</dt><dd><input v-if="editMode" v-model="form.birth_date" type="date"><template v-else>{{ date(student.profile?.birth_date) }}</template></dd></div><div><dt>Gender</dt><dd><input v-if="editMode" v-model.trim="form.gender"><template v-else>{{ text(student.profile?.gender) }}</template></dd></div><div><dt>Civil Status</dt><dd><input v-if="editMode" v-model.trim="form.civil_status"><template v-else>{{ text(student.profile?.civil_status) }}</template></dd></div><div><dt>Nationality</dt><dd><input v-if="editMode" v-model.trim="form.nationality"><template v-else>{{ text(student.profile?.nationality) }}</template></dd></div><div><dt>Email</dt><dd>{{ text(student.profile?.email) }}</dd></div><div><dt>Contact Number</dt><dd><input v-if="editMode" v-model.trim="form.contact_number"><template v-else>{{ text(student.profile?.contact_number) }}</template></dd></div><div class="wide"><dt>Complete Address</dt><dd><textarea v-if="editMode" v-model.trim="form.address" rows="2"></textarea><template v-else>{{ text(student.profile?.address) }}</template></dd></div>
    </dl></section>
    <section class="record-card"><h2>Academic Information</h2><dl class="information-grid">
      <div><dt>Student Number</dt><dd>{{ student.student_number }}</dd></div><div><dt>Course</dt><dd><input v-if="editMode" v-model.number="form.course_id" type="number" min="1"><template v-else>{{ student.course?.code }} — {{ student.course?.name }}</template><small v-if="validationErrors.course_id">{{ validationErrors.course_id[0] }}</small></dd></div><div><dt>Curriculum</dt><dd>{{ student.curriculum?.code }} — {{ student.curriculum?.name }}</dd></div><div><dt>Department</dt><dd>{{ student.department?.code }} — {{ student.department?.name }}</dd></div><div><dt>Year Level</dt><dd><input v-if="editMode" v-model.number="form.year_level" type="number" min="1" max="20"><template v-else>Year {{ student.year_level }}</template><small v-if="validationErrors.year_level">{{ validationErrors.year_level[0] }}</small></dd></div><div><dt>Student Status</dt><dd><select v-if="editMode" v-model="form.student_status"><option value="regular">Regular</option><option value="irregular">Irregular</option><option value="graduated">Graduated</option><option value="transferred">Transferred</option><option value="dropped">Dropped</option><option value="leave_of_absence">Leave of absence</option></select><template v-else>{{ text(student.student_status) }}</template><small v-if="validationErrors.student_status">{{ validationErrors.student_status[0] }}</small></dd></div><div><dt>Semester</dt><dd>{{ text(student.semester?.name) }}</dd></div><div><dt>Academic Year</dt><dd>{{ text(student.academic_year?.school_year) }}</dd></div><div><dt>Admission Date</dt><dd>{{ date(student.admission_date) }}</dd></div><div><dt>Enrollment Status</dt><dd>{{ text(student.enrollment_status) }}</dd></div>
    </dl></section>
    <section class="record-card"><h2>Account Information</h2><dl class="information-grid">
      <div><dt>Username</dt><dd>{{ text(student.account?.username) }}</dd></div><div><dt>Account Role</dt><dd>{{ text(student.account?.role) }}</dd></div><div><dt>Linked Account Status</dt><dd>{{ student.account?.linked ? 'Linked' : 'Not linked' }}</dd></div><div><dt>Last Login</dt><dd>{{ date(student.account?.last_login) }}</dd></div><div><dt>First Login Required</dt><dd>{{ student.account?.is_first_login ? 'Yes' : 'No' }}</dd></div>
    </dl></section>
    <section class="record-card"><h2>Registrar Information</h2><dl class="information-grid">
      <div><dt>Record Created</dt><dd>{{ date(student.record_created_at) }}</dd></div><div><dt>Last Updated</dt><dd>{{ date(student.record_updated_at) }}</dd></div><div><dt>Created By</dt><dd>{{ text(student.created_by) }}</dd></div>
    </dl></section>
  </template>
</template>

<style scoped>
.profile-card,.record-card { background: var(--color-surface); border: 1px solid var(--color-border); border-radius: 12px; box-shadow: var(--shadow-soft); margin-bottom: 20px; padding: 24px; }.profile-card { align-items: center; background: linear-gradient(135deg,#ffffff 0%,#eef8f1 100%); border-top: 5px solid var(--color-dartmouth-green); display: flex; gap: 22px; }.photo-frame img,.photo-placeholder { border: 4px solid #fff; border-radius: 50%; box-shadow: 0 4px 14px rgba(31,31,31,.14); height: 96px; object-fit: cover; width: 96px; }.photo-placeholder { align-items: center; background: var(--color-dartmouth-green); color:#fff; display:flex; font-size:1.5rem; font-weight:800; justify-content:center; }.identity { flex: 1; }.label,.student-number,.course,.year { margin: 0; }.label { color: var(--color-muted); font-size: .74rem; font-weight: 800; text-transform: uppercase; }.student-number { color: var(--color-dartmouth-green); font-weight: 800; margin-top: 3px; }.identity h2 { font-size: clamp(1.4rem,3vw,2rem); margin: 6px 0; }.course,.year { color: var(--color-muted); }.status-column { align-items: flex-end; display: flex; flex-direction: column; gap: 8px; }.badge { border-radius: 999px; font-size:.8rem; font-weight:700; padding: 6px 10px; }.student-status { background:#e2f4e7; color:#106a2e; }.account-status { background:#fff2c2; color:#735400; }.action-bar { display:flex; flex-wrap:wrap; gap:9px; margin: 0 0 20px; }.action-bar button { background:var(--color-green-tint); border:1px solid transparent; border-radius:6px; color:var(--color-dartmouth-green); min-height:40px; padding:8px 13px; }.action-bar .back { background:var(--color-dartmouth-green); color:#fff; cursor:pointer; }.action-bar button:disabled { cursor:not-allowed; opacity:.58; }.record-card h2 { font-size:1.1rem; margin:0 0 18px; }.information-grid { display:grid; gap:12px; grid-template-columns:repeat(3,minmax(0,1fr)); margin:0; }.information-grid div { background:var(--color-anti-flash-white); border-radius:7px; min-width:0; padding:12px; }.information-grid dt { color:var(--color-muted); font-size:.75rem; font-weight:700; }.information-grid dd { margin:5px 0 0; overflow-wrap:anywhere; }.information-grid input,.information-grid select,.information-grid textarea { border:1px solid var(--color-border); border-radius:5px; padding:7px; width:100%; }.information-grid small { color:#9c2222; display:block; margin-top:5px; }.wide { grid-column:span 2; }.success,.save-error { border-radius:7px; margin:0 0 16px; padding:12px; }.success { background:#e2f4e7; color:#106a2e; }.save-error { background:#fce8e8; color:#9c2222; }.state { color:var(--color-muted); text-align:center; }.error { background:#fce8e8; color:#9c2222; }@media(max-width:720px){.information-grid{grid-template-columns:repeat(2,minmax(0,1fr));}.profile-card{align-items:flex-start;}.status-column{align-items:flex-start;}}@media(max-width:500px){.profile-card,.record-card{padding:16px;}.profile-card{flex-wrap:wrap;}.status-column{flex-direction:row;width:100%;}.information-grid{grid-template-columns:1fr;}.wide{grid-column:auto;}.action-bar button{flex:1;}}
</style>
