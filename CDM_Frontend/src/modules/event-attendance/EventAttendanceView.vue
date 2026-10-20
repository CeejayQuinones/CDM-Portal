<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { App } from '@capacitor/app'
import { Capacitor } from '@capacitor/core'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '../../stores/authStore'
import { clientPlatform } from '../../config/clientPlatform'
import { eventErrorMessage, eventService } from './eventService'
import { eventDateKey, formatEventDateTime, formatEventTime, toEventApiDateTime, toEventDateTimeInput } from './eventDateTime'
import EventAttendancePanel from './EventAttendancePanel.vue'
import EventModeratorRequestsPanel from './EventModeratorRequestsPanel.vue'
import EventNotificationsPanel from './EventNotificationsPanel.vue'
import EventOperatorScanner from './EventOperatorScanner.vue'
import EventParticipantQr from './EventParticipantQr.vue'
import EventPersonnelPanel from './EventPersonnelPanel.vue'
import EventProfilePanel from './EventProfilePanel.vue'
import EventQrScanner from './EventQrScanner.vue'
import EventReportsView from './EventReportsView.vue'
import { useEventModalScrollLock } from './useEventModalScrollLock'
import './event.css'

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()
const isStaff = computed(() => clientPlatform === 'desktop')
const eventSelfScanEnabled = import.meta.env.VITE_EVENT_SELF_SCAN_ENABLED === 'true'
const events = ref([])
const loading = ref(true)
const saving = ref(false)
const error = ref('')
const notice = ref('')
const eventAccess = ref({ can_create_event: false })
const search = ref('')
const status = ref('')
const audience = ref('')
const eventDate = ref('')
const view = ref('list')
const modal = ref('')
const selected = ref(null)
const attendanceTab = ref('scan')
const peopleTab = ref('participants')
const certificateForm = ref({ enabled: false, minimum_minutes: '', required_event_ids: [] })
useEventModalScrollLock(() => Boolean(modal.value))

const legacyAreas = {
  dashboard: 'overview',
  scanner: 'attendance',
  management: 'manage',
  participants: 'people',
  profile: 'overview',
}
const normalizeArea = (value) => legacyAreas[value] || value || 'overview'
const area = ref(normalizeArea(String(route.query.area || (route.name === 'event-attendance-reports' ? 'reports' : 'overview'))))

const canManageWorkspace = computed(() => eventAccess.value.can_create_event || events.value.some((event) => event.capabilities?.can_manage_event))
const canAttendWorkspace = computed(() => events.value.some((event) => event.capabilities?.can_operate_attendance))
const canViewPeopleWorkspace = computed(() => events.value.some((event) => event.capabilities?.can_view_personnel))
const canViewReports = computed(() => isStaff.value && events.value.some((event) => event.capabilities?.can_view_reports))
const canScanParticipantQr = computed(() => Boolean(selected.value?.capabilities?.can_scan_static_participant_qr || selected.value?.capabilities?.can_scan_dynamic_participant_qr))
const attendanceTabs = computed(() => [
  ['scan', 'Scan QR'],
  ['manual', 'Manual'],
  ['records', 'Records'],
  ...(selected.value?.capabilities?.can_correct_attendance ? [['corrections', 'Corrections']] : []),
])
const workspaceItems = computed(() => [
  { key: 'overview', label: 'Overview', visible: true },
  { key: 'calendar', label: 'Calendar', visible: true },
  { key: 'manage', label: 'Manage', visible: canManageWorkspace.value },
  { key: 'attendance', label: 'Attendance', visible: canAttendWorkspace.value },
  { key: 'people', label: 'People', visible: canViewPeopleWorkspace.value },
  { key: 'notifications', label: 'Notifications', visible: true },
  { key: 'reports', label: 'Reports', visible: canViewReports.value },
].filter((item) => item.visible))

const calendarCursor = ref(new Date(new Date().getFullYear(), new Date().getMonth(), 1))
const dialogRef = ref(null)
let dialogTrigger = null
let backButtonListener = null
let appStateListener = null
const options = ref({ courses: [], sections: [], year_levels: [1, 2, 3, 4] })
const blank = () => ({ parent_event_id: '', inherit_audience: false, title: '', description: '', venue: '', starts_at: '', ends_at: '', sort_order: 0, attendance_mode: 'check_in_only', late_threshold_minutes: 15, minimum_participation_minutes: '', attendance_methods: ['dynamic_event', 'participant_static', 'participant_dynamic', 'manual'], audience_type: 'all_students', course_id: '', year_level: '', section_id: '', version: 1 })
const form = ref(blank())
const filteredEvents = computed(() => events.value.filter((event) => {
  const needle = search.value.trim().toLowerCase()
  const audienceMatch = !audience.value || event.audiences.some((item) => item.audience_type === audience.value)
  const dateMatch = !eventDate.value || eventDateKey(event.starts_at) === eventDate.value
  return (!needle || `${event.title} ${event.venue}`.toLowerCase().includes(needle))
    && (!status.value || event.status === status.value || event.managed_status === status.value)
    && audienceMatch
    && dateMatch
}))
const sectionEvents = computed(() => filteredEvents.value.filter((event) => {
  if (area.value === 'manage') return event.capabilities?.can_manage_event
  if (area.value === 'attendance') return event.capabilities?.can_operate_attendance
  if (area.value === 'people') return event.capabilities?.can_view_personnel
  return true
}))
const liveEvents = computed(() => events.value.filter((event) => new Date(event.starts_at).getTime() <= Date.now() && new Date(event.ends_at).getTime() > Date.now()))
const upcomingEvents = computed(() => events.value.filter((event) => new Date(event.starts_at).getTime() > Date.now()).sort((left, right) => new Date(left.starts_at) - new Date(right.starts_at)))
const assignedEvents = computed(() => events.value.filter((event) => event.capabilities?.event_responsibility))
const overviewEvent = computed(() => liveEvents.value[0] || upcomingEvents.value[0] || events.value[0] || null)
const responsibilityLabel = computed(() => assignedEvents.value[0]?.capabilities?.event_responsibility_label || 'Participant')
const groups = computed(() => {
  const now = Date.now()
  return {
    'Happening now': sectionEvents.value.filter((event) => new Date(event.starts_at).getTime() <= now && new Date(event.ends_at).getTime() > now),
    Today: sectionEvents.value.filter((event) => new Date(event.starts_at).getTime() > now && eventDateKey(event.starts_at) === eventDateKey(new Date())),
    Upcoming: sectionEvents.value.filter((event) => new Date(event.starts_at).getTime() > now && eventDateKey(event.starts_at) !== eventDateKey(new Date())),
    Past: sectionEvents.value.filter((event) => new Date(event.ends_at).getTime() <= now),
  }
})
const calendarEvents = computed(() => filteredEvents.value.filter((event) => {
  const month = `${calendarCursor.value.getFullYear()}-${String(calendarCursor.value.getMonth() + 1).padStart(2, '0')}`
  return eventDateKey(event.starts_at).startsWith(month)
}))
const calendarDays = computed(() => {
  const first = new Date(calendarCursor.value.getFullYear(), calendarCursor.value.getMonth(), 1)
  const start = new Date(first)
  start.setDate(1 - first.getDay())
  return Array.from({ length: 42 }, (_, index) => {
    const date = new Date(start)
    date.setDate(start.getDate() + index)
    const key = [date.getFullYear(), String(date.getMonth() + 1).padStart(2, '0'), String(date.getDate()).padStart(2, '0')].join('-')
    return { key, date, inMonth: date.getMonth() === calendarCursor.value.getMonth(), events: calendarEvents.value.filter((event) => eventDateKey(event.starts_at) === key) }
  })
})
const calendarLabel = computed(() => calendarCursor.value.toLocaleDateString(undefined, { month: 'long', year: 'numeric' }))
const showFilters = computed(() => ['calendar', 'manage', 'attendance', 'people'].includes(area.value))
const sectionDescription = computed(() => ({
  calendar: 'Browse the Events available to you by date.',
  manage: 'Select an Event to update details, sub-events, requests, and lifecycle controls.',
  attendance: 'Select an assigned Event to scan, verify, or review attendance.',
  people: 'Select a managed Event to view participants and assigned personnel.',
}[area.value] || ''))

const shiftMonth = (amount) => { calendarCursor.value = new Date(calendarCursor.value.getFullYear(), calendarCursor.value.getMonth() + amount, 1) }
const today = () => { calendarCursor.value = new Date(new Date().getFullYear(), new Date().getMonth(), 1) }
const selectArea = (value) => {
  area.value = value
  modal.value = ''
  selected.value = null
  view.value = value === 'calendar' ? 'calendar' : 'list'
  router.replace({ name: 'event-attendance', query: { ...route.query, area: value } })
}
const isToday = (key) => key === eventDateKey(new Date())
const fmt = (value, dateOnly = false) => formatEventDateTime(value, { dateOnly })

async function load() {
  loading.value = true
  error.value = ''
  try {
    const page = await eventService.list({ per_page: 100 })
    events.value = page.data || []
    eventAccess.value = page.event_access || {}
    if (isStaff.value) options.value = await eventService.options()
    if (!workspaceItems.value.some((item) => item.key === area.value)) selectArea('overview')
  } catch (requestError) {
    error.value = eventErrorMessage(requestError)
  } finally {
    loading.value = false
  }
}
function openCreate() { form.value = blank(); modal.value = 'form' }
function openSubEvent(parent) { form.value = { ...blank(), parent_event_id: parent.id, inherit_audience: true, venue: parent.venue, starts_at: toEventDateTimeInput(parent.starts_at), ends_at: toEventDateTimeInput(parent.ends_at) }; modal.value = 'form' }
function openEdit(event) {
  const selectedAudience = event.audiences[0] || {}
  form.value = { ...event, inherit_audience: Boolean(event.parent_event_id), starts_at: toEventDateTimeInput(event.starts_at), ends_at: toEventDateTimeInput(event.ends_at), attendance_mode: event.attendance_policy?.mode || 'check_in_only', late_threshold_minutes: event.attendance_policy?.late_threshold_minutes ?? 15, minimum_participation_minutes: event.attendance_policy?.minimum_participation_minutes ?? '', attendance_methods: [...(event.attendance_policy?.methods || ['dynamic_event', 'participant_static', 'participant_dynamic', 'manual'])], audience_type: selectedAudience.audience_type || 'all_students', course_id: selectedAudience.course_id || '', year_level: selectedAudience.year_level || '', section_id: selectedAudience.section_id || '' }
  modal.value = 'form'
}
function openParticipantScanner() {
  if (!canScanParticipantQr.value) return
  area.value = 'attendance'
  attendanceTab.value = 'scan'
  router.replace({ name: 'event-attendance', query: { ...route.query, area: 'attendance' } })
}
async function inspect(event) {
  error.value = ''
  attendanceTab.value = 'scan'
  peopleTab.value = 'participants'
  try {
    selected.value = await eventService.show(event.id)
    certificateForm.value = { enabled: Boolean(selected.value.certificate_settings?.enabled), minimum_minutes: selected.value.certificate_settings?.minimum_minutes ?? '', required_event_ids: [...(selected.value.certificate_settings?.required_event_ids || [])] }
    modal.value = 'detail'
  } catch (requestError) {
    error.value = eventErrorMessage(requestError)
  }
}
async function refreshSelected() { if (selected.value) selected.value = await eventService.show(selected.value.id) }
async function refreshWorkspace() {
  const selectedId = selected.value?.id
  await load()
  if (!selectedId) return
  try {
    selected.value = await eventService.show(selectedId)
  } catch (requestError) {
    selected.value = null
    modal.value = ''
    error.value = eventErrorMessage(requestError)
  }
}
async function saveCertificateSettings() {
  if (!selected.value || saving.value) return
  saving.value = true; error.value = ''
  try {
    await eventService.configureCertificates(selected.value.id, { version: selected.value.version, certificates_enabled: certificateForm.value.enabled, certificate_minimum_minutes: certificateForm.value.minimum_minutes === '' ? null : Number(certificateForm.value.minimum_minutes), required_event_ids: certificateForm.value.required_event_ids.map(Number) })
    notice.value = 'Certificate requirements updated.'; await refreshSelected()
  } catch (requestError) { error.value = eventErrorMessage(requestError) } finally { saving.value = false }
}
async function downloadCertificate() {
  if (!selected.value || saving.value) return
  saving.value = true; error.value = ''
  try {
    const response = await eventService.downloadCertificate(selected.value.id)
    const url = URL.createObjectURL(response.data); const anchor = document.createElement('a')
    anchor.href = url; anchor.download = `certificate-${selected.value.title}.pdf`; anchor.click(); URL.revokeObjectURL(url); await refreshSelected()
  } catch (requestError) { error.value = eventErrorMessage(requestError) } finally { saving.value = false }
}
function payload(intent = 'draft') {
  const selectedAudience = { audience_type: form.value.audience_type }
  if (form.value.audience_type === 'course') selectedAudience.course_id = Number(form.value.course_id)
  if (form.value.audience_type === 'year_level') selectedAudience.year_level = Number(form.value.year_level)
  if (form.value.audience_type === 'section') selectedAudience.section_id = Number(form.value.section_id)
  return { parent_event_id: form.value.parent_event_id || null, sort_order: Number(form.value.sort_order || 0), title: form.value.title, description: form.value.description || null, venue: form.value.venue, starts_at: toEventApiDateTime(form.value.starts_at), ends_at: toEventApiDateTime(form.value.ends_at), attendance_mode: form.value.attendance_mode, late_threshold_minutes: Number(form.value.late_threshold_minutes), minimum_participation_minutes: form.value.attendance_mode === 'check_in_and_out' && form.value.minimum_participation_minutes !== '' ? Number(form.value.minimum_participation_minutes) : null, attendance_methods: form.value.attendance_methods, audiences: form.value.parent_event_id ? [] : [selectedAudience], intent, version: form.value.version }
}
async function save(intent) {
  if (saving.value) return
  saving.value = true
  error.value = ''
  try {
    form.value.id ? await eventService.update(form.value.id, payload(intent)) : await eventService.create(payload(intent))
    modal.value = ''
    notice.value = intent === 'publish' ? 'Event published.' : 'Event saved.'
    await load()
  } catch (requestError) {
    error.value = eventErrorMessage(requestError)
  } finally {
    saving.value = false
  }
}
async function changeStatus(action) {
  if (saving.value || !selected.value) return
  saving.value = true
  error.value = ''
  try {
    await eventService.transition(selected.value.id, action, selected.value.version)
    modal.value = ''
    notice.value = `Event ${{ publish: 'published', cancel: 'cancelled', archive: 'archived' }[action] || action}.`
    await load()
  } catch (requestError) {
    error.value = eventErrorMessage(requestError)
  } finally {
    saving.value = false
  }
}
async function archiveSubEvent(child) {
  if (saving.value || !window.confirm(`Archive ${child.title}? Its attendance and audit history will be preserved.`)) return
  saving.value = true; error.value = ''
  try { await eventService.archiveSubEvent(child.id, child.version); notice.value = 'Sub-event archived.'; await refreshSelected(); await load() }
  catch (requestError) { error.value = eventErrorMessage(requestError) }
  finally { saving.value = false }
}

watch(modal, async (value, previous) => {
  if (value && !previous) {
    dialogTrigger = document.activeElement
    await nextTick()
    dialogRef.value?.focus()
  } else if (!value && previous) {
    dialogTrigger?.focus?.({ preventScroll: true })
  }
  if (Capacitor.isNativePlatform()) {
    await backButtonListener?.remove()
    backButtonListener = value
      ? await App.addListener('backButton', () => { modal.value = '' })
      : null
  }
})
watch(() => route.query.area, (value) => {
  const normalized = normalizeArea(String(value || 'overview'))
  if (workspaceItems.value.some((item) => item.key === normalized)) area.value = normalized
})
onMounted(async () => {
  await load()
  if (Capacitor.isNativePlatform()) {
    appStateListener = await App.addListener('appStateChange', ({ isActive }) => {
      if (isActive) refreshWorkspace()
    })
  }
})
onBeforeUnmount(async () => {
  await backButtonListener?.remove()
  await appStateListener?.remove()
})
</script>

<template>
  <main class="event-page">
    <header class="event-hero">
      <div>
        <p class="event-eyebrow">{{ isStaff ? 'Event administration' : 'Event participation' }}</p>
        <h1>{{ isStaff ? 'Event Administration' : 'Events' }}</h1>
        <p>{{ isStaff ? 'Plan Events, coordinate people, and manage attendance from one workspace.' : 'Find campus Events and use the tools assigned to you.' }}</p>
      </div>
      <div class="event-hero-actions">
        <button class="event-button secondary" type="button" :disabled="loading" @click="refreshWorkspace">{{ loading ? 'Refreshing…' : 'Refresh Events' }}</button>
        <button v-if="eventAccess.can_create_event" class="event-button primary" type="button" @click="openCreate">Create Event</button>
      </div>
    </header>

    <nav class="event-workspace-nav" aria-label="Event workspace">
      <button v-for="item in workspaceItems" :key="item.key" type="button" :class="{ active: area === item.key }" :aria-current="area === item.key ? 'page' : undefined" @click="selectArea(item.key)">{{ item.label }}</button>
    </nav>

    <p v-if="notice" class="event-alert success" role="status">{{ notice }}</p>
    <p v-if="error" class="event-alert error" role="alert">{{ error }}</p>

    <EventReportsView v-if="area === 'reports'" embedded />

    <EventNotificationsPanel v-else-if="area === 'notifications'" />

    <template v-else-if="area === 'overview'">
      <div v-if="loading" class="event-state" aria-live="polite">Loading Events…</div>
      <section v-else class="event-overview" aria-labelledby="event-overview-title">
        <header class="event-section-heading">
          <div><p class="event-eyebrow">At a glance</p><h2 id="event-overview-title">Overview</h2></div>
          <span class="event-responsibility">{{ responsibilityLabel }}</span>
        </header>
        <div class="event-overview-stats">
          <article><strong>{{ upcomingEvents.length }}</strong><span>Upcoming</span></article>
          <article><strong>{{ liveEvents.length }}</strong><span>Happening now</span></article>
          <article><strong>{{ assignedEvents.length }}</strong><span>Assigned to you</span></article>
        </div>
        <article v-if="overviewEvent" class="event-spotlight">
          <div><p class="event-eyebrow">{{ liveEvents.includes(overviewEvent) ? 'Happening now' : 'Next Event' }}</p><h3>{{ overviewEvent.title }}</h3><p>{{ fmt(overviewEvent.starts_at) }} · {{ overviewEvent.venue }}</p></div>
          <button class="event-button secondary" type="button" @click="inspect(overviewEvent)">View Event</button>
        </article>
        <div v-else class="event-state compact"><strong>No Events available</strong><span>Published Events for your audience will appear here.</span></div>
        <nav class="event-quick-actions" aria-label="Event quick actions">
          <button type="button" @click="selectArea('calendar')">Open Calendar</button>
          <button v-if="canManageWorkspace" type="button" @click="selectArea('manage')">Manage Events</button>
          <button v-if="canAttendWorkspace" type="button" @click="selectArea('attendance')">Open Attendance</button>
          <button v-if="canViewPeopleWorkspace" type="button" @click="selectArea('people')">Manage People</button>
          <button v-if="canViewReports" type="button" @click="selectArea('reports')">View Reports</button>
        </nav>
        <details class="event-profile-disclosure">
          <summary>My Event status and QR</summary>
          <EventProfilePanel compact />
        </details>
      </section>
    </template>

    <template v-else>
      <section v-if="showFilters" class="event-section-heading event-context">
        <div><p class="event-eyebrow">Workspace</p><h2>{{ workspaceItems.find((item) => item.key === area)?.label }}</h2></div>
        <span>{{ sectionDescription }}</span>
      </section>
      <section v-if="showFilters" class="event-toolbar" aria-label="Event filters">
        <label><span>Search</span><input v-model="search" type="search" placeholder="Title or venue" /></label>
        <label><span>Status</span><select v-model="status"><option value="">All statuses</option><option v-for="item in ['draft','published','ongoing','completed','cancelled','archived']" :key="item" :value="item">{{ item }}</option></select></label>
        <label><span>Audience</span><select v-model="audience"><option value="">All audiences</option><option value="all_students">All Students</option><option value="all_professors">All Professors</option><option value="all_users">All academic users</option><option value="course">Course</option><option value="year_level">Year level</option><option value="section">Section</option></select></label>
        <label><span>Date</span><input v-model="eventDate" type="date" /></label>
        <div v-if="area === 'manage'" class="view-switch"><button type="button" :class="{ active: view === 'list' }" @click="view = 'list'">List</button><button type="button" :class="{ active: view === 'calendar' }" @click="view = 'calendar'">Calendar</button></div>
      </section>

      <div v-if="loading" class="event-state" aria-live="polite">Loading Events…</div>
      <div v-else-if="showFilters && !sectionEvents.length" class="event-state"><strong>No Events found</strong><span>Adjust the filters or choose another workspace section.</span></div>
      <section v-else-if="area === 'calendar' || (area === 'manage' && view === 'calendar')" class="event-calendar" aria-label="Event calendar">
        <header><div><button type="button" aria-label="Previous month" @click="shiftMonth(-1)">‹</button><button type="button" @click="today">Today</button></div><h2>{{ calendarLabel }}</h2><button type="button" aria-label="Next month" @click="shiftMonth(1)">›</button></header>
        <div class="calendar-weekdays" aria-hidden="true"><span v-for="day in ['Sun','Mon','Tue','Wed','Thu','Fri','Sat']" :key="day">{{ day }}</span></div>
        <div class="calendar-month-grid"><article v-for="day in calendarDays" :key="day.key" :class="{ 'is-today': isToday(day.key), 'outside-month': !day.inMonth }"><time :datetime="day.key">{{ day.date.getDate() }}</time><button v-for="event in day.events" :key="event.id" type="button" :title="`${event.title} — ${fmt(event.starts_at)}`" @click="inspect(event)"><strong>{{ event.title }}</strong><span>{{ formatEventTime(event.starts_at) }}</span></button></article></div>
      </section>
      <section v-else-if="isStaff" class="event-table-wrap">
        <table><thead><tr><th>Event</th><th>Schedule</th><th>Venue</th><th>Audience</th><th>Status</th><th><span class="sr-only">Actions</span></th></tr></thead><tbody><tr v-for="event in sectionEvents" :key="event.id"><td><strong>{{ event.title }}</strong></td><td>{{ fmt(event.starts_at) }}</td><td>{{ event.venue }}</td><td>{{ event.audiences.map((item) => item.label).join(', ') }}</td><td><span class="event-badge" :data-status="event.status">{{ event.status }}</span></td><td class="row-actions"><button type="button" @click="inspect(event)">{{ area === 'attendance' ? 'Attendance' : area === 'people' ? 'People' : 'View' }}</button><button v-if="area === 'manage' && event.capabilities?.can_edit_event && !['cancelled','archived'].includes(event.managed_status)" type="button" @click="openEdit(event)">Edit</button></td></tr></tbody></table>
      </section>
      <section v-else class="event-groups">
        <template v-for="(items, group) in groups" :key="group"><div v-if="items.length"><h2>{{ group }}</h2><div class="event-cards"><button v-for="event in items" :key="event.id" class="event-card" type="button" @click="inspect(event)"><span class="event-date">{{ fmt(event.starts_at, true) }}</span><strong>{{ event.title }}</strong><span>{{ fmt(event.starts_at) }}</span><span>{{ event.venue }}</span><span class="event-badge" :data-status="event.status">{{ event.status }}</span></button></div></div></template>
      </section>
    </template>

    <Teleport to="body">
    <div v-if="modal" class="event-modal-backdrop" @click.self="modal = ''">
      <section ref="dialogRef" class="event-modal" role="dialog" aria-modal="true" :aria-labelledby="modal === 'form' ? 'event-form-title' : 'event-detail-title'" tabindex="-1" @keydown.esc="modal = ''">
        <header><h2 :id="modal === 'form' ? 'event-form-title' : 'event-detail-title'">{{ modal === 'form' ? (form.id ? 'Edit Event' : 'Create Event') : selected?.title }}</h2><button type="button" aria-label="Close dialog" @click="modal = ''">×</button></header>

        <div class="event-modal-body">

        <form v-if="modal === 'form'" class="event-form" @submit.prevent="save('draft')">
          <p v-if="form.parent_event_id" class="wide event-alert success">Sub-event schedules must remain inside the parent Event.</p>
          <label class="wide"><span>Title</span><input v-model.trim="form.title" required maxlength="180" /></label>
          <label class="wide"><span>Description</span><textarea v-model.trim="form.description" rows="4" maxlength="10000"></textarea></label>
          <label><span>Starts</span><input v-model="form.starts_at" type="datetime-local" required /></label>
          <label><span>Ends</span><input v-model="form.ends_at" type="datetime-local" required /></label>
          <label class="wide"><span>Venue</span><input v-model.trim="form.venue" required maxlength="180" /></label>
          <label v-if="form.parent_event_id"><span>Display order</span><input v-model.number="form.sort_order" type="number" min="0" max="65535" /></label>
          <p v-if="form.parent_event_id" class="wide event-help"><strong>Audience:</strong> Sub-events inherit parent Event audience.</p>
          <label v-if="!form.inherit_audience" class="wide"><span>Audience</span><select v-model="form.audience_type"><option value="all_students">All Students</option><option value="all_professors">All Professors</option><option value="all_users">All Students and Professors</option><option value="course">Course</option><option value="year_level">Year Level</option><option value="section">Section</option></select></label>
          <label v-if="!form.inherit_audience && form.audience_type === 'course'" class="wide"><span>Course</span><select v-model="form.course_id" required><option disabled value="">Select course</option><option v-for="course in options.courses" :key="course.id" :value="course.id">{{ course.course_code }} — {{ course.course_name }}</option></select></label>
          <label v-if="!form.inherit_audience && form.audience_type === 'year_level'" class="wide"><span>Year level</span><select v-model="form.year_level" required><option disabled value="">Select year</option><option v-for="year in options.year_levels" :key="year" :value="year">Year {{ year }}</option></select></label>
          <label v-if="!form.inherit_audience && form.audience_type === 'section'" class="wide"><span>Section</span><select v-model="form.section_id" required><option disabled value="">Select section</option><option v-for="section in options.sections" :key="section.id" :value="section.id">{{ section.course?.course_code }} · {{ section.section_name }}</option></select></label>
          <fieldset class="wide attendance-policy"><legend>Attendance configuration</legend><label><span>Attendance mode</span><select v-model="form.attendance_mode"><option value="check_in_only">Check-in only</option><option value="check_in_and_out">Check-in and check-out</option></select></label><label><span>Late after</span><span class="event-inline-input"><input v-model.number="form.late_threshold_minutes" type="number" min="0" max="1440" required /> minutes</span></label><label v-if="form.attendance_mode === 'check_in_and_out'"><span>Minimum participation</span><span class="event-inline-input"><input v-model.number="form.minimum_participation_minutes" type="number" min="1" max="10080" /> minutes (optional)</span></label><div class="attendance-method-options"><span>Allowed methods</span><label v-for="method in [['dynamic_event','Dynamic Event QR'],['static_event','Static Event QR + staff confirmation'],['participant_static','Static participant QR'],['participant_dynamic','Dynamic participant QR'],['manual','Manual attendance']]" :key="method[0]"><input v-model="form.attendance_methods" type="checkbox" :value="method[0]" /> {{ method[1] }}</label></div></fieldset>
          <footer><button class="event-button secondary" type="button" @click="modal = ''">Cancel</button><button class="event-button secondary" type="submit" :disabled="saving">{{ form.id ? 'Save Changes' : 'Save Draft' }}</button><button v-if="!form.id" class="event-button primary" type="button" :disabled="saving" @click="save('publish')">Publish</button></footer>
        </form>

        <div v-else-if="selected" class="event-detail">
          <div class="event-detail-summary"><span class="event-badge" :data-status="selected.status">{{ selected.status }}</span><p>{{ selected.description || 'No description provided.' }}</p></div>
          <section v-if="selected.capabilities?.event_responsibility" class="event-role-summary"><div><span>Portal Role</span><strong>{{ selected.capabilities.portal_role }}</strong></div><div><span>Event Responsibility</span><strong>{{ selected.capabilities.event_responsibility_label }}</strong><small v-if="selected.capabilities.assignment_inherited">Inherited from the parent Event</small></div></section>
          <dl><div><dt>Schedule</dt><dd>{{ fmt(selected.starts_at) }} – {{ fmt(selected.ends_at) }}</dd></div><div><dt>Venue</dt><dd>{{ selected.venue }}</dd></div><div><dt>Audience</dt><dd>{{ selected.audiences.map((item) => item.label).join(', ') }}<small v-if="selected.audience_inherited"> · inherited from parent</small></dd></div><div><dt>Coordinator</dt><dd>{{ selected.creator?.name || 'Event Coordinator' }}</dd></div></dl>

          <template v-if="['overview', 'calendar'].includes(area)">
            <section v-if="auth.currentRole === 'Student'" class="own-attendance"><h3>My Attendance</h3><strong>{{ selected.my_attendance?.status?.replace('_', ' ') || 'Not recorded' }}</strong><p v-if="selected.my_attendance?.checked_in_at">Checked in {{ fmt(selected.my_attendance.checked_in_at) }}</p><p v-if="selected.my_attendance?.checked_out_at">Checked out {{ fmt(selected.my_attendance.checked_out_at) }} · {{ selected.my_attendance.participation_minutes }} minutes</p><p v-else-if="selected.my_attendance?.completion_status === 'in_progress'">Check-out is still required.</p></section>
            <section v-if="auth.currentRole === 'Student' && selected.my_certificate" class="event-certificate-card"><div><h3>Certificate</h3><strong>{{ selected.my_certificate.eligible ? 'Eligible' : (selected.my_certificate.certificate?.status === 'revoked' ? 'Revoked' : 'Not eligible') }}</strong><p v-if="!selected.my_certificate.eligible">{{ selected.my_certificate.reasons?.[0] }}</p><p v-else-if="selected.my_certificate.requirements?.length">Completed {{ selected.my_certificate.requirements.filter((item) => item.completed).length }} of {{ selected.my_certificate.requirements.length }} required activities.</p></div><button v-if="selected.my_certificate.eligible" class="event-button primary" type="button" :disabled="saving" @click="downloadCertificate">{{ saving ? 'Preparing…' : 'Download Certificate' }}</button></section>
            <section v-if="canScanParticipantQr" class="operator-access-card">
              <div><h3>Moderator attendance tools</h3><p>Scan Participant QR codes for this assigned Event.</p></div>
              <button class="event-button primary" type="button" @click="openParticipantScanner">Scan Participant QR</button>
            </section>
            <EventQrScanner v-if="eventSelfScanEnabled && selected.capabilities?.can_scan_event_qr && selected.managed_status === 'published'" :event="selected" />
            <EventParticipantQr v-if="selected.capabilities?.can_generate_participant_qr && selected.managed_status === 'published'" :event="selected" />
          </template>

          <template v-if="area === 'manage'">
            <section v-if="selected.sub_events?.length" class="subevent-list"><h3>Sub-events</h3><ul><li v-for="child in selected.sub_events" :key="child.id"><span><strong>{{ child.title }}</strong><br /><small>{{ fmt(child.starts_at) }} · {{ child.venue }} · Order {{ child.sort_order }}</small></span><div class="row-actions"><button type="button" @click="inspect(child)">View</button><button v-if="child.capabilities?.can_edit_event && !['cancelled','archived'].includes(child.managed_status)" type="button" @click="openEdit(child)">Edit</button><button v-if="selected.capabilities?.can_manage_sub_events && child.managed_status !== 'archived'" type="button" :disabled="saving" @click="archiveSubEvent(child)">Archive</button></div></li></ul></section>
            <section v-else-if="selected.capabilities?.can_manage_sub_events && !selected.parent_event_id" class="event-state compact"><strong>No sub-events configured.</strong></section>
            <form v-if="selected.capabilities?.can_create_event && !selected.parent_event_id" class="certificate-settings" @submit.prevent="saveCertificateSettings"><header><div><h3>Participation certificates</h3><p>Certificates use verified attendance and completed required activities.</p></div><label><input v-model="certificateForm.enabled" type="checkbox" /> Enabled</label></header><label><span>Minimum total participation <small>(optional)</small></span><span class="event-inline-input"><input v-model.number="certificateForm.minimum_minutes" type="number" min="1" max="100000" /> minutes</span></label><fieldset v-if="selected.sub_events?.length"><legend>Required sub-events</legend><label v-for="child in selected.sub_events" :key="child.id"><input v-model="certificateForm.required_event_ids" type="checkbox" :value="child.id" /> {{ child.title }}</label></fieldset><button class="event-button secondary" type="submit" :disabled="saving">Save certificate settings</button></form>
            <EventModeratorRequestsPanel v-if="selected.capabilities?.can_view_personnel" :event="selected" :can-view="true" :can-review="selected.capabilities.can_manage_personnel" @changed="refreshSelected" />
            <section v-if="selected.capabilities?.can_view_audit && selected.audit?.length" class="event-audit"><h3>Activity</h3><ol><li v-for="entry in selected.audit" :key="`${entry.action}-${entry.created_at}`"><strong>{{ entry.action.replace('event.', '') }}</strong><span>{{ entry.actor_role }} · {{ fmt(entry.created_at) }}</span></li></ol></section>
          </template>

          <template v-if="area === 'attendance'">
            <nav class="event-subtabs" aria-label="Attendance tools"><button v-for="item in attendanceTabs" :key="item[0]" type="button" :class="{ active: attendanceTab === item[0] }" @click="attendanceTab = item[0]">{{ item[1] }}</button></nav>
            <template v-if="selected.managed_status === 'published'">
              <EventAttendancePanel v-if="attendanceTab === 'scan' && selected.capabilities?.can_manage_attendance_session" :event="selected" :capabilities="selected.capabilities" :show-records="false" />
              <EventOperatorScanner v-if="attendanceTab === 'scan' && (selected.capabilities?.can_scan_static_participant_qr || selected.capabilities?.can_scan_dynamic_participant_qr)" :event="selected" :capabilities="selected.capabilities" />
              <EventQrScanner v-if="eventSelfScanEnabled && attendanceTab === 'scan' && selected.capabilities?.can_scan_event_qr" :event="selected" />
              <EventParticipantQr v-if="attendanceTab === 'scan' && selected.capabilities?.can_generate_participant_qr" :event="selected" />
              <EventAttendancePanel v-if="attendanceTab === 'manual' && selected.capabilities?.can_operate_attendance" :event="selected" :capabilities="selected.capabilities" :show-session="false" action-mode="all" />
              <EventAttendancePanel v-if="attendanceTab === 'records' && selected.capabilities?.can_operate_attendance" :event="selected" :capabilities="selected.capabilities" :show-session="false" action-mode="none" />
              <EventAttendancePanel v-if="attendanceTab === 'corrections' && selected.capabilities?.can_correct_attendance" :event="selected" :capabilities="selected.capabilities" :show-session="false" action-mode="corrections" />
            </template>
            <div v-else class="event-state compact">Attendance tools become available when this Event is published.</div>
          </template>

          <template v-if="area === 'people'">
            <nav class="event-subtabs" aria-label="Event people"><button v-for="item in [['participants','Participants'],['moderators','Moderators'],['staff','Staff'],['coordinators','Coordinators']]" :key="item[0]" type="button" :class="{ active: peopleTab === item[0] }" @click="peopleTab = item[0]">{{ item[1] }}</button></nav>
            <EventAttendancePanel v-if="peopleTab === 'participants' && selected.capabilities?.can_operate_attendance" :event="selected" :capabilities="selected.capabilities" :show-session="false" action-mode="none" />
            <template v-else-if="peopleTab === 'coordinators'"><article class="event-coordinator-card"><span>Event Coordinator</span><strong>{{ selected.creator?.name || 'Event Coordinator' }}</strong></article><EventPersonnelPanel :event="selected" :can-manage="selected.capabilities.can_manage_personnel" responsibility-filter="coordinators" /></template>
            <EventPersonnelPanel v-else :event="selected" :can-manage="selected.capabilities.can_manage_personnel" :responsibility-filter="peopleTab" />
          </template>

          <footer v-if="area === 'manage' && selected.capabilities?.can_manage_event"><button v-if="selected.capabilities.can_manage_sub_events && !selected.parent_event_id && !['cancelled','archived'].includes(selected.managed_status)" class="event-button secondary" type="button" @click="openSubEvent(selected)">Add Sub-event</button><button v-if="selected.capabilities.can_edit_event && !['cancelled','archived'].includes(selected.managed_status)" class="event-button secondary" type="button" @click="openEdit(selected)">Edit</button><button v-if="selected.capabilities.can_create_event && selected.managed_status === 'draft'" class="event-button primary" type="button" :disabled="saving" @click="changeStatus('publish')">Publish</button><button v-if="selected.capabilities.can_create_event && ['draft','published'].includes(selected.managed_status)" class="event-button danger" type="button" :disabled="saving" @click="changeStatus('cancel')">Cancel Event</button><button v-if="selected.parent_event_id && selected.managed_status !== 'archived'" class="event-button secondary" type="button" :disabled="saving" @click="archiveSubEvent(selected)">Archive Sub-event</button><button v-else-if="selected.capabilities.can_create_event && selected.managed_status !== 'archived'" class="event-button secondary" type="button" :disabled="saving" @click="changeStatus('archive')">Archive</button></footer>
        </div>
        </div>
      </section>
    </div>
    </Teleport>
  </main>
</template>
