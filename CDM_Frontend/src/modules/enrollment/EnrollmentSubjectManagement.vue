<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import { onBeforeRouteLeave } from 'vue-router'
import { apiClient } from '../../services/apiClient'
import { ROLES } from '../../config/accessControl'
import { useAuthStore } from '../../stores/authStore'
import { label, safeError } from './enrollment'
import EnrollmentDialog from './EnrollmentDialog.vue'
import './enrollment.css'
import './registrarEnrollmentComplete.css'

const data = ref(null), form = ref(null), detail = ref(null), statusChange = ref(null), professorAssignments = ref(null), professorSearch = ref(''), busy = ref(false), error = ref(''), notice = ref(''), page = ref(1), filtersOpen = ref(false)
const auth = useAuthStore()
const canManageProfessors = computed(() => auth.currentRole === ROLES.REGISTRAR_STAFF)
const manageMenuId = ref(null)
const manageMenuTriggers = new Map()
const filters = ref({ search: '', course_id: '', curriculum_id: '', status: '' })
const curricula = computed(() => data.value?.curriculums || [])
const filteredCurricula = computed(() => curricula.value.filter(curriculum => !filters.value.course_id || Number(curriculum.course_id) === Number(filters.value.course_id)))
const formCurriculum = computed(() => curricula.value.find(curriculum => Number(curriculum.id) === Number(form.value?.curriculum_id)))
const prerequisites = computed(() => data.value?.subjects?.data?.filter(subject => subject.id !== form.value?.id && subject.status === 'active' && Number(subject.curriculum_id) === Number(form.value?.curriculum_id)) || [])
const curriculumGroups = computed(() => {
  const groups = new Map()
  for (const subject of data.value?.subjects?.data || []) {
    const curriculumKey = String(subject.curriculum_id)
    if (!groups.has(curriculumKey)) groups.set(curriculumKey, { key: curriculumKey, curriculum: subject.curriculum, periods: new Map(), total: 0 })
    const group = groups.get(curriculumKey)
    const periodKey = `${subject.year_level}:${subject.semester_id}`
    if (!group.periods.has(periodKey)) group.periods.set(periodKey, { key: periodKey, yearLevel: subject.year_level, semester: subject.semester, subjects: [] })
    group.periods.get(periodKey).subjects.push(subject)
    group.total++
  }
  return [...groups.values()].map(group => ({ ...group, periods: [...group.periods.values()] }))
})
const activeFilterCount = computed(() => [filters.value.curriculum_id, filters.value.status].filter(Boolean).length)
const filteredProfessors = computed(() => {
  const query = professorSearch.value.trim().toLowerCase()
  const rows = professorAssignments.value?.professors || []
  if (!query) return rows
  return rows.filter(professor => [professor.first_name, professor.middle_name, professor.last_name, professor.employee_number, professor.department_code, professor.department_name].some(value => String(value || '').toLowerCase().includes(query)))
})

async function load() { busy.value = true; error.value = ''; try { const response = await apiClient.get('/enrollment/academic/subjects', { params: { ...filters.value, page: page.value } }); data.value = response.data.data } catch (reason) { error.value = safeError(reason) } finally { busy.value = false } }
function blank() { return { curriculum_id: filters.value.curriculum_id || '', semester_id: '', subject_code: '', subject_name: '', year_level: 1, units: 3, lecture_hours: 3, laboratory_hours: 0, prerequisite_subject_id: null, status: 'active' } }
function setManageMenuTrigger(id, element) { if (element) manageMenuTriggers.set(id, element); else manageMenuTriggers.delete(id) }
function closeManageMenu(restoreFocus = false) {
  const trigger = manageMenuTriggers.get(manageMenuId.value)
  manageMenuId.value = null
  if (restoreFocus && trigger?.isConnected) nextTick(() => trigger.focus({ preventScroll: true }))
}
function toggleManageMenu(id) { manageMenuId.value = manageMenuId.value === id ? null : id }
function chooseManageAction(subject, action) {
  const trigger = manageMenuTriggers.get(subject.id)
  manageMenuId.value = null
  trigger?.focus({ preventScroll: true })
  if (action === 'edit') openEdit(subject)
  else if (action === 'assign') openEdit(subject, true)
  else if (action === 'professors') openProfessorAssignments(subject)
  else statusChange.value = subject
}
function handleOutsideMenu(event) { if (manageMenuId.value !== null && !event.target.closest?.('.en-row-menu')) closeManageMenu() }
function handleMenuEscape(event) { if (event.key === 'Escape' && manageMenuId.value !== null) { event.preventDefault(); event.stopPropagation(); closeManageMenu(true) } }
function handleViewportChange() { closeManageMenu() }
function openCreate() { closeManageMenu(); form.value = blank(); detail.value = null }
function openEdit(subject, assignment = false) { closeManageMenu(); form.value = { ...subject, units: Number(subject.units), assignment }; detail.value = null }
async function save() {
  if (busy.value) return
  busy.value = true; error.value = ''
  try {
    const payload = { ...form.value }
    delete payload.curriculum; delete payload.semester; delete payload.prerequisite; delete payload.section_subjects_count; delete payload.enrollment_subjects_count; delete payload.assignment
    if (payload.id) delete payload.status
    for (const key of ['curriculum_id', 'semester_id', 'year_level', 'lecture_hours', 'laboratory_hours', 'prerequisite_subject_id']) payload[key] = payload[key] ? Number(payload[key]) : null
    if (payload.id) await apiClient.put(`/enrollment/academic/subjects/${payload.id}`, payload)
    else await apiClient.post('/enrollment/academic/subjects', payload)
    form.value = null; notice.value = 'Subject saved successfully.'; await load()
  } catch (reason) { error.value = safeError(reason) }
  finally { busy.value = false }
}
async function changeStatus() { if (busy.value) return; busy.value = true; error.value = ''; try { const action = statusChange.value.status === 'active' ? 'archive' : 'reactivate'; await apiClient.post(`/enrollment/academic/subjects/${statusChange.value.id}/${action}`); statusChange.value = null; notice.value = `Subject ${action === 'archive' ? 'archived' : 'reactivated'} successfully.`; await load() } catch (reason) { error.value = safeError(reason) } finally { busy.value = false } }
async function openProfessorAssignments(subject) {
  if (busy.value) return
  busy.value = true; error.value = ''; notice.value = ''
  try {
    const response = await apiClient.get(`/enrollment/academic/subjects/${subject.id}/professors`)
    professorAssignments.value = { ...response.data.data, selected: [...response.data.data.assigned_professor_ids] }
    professorSearch.value = ''
  } catch (reason) { error.value = safeError(reason) }
  finally { busy.value = false }
}
const professorSelected = id => professorAssignments.value?.selected.includes(id) || false
const professorCanToggle = professor => professorSelected(professor.id) || (professor.status === 'active' && professor.account_status === 'active')
function toggleProfessor(id) {
  const selected = new Set(professorAssignments.value?.selected || [])
  selected.has(id) ? selected.delete(id) : selected.add(id)
  professorAssignments.value.selected = [...selected]
}
async function saveProfessorAssignments() {
  if (busy.value || !professorAssignments.value) return
  busy.value = true; error.value = ''
  try {
    await apiClient.put(`/enrollment/academic/subjects/${professorAssignments.value.subject.id}/professors`, { professor_ids: professorAssignments.value.selected, confirmed: true })
    notice.value = `Professor associations for ${professorAssignments.value.subject.subject_code} updated.`
    professorAssignments.value = null; professorSearch.value = ''; await load()
  } catch (reason) { error.value = safeError(reason) }
  finally { busy.value = false }
}
function apply() { page.value = 1; load() }
function resetFilters() { filters.value = { search: '', course_id: '', curriculum_id: '', status: '' }; filtersOpen.value = false; page.value = 1; load() }
onMounted(() => {
  load()
  document.addEventListener('pointerdown', handleOutsideMenu, true)
  document.addEventListener('keydown', handleMenuEscape, true)
  window.addEventListener('resize', handleViewportChange)
  window.addEventListener('scroll', handleViewportChange, true)
})
onBeforeUnmount(() => {
  document.removeEventListener('pointerdown', handleOutsideMenu, true)
  document.removeEventListener('keydown', handleMenuEscape, true)
  window.removeEventListener('resize', handleViewportChange)
  window.removeEventListener('scroll', handleViewportChange, true)
  manageMenuTriggers.clear()
})
onBeforeRouteLeave(() => closeManageMenu())
</script>

<template>
  <section class="en-staff-modern en-subject-catalog">
    <header class="en-modern-header"><div><p>Enrollment management</p><h1>Subjects</h1><span>Manage the curriculum-specific catalog used by Enrollment, Scheduling, prerequisites, and grading.</span></div><button class="en-modern-primary" :disabled="busy" @click="openCreate">Add Subject</button></header>
    <div v-if="error" class="en-modern-feedback error" role="alert"><span>{{ error }}</span><button :disabled="busy" @click="load">Retry</button></div><p v-if="notice" class="en-modern-feedback success" role="status">{{ notice }}</p><p v-if="busy" class="en-modern-loading" role="status">Loading subjects…</p>
    <template v-if="data">
      <form class="en-modern-toolbar en-subject-toolbar" @submit.prevent="apply">
        <label class="en-modern-search">Find a subject<input v-model.trim="filters.search" type="search" maxlength="100" placeholder="Subject code or name"></label>
        <label>Program<select v-model="filters.course_id" @change="filters.curriculum_id='' "><option value="">All programs</option><option v-for="course in data.courses" :key="course.id" :value="course.id">{{ course.course_code }} — {{ course.course_name }}</option></select></label>
        <label>Curriculum<select v-model="filters.curriculum_id"><option value="">All curricula</option><option v-for="curriculum in filteredCurricula" :key="curriculum.id" :value="curriculum.id">{{ curriculum.curriculum_code }} — {{ curriculum.curriculum_name }}</option></select></label>
        <div class="en-toolbar-actions"><button class="en-modern-primary" :disabled="busy" type="submit">Search</button><button type="button" class="en-modern-outline en-more-filters" :aria-expanded="filtersOpen" aria-controls="en-subject-filters" @click="filtersOpen=!filtersOpen">More filters{{ activeFilterCount ? ` (${activeFilterCount})` : '' }}</button><button type="button" class="en-modern-ghost" :disabled="busy" @click="resetFilters">Reset</button></div>
      </form>
      <form :hidden="!filtersOpen" id="en-subject-filters" class="en-modern-filters" @submit.prevent="apply"><label>Status<select v-model="filters.status"><option value="">All statuses</option><option value="active">Active</option><option value="inactive">Archived</option></select></label><button class="en-modern-primary" :disabled="busy" type="submit">Apply filters</button></form>

      <section class="en-catalog-directory" aria-label="Curriculum subject catalog">
        <header><div><h2>Curriculum catalog</h2><p>{{ data.subjects.total }} subjects match the current program and curriculum context.</p></div><span>Page {{ data.subjects.current_page }} of {{ data.subjects.last_page }}</span></header>
        <div v-if="!curriculumGroups.length" class="en-modern-empty" role="status"><h2>No matching subjects</h2><p>Create the first subject or adjust the program, curriculum, and status filters.</p><button class="en-modern-primary" @click="openCreate">Add Subject</button></div>
        <section v-for="group in curriculumGroups" v-else :key="group.key" class="en-catalog-group">
          <header><div><small>{{ group.curriculum?.course?.course_code || 'Program' }} · {{ group.curriculum?.curriculum_code || 'Curriculum' }}</small><h3>{{ group.curriculum?.curriculum_name || 'Curriculum unavailable' }}</h3></div><span>{{ group.total }} {{ group.total===1?'subject':'subjects' }}</span></header>
          <section v-for="period in group.periods" :key="period.key" class="en-catalog-period"><header><span>Year {{ period.yearLevel }} · {{ period.semester?.semester_name || 'Semester unavailable' }}</span><small>{{ period.subjects.length }} {{ period.subjects.length===1?'subject':'subjects' }}</small></header><article v-for="subject in period.subjects" :key="subject.id" class="en-subject-row"><div class="en-subject-code"><strong>{{ subject.subject_code }}</strong></div><div class="en-subject-name"><h4>{{ subject.subject_name }}</h4><p>{{ subject.units }} units · {{ subject.lecture_hours }} lecture · {{ subject.laboratory_hours }} laboratory</p></div><span class="en-badge" :data-status="subject.status">{{ subject.status === 'inactive' ? 'Archived' : label(subject.status) }}</span><div class="en-subject-context"><small>Prerequisite</small><span>{{ subject.prerequisite?.subject_code || 'None' }}</span><small>{{ subject.section_subjects_count }} section assignments · {{ subject.enrollment_subjects_count }} enrolled records</small></div><div class="en-subject-actions"><button type="button" class="en-modern-ghost en-row-action" @click="detail=subject">View</button><div class="en-row-menu"><button :ref="element => setManageMenuTrigger(subject.id, element)" type="button" class="en-row-menu-trigger" :aria-expanded="manageMenuId === subject.id" :aria-controls="`subject-manage-menu-${subject.id}`" @click.stop="toggleManageMenu(subject.id)">Manage</button><div v-if="manageMenuId === subject.id" :id="`subject-manage-menu-${subject.id}`" class="en-row-menu-popover" role="menu" @click.stop><button type="button" role="menuitem" @click="chooseManageAction(subject, 'edit')">Edit</button><button type="button" role="menuitem" @click="chooseManageAction(subject, 'assign')">Assign to Curriculum</button><button v-if="canManageProfessors" type="button" role="menuitem" @click="chooseManageAction(subject, 'professors')">Manage Professors</button><button type="button" role="menuitem" class="en-danger-link" @click="chooseManageAction(subject, 'status')">{{ subject.status==='active'?'Archive':'Reactivate' }}</button></div></div></div></article></section>
        </section>
        <nav class="en-compact-pagination" aria-label="Subject pages"><button :disabled="busy||page<=1" @click="page--;load()">Previous</button><span>Page {{ data.subjects.current_page }} of {{ data.subjects.last_page }}</span><button :disabled="busy||page>=data.subjects.last_page" @click="page++;load()">Next</button></nav>
      </section>
    </template>

    <EnrollmentDialog v-if="form" labelledby="subject-form-title" :busy="busy" wide @cancel="form=null"><form class="en-modern-dialog" @submit.prevent="save"><header><div><p>Curriculum catalog</p><h2 id="subject-form-title">{{ form.id ? (form.assignment ? 'Assign Subject to Curriculum' : 'Edit Subject') : 'Add Subject' }}</h2><span>Use only the approved academic subject and curriculum information.</span></div><button type="button" aria-label="Close subject editor" :disabled="busy" @click="form=null">×</button></header><div class="en-modern-dialog-body"><div class="en-modern-form-grid"><label>Curriculum<select v-model="form.curriculum_id" required><option value="">Choose curriculum</option><option v-for="curriculum in curricula.filter(item=>item.status==='active'&&item.course?.status==='active')" :key="curriculum.id" :value="curriculum.id">{{ curriculum.course?.course_code }} · {{ curriculum.curriculum_code }} — {{ curriculum.curriculum_name }}</option></select></label><label>Semester<select v-model="form.semester_id" required><option value="">Choose semester</option><option v-for="semester in data.semesters.filter(item=>item.status==='active')" :key="semester.id" :value="semester.id">{{ semester.semester_name }}</option></select></label><label>Subject Code<input v-model.trim="form.subject_code" required maxlength="20" placeholder="BUS101"></label><label>Subject Name<input v-model.trim="form.subject_name" required maxlength="150"></label><label>Year Level<input v-model.number="form.year_level" type="number" min="1" :max="formCurriculum?.course?.years||20" required></label><label>Units<input v-model.number="form.units" type="number" min="0" max="999.9" step="0.1" required></label><label>Lecture Hours<input v-model.number="form.lecture_hours" type="number" min="0" max="40" required></label><label>Laboratory Hours<input v-model.number="form.laboratory_hours" type="number" min="0" max="40" required></label><label>Prerequisite<select v-model="form.prerequisite_subject_id"><option :value="null">No prerequisite</option><option v-for="subject in prerequisites" :key="subject.id" :value="subject.id">{{ subject.subject_code }} — {{ subject.subject_name }}</option></select></label><label v-if="!form.id">Status<select v-model="form.status"><option value="active">Active</option><option value="inactive">Archived</option></select></label></div><p v-if="form.id" class="en-form-note">Code, curriculum, semester, and year level cannot change after the subject is used in Enrollment, Scheduling, prerequisites, or grading.</p></div><footer><button type="button" class="en-modern-outline" :disabled="busy" @click="form=null">Cancel</button><button class="en-modern-primary" :disabled="busy" type="submit">Save Subject</button></footer></form></EnrollmentDialog>
    <EnrollmentDialog v-if="detail" labelledby="subject-detail-title" @cancel="detail=null"><div class="en-modern-confirm"><h2 id="subject-detail-title">{{ detail.subject_code }} — {{ detail.subject_name }}</h2><dl class="en-modern-details"><div><dt>Program and curriculum</dt><dd>{{ detail.curriculum?.course?.course_name }} · {{ detail.curriculum?.curriculum_name }}</dd></div><div><dt>Academic context</dt><dd>Year {{ detail.year_level }} · {{ detail.semester?.semester_name }}</dd></div><div><dt>Units and hours</dt><dd>{{ detail.units }} units · {{ detail.lecture_hours }} lecture · {{ detail.laboratory_hours }} laboratory</dd></div><div><dt>Prerequisite</dt><dd>{{ detail.prerequisite?.subject_code || 'None' }}</dd></div><div><dt>References</dt><dd>{{ detail.section_subjects_count }} section assignments · {{ detail.enrollment_subjects_count }} enrolled records</dd></div></dl><footer><button class="en-modern-outline" @click="detail=null">Close</button></footer></div></EnrollmentDialog>
    <EnrollmentDialog v-if="statusChange" labelledby="subject-status-title" :busy="busy" @cancel="statusChange=null"><div class="en-modern-confirm"><h2 id="subject-status-title">{{ statusChange.status==='active'?'Archive':'Reactivate' }} {{ statusChange.subject_code }}?</h2><p>Historical academic records remain unchanged. Archived subjects cannot be selected for new curriculum loads or schedules.</p><footer><button class="en-modern-outline" @click="statusChange=null">Cancel</button><button class="en-modern-primary" :disabled="busy" @click="changeStatus">Confirm</button></footer></div></EnrollmentDialog>
    <EnrollmentDialog v-if="professorAssignments" labelledby="subject-professors-title" :busy="busy" wide @cancel="professorAssignments=null"><form class="en-modern-dialog en-professor-assignment-dialog" @submit.prevent="saveProfessorAssignments"><header><div><p>Subject staffing</p><h2 id="subject-professors-title">{{ professorAssignments.subject.subject_code }} — {{ professorAssignments.subject.subject_name }}</h2><span>{{ professorAssignments.selected.length }} associated Professor{{ professorAssignments.selected.length===1?'':'s' }}. Teaching access still requires a Section assignment.</span></div><button type="button" aria-label="Close Professor assignments" :disabled="busy" @click="professorAssignments=null">×</button></header><div class="en-modern-dialog-body"><label class="en-professor-search">Find a Professor<input v-model.trim="professorSearch" type="search" maxlength="100" placeholder="Name, employee ID, or department"></label><div class="en-professor-assignment-list" role="group" aria-label="Professor associations"><label v-for="professor in filteredProfessors" :key="professor.id" :class="{ selected: professorSelected(professor.id) }"><input type="checkbox" :checked="professorSelected(professor.id)" :disabled="!professorCanToggle(professor)" @change="toggleProfessor(professor.id)"><span><strong>{{ [professor.first_name, professor.middle_name, professor.last_name].filter(Boolean).join(' ') }}</strong><small>{{ professor.employee_number }} · {{ professor.department_code || professor.department_name || 'Department unavailable' }}</small></span><em v-if="professor.status!=='active'||professor.account_status!=='active'">Inactive</em></label><p v-if="!filteredProfessors.length">No Professors match this search.</p></div></div><footer><button type="button" class="en-modern-outline" :disabled="busy" @click="professorAssignments=null">Cancel</button><button type="submit" class="en-modern-primary" :disabled="busy">Save associations</button></footer></form></EnrollmentDialog>
  </section>
</template>
