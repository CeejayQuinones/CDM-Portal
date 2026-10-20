<script setup>
import { computed, onMounted, ref } from 'vue'
import { formatEventDateTime } from './eventDateTime'
import { eventErrorMessage, eventService } from './eventService'
import EventParticipantQr from './EventParticipantQr.vue'
import { useEventModalScrollLock } from './useEventModalScrollLock'

defineProps({ compact: { type: Boolean, default: false } })

const profile = ref(null)
const loading = ref(true)
const busy = ref(false)
const error = ref('')
const notice = ref('')
const selectedEventId = ref('')
const requestEventId = ref('')
const requestReason = ref('')
const requestOpen = ref(false)

useEventModalScrollLock(() => requestOpen.value)

const selectedEvent = computed(() => profile.value?.available_events?.find((item) => item.id === Number(selectedEventId.value)))
const eligibleRequestEvents = computed(() => profile.value?.available_events?.filter((item) => item.can_request_moderator) || [])
const pendingRequests = computed(() => profile.value?.moderator_requests?.filter((item) => item.status === 'pending') || [])
const fmt = (value) => formatEventDateTime(value)

async function load() {
  error.value = ''
  try {
    profile.value = await eventService.profile()
    if (!profile.value.available_events?.some((item) => item.id === Number(selectedEventId.value))) {
      selectedEventId.value = profile.value.available_events?.[0]?.id || ''
    }
  } catch (cause) {
    error.value = eventErrorMessage(cause)
  } finally {
    loading.value = false
  }
}

function openRequest() {
  const first = eligibleRequestEvents.value[0]
  if (!first) return
  requestEventId.value = first.id
  requestReason.value = ''
  requestOpen.value = true
}

async function requestModerator() {
  if (!requestEventId.value || busy.value) return
  busy.value = true
  error.value = ''
  notice.value = ''
  try {
    await eventService.requestModerator(requestEventId.value, { reason: requestReason.value.trim() || null })
    requestOpen.value = false
    notice.value = 'Moderator request submitted for Coordinator review.'
    await load()
  } catch (cause) {
    error.value = eventErrorMessage(cause)
  } finally {
    busy.value = false
  }
}

onMounted(load)
</script>

<template>
  <section class="event-profile" aria-labelledby="event-profile-title">
    <div v-if="loading" class="event-state">Loading Event status…</div>
    <p v-else-if="error && !profile" class="event-alert error" role="alert">{{ error }}</p>
    <template v-else-if="profile">
      <header>
        <div><p class="event-eyebrow">Event-specific access</p><h2 id="event-profile-title">My Event Status</h2></div>
        <span class="event-badge" data-status="published">{{ profile.identity.account_status }}</span>
      </header>
      <p v-if="notice" class="event-alert success" role="status">{{ notice }}</p>
      <p v-if="error" class="event-alert error" role="alert">{{ error }}</p>

      <div class="profile-summary">
        <article><strong>{{ profile.identity.portal_role }}</strong><span>Portal role</span></article>
        <article><strong>{{ profile.identity.event_responsibility_label }}</strong><span>Event responsibility</span></article>
        <article><strong>{{ profile.attended_events.total }}</strong><span>Events attended</span></article>
        <article><strong>{{ pendingRequests.length }}</strong><span>Pending requests</span></article>
      </div>

      <div v-if="!compact" class="profile-identity">
        <div><strong>{{ profile.identity.name }}</strong><span>{{ profile.identity.email || 'No email on file' }}</span></div>
        <dl>
          <div><dt>Portal role</dt><dd>{{ profile.identity.portal_role }}</dd></div>
          <div><dt>Event responsibility</dt><dd>{{ profile.identity.event_responsibility_label }}</dd></div>
          <div v-if="profile.identity.student_number"><dt>Student number</dt><dd>{{ profile.identity.student_number }}</dd></div>
          <div v-if="profile.identity.course"><dt>Course</dt><dd>{{ profile.identity.course }}</dd></div>
        </dl>
      </div>

      <section v-if="profile.available_events.length" class="profile-event-tools">
        <div class="profile-tools-heading">
          <h3>My Event QR</h3>
          <button v-if="eligibleRequestEvents.length" class="event-button secondary" type="button" @click="openRequest">Request Moderator Access</button>
        </div>
        <label><span>Event</span><select v-model="selectedEventId"><option v-for="item in profile.available_events" :key="item.id" :value="item.id">{{ item.title }} · {{ fmt(item.starts_at) }}</option></select></label>
        <article v-if="selectedEvent?.moderator_request_status" class="request-access-card">
          <span class="event-badge" :data-status="selectedEvent.moderator_request_status">Moderator request {{ selectedEvent.moderator_request_status }}</span>
          <p v-if="selectedEvent.moderator_request?.requested_at">Submitted {{ fmt(selectedEvent.moderator_request.requested_at) }}</p>
        </article>
        <EventParticipantQr v-if="profile.identity.can_use_personal_qr && selectedEvent" :event="selectedEvent" />
      </section>

      <section v-if="profile.responsibilities.length">
        <h3>Assigned Events</h3>
        <div class="profile-badges"><span v-for="item in profile.responsibilities" :key="`${item.event_id}-${item.responsibility}`"><strong>{{ item.responsibility_label }}</strong> · {{ item.event_title }}</span></div>
      </section>
      <section v-else-if="!compact"><h3>Assigned Events</h3><p class="profile-empty">No active Event assignment.</p></section>

      <section v-if="!compact"><h3>Moderator Request History</h3><div v-if="profile.moderator_requests.length" class="profile-badges"><span v-for="item in profile.moderator_requests" :key="item.id"><strong>{{ item.status }}</strong> · {{ fmt(item.requested_at) }}</span></div><p v-else class="profile-empty">No moderator requests.</p></section>
      <section v-if="!compact"><h3>Attended Events</h3><div v-if="profile.attended_events.data.length" class="event-table-wrap"><table><thead><tr><th>Event</th><th>Date</th><th>Venue</th><th>Status</th><th>Method</th></tr></thead><tbody><tr v-for="item in profile.attended_events.data" :key="`${item.event_id}-${item.checked_in_at}`"><td><strong>{{ item.title }}</strong></td><td>{{ item.date }}</td><td>{{ item.venue }}</td><td>{{ item.status }}</td><td>{{ item.method }}</td></tr></tbody></table></div><p v-else class="profile-empty">No attended Event history yet.</p></section>
    </template>

    <Teleport to="body">
      <div v-if="requestOpen" class="event-modal-backdrop" @click.self="requestOpen = false">
        <section class="event-modal request-moderator-modal" role="dialog" aria-modal="true" aria-labelledby="request-moderator-title" tabindex="-1" @keydown.esc="requestOpen = false">
          <header><h2 id="request-moderator-title">Request Moderator Access</h2><button type="button" aria-label="Close request dialog" @click="requestOpen = false">×</button></header>
          <form class="event-modal-body event-form" @submit.prevent="requestModerator">
            <p class="wide profile-empty">Moderator access is scoped to one Event and grants no attendance privileges until a Coordinator approves it.</p>
            <label class="wide"><span>Event</span><select v-model="requestEventId" required><option v-for="item in eligibleRequestEvents" :key="item.id" :value="item.id">{{ item.title }} · {{ fmt(item.starts_at) }}</option></select></label>
            <label class="wide"><span>Reason <small>(optional)</small></span><textarea v-model.trim="requestReason" rows="4" maxlength="1000" placeholder="Briefly explain why you need moderator access."></textarea></label>
            <footer><button class="event-button secondary" type="button" @click="requestOpen = false">Cancel</button><button class="event-button primary" type="submit" :disabled="busy">{{ busy ? 'Submitting…' : 'Submit Request' }}</button></footer>
          </form>
        </section>
      </div>
    </Teleport>
  </section>
</template>
