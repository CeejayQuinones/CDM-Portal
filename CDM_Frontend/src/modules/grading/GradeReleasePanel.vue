<script setup>
import { computed, ref, watch } from 'vue'
import { academicInstitutes } from '../../config/publicHomepage'
import { approvedReleaseSheets, programsForInstitute } from './gradeReleaseSelection'

const props = defineProps({
  options: { type: Object, required: true },
  releaseData: { type: Object, required: true },
  saving: Boolean,
})
const emit = defineEmits(['create', 'release'])
const yearId = ref('')
const semesterId = ref('')
const releaseAt = ref('')
const instituteCode = ref('')
const programIds = ref([])
const sheetIds = ref([])

const programs = computed(() => programsForInstitute(props.options.courses || [], instituteCode.value))
const termSheets = computed(() => approvedReleaseSheets(props.releaseData.approved_sheets, { yearId: yearId.value, semesterId: semesterId.value }))
const programSheets = computed(() => programIds.value.length ? approvedReleaseSheets(termSheets.value, { yearId: yearId.value, semesterId: semesterId.value, programIds: programIds.value }) : [])
const selectedSheets = computed(() => programSheets.value.filter(sheet => sheetIds.value.map(String).includes(String(sheet.id))))
const programSheetCount = courseId => termSheets.value.filter(sheet => String(sheet.section_subject?.section?.course_id) === String(courseId)).length
const courseCode = courseId => (props.options.courses || []).find(course => String(course.id) === String(courseId))?.course_code || 'Unknown program'
const scheduleScope = schedule => {
  const codes = [...new Set((schedule.items || []).map(item => courseCode(item.grade_sheet?.section_subject?.section?.course_id)))]
  const institutes = [...new Set((schedule.items || []).map(item => (props.options.courses || []).find(course => String(course.id) === String(item.grade_sheet?.section_subject?.section?.course_id))?.department?.department_code).filter(Boolean))]
  return `${institutes.join(', ') || 'Unmapped institute'} · ${codes.join(', ') || 'No programs'}`
}
const formatDate = value => value ? new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value)) : 'Not configured'

function resetInstitute() { programIds.value = []; sheetIds.value = [] }
watch([yearId, semesterId, programIds], () => { sheetIds.value = programSheets.value.map(sheet => sheet.id) })
function reset() { yearId.value = ''; semesterId.value = ''; releaseAt.value = ''; instituteCode.value = ''; resetInstitute() }
function submit() {
  if (!yearId.value || !semesterId.value || !releaseAt.value || !instituteCode.value || !programIds.value.length || !selectedSheets.value.length || selectedSheets.value.length > 200) return
  emit('create', {
    academic_year_id: yearId.value,
    semester_id: semesterId.value,
    release_at: releaseAt.value,
    grade_sheet_ids: selectedSheets.value.map(sheet => Number(sheet.id)),
  })
}
defineExpose({ reset })
</script>

<template>
  <section class="grade-release-workspace">
    <form class="grading-release-form" @submit.prevent="submit">
      <div class="release-heading"><div><p class="release-kicker">Registrar publication</p><h2>Schedule grade release</h2><p>Choose one institute and its programs. Only approved grade sheets for the selected term can be published.</p></div><span class="release-count">{{ selectedSheets.length }} selected</span></div>
      <label>Academic year<select v-model="yearId" required><option disabled value="">Select year</option><option v-for="year in options.academic_years" :key="year.id" :value="year.id">{{ year.school_year }}</option></select></label>
      <label>Semester<select v-model="semesterId" required><option disabled value="">Select semester</option><option v-for="semester in options.semesters" :key="semester.id" :value="semester.id">{{ semester.semester_name }}</option></select></label>
      <label>Release date and time<input v-model="releaseAt" type="datetime-local" required></label>
      <label class="release-institute">Institute<select v-model="instituteCode" required @change="resetInstitute"><option disabled value="">Select institute</option><option v-for="institute in academicInstitutes" :key="institute.code" :value="institute.code">{{ institute.name }}</option></select></label>
      <fieldset class="release-programs"><legend>Programs to release</legend><p v-if="!instituteCode">Select an institute to see its programs.</p><p v-else-if="!programs.length">No mapped programs are available for this institute. Check the program configuration before scheduling.</p><label v-for="program in programs" :key="program.id"><input v-model="programIds" type="checkbox" :value="program.id"><span><strong>{{ program.course_code }} · {{ program.course_name }}</strong><small>{{ programSheetCount(program.id) }} approved {{ programSheetCount(program.id) === 1 ? 'sheet' : 'sheets' }} for this term</small></span></label></fieldset>
      <fieldset class="release-sheets"><legend>Approved grade sheets <small>{{ selectedSheets.length }} of {{ programSheets.length }} selected</small></legend><p v-if="!yearId || !semesterId || !programIds.length">Choose a term and at least one program to review its approved sheets.</p><p v-else-if="!programSheets.length">No approved grade sheets match this term and these programs.</p><label v-for="sheet in programSheets" :key="sheet.id"><input v-model="sheetIds" type="checkbox" :value="sheet.id"><span><strong>{{ sheet.section_subject.subject.subject_code }}</strong> · {{ sheet.section_subject.section.section_name }}<small>{{ courseCode(sheet.section_subject.section.course_id) }}</small></span></label></fieldset>
      <p class="release-safety">Only the checked sheets will be scheduled. Grades stay private until the release time or an authorized manual release.</p>
      <button class="grading-button primary" :disabled="saving || !selectedSheets.length || selectedSheets.length > 200">Schedule {{ selectedSheets.length }} {{ selectedSheets.length === 1 ? 'sheet' : 'sheets' }}</button>
      <p v-if="selectedSheets.length > 200" class="grading-field-error">A release can contain at most 200 grade sheets. Split this selection into separate schedules.</p>
    </form>
    <div v-if="!releaseData.schedules?.data?.length" class="grading-state"><strong>No release schedules</strong><span>Approved sheets remain private until scheduled and released.</span></div>
    <div v-else class="grading-table-wrap release-schedule-list"><table><thead><tr><th>Term</th><th>Institute / programs</th><th>Release time</th><th>Sheets</th><th>Status</th><th>Action</th></tr></thead><tbody><tr v-for="item in releaseData.schedules.data" :key="item.id"><td>{{ item.academic_year.school_year }} · {{ item.semester.semester_name }}</td><td>{{ scheduleScope(item) }}</td><td>{{ formatDate(item.release_at) }}</td><td>{{ item.items.length }}</td><td><em :data-state="item.status">{{ item.status }}</em></td><td><button v-if="item.status === 'scheduled'" type="button" :disabled="saving" @click="emit('release', item)">Release now</button></td></tr></tbody></table></div>
  </section>
</template>
