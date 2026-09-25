<script setup>
import { computed, ref, watch } from 'vue'
import { admissionApi, admissionError } from '../services/workflowService'
import { useStepUpAuth } from '../../../composables/useStepUpAuth'
const props = defineProps({ applicantId: { type: Number, required: true } })
const emit = defineEmits(['converted'])
const { runWithStepUp } = useStepUpAuth()
const data = ref(null), loading = ref(false), saving = ref(false), error = ref(''), confirmation = ref(null)
const courseId = ref(''), curriculumId = ref(''), studentNumber = ref(''), admissionDate = ref('')
let generation = 0
const curriculums = computed(() => (data.value?.curriculums || []).filter(c => c.course_id === Number(courseId.value)))
const course = computed(() => data.value?.courses.find(c => c.id === Number(courseId.value)))
const curriculum = computed(() => curriculums.value.find(c => c.id === Number(curriculumId.value)))
async function load() {
  const token = ++generation
  loading.value = true; error.value = ''; confirmation.value = null; data.value = null
  try {
    const value = await admissionApi('get', `registrar/applicants/${props.applicantId}/conversion`)
    if (token !== generation) return
    data.value = value; courseId.value = value.course_id || ''; curriculumId.value = value.curriculum_id || ''
  } catch (e) { if (token === generation) error.value = admissionError(e) }
  finally { if (token === generation) loading.value = false }
}
function prepare() {
  confirmation.value = data.value.can_convert ? 'convert' : 'accept'
}
async function confirm() {
  if (saving.value || !confirmation.value) return
  saving.value = true; error.value = ''
  const kind = confirmation.value, id = props.applicantId, token = generation
  const payload = { version: data.value.version, result_id: data.value.result_id, result_version: data.value.result_version,
    course_id: Number(courseId.value), curriculum_id: Number(curriculumId.value), confirmed: true,
    ...(kind === 'convert' ? { student_number: studentNumber.value, admission_date: admissionDate.value } : {}) }
  try {
    await runWithStepUp(() => admissionApi('post', `registrar/applicants/${id}/${kind}`, payload))
    if (token !== generation) return
    await load()
    if (kind === 'convert') emit('converted')
  } catch (e) { if (token === generation) { error.value = admissionError(e); confirmation.value = null } }
  finally { saving.value = false }
}
watch(() => props.applicantId, () => { studentNumber.value = ''; admissionDate.value = ''; load() }, { immediate: true })
</script>
<template>
  <section aria-labelledby="conversion-title" :aria-busy="loading || saving">
    <h3 id="conversion-title">Academic Student conversion</h3>
    <p v-if="loading" role="status">Checking acceptance and conversion eligibility...</p>
    <p v-if="error" role="alert">{{ error }}</p><button v-if="error" :disabled="loading || saving" @click="load">Refresh eligibility</button>
    <template v-if="data && !loading">
      <div v-if="data.student" class="panel"><h3>Converted to Student</h3><p>{{ course?.course_name || 'Program ID: ' + data.student.course_id }} · {{ curriculum?.curriculum_code || 'Curriculum ID: ' + data.student.curriculum_id }}</p><p>Official student number: <strong>{{ data.student.student_number }}</strong></p><p>Admission date: {{ data.student.admission_date }} · Year {{ data.student.year_level }} · {{ data.student.student_status }}</p><p>The same account and password continue to work. The student should sign in again to load Student navigation. Admission history remains available read-only.</p></div>
      <template v-else>
        <p v-if="data.reason" role="status">{{ data.reason }}</p>
        <form v-if="data.can_accept" @submit.prevent="prepare">
          <p v-if="!data.can_convert">Review the applicant's identity and published pass, then accept the academic program. Acceptance alone does not create a Student.</p>
          <div class="grid">
            <label>Admitted program<select v-model.number="courseId" :disabled="data.can_convert || saving" required @change="curriculumId = ''"><option value="" disabled>Select program</option><option v-for="c in data.courses" :key="c.id" :value="c.id">{{ c.course_code }} — {{ c.course_name }}</option></select></label>
            <label>Curriculum<select v-model.number="curriculumId" :disabled="data.can_convert || saving" required><option value="" disabled>Select curriculum</option><option v-for="c in curriculums" :key="c.id" :value="c.id">{{ c.curriculum_code }} — {{ c.curriculum_name }}</option></select></label>
          </div>
          <template v-if="data.can_convert">
            <p>Accepted for the current published result. Confirm the official number and admission date to create a first-year, regular Student.</p>
            <div class="grid"><label>Official student number<input v-model="studentNumber" required maxlength="20" :disabled="saving" autocomplete="off"></label><label>Admission date<input v-model="admissionDate" type="date" required :disabled="saving"></label></div>
          </template>
          <button class="primary" :disabled="saving || !course || !curriculum">{{ data.can_convert ? 'Convert to Student' : 'Accept application' }}</button>
        </form>
      </template>
    </template>
    <dialog v-if="confirmation" open aria-labelledby="conversion-confirm" @cancel.prevent="!saving && (confirmation = null)">
      <h2 id="conversion-confirm">{{ confirmation === 'convert' ? 'Confirm final conversion' : 'Confirm admission acceptance' }}</h2>
      <p><strong>{{ data.name }}</strong><br>{{ data.applicant_number }}</p><p>{{ course?.course_name }}<br>{{ curriculum?.curriculum_code }}</p>
      <p v-if="confirmation === 'convert'">Official student number: <strong>{{ studentNumber }}</strong><br>Admission date: {{ admissionDate }}<br>Year 1 · Regular</p>
      <p>{{ confirmation === 'convert' ? 'This creates the academic Student record and grants Student access to the existing account. No new login or password is created.' : 'I have reviewed the applicant profile and current published pass, and approve admission to this program and curriculum.' }}</p>
      <div class="toolbar"><button :disabled="saving" @click="confirmation = null">Cancel</button><button class="primary" :disabled="saving" @click="confirm">{{ saving ? 'Saving...' : 'Confirm ' + (confirmation === 'convert' ? 'conversion' : 'acceptance') }}</button></div>
    </dialog>
  </section>
</template>
