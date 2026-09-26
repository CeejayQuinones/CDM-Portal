<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useAuthStore } from '../../stores/authStore'
import { apiClient } from '../../services/apiClient'
const auth = useAuthStore()
const isStudent = computed(() => auth.currentRole === 'Student')
const data = ref(null), busy = ref(false), error = ref('')
let requestVersion = 0
const messages = {
  student_record_required: 'Your academic Student record is not available. Please contact the Registrar.',
  profile_reconciliation_required: 'Your Student profile needs to be checked by the Registrar.',
  academic_identity_incomplete: 'Your academic identity needs to be completed by the Registrar.',
  academic_review_required: 'Your academic status requires review before enrollment.',
  course_inactive: 'Your program is currently inactive. Please contact the Registrar.',
  curriculum_invalid: 'Your curriculum needs review before enrollment.',
  admission_conversion_incomplete: 'Your Admission conversion needs to be completed or reconciled.',
  enrollment_period_unavailable: 'No enrollment period is available yet. Your academic record is ready.',
  enrollment_period_closed: 'The enrollment period is not currently open.',
  term_unavailable: 'The academic term is not available for enrollment.',
  term_enrollment_exists: 'An enrollment record already exists for this academic term.',
}
const message = computed(() => messages[data.value?.reason] || (data.value?.eligible ? 'Your academic record meets the current eligibility checks.' : 'Enrollment eligibility is not available.'))
async function load() {
  const version = ++requestVersion
  data.value = null; error.value = ''; busy.value = false
  if (!isStudent.value) return
  busy.value = true
  try {
    const response = await apiClient.get('/enrollment/status')
    if (version !== requestVersion) return
    if (response.data?.success !== true) throw new Error('Invalid response')
    data.value = response.data.data
  } catch (e) {
    if (version === requestVersion) error.value = e?.response?.status === 403
      ? 'Enrollment status is available only to active Student accounts.'
      : 'Enrollment status is temporarily unavailable. Please try again.'
  } finally { if (version === requestVersion) busy.value = false }
}
watch(() => [auth.currentRole, auth.currentUser?.id], load)
onMounted(load)
onBeforeUnmount(() => { requestVersion++ })
</script>
<template>
  <section class="enrollment-foundation">
    <header class="page-header"><p class="page-kicker">Enrollment</p><h1 class="page-title">Enrollment status</h1><p class="page-description">Your academic identity and term enrollment information.</p></header>
    <div v-if="!isStudent" class="placeholder-panel"><h2>Enrollment workspace</h2><p>Staff review and configuration will be available in a later step.</p></div>
    <template v-else>
      <p v-if="busy" class="placeholder-panel" role="status">Checking enrollment eligibility…</p>
      <div v-else-if="error" class="placeholder-panel" role="alert"><p>{{ error }}</p><button @click="load">Try again</button></div>
      <template v-else-if="data">
        <div class="placeholder-panel" role="status"><h2>{{ data.academic_ready ? 'Academic record ready' : 'Academic review needed' }}</h2><p>{{ message }}</p><p>Enrollment applications are not open in this foundation step.</p></div>
        <div v-if="data.student" class="placeholder-panel">
          <h2>{{ data.student.name }}</h2>
          <dl class="enrollment-summary"><div><dt>Student number</dt><dd>{{ data.student.student_number }}</dd></div><div><dt>Program</dt><dd>{{ data.student.course.course_name }}</dd></div><div><dt>Curriculum</dt><dd>{{ data.student.curriculum.curriculum_name }}</dd></div><div><dt>Year level</dt><dd>{{ data.student.year_level }}</dd></div></dl>
          <p>Your existing Student account and academic record are reused. No new application or identity has been created.</p>
        </div>
        <div class="placeholder-panel"><h2>Recent academic enrollment records</h2><p v-if="!data.enrollments?.length">No term enrollment recorded yet.</p><ul v-else><li v-for="record in data.enrollments" :key="record.id">{{ record.academic_year }} · {{ record.semester }} · {{ record.status }}</li></ul></div>
        <div class="placeholder-panel"><h2>Upcoming enrollment steps</h2><p>Enrollment applications, subject selection, schedules, and Certificate of Registration will be added in later steps.</p></div>
      </template>
    </template>
  </section>
</template>
<style scoped>
.enrollment-foundation { max-width: 1080px; margin: auto; min-width: 0; overflow-wrap: anywhere; }
.placeholder-panel { margin: 18px 0; }
.placeholder-panel p { margin: 12px 0; line-height: 1.6; }
.enrollment-summary { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(220px, 100%), 1fr)); gap: 18px; }
dt { color: var(--color-muted); font-size: .85rem; }
dd { margin: 6px 0 0; font-weight: 600; }
button { min-height: 44px; padding: 10px 16px; border: 1px solid var(--color-border); border-radius: 10px; background: var(--color-dartmouth-green); color: white; cursor: pointer; }
button:focus-visible { outline: 3px solid var(--color-dark-spring-green); outline-offset: 3px; }
@media (prefers-reduced-motion: reduce) { .page-header { animation: none; } }
</style>
