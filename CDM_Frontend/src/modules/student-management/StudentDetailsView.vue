<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { apiClient } from '../../services/apiClient'

const route = useRoute()
const router = useRouter()
const student = ref(null)
const loading = ref(false)
const error = ref('')
const display = (value) => value ? String(value).replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase()) : 'Not available'
const date = (value) => value ? new Intl.DateTimeFormat('en-PH', { dateStyle: 'long' }).format(new Date(`${value.slice(0, 10)}T00:00:00`)) : 'Not available'
const initials = computed(() => student.value?.full_name?.split(' ').map((part) => part[0]).slice(0, 2).join('') || 'ST')

async function loadStudent() {
  loading.value = true
  error.value = ''
  student.value = null
  try {
    const { data } = await apiClient.get(`/students/${route.params.id}`)
    student.value = data.data
  } catch (requestError) {
    error.value = requestError.response?.status === 404 ? 'This student record could not be found.' : requestError.response?.data?.message || 'Unable to load the student record.'
  } finally {
    loading.value = false
  }
}

watch(() => route.params.id, loadStudent)
onMounted(loadStudent)
</script>

<template>
  <section class="page-header"><p class="page-kicker">Registrar / Student Management</p><h1 class="page-title">Student Details</h1><p class="page-description">View the complete student record. Changes are not available in this phase.</p></section>
  <section v-if="loading" class="panel state">Loading student record…</section>
  <section v-else-if="error" class="panel state error" role="alert">{{ error }}</section>
  <template v-else-if="student">
    <section class="panel header"><img v-if="student.profile?.profile_photo" class="photo" :src="student.profile.profile_photo" :alt="`${student.full_name} profile`"><div v-else class="photo fallback">{{ initials }}</div><div><p class="number">{{ student.student_number }}</p><h2>{{ student.full_name }}</h2><span class="badge">{{ display(student.student_status) }}</span> <span class="badge">{{ display(student.account_status) }}</span></div></section>
    <section class="panel"><h2>Personal Information</h2><dl class="grid">
      <div><dt>First Name</dt><dd>{{ display(student.profile?.first_name) }}</dd></div><div><dt>Middle Name</dt><dd>{{ display(student.profile?.middle_name) }}</dd></div><div><dt>Last Name</dt><dd>{{ display(student.profile?.last_name) }}</dd></div><div><dt>Suffix</dt><dd>{{ display(student.profile?.suffix) }}</dd></div><div><dt>Birth Date</dt><dd>{{ date(student.profile?.birth_date) }}</dd></div><div><dt>Gender</dt><dd>{{ display(student.profile?.gender) }}</dd></div><div><dt>Civil Status</dt><dd>{{ display(student.profile?.civil_status) }}</dd></div><div><dt>Nationality</dt><dd>{{ display(student.profile?.nationality) }}</dd></div><div><dt>Email</dt><dd>{{ display(student.profile?.email) }}</dd></div><div><dt>Contact Number</dt><dd>{{ display(student.profile?.contact_number) }}</dd></div><div class="wide"><dt>Address</dt><dd>{{ display(student.profile?.address) }}</dd></div>
    </dl></section>
    <section class="panel"><h2>Academic Information</h2><dl class="grid">
      <div><dt>Student Number</dt><dd>{{ student.student_number }}</dd></div><div><dt>Course</dt><dd>{{ student.course?.code }} — {{ student.course?.name }}</dd></div><div><dt>Curriculum</dt><dd>{{ student.curriculum?.code }} — {{ student.curriculum?.name }}</dd></div><div><dt>Department</dt><dd>{{ student.department?.code }} — {{ student.department?.name }}</dd></div><div><dt>Year Level</dt><dd>Year {{ student.year_level }}</dd></div><div><dt>Semester</dt><dd>{{ display(student.semester?.name) }}</dd></div><div><dt>Academic Year</dt><dd>{{ display(student.academic_year?.school_year) }}</dd></div><div><dt>Admission Date</dt><dd>{{ date(student.admission_date) }}</dd></div>
    </dl></section>
    <section class="panel"><h2>Account Information</h2><dl class="grid">
      <div><dt>Username</dt><dd>{{ display(student.account?.username) }}</dd></div><div><dt>Role</dt><dd>{{ display(student.account?.role) }}</dd></div><div><dt>Linked Account</dt><dd>{{ student.account?.linked ? 'Linked' : 'Not linked' }}</dd></div><div><dt>Last Login</dt><dd>{{ date(student.account?.last_login) }}</dd></div>
    </dl></section>
  </template>
  <footer class="actions"><button type="button" @click="router.push({ name: 'student-management' })">← Back to Student List</button></footer>
</template>

<style scoped>
.panel { background: var(--color-surface); border: 1px solid var(--color-border); border-radius: 10px; box-shadow: var(--shadow-soft); margin-bottom: 20px; padding: 22px; }.panel h2 { font-size: 1.1rem; margin: 0 0 18px; }.state { color: var(--color-muted); text-align: center; }.error { background: #fce8e8; color: #9c2222; }.header { align-items: center; display: flex; gap: 18px; }.photo { border-radius: 50%; height: 76px; object-fit: cover; width: 76px; }.fallback { align-items: center; background: var(--color-green-tint); color: var(--color-dartmouth-green); display: flex; font-size: 1.2rem; font-weight: 800; justify-content: center; }.header h2 { margin: 2px 0 10px; }.number { color: var(--color-muted); font-size: .88rem; margin: 0; }.badge { background: var(--color-green-tint); border-radius: 999px; color: var(--color-dartmouth-green); display: inline-block; font-size: .78rem; margin-right: 6px; padding: 4px 9px; }.grid { display: grid; gap: 12px; grid-template-columns: repeat(3, minmax(0, 1fr)); margin: 0; }.grid div { background: var(--color-anti-flash-white); border-radius: 6px; min-width: 0; padding: 12px; }.grid dt { color: var(--color-muted); font-size: .75rem; font-weight: 700; }.grid dd { margin: 5px 0 0; overflow-wrap: anywhere; }.wide { grid-column: span 2; }.actions { margin: 6px 0 20px; }.actions button { background: var(--color-dartmouth-green); border: 0; border-radius: 6px; color: #fff; cursor: pointer; min-height: 42px; padding: 9px 15px; }@media (max-width: 760px) { .grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }@media (max-width: 500px) { .panel { padding: 16px; }.header { align-items: flex-start; }.grid { grid-template-columns: 1fr; }.wide { grid-column: auto; } }
</style>
