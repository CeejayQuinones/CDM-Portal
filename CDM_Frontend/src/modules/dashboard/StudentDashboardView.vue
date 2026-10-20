<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { clientPlatform } from '../../config/clientPlatform'
import { apiAssetUrl } from '../../services/apiClient'
import { eventErrorMessage, eventService } from '../event-attendance/eventService'
import { getStudentDashboard } from './dashboardService'
import { formatDashboardStatus } from './dashboardPresentation'
import { carouselCardState, horizontalSwipeDirection, moveCarouselIndex } from './eventCarousel'
import cdmLogo from '../../assets/styles/images/cdm_logo.png'
import './guideDashboard.css'

const loading = ref(true)
const error = ref('')
const dashboard = ref({ identity: {}, summary: {}, has_finalized_enrollment: false })
const events = ref([])
const eventsLoading = ref(true)
const eventsError = ref('')
const activeEventIndex = ref(0)
const pointerStart = ref(null)
const activeEvent = computed(() => events.value[activeEventIndex.value] || null)

const cards = computed(() => [
  { label: 'Enrollment', value: formatDashboardStatus(dashboard.value.summary.enrollment_status) },
  { label: 'Academic term', value: dashboard.value.summary.academic_term || 'No current term' },
  { label: 'Enrolled subjects', value: dashboard.value.summary.enrolled_subjects ?? 0 },
  { label: 'Unread notices', value: dashboard.value.summary.unread_monitoring_notices ?? 0 },
  { label: 'Upcoming events', value: dashboard.value.summary.upcoming_events ?? 0 },
])

const guide = [
  { step: '1', title: 'Enrollment', text: 'Check your enrollment application and finalized enrollment.', to: { name: 'enrollment-status' } },
  { step: '2', title: 'My Subjects', text: 'Review the Subjects included in your finalized enrollment.', to: { name: 'enrollment-subjects' } },
  { step: '3', title: 'My Schedule', text: 'Check when and where your enrolled classes meet.', to: { name: 'enrollment-schedule' } },
  { step: '4', title: 'Grading', text: 'View grades after they are officially published.', to: { name: 'grading' } },
  { step: '5', title: 'Academic Monitoring', text: 'Review academic signals, Study Plan, notices, and Academic Coach.', to: { name: 'monitoring' } },
  { step: '6', title: 'Events', text: 'Discover published activities and campus announcements.', to: { name: 'event-attendance' } },
  { step: '7', title: 'Document Requests', text: 'Request official documents and track their progress.', to: { name: 'student-document-requests' } },
]

const eventGuidance = clientPlatform === 'mobile'
  ? 'Open an Event to view the participation options available in the Mobile app.'
  : 'Events shown here are public promotions. Use the Mobile app for Event participation and check-in.'

const formatEventDate = (value) => value
  ? new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(new Date(value))
  : 'Date to be announced'
const formatEventTime = (value) => value
  ? new Intl.DateTimeFormat(undefined, { timeStyle: 'short' }).format(new Date(value))
  : 'Time to be announced'
const eventImage = (event) => apiAssetUrl(event.image_url)

function moveEvent(direction) {
  activeEventIndex.value = moveCarouselIndex(activeEventIndex.value, events.value.length, direction)
}

function goToEvent(index) {
  if (index >= 0 && index < events.value.length) activeEventIndex.value = index
}

const eventState = (index) => carouselCardState(index, activeEventIndex.value, events.value.length)

function activateSideEvent(index) {
  if (['previous', 'next'].includes(eventState(index))) goToEvent(index)
}

function beginPointer(event) {
  if (!event.isPrimary || (event.pointerType === 'mouse' && event.button !== 0)) return
  pointerStart.value = { id: event.pointerId, x: event.clientX, y: event.clientY }
  event.currentTarget.setPointerCapture?.(event.pointerId)
}

function finishPointer(event) {
  if (!pointerStart.value || pointerStart.value.id !== event.pointerId) return
  const direction = horizontalSwipeDirection(pointerStart.value, { x: event.clientX, y: event.clientY })
  pointerStart.value = null
  if (direction !== 0) moveEvent(direction)
}

function cancelPointer() {
  pointerStart.value = null
}

async function loadEvents() {
  eventsLoading.value = true
  eventsError.value = ''
  if (clientPlatform !== 'web') {
    eventsLoading.value = false
    return
  }
  try {
    const page = await eventService.promotions({ per_page: 5 })
    events.value = (page.data || []).slice(0, 5)
    activeEventIndex.value = 0
  } catch (reason) {
    events.value = []
    eventsError.value = eventErrorMessage(reason)
  } finally {
    eventsLoading.value = false
  }
}

async function loadDashboard() {
  loading.value = true
  error.value = ''
  try {
    dashboard.value = await getStudentDashboard()
  } catch (reason) {
    error.value = reason.response?.data?.message || 'Your dashboard could not be loaded. Please try again.'
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  void Promise.all([loadDashboard(), loadEvents()])
})
</script>

<template>
  <main class="guide-dashboard">
    <header class="guide-hero">
      <div>
        <p class="guide-kicker">Student portal</p>
        <h1>{{ loading ? 'Welcome' : `Welcome, ${dashboard.identity.name || 'Student'}` }}</h1>
        <p>Use this page as your guide to the academic services available in CDM Portal.</p>
      </div>
      <dl v-if="!loading" class="identity-list" aria-label="Student identity">
        <div><dt>Student no.</dt><dd>{{ dashboard.identity.student_number || 'Not available' }}</dd></div>
        <div><dt>Program</dt><dd>{{ dashboard.identity.program_code || dashboard.identity.program || 'Not available' }}</dd></div>
        <div><dt>Year & section</dt><dd>{{ dashboard.identity.year_level ? `Year ${dashboard.identity.year_level}` : 'Year not set' }}<span v-if="dashboard.identity.section"> · {{ dashboard.identity.section }}</span></dd></div>
      </dl>
    </header>

    <div v-if="error" class="guide-error" role="alert">
      <span>{{ error }}</span><button type="button" @click="loadDashboard">Try again</button>
    </div>

    <section aria-labelledby="student-summary-title">
      <div class="guide-section-heading"><div><p class="guide-kicker">At a glance</p><h2 id="student-summary-title">Your current portal summary</h2></div></div>
      <div class="guide-summary-grid" :aria-busy="loading">
        <article v-for="card in cards" :key="card.label" class="guide-summary-card">
          <span>{{ card.label }}</span><strong>{{ loading ? '—' : card.value }}</strong>
        </article>
      </div>
      <p v-if="!loading && !dashboard.has_finalized_enrollment" class="guide-note">No finalized enrollment is available yet. Open Enrollment to review your application status.</p>
    </section>

    <section class="dashboard-events" aria-labelledby="student-events-title">
      <div class="guide-section-heading event-section-heading">
        <div><p class="guide-kicker">Campus life</p><h2 id="student-events-title">Upcoming at Colegio de Montalban</h2></div>
        <RouterLink :to="{ name: 'event-attendance' }" class="section-link">View all Events <span aria-hidden="true">→</span></RouterLink>
      </div>
      <p class="event-guidance">Events shown here are public promotions.</p>
      <div v-if="eventsLoading" class="event-carousel-loading" aria-live="polite" aria-label="Loading upcoming Events">
        <article class="event-showcase-loading event-card-loading" aria-hidden="true">
          <div class="dashboard-event-poster"></div><span></span><strong></strong><small></small>
        </article>
      </div>
      <div v-else-if="eventsError" class="event-empty-state" role="alert">
        <strong>Upcoming Events are temporarily unavailable.</strong>
        <span>{{ eventsError }}</span>
        <button type="button" @click="loadEvents">Try again</button>
      </div>
      <div v-else-if="clientPlatform !== 'web'" class="event-empty-state">
        <strong>Events are available in the Mobile Event workspace.</strong>
        <span>Open Events to view the participation options available to you.</span>
      </div>
      <div v-else-if="!events.length" class="event-empty-state">
        <strong>No upcoming Events are currently posted.</strong>
        <span>Published campus activities will appear here.</span>
      </div>
      <div v-else-if="activeEvent" class="event-carousel" role="region" aria-roledescription="carousel" aria-label="Upcoming Events">
        <div
          class="event-carousel-stage"
          aria-live="polite"
          @pointerdown="beginPointer"
          @pointerup="finishPointer"
          @pointercancel="cancelPointer"
        >
          <article
            v-for="(event, index) in events"
            :key="event.id"
            class="event-carousel-card"
            :class="`is-${eventState(index)}`"
            :role="['previous', 'next'].includes(eventState(index)) ? 'button' : undefined"
            :tabindex="['previous', 'next'].includes(eventState(index)) ? 0 : undefined"
            :aria-label="['previous', 'next'].includes(eventState(index)) ? `Show ${event.title}` : undefined"
            :aria-hidden="eventState(index).startsWith('hidden') ? 'true' : undefined"
            :aria-current="eventState(index) === 'active' ? 'true' : undefined"
            :inert="eventState(index).startsWith('hidden') ? true : undefined"
            @click="activateSideEvent(index)"
            @keydown.enter.prevent="activateSideEvent(index)"
            @keydown.space.prevent="activateSideEvent(index)"
          >
            <div class="dashboard-event-poster">
              <span class="event-poster-fallback" aria-hidden="true">
                <img :src="cdmLogo" alt="" />
                <b>CDM</b><small>Campus Event</small>
              </span>
              <img v-if="eventImage(event)" :src="eventImage(event)" :alt="`${event.title} poster`" draggable="false" @error="$event.currentTarget.remove()" />
              <span v-if="eventState(index) !== 'active'" class="event-side-title">{{ event.title }}</span>
            </div>
          </article>
          <button v-if="events.length > 1" type="button" class="event-carousel-arrow previous" aria-label="Previous event" @click="moveEvent(-1)">‹</button>
          <button v-if="events.length > 1" type="button" class="event-carousel-arrow next" aria-label="Next event" @click="moveEvent(1)">›</button>
        </div>
        <div :key="activeEvent.id" class="event-feature-copy">
          <span class="event-card-status">{{ activeEvent.status }}</span>
          <h3>{{ activeEvent.title }}</h3>
          <p class="event-feature-meta">
            {{ formatEventDate(activeEvent.starts_at) }} <span aria-hidden="true">·</span>
            {{ formatEventTime(activeEvent.starts_at) }} <span aria-hidden="true">·</span>
            {{ activeEvent.venue || 'Venue to be announced' }}
          </p>
          <p v-if="activeEvent.public_audience" class="event-feature-audience">{{ activeEvent.public_audience }}</p>
          <p class="event-feature-description">{{ activeEvent.description || 'More Event information will be announced soon.' }}</p>
          <RouterLink :to="{ name: 'event-attendance' }" class="event-card-action">View Event <span aria-hidden="true">→</span></RouterLink>
        </div>
        <div v-if="events.length > 1" class="event-carousel-dots" aria-label="Choose an Event">
          <button
            v-for="(event, index) in events"
            :key="event.id"
            type="button"
            :class="{ active: index === activeEventIndex }"
            :aria-label="`Go to event ${index + 1}`"
            :aria-current="index === activeEventIndex ? 'true' : undefined"
            @click="goToEvent(index)"
          ></button>
        </div>
        <p class="event-participation-note">{{ eventGuidance }}</p>
      </div>
    </section>

    <section aria-labelledby="student-guide-title">
      <div class="guide-section-heading"><div><p class="guide-kicker">Start here</p><h2 id="student-guide-title">Your CDM Portal Guide</h2></div></div>
      <ol class="workflow-grid">
        <li v-for="item in guide" :key="item.step">
          <RouterLink :to="item.to" class="workflow-card">
            <span class="step-number" aria-hidden="true">{{ item.step }}</span>
            <span><strong>{{ item.title }}</strong><small>{{ item.text }}</small></span>
            <span class="guide-arrow" aria-hidden="true">→</span>
          </RouterLink>
        </li>
      </ol>
    </section>

  </main>
</template>
