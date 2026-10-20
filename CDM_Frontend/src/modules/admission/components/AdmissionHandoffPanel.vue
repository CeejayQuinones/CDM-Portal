<script setup>
import { computed, ref, watch } from 'vue'
import AdmissionDialog from './AdmissionDialog.vue'
import { useStepUpAuth } from '../../../composables/useStepUpAuth'
import { admissionApi, admissionError } from '../services/workflowService'

const props = defineProps({ applicantId: { type: Number, required: true } })
const emit = defineEmits(['completed'])
const { runWithStepUp } = useStepUpAuth()
const data = ref(null), loading = ref(false), saving = ref(false), error = ref(''), notice = ref(''), confirming = ref(false)
const courseId = ref(''), curriculumId = ref('')
let generation = 0
const curriculums = computed(() => (data.value?.curriculums || []).filter(item => item.course_id === Number(courseId.value)))
const course = computed(() => data.value?.courses.find(item => item.id === Number(courseId.value)))
const curriculum = computed(() => curriculums.value.find(item => item.id === Number(curriculumId.value)))

async function load(successNotice = '') {
  const token = ++generation
  loading.value = true; error.value = ''; notice.value = ''; confirming.value = false; data.value = null
  try {
    const value = await admissionApi('get', `registrar/applicants/${props.applicantId}/handoff`)
    if (token !== generation) return
    data.value = value; courseId.value = value.course_id || ''; curriculumId.value = value.curriculum_id || ''; notice.value = successNotice
  } catch (exception) { if (token === generation) error.value = admissionError(exception) }
  finally { if (token === generation) loading.value = false }
}

async function confirm() {
  if (saving.value || !confirming.value) return
  saving.value = true; error.value = ''
  const token = generation
  const payload = { version: data.value.version, result_id: data.value.result_id, result_version: data.value.result_version,
    course_id: Number(courseId.value), curriculum_id: Number(curriculumId.value), confirmed: true }
  try {
    await runWithStepUp(() => admissionApi('post', `registrar/applicants/${props.applicantId}/accept`, payload))
    if (token !== generation) return
    await load('Admission completed. The applicant can now continue to Enrollment using the same Guest account.')
    emit('completed')
  } catch (exception) {
    if (token === generation) {
      confirming.value = false
      if (exception?.response?.status === 409 && ['ADMISSION_STATE_CHANGED', 'ADMISSION_ACCEPTANCE_STALE', 'ADMISSION_PROGRAM_CHANGED'].includes(exception?.response?.data?.code)) await load(admissionError(exception))
      else error.value = admissionError(exception)
    }
  } finally { saving.value = false }
}
watch(() => props.applicantId, () => load(), { immediate: true })
</script>

<template>
  <section aria-labelledby="handoff-title" :aria-busy="loading || saving">
    <h3 id="handoff-title">Admission completion and Enrollment handoff</h3>
    <p v-if="loading" role="status">Checking completion eligibility...</p>
    <p v-if="error" role="alert">{{ error }}</p><p v-if="notice" role="status">{{ notice }}</p>
    <button v-if="error" :disabled="loading || saving" @click="load()">Refresh eligibility</button>
    <template v-if="data && !loading">
      <div v-if="data.student" class="placeholder-panel panel"><h3>Existing Student identity preserved</h3><p>Student number: <strong>{{ data.student.student_number }}</strong></p><p>This applicant was converted under the earlier lifecycle. The Student account and Admission history remain unchanged.</p></div>
      <div v-else-if="data.completed" class="placeholder-panel panel"><h3>Admission completed</h3><p><strong>Eligible for Enrollment</strong></p><p>{{ course?.course_name }} · {{ curriculum?.curriculum_name }}</p><p>Current portal role: <strong>Guest</strong><br>Student identity: <strong>Not created yet</strong></p><p>The same account continues to Enrollment. Student creation and official student-number assignment occur in Phase 2.</p></div>
      <template v-else>
        <p v-if="data.reason" role="status">{{ data.reason }}</p>
        <form v-if="data.can_complete" @submit.prevent="confirming = true">
          <p>Review the applicant's identity and published passing result, then approve the program and curriculum for Enrollment.</p>
          <div class="grid">
            <label>Admitted program<select v-model.number="courseId" :disabled="saving" required @change="curriculumId = ''"><option value="" disabled>Select program</option><option v-for="item in data.courses" :key="item.id" :value="item.id">{{ item.course_code }} — {{ item.course_name }}</option></select></label>
            <label>Curriculum<select v-model.number="curriculumId" :disabled="saving" required><option value="" disabled>Select curriculum</option><option v-for="item in curriculums" :key="item.id" :value="item.id">{{ item.curriculum_code }} — {{ item.curriculum_name }}</option></select></label>
          </div>
          <button class="primary" :disabled="saving || !course || !curriculum">Approve for Enrollment</button>
        </form>
      </template>
    </template>
    <AdmissionDialog v-if="confirming" labelledby="handoff-confirm" :busy="saving" @cancel="confirming = false">
      <h2 id="handoff-confirm">Complete Admission?</h2><p><strong>{{ data.name }}</strong><br>{{ data.applicant_number }}</p><p>{{ course?.course_name }}<br>{{ curriculum?.curriculum_name }}</p>
      <p>This marks Admission complete and grants Enrollment entry to the existing Guest account. It does not create a Student, assign a student number, or change the account role.</p>
      <div class="toolbar"><button :disabled="saving" @click="confirming = false">Cancel</button><button class="primary" :disabled="saving" @click="confirm">{{ saving ? 'Saving...' : 'Confirm completion' }}</button></div>
    </AdmissionDialog>
  </section>
</template>
