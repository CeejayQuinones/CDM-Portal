<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { apiClient } from '../../services/apiClient'
import { safeError, label, termLabel, hasSavedSchedule } from './enrollment'
import EnrollmentSchedule from './EnrollmentSchedule.vue'
import EnrollmentCor from './EnrollmentCor.vue'
import EnrollmentOvrfActions from './EnrollmentOvrfActions.vue'
import EnrollmentDialog from './EnrollmentDialog.vue'
import ScheduleTimeSelect from './ScheduleTimeSelect.vue'
import { formatScheduleDuration, meetingTimeState, weeklyScheduleMinutes } from './scheduleTime'
import './registrarEnrollmentComplete.css'

const route = useRoute()
const mode = computed(() => route.name === 'enrollment-sections' ? 'sections' : route.name === 'enrollment-scheduling' ? 'scheduling' : 'records')
const pageTitle = computed(() => mode.value === 'sections' ? 'Sections' : mode.value === 'scheduling' ? 'Scheduling' : 'Enrollment Records')
const pageDescription = computed(() => mode.value === 'sections'
  ? 'Manage academic sections, verified capacity, reservations, and availability.'
  : mode.value === 'scheduling'
    ? 'Build and review section timetables using authorized subjects and teaching assignments.'
    : 'Find official student enrollment records and inspect Certificates of Registration.')
const options = ref(null), data = ref(null), sections = ref(null), scheduleData = ref(null), sectionId = ref(''), sectionSearch = ref(''), selectedRecord = ref(null), sectionDetail = ref(null), sectionPage = ref(1), profPage = ref(1), page = ref(1), form = ref(null), record = ref(null), remove = ref(null), reassignment = ref(null), busy = ref(false), error = ref(''), notice = ref(''), formErrors = ref({}), modalError = ref(''), scheduleDialogForm = ref(null)
const filters = ref({ search: '', course_id: '', section_id: '', academic_year_id: '', semester_id: '', classification: '', status: '', sort: 'newest' })
const filtersOpen = ref(false)
const sectionCurricula = computed(() => options.value?.curriculums?.filter(curriculum => Number(curriculum.course_id) === Number(form.value?.course_id)) || [])
const selectedSection = computed(() => sections.value?.data?.find(section => Number(section.id) === Number(sectionId.value)) || scheduleData.value?.section || null)
const visibleSections = computed(() => {
  const query = sectionSearch.value.trim().toLocaleLowerCase()
  if (!query) return sections.value?.data || []
  return (sections.value?.data || []).filter(section => [section.section_name, section.course?.course_name, section.course?.course_code].filter(Boolean).some(value => String(value).toLocaleLowerCase().includes(query)))
})
const activeFilterCount = computed(() => [filters.value.course_id, filters.value.section_id, filters.value.academic_year_id, filters.value.semester_id, filters.value.classification, filters.value.status, filters.value.sort !== 'newest' ? filters.value.sort : ''].filter(Boolean).length)
const scheduleDays = computed(() => scheduleData.value?.days || ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'])
const scheduleTimesValid = computed(() => Boolean(form.value?.meetings?.length) && form.value.meetings.every(meeting => meetingTimeState(meeting).valid))
const totalWeeklyMinutes = computed(() => weeklyScheduleMinutes(form.value?.meetings))
const scheduleAvailability = ref({ status: 'idle', meetings: [], message: '' })
const availabilityReady = computed(() => mode.value === 'scheduling' && Boolean(
  form.value?.section_id && form.value?.subject_id && form.value?.professor_id && form.value?.room?.trim() && scheduleTimesValid.value,
))
const availabilityHasConflicts = computed(() => scheduleAvailability.value.meetings.some(meeting => !meeting.available))
const scheduleCanSave = computed(() => availabilityReady.value && scheduleAvailability.value.status === 'available' && !availabilityHasConflicts.value)
const availabilitySignature = computed(() => mode.value === 'scheduling' && form.value ? JSON.stringify({
  ...(form.value.id ? { schedule_id: form.value.id } : {}),
  section_id: form.value.section_id,
  subject_id: form.value.subject_id,
  professor_id: form.value.professor_id,
  room: form.value.room?.trim() || '',
  meetings: (form.value.meetings || []).map(({ day, start_time, end_time }) => ({ day, start_time, end_time })),
}) : '')
const scheduleFieldKeys = ['section_id', 'subject_id', 'professor_id', 'room']
const formValidationSummary = computed(() => Object.entries(formErrors.value)
  .filter(([key]) => !key.startsWith('meetings.') && !['day', 'start_time', 'end_time', ...scheduleFieldKeys].includes(key))
  .flatMap(([, messages]) => messages).join(' '))
const prioritizedProfessors = computed(() => {
  const rows = options.value?.professors?.data || []
  const subject = scheduleData.value?.subjects?.find(item => Number(item.id) === Number(form.value?.subject_id))
  const associated = new Set((subject?.professors || []).map(professor => Number(professor.id)))
  return [...rows].sort((left, right) => Number(associated.has(Number(right.id))) - Number(associated.has(Number(left.id))) || String(left.employee_number).localeCompare(String(right.employee_number)))
})
const capacityPercent = section => section?.capacity ? Math.min(100, Math.round(((Number(section.enrolled_count) + Number(section.reserved_count)) / Number(section.capacity)) * 100)) : 0
const studentName = row => [row.student?.user_profile?.first_name, row.student?.user_profile?.last_name].filter(Boolean).join(' ') || 'Student name unavailable'
const scheduleProfessorName = schedule => [schedule.professor?.user?.profile?.first_name, schedule.professor?.user?.profile?.last_name].filter(Boolean).join(' ') || 'Not assigned'
let epoch = 0
let availabilityTimer = null
let availabilityRequest = 0

async function load() {
  const version = ++epoch; busy.value = true; error.value = ''
  try {
    const [optionResponse, sectionResponse] = await Promise.all([
      apiClient.get('/enrollment/academic/options'),
      apiClient.get('/enrollment/academic/sections', { params: { page: sectionPage.value, course_id: filters.value.course_id, academic_year_id: filters.value.academic_year_id, semester_id: filters.value.semester_id, search: mode.value === 'sections' ? filters.value.search : '' } }),
    ])
    if (version !== epoch) return
    options.value = optionResponse.data.data; sections.value = sectionResponse.data.data; profPage.value = 1
    if (mode.value === 'scheduling') {
      if (sectionId.value) await loadSchedules()
      else data.value = null
    } else {
      const response = await apiClient.get(`/enrollment/academic/${mode.value}`, { params: { ...filters.value, page: page.value } })
      if (version === epoch) {
        data.value = response.data.data
        if (mode.value === 'records') selectedRecord.value = data.value.data.find(row => Number(row.id) === Number(selectedRecord.value?.id)) || data.value.data[0] || null
      }
    }
  } catch (reason) { if (version === epoch) error.value = safeError(reason) }
  finally { if (version === epoch) busy.value = false }
}
async function loadSchedules() {
  if (!sectionId.value) return
  const version = ++epoch; busy.value = true; error.value = ''
  try {
    const response = await apiClient.get('/enrollment/academic/schedules', { params: { section_id: sectionId.value, search: filters.value.search, page: page.value } })
    if (version === epoch) scheduleData.value = response.data.data
  } catch (reason) { if (version === epoch) error.value = safeError(reason) }
  finally { if (version === epoch) busy.value = false }
}
async function moreSections(delta) {
  sectionPage.value += delta
  const version = epoch
  try {
    const response = await apiClient.get('/enrollment/academic/sections', { params: { page: sectionPage.value, course_id: filters.value.course_id, academic_year_id: filters.value.academic_year_id, semester_id: filters.value.semester_id } })
    if (version === epoch) sections.value = response.data.data
  } catch (reason) { if (version === epoch) error.value = safeError(reason) }
}
async function moreProfessors() {
  const version = epoch
  try {
    const response = await apiClient.get('/enrollment/academic/options', { params: { page: ++profPage.value } })
    if (version === epoch) {
      options.value.professors.data.push(...response.data.data.professors.data)
      options.value.professors.current_page = response.data.data.professors.current_page
    }
  } catch (reason) { if (version === epoch) error.value = safeError(reason) }
}
function editSection(section) { form.value = section ? { ...section } : { course_id: '', curriculum_id: '', academic_year_id: '', semester_id: '', section_name: '', year_level: 1, capacity: 40, status: 'open', adviser_id: null } }
function selectSection(id) { sectionId.value = Number(id); page.value = 1; form.value = null; loadSchedules() }
const scheduleMeetings = schedule => schedule?.meetings?.length ? schedule.meetings.map(meeting => ({ day: meeting.day, start_time: meeting.start_time.slice(0, 5), end_time: meeting.end_time.slice(0, 5) })) : schedule?.day ? [{ day: schedule.day, start_time: schedule.start_time.slice(0, 5), end_time: schedule.end_time.slice(0, 5) }] : []
function clearModalErrors() { formErrors.value = {}; modalError.value = '' }
function resetAvailability() {
  if (availabilityTimer) clearTimeout(availabilityTimer)
  availabilityTimer = null
  availabilityRequest++
  scheduleAvailability.value = { status: 'idle', meetings: [], message: '' }
}
function editSchedule(schedule, copy = false) { clearModalErrors(); resetAvailability(); notice.value = ''; form.value = schedule ? { ...schedule, id: copy ? null : schedule.id, original_professor_id: copy ? null : schedule.professor_id, meetings: scheduleMeetings(schedule) } : { section_id: Number(sectionId.value), subject_id: '', professor_id: '', meetings: [{ day: 'Monday', start_time: '', end_time: '' }], room: '' } }
function addAssignmentSchedule(schedule) { clearModalErrors(); resetAvailability(); notice.value = ''; form.value = { assignment_id: schedule.id, section_id: schedule.section_id, subject_id: schedule.subject_id, professor_id: schedule.professor_id || '', meetings: [{ day: 'Monday', start_time: '', end_time: '' }], room: '' } }
const fieldError = key => (formErrors.value[key] || []).join(' ')
function meetingServerError(index) {
  const keys = [`meetings.${index}.day`, `meetings.${index}.start_time`, `meetings.${index}.end_time`, `meetings.${index}._schedule`]
  if (index === 0) keys.push('day', 'start_time', 'end_time')
  return keys.flatMap(key => formErrors.value[key] || []).join(' ')
}
function focusFirstModalError() {
  nextTick(() => {
    const target = scheduleDialogForm.value?.querySelector?.('.en-field-error, .en-meeting-error, .en-form-validation-summary')
    if (!target) return
    target.scrollIntoView?.({ block: 'nearest', behavior: 'auto' })
    const control = target.closest?.('label, .en-meeting-row')?.querySelector?.('input:not(:disabled), select:not(:disabled), button:not(:disabled)')
    if (!control && !target.hasAttribute?.('tabindex')) target.setAttribute?.('tabindex', '-1')
    ;(control || target).focus?.({ preventScroll: true })
  })
}
function assignScheduleError(reason) {
  const response = reason?.response
  const validation = response?.status === 422 ? response.data?.errors : null
  if (validation && Object.keys(validation).length) formErrors.value = validation
  else {
    const message = response?.data?.message || safeError(reason)
    const dayIndex = form.value?.meetings?.findIndex(meeting => new RegExp(`^${meeting.day}(?:,|\\b)`, 'i').test(message)) ?? -1
    if (response?.status === 409 && dayIndex >= 0) formErrors.value = { [`meetings.${dayIndex}._schedule`]: [message] }
    else modalError.value = message
  }
  focusFirstModalError()
}
function toggleScheduleDay(day) {
  clearModalErrors()
  const index = form.value.meetings.findIndex(meeting => meeting.day === day)
  if (index >= 0) {
    if (form.value.meetings.length > 1) form.value.meetings.splice(index, 1)
  } else {
    form.value.meetings.push({ day, start_time: '', end_time: '' })
    form.value.meetings.sort((left, right) => scheduleDays.value.indexOf(left.day) - scheduleDays.value.indexOf(right.day))
  }
}
const meetingAvailability = meeting => scheduleAvailability.value.meetings.find(result => result.day === meeting.day)
function conflictDescription(conflict) {
  if (conflict.type === 'batch') return conflict.message
  const existing = conflict.existing || {}
  const context = [existing.subject?.code, existing.section?.name, existing.professor?.name].filter(Boolean).join(' · ')
  const room = existing.room ? ` · ${existing.room}` : ''
  return `${label(conflict.type)} conflict: ${context || 'Existing schedule'} · ${existing.start_time || '—'}–${existing.end_time || '—'}${room}`
}
async function checkScheduleAvailability(requestId, signature) {
  try {
    const payload = JSON.parse(signature)
    const response = await apiClient.post('/enrollment/academic/schedules/availability', payload)
    if (requestId !== availabilityRequest || signature !== availabilitySignature.value || !form.value) return
    const result = response.data.data
    scheduleAvailability.value = { status: result.available ? 'available' : 'conflict', meetings: result.meetings || [], message: '' }
  } catch (reason) {
    if (requestId !== availabilityRequest || signature !== availabilitySignature.value || !form.value) return
    scheduleAvailability.value = { status: 'error', meetings: [], message: safeError(reason) }
  }
}
function queueScheduleAvailability(signature) {
  if (availabilityTimer) clearTimeout(availabilityTimer)
  availabilityTimer = null
  const requestId = ++availabilityRequest
  if (!signature || !availabilityReady.value) {
    scheduleAvailability.value = { status: 'idle', meetings: [], message: '' }
    return
  }
  scheduleAvailability.value = { status: 'checking', meetings: [], message: '' }
  availabilityTimer = setTimeout(() => {
    availabilityTimer = null
    checkScheduleAvailability(requestId, signature)
  }, 400)
}
async function save(confirmed = false) {
  if (busy.value || (mode.value === 'scheduling' && !scheduleCanSave.value)) return
  const version = ++epoch; busy.value = true; clearModalErrors()
  try {
    const path = `/enrollment/academic/${mode.value === 'sections' ? 'sections' : 'schedules'}`
    const payload = { ...form.value }, originalProfessor = payload.original_professor_id
    delete payload.original_professor_id
    delete payload.day; delete payload.start_time; delete payload.end_time; delete payload.meetings_count; delete payload.professor; delete payload.subject; delete payload.section
    if (mode.value === 'scheduling') payload.meetings = payload.meetings.map(({ day, start_time, end_time }) => ({ day, start_time, end_time }))
    for (const key of ['course_id', 'curriculum_id', 'academic_year_id', 'semester_id', 'year_level', 'capacity', 'section_id', 'subject_id', 'professor_id', 'adviser_id', 'assignment_id']) if (payload[key] !== undefined) payload[key] = payload[key] ? Number(payload[key]) : null
    if (!confirmed && payload.id && originalProfessor && Number(originalProfessor) !== payload.professor_id) { reassignment.value = payload; return }
    if (payload.id) await apiClient.put(`${path}/${payload.id}`, payload)
    else await apiClient.post(path, payload)
    if (version !== epoch) return
    form.value = null; reassignment.value = null; notice.value = 'Saved successfully.'; await load()
  } catch (reason) {
    if (version === epoch) {
      if (mode.value === 'scheduling') assignScheduleError(reason)
      else error.value = safeError(reason)
    }
  }
  finally { if (version === epoch) busy.value = false }
}
async function confirmReassignment() { form.value = { ...reassignment.value }; await save(true) }
const professorName = id => { const professor = options.value?.professors?.data?.find(row => Number(row.id) === Number(id)); return professor ? `${professor.user?.profile?.first_name || ''} ${professor.user?.profile?.last_name || ''}`.trim() || professor.employee_number : 'the selected Professor' }
async function deleteSchedule() {
  if (busy.value) return
  const version = ++epoch; busy.value = true; error.value = ''
  try {
    await apiClient.delete(`/enrollment/academic/schedules/${remove.value.id}`, { data: { version: remove.value.version, confirmed: true } })
    if (version !== epoch) return
    remove.value = null; notice.value = 'Schedule removed.'; await loadSchedules()
  } catch (reason) { if (version === epoch) error.value = safeError(reason); remove.value = null }
  finally { if (version === epoch) busy.value = false }
}
async function inspect(id) {
  const version = ++epoch; busy.value = true; error.value = ''
  try { const response = await apiClient.get(`/enrollment/academic/records/${id}`); if (version === epoch) record.value = response.data.data }
  catch (reason) { if (version === epoch) error.value = safeError(reason) }
  finally { if (version === epoch) busy.value = false }
}
function applyFilters() { page.value = 1; sectionPage.value = 1; load() }
function resetFilters() { filters.value = { search: '', course_id: '', section_id: '', academic_year_id: '', semester_id: '', classification: '', status: '', sort: 'newest' }; filtersOpen.value = false; page.value = 1; sectionPage.value = 1; load() }
watch(() => form.value, () => { if (form.value && (modalError.value || Object.keys(formErrors.value).length)) clearModalErrors() }, { deep: true })
watch(availabilitySignature, signature => queueScheduleAvailability(signature))
watch(() => route.name, () => {
  data.value = null; scheduleData.value = null; sectionId.value = ''; sectionSearch.value = ''; selectedRecord.value = null; sectionDetail.value = null; form.value = null; clearModalErrors(); resetAvailability(); record.value = null; remove.value = null; reassignment.value = null; filtersOpen.value = false; page.value = 1; sectionPage.value = 1
  filters.value = { search: '', course_id: '', section_id: '', academic_year_id: '', semester_id: '', classification: '', status: '', sort: 'newest' }
  load()
}, { immediate: true })
onBeforeUnmount(() => { epoch++; resetAvailability() })
</script>

<template>
  <section class="en-staff-modern" :class="`en-staff-${mode}`">
    <header class="en-modern-header en-no-print">
      <div><p>Enrollment management</p><h1>{{ pageTitle }}</h1><span>{{ pageDescription }}</span></div>
      <button v-if="mode==='sections'" class="en-modern-primary" type="button" :disabled="busy" @click="editSection(null)">Create section</button>
      <button v-else-if="mode==='scheduling' && sectionId && !scheduleData?.configuration_issue" class="en-modern-primary" type="button" :disabled="busy" @click="editSchedule(null)">Add schedule</button>
    </header>

    <div v-if="error" class="en-modern-feedback error" role="alert"><span>{{ error }}</span><button :disabled="busy" @click="load">Reload</button></div>
    <p v-if="notice" class="en-modern-feedback success" role="status">{{ notice }}</p>
    <p v-if="busy" class="en-modern-loading" role="status">Loading…</p>

    <template v-if="options">
      <template v-if="mode==='scheduling'">
        <section v-if="selectedSection" class="en-schedule-context-strip" aria-label="Selected schedule context"><div><small>Section</small><strong>{{ selectedSection.section_name }}</strong></div><div><small>Program</small><strong>{{ selectedSection.course?.course_name || 'Program unavailable' }}</strong></div><div><small>Academic year</small><strong>{{ selectedSection.academic_year?.school_year || 'Unavailable' }}</strong></div><div><small>Semester</small><strong>{{ selectedSection.semester?.semester_name || 'Unavailable' }}</strong></div><span class="en-badge" :data-status="selectedSection.status">{{ label(selectedSection.status) }}</span></section>

        <section class="en-scheduling-layout">
          <aside class="en-section-browser en-no-print" aria-label="Section browser">
            <header><div><h2>Sections</h2><span>{{ sections.total }} available</span></div></header>
            <label class="en-browser-search">Find a section<input v-model.trim="sectionSearch" type="search" maxlength="100" placeholder="Search section…"></label>
            <label class="en-section-native-select">Active section<select v-model="sectionId" :disabled="busy" @change="selectSection(sectionId)"><option value="">Choose section</option><option v-for="section in sections.data" :key="section.id" :value="section.id">{{ section.section_name }} · {{ termLabel(section) }}</option></select></label>
            <div class="en-section-browser-list"><button v-for="section in visibleSections" :key="section.id" type="button" :class="{ active:Number(sectionId)===Number(section.id) }" :aria-pressed="Number(sectionId)===Number(section.id)" :disabled="busy" @click="selectSection(section.id)"><span><strong>{{ section.section_name }}</strong><small>{{ termLabel(section) }}</small></span><span class="en-badge" :data-status="section.status">{{ label(section.status) }}</span></button><p v-if="!visibleSections.length" class="en-browser-empty">No matching sections.</p></div>
            <nav v-if="sections.last_page>1" class="en-browser-pagination" aria-label="Section pages"><button :disabled="busy||sectionPage<=1" @click="moreSections(-1)">Previous</button><span>{{ sectionPage }} / {{ sections.last_page }}</span><button :disabled="busy||sectionPage>=sections.last_page" @click="moreSections(1)">Next</button></nav>
            <section v-if="selectedSection" class="en-section-overview"><h3>Section overview</h3><div><span><small>Enrolled</small><strong>{{ selectedSection.enrolled_count }}</strong></span><span><small>Reserved</small><strong>{{ selectedSection.reserved_count }}</strong></span><span><small>Capacity</small><strong>{{ selectedSection.capacity }}</strong></span></div><progress :value="Number(selectedSection.enrolled_count)+Number(selectedSection.reserved_count)" :max="selectedSection.capacity">{{ capacityPercent(selectedSection) }}%</progress><small>{{ capacityPercent(selectedSection) }}% filled</small></section>
          </aside>

          <main class="en-timetable-workspace">
            <header><div><h2>Weekly timetable</h2><p v-if="selectedSection">{{ selectedSection.section_name }} · {{ termLabel(selectedSection) }}</p></div></header>
            <div v-if="scheduleData?.configuration_issue" class="en-modern-empty" role="alert"><h2>Section configuration needs attention</h2><p>{{ scheduleData.configuration_issue }}</p><RouterLink class="en-link" to="/enrollment/sections">Edit Section</RouterLink></div>
            <div v-else-if="sectionId && scheduleData && !scheduleData.subjects.length" class="en-modern-empty" role="status"><h2>No active curriculum subjects</h2><p>No active subjects are configured for {{ scheduleData.curriculum?.curriculum_code }}, Year {{ scheduleData.section.year_level }}, and {{ scheduleData.section.semester?.semester_name }}.</p><RouterLink class="en-link" to="/enrollment/subject-management">Open Subject Management</RouterLink></div>
            <div v-else-if="!sectionId" class="en-modern-empty"><h2>Select a section</h2><p>Choose an authorized section to view its weekly timetable and teaching assignments.</p></div>
            <EnrollmentSchedule v-else-if="scheduleData" :schedules="scheduleData.schedules.data"/>
          </main>

          <aside class="en-scheduled-subjects" aria-label="Scheduled subjects">
            <header><div><h2>Scheduled subjects</h2><span v-if="scheduleData">{{ scheduleData.schedules.total }} assignments</span></div></header>
            <form class="en-subject-search en-no-print" @submit.prevent="loadSchedules"><label>Find a scheduled subject<input v-model.trim="filters.search" type="search" maxlength="100" placeholder="Search this section…"></label><button type="submit" :disabled="busy||!sectionId" aria-label="Search scheduled subjects">Search</button></form>
            <div v-if="!sectionId" class="en-panel-empty">Select a section to inspect its assignments.</div>
            <div v-else-if="scheduleData" class="en-scheduled-subject-list"><article v-for="schedule in scheduleData.schedules.data" :key="schedule.id" class="en-scheduled-subject"><header><div><strong>{{ schedule.subject?.subject_code || 'Subject' }}</strong><span>{{ schedule.subject?.subject_name || 'Subject details unavailable' }}</span></div><small>{{ schedule.subject?.units ?? '—' }} units</small></header><dl><div><dt>Schedule</dt><dd v-if="hasSavedSchedule(schedule)" class="en-meeting-summary"><span v-for="meeting in scheduleMeetings(schedule)" :key="meeting.day">{{ meeting.day }} · {{ meeting.start_time }}–{{ meeting.end_time }}</span></dd><dd v-else>Not assigned</dd></div><div><dt>Room</dt><dd>{{ hasSavedSchedule(schedule) ? schedule.room : 'Not assigned' }}</dd></div><div><dt>Professor</dt><dd>{{ scheduleProfessorName(schedule) }}</dd></div></dl><div class="en-actions en-schedule-row-actions en-no-print"><button v-if="hasSavedSchedule(schedule)" :disabled="busy" @click="editSchedule(schedule)">Edit</button><button v-else :disabled="busy" @click="addAssignmentSchedule(schedule)">Add Schedule</button><template v-if="hasSavedSchedule(schedule)"><button :disabled="busy" @click="editSchedule(schedule,true)">Copy</button><button class="en-danger-link" :disabled="busy" @click="remove=schedule">Delete</button></template></div></article><p v-if="!scheduleData.schedules.data.length" class="en-panel-empty">No scheduled subjects match this search.</p></div>
            <nav v-if="scheduleData?.schedules.last_page>1" class="en-browser-pagination en-no-print"><button :disabled="busy||page<=1" @click="page--;loadSchedules()">Previous</button><span>{{ page }} / {{ scheduleData.schedules.last_page }}</span><button :disabled="busy||page>=scheduleData.schedules.last_page" @click="page++;loadSchedules()">Next</button></nav>
          </aside>
        </section>
      </template>

      <template v-else>
        <template v-if="mode==='sections'">
          <form class="en-modern-toolbar en-section-catalog-toolbar en-no-print" @submit.prevent="applyFilters"><label class="en-modern-search">Find a section<input v-model.trim="filters.search" type="search" maxlength="100" placeholder="Search section name…"></label><label>Program<select v-model="filters.course_id"><option value="">All programs</option><option v-for="course in options.courses" :key="course.id" :value="course.id">{{ course.course_name }}</option></select></label><label>Academic year<select v-model="filters.academic_year_id"><option value="">All years</option><option v-for="year in options.academic_years" :key="year.id" :value="year.id">{{ year.school_year }}</option></select></label><label>Semester<select v-model="filters.semester_id"><option value="">All semesters</option><option v-for="semester in options.semesters" :key="semester.id" :value="semester.id">{{ semester.semester_name }}</option></select></label><div class="en-toolbar-actions"><button class="en-modern-primary" :disabled="busy" type="submit">Search</button><button type="button" class="en-modern-outline en-more-filters" :aria-expanded="filtersOpen" aria-controls="en-section-filters" @click="filtersOpen=!filtersOpen">More filters</button><button type="button" class="en-modern-ghost" :disabled="busy" @click="resetFilters">Reset</button></div></form>
          <form :hidden="!filtersOpen" id="en-section-filters" class="en-modern-filters en-no-print" @submit.prevent="applyFilters"><label>Status<select v-model="filters.status"><option value="">All statuses</option><option value="open">Open</option><option value="closed">Closed</option></select></label><button class="en-modern-primary" :disabled="busy" type="submit">Apply filters</button></form>
          <section v-if="data" class="en-section-directory" aria-label="Academic sections"><header><div><h2>Section catalog</h2><p>{{ data.total }} sections match the current academic context.</p></div><span>Page {{ data.current_page }} of {{ data.last_page }}</span></header><div class="en-section-card-grid"><article v-for="section in data.data" :key="section.id" class="en-section-card"><header><div class="en-section-monogram">{{ section.section_name.slice(0,2) }}</div><div><h3>{{ section.section_name }}</h3><p>{{ section.course?.course_name || 'Program unavailable' }}</p><small>{{ termLabel(section) }}</small></div><span class="en-badge" :data-status="section.status">{{ label(section.status) }}</span></header><div class="en-section-card-counts"><span><small>Enrolled</small><strong>{{ section.enrolled_count }}</strong></span><span><small>Reserved</small><strong>{{ section.reserved_count }}</strong></span><span><small>Capacity</small><strong>{{ section.capacity }}</strong></span></div><div class="en-section-card-capacity"><progress :value="Number(section.enrolled_count)+Number(section.reserved_count)" :max="section.capacity">{{ capacityPercent(section) }}%</progress><small>{{ capacityPercent(section) }}% filled</small></div><footer><button type="button" class="en-modern-ghost" @click="sectionDetail=section">View</button><button type="button" class="en-modern-outline" :disabled="busy" @click="editSection(section)">Edit</button></footer></article><div v-if="!data.data.length" class="en-modern-empty"><h2>No matching sections</h2><p>Adjust the program, academic term, or status filters.</p></div></div><nav class="en-compact-pagination en-no-print" aria-label="Section result pages"><button :disabled="busy||page<=1" @click="page--;load()">Previous</button><span>Page {{ page }} of {{ data.last_page }}</span><button :disabled="busy||page>=data.last_page" @click="page++;load()">Next</button></nav></section>
        </template>

        <section v-else-if="data" class="en-records-workspace" aria-label="Official enrollment records">
          <aside class="en-record-filters en-no-print"><header><h2>Filters</h2><button type="button" class="en-modern-ghost" :disabled="busy" @click="resetFilters">Clear</button></header><form @submit.prevent="applyFilters"><label>Find a student<input v-model.trim="filters.search" type="search" maxlength="100" placeholder="Name or student number…"></label><label>Academic year<select v-model="filters.academic_year_id"><option value="">All years</option><option v-for="year in options.academic_years" :key="year.id" :value="year.id">{{ year.school_year }}</option></select></label><label>Program<select v-model="filters.course_id"><option value="">All programs</option><option v-for="course in options.courses" :key="course.id" :value="course.id">{{ course.course_name }}</option></select></label><label>Section<select v-model="filters.section_id"><option value="">All sections</option><option v-for="section in sections.data" :key="section.id" :value="section.id">{{ section.section_name }}</option></select></label><button type="button" class="en-modern-outline en-more-filters" :aria-expanded="filtersOpen" aria-controls="en-record-more-filters" @click="filtersOpen=!filtersOpen">More filters{{ activeFilterCount ? ` (${activeFilterCount})` : '' }}</button><div :hidden="!filtersOpen" id="en-record-more-filters" class="en-record-more-filters"><label>Classification<select v-model="filters.classification"><option value="">All classifications</option><option v-for="classification in ['regular','irregular','transferee','returnee']" :key="classification" :value="classification">{{ label(classification) }}</option></select></label><label>Status<select v-model="filters.status"><option value="">All statuses</option><option v-for="state in ['pending','enrolled','completed','cancelled']" :key="state" :value="state">{{ label(state) }}</option></select></label><label>Sort<select v-model="filters.sort"><option v-for="sort in ['newest','oldest','student_number','name','classification']" :key="sort" :value="sort">{{ label(sort) }}</option></select></label></div><button class="en-modern-primary" :disabled="busy" type="submit">Apply filters</button></form></aside>

          <section class="en-record-results"><header><div><h2>Student records</h2><p>{{ data.total }} records</p></div></header><div class="en-record-master-list"><button v-for="row in data.data" :key="row.id" type="button" class="en-record-master-row" :class="{ active:Number(selectedRecord?.id)===Number(row.id) }" :aria-pressed="Number(selectedRecord?.id)===Number(row.id)" @click="selectedRecord=row"><span class="en-record-avatar">{{ studentName(row).split(' ').map(part=>part[0]).join('').slice(0,2) }}</span><span class="en-record-primary"><strong :title="studentName(row)">{{ studentName(row) }}</strong><small>{{ row.student?.student_number || 'Student number unavailable' }}</small></span><span class="en-record-context"><strong>{{ row.section?.course?.course_code || row.student?.course?.course_code || 'Program' }} · {{ row.section?.section_name || 'No section' }}</strong><small>{{ termLabel(row) }}</small></span><span class="en-badge">{{ row.application ? label(row.application.classification) : 'Legacy record' }}</span><span class="en-badge" :data-status="row.status">{{ label(row.status) }}</span><span aria-hidden="true">›</span></button><div v-if="!data.data.length" class="en-modern-empty"><h2>No matching records</h2><p>Adjust or clear the filters to search another academic context.</p></div></div><nav class="en-compact-pagination en-no-print" aria-label="Enrollment record pages"><button :disabled="busy||page<=1" @click="page--;load()">Previous</button><span>Page {{ page }} of {{ data.last_page }}</span><button :disabled="busy||page>=data.last_page" @click="page++;load()">Next</button></nav></section>

          <aside v-if="selectedRecord" class="en-record-inspector" aria-label="Selected enrollment record"><header><span class="en-record-avatar">{{ studentName(selectedRecord).split(' ').map(part=>part[0]).join('').slice(0,2) }}</span><div><h2>{{ studentName(selectedRecord) }}</h2><p>{{ selectedRecord.student?.student_number || 'Student number unavailable' }}</p></div><button type="button" class="en-inspector-close" aria-label="Close record details" @click="selectedRecord=null">×</button></header><div class="en-record-inspector-tabs"><span class="active">Overview</span><span>Subjects</span><span>COR / OVRF</span></div><dl><div><dt>Program</dt><dd>{{ selectedRecord.section?.course?.course_name || selectedRecord.student?.course?.course_name || 'Program unavailable' }}</dd></div><div><dt>Section</dt><dd>{{ selectedRecord.section?.section_name || 'No section' }}</dd></div><div><dt>Academic term</dt><dd>{{ termLabel(selectedRecord) }}</dd></div><div><dt>Classification</dt><dd>{{ selectedRecord.application ? label(selectedRecord.application.classification) : 'Legacy record' }}</dd></div><div><dt>Record status</dt><dd>{{ label(selectedRecord.status) }}</dd></div></dl><footer><template v-if="['enrolled','completed'].includes(selectedRecord.status)"><button type="button" class="en-modern-primary" :disabled="busy" @click="inspect(selectedRecord.id)">View COR</button><EnrollmentOvrfActions :enrollment-id="selectedRecord.id" :student-name="studentName(selectedRecord)" :academic-term="termLabel(selectedRecord)" /></template><span v-else class="en-record-unavailable">COR and OVRF unavailable for this record</span></footer></aside>
          <aside v-else class="en-record-inspector en-record-inspector-empty"><h2>Select a record</h2><p>Choose a student record to inspect its authorized enrollment summary.</p></aside>
        </section>
      </template>
    </template>

    <EnrollmentDialog v-if="form" :class="{ 'en-schedule-editor-overlay': mode==='scheduling' }" labelledby="en-academic-editor" :busy="busy" wide @cancel="form=null">
      <form ref="scheduleDialogForm" class="en-modern-dialog" :class="{ 'en-schedule-dialog': mode==='scheduling' }" @submit.prevent="save()"><header><div><p>Enrollment management</p><h2 id="en-academic-editor">{{ form.id ? 'Edit' : mode==='sections' ? 'Create' : 'Add' }} {{ mode==='sections' ? 'section' : 'schedule' }}</h2><span>{{ mode==='sections' ? 'Configure the real academic context, capacity, state, and adviser.' : 'Assign a verified curriculum subject, Professor, meetings, and room.' }}</span></div><button type="button" aria-label="Close editor" :disabled="busy" @click="form=null">×</button></header><div class="en-modern-dialog-body"><p v-if="modalError || formValidationSummary" class="en-form-validation-summary" role="alert" tabindex="-1">{{ modalError || formValidationSummary }}</p><div class="en-modern-form-grid"><template v-if="mode==='sections'"><label>Section name<input v-model="form.section_name" required maxlength="20"></label><label>Course<select v-model="form.course_id" required @change="form.curriculum_id='' "><option value="">Choose Course</option><option v-for="course in options.courses" :key="course.id" :value="course.id">{{ course.course_name }}</option></select></label><label>Curriculum<select v-model="form.curriculum_id" required><option value="">Choose curriculum</option><option v-for="curriculum in sectionCurricula" :key="curriculum.id" :value="curriculum.id">{{ curriculum.curriculum_code }} — {{ curriculum.curriculum_name }}</option></select></label><label>Academic year<select v-model="form.academic_year_id" :disabled="!!form.id" required><option value="">Choose year</option><option v-for="year in options.academic_years" :key="year.id" :value="year.id">{{ year.school_year }}</option></select></label><label>Semester<select v-model="form.semester_id" :disabled="!!form.id" required><option value="">Choose semester</option><option v-for="semester in options.semesters" :key="semester.id" :value="semester.id">{{ semester.semester_name }}</option></select></label><label>Year level<input v-model.number="form.year_level" type="number" min="1" max="20" required></label><label>Capacity<input v-model.number="form.capacity" type="number" min="1" max="65535" required></label><label>State<select v-model="form.status"><option value="open">Open</option><option value="closed">Closed</option></select></label><label>Adviser<select v-model="form.adviser_id"><option :value="null">No adviser</option><option v-for="professor in options.professors.data" :key="professor.id" :value="professor.id">{{ professor.user?.profile?.first_name }} {{ professor.user?.profile?.last_name }} · {{ professor.employee_number }}</option></select></label></template><template v-else><label>Section / copy destination<select v-model="form.section_id" :disabled="!!form.id||!!form.assignment_id" required><option v-for="section in sections.data" :key="section.id" :value="section.id">{{ section.section_name }}</option></select><small v-if="fieldError('section_id')" class="en-field-error" role="alert">{{ fieldError('section_id') }}</small></label><label>Subject<select v-model="form.subject_id" :disabled="!!form.assignment_id" required><option value="">Choose subject</option><option v-for="subject in scheduleData?.subjects" :key="subject.id" :value="subject.id">{{ subject.subject_code }} — {{ subject.subject_name }}</option></select><small v-if="fieldError('subject_id')" class="en-field-error" role="alert">{{ fieldError('subject_id') }}</small></label><label>Professor<select v-model="form.professor_id" required><option value="">Choose Professor</option><option v-for="professor in prioritizedProfessors" :key="professor.id" :value="professor.id">{{ professor.user?.profile?.first_name }} {{ professor.user?.profile?.last_name }} · {{ professor.employee_number }}{{ scheduleData?.subjects?.find(subject=>Number(subject.id)===Number(form.subject_id))?.professors?.some(item=>Number(item.id)===Number(professor.id)) ? ' · Associated' : '' }}</option></select><small v-if="fieldError('professor_id')" class="en-field-error" role="alert">{{ fieldError('professor_id') }}</small></label><label>Room<input v-model.trim="form.room" required maxlength="50"><small v-if="fieldError('room')" class="en-field-error" role="alert">{{ fieldError('room') }}</small></label><fieldset class="en-day-picker"><legend>Meeting days</legend><button v-for="day in scheduleDays" :key="day" type="button" :aria-pressed="form.meetings.some(meeting=>meeting.day===day)" :class="{ selected: form.meetings.some(meeting=>meeting.day===day) }" @click="toggleScheduleDay(day)">{{ day.slice(0,3) }}</button></fieldset><div class="en-meeting-editor"><div v-for="(meeting, meetingIndex) in form.meetings" :key="meeting.day" class="en-meeting-row" :class="{ invalid: !meetingTimeState(meeting).valid || meetingServerError(meetingIndex) || meetingAvailability(meeting)?.available===false }"><strong>{{ meeting.day }}</strong><ScheduleTimeSelect v-model="meeting.start_time" label="Start time" :input-id="`meeting-${meeting.day.toLowerCase()}-start`" :disabled="busy"/><ScheduleTimeSelect v-model="meeting.end_time" label="End time" :input-id="`meeting-${meeting.day.toLowerCase()}-end`" :disabled="busy"/><div class="en-meeting-duration"><span>Duration</span><strong>{{ meetingTimeState(meeting).valid ? formatScheduleDuration(meetingTimeState(meeting).duration) : '—' }}</strong></div><button type="button" :disabled="busy||form.meetings.length===1" :aria-label="`Remove ${meeting.day}`" @click="toggleScheduleDay(meeting.day)">Remove</button><p v-if="!meetingTimeState(meeting).valid || meetingServerError(meetingIndex)" class="en-meeting-error" role="alert" tabindex="-1">{{ meetingTimeState(meeting).error || meetingServerError(meetingIndex) }}</p><div v-if="availabilityReady" class="en-meeting-availability" :data-status="scheduleAvailability.status==='checking'?'checking':scheduleAvailability.status==='error'?'error':meetingAvailability(meeting)?.available===false?'conflict':meetingAvailability(meeting)?.available===true?'available':'idle'" aria-live="polite"><span v-if="scheduleAvailability.status==='checking'">Checking availability…</span><span v-else-if="scheduleAvailability.status==='error'">Unable to check availability. {{ scheduleAvailability.message }}</span><span v-else-if="meetingAvailability(meeting)?.available===true">Available</span><template v-else-if="meetingAvailability(meeting)?.available===false"><strong>Conflict</strong><ul><li v-for="(conflict, conflictIndex) in meetingAvailability(meeting).conflicts" :key="conflictIndex">{{ conflictDescription(conflict) }}</li></ul></template></div></div></div><section class="en-weekly-hours" :class="{ invalid: !scheduleTimesValid }" aria-live="polite"><span>Total weekly class hours</span><strong>{{ formatScheduleDuration(totalWeeklyMinutes) }}</strong><small>{{ scheduleTimesValid ? `${form.meetings.length} meeting${form.meetings.length===1?'':'s'} configured` : 'Complete every meeting time before saving.' }}</small></section></template></div><button v-if="options.professors.current_page<options.professors.last_page" type="button" class="en-modern-outline" :disabled="busy" @click="moreProfessors">More Professors</button></div><footer><button type="button" class="en-modern-outline" :disabled="busy" @click="form=null">Cancel</button><button class="en-modern-primary" :disabled="busy||(mode==='scheduling'&&!scheduleCanSave)" type="submit">Save {{ mode==='sections' ? 'section' : 'schedule' }}</button></footer></form>
    </EnrollmentDialog>
    <EnrollmentDialog v-if="record" labelledby="en-record-detail" :busy="busy" wide @cancel="record=null"><header class="en-dialog-header en-no-print"><div><h2 id="en-record-detail">{{ record.name }}</h2><p>{{ record.student_number }} · {{ record.course }}</p></div><button type="button" :disabled="busy" aria-label="Close enrollment record" @click="record=null">Close</button></header><div class="en-dialog-body"><EnrollmentCor :record="record"/></div></EnrollmentDialog>
    <EnrollmentDialog v-if="sectionDetail" labelledby="en-section-detail" @cancel="sectionDetail=null"><div class="en-modern-confirm"><h2 id="en-section-detail">{{ sectionDetail.section_name }}</h2><dl class="en-modern-details"><div><dt>Program</dt><dd>{{ sectionDetail.course?.course_name || 'Program unavailable' }}</dd></div><div><dt>Academic context</dt><dd>Year {{ sectionDetail.year_level }} · {{ termLabel(sectionDetail) }}</dd></div><div><dt>Occupancy</dt><dd>{{ sectionDetail.enrolled_count }} enrolled · {{ sectionDetail.reserved_count }} reserved · {{ sectionDetail.capacity }} capacity</dd></div><div><dt>Status</dt><dd>{{ label(sectionDetail.status) }}</dd></div></dl><footer><button class="en-modern-outline" @click="sectionDetail=null">Close</button><button class="en-modern-primary" @click="editSection(sectionDetail);sectionDetail=null">Edit section</button></footer></div></EnrollmentDialog>
    <EnrollmentDialog v-if="remove" labelledby="en-delete-schedule" :busy="busy" @cancel="remove=null"><div class="en-modern-confirm"><h2 id="en-delete-schedule">Delete this schedule?</h2><p>{{ remove.subject?.subject_code }} · {{ remove.day }} · {{ remove.start_time }}–{{ remove.end_time }}</p><p>Schedules used by academic enrollment cannot be deleted.</p><footer><button class="en-modern-outline" @click="remove=null">Go back</button><button class="en-modern-danger" :disabled="busy" @click="deleteSchedule">Confirm deletion</button></footer></div></EnrollmentDialog>
    <EnrollmentDialog v-if="reassignment" labelledby="en-reassign-professor" :busy="busy" @cancel="reassignment=null"><div class="en-modern-confirm"><h2 id="en-reassign-professor">Reassign this teaching assignment?</h2><p>{{ scheduleData?.subjects?.find(subject=>subject.id===reassignment.subject_id)?.subject_code }} in {{ scheduleData?.section?.section_name }} will be assigned to {{ professorName(reassignment.professor_id) }}.</p><p>The previous Professor will immediately lose access to this class. Submitted, approved, or published grade sheets cannot be reassigned.</p><footer><button class="en-modern-outline" :disabled="busy" @click="reassignment=null">Go back</button><button class="en-modern-primary" :disabled="busy" @click="confirmReassignment">Confirm reassignment</button></footer></div></EnrollmentDialog>
  </section>
</template>
