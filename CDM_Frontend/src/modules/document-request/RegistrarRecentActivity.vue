<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { formatExactDateTime, formatRelativeTime, requestReference, studentName } from './documentRequestPresentation'
import { documentRequestService as api } from './documentRequestService'

const activities = ref([])
const loading = ref(false)
const error = ref('')
const currentTime = ref(new Date())
let clock = null

const activityError = (err) => err.response?.data?.message || 'Recent document request activity could not be loaded.'
const activityReference = (activity) =>
  activity.request_reference || requestReference(activity.request_id || activity.document_request_id)
const actionLabel = (value) =>
  value ? value.replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase()) : 'Updated'
const activityHeadline = (activity) => {
  const recordType = activity.type === 'appointment' ? 'Appointment' : 'Request'
  const name = studentName(activity.student) || 'Student'

  return `${name} — ${recordType} ${actionLabel(activity.action).toLowerCase()}`
}

function activityRoute(activity) {
  const reference = activityReference(activity)
  const requestId = activity.request_id || undefined
  const appointmentId = activity.appointment_id || undefined

  if (activity.destination === 'appointments') {
    return {
      name: 'registrar-document-appointments',
      query: {
        search: reference,
        request_id: requestId,
        appointment_id: appointmentId,
        focus: appointmentId ? `appointment-${appointmentId}` : undefined,
      },
    }
  }

  if (activity.destination === 'history') {
    const appointmentActivity = activity.type === 'appointment'

    return {
      name: 'registrar-document-request-history',
      query: {
        section: appointmentActivity ? 'appointments' : 'requests',
        search: reference,
        request_id: requestId,
        appointment_id: appointmentActivity ? appointmentId : undefined,
        focus: appointmentActivity && appointmentId ? `appointment-${appointmentId}` : `request-${requestId}`,
      },
    }
  }

  return {
    name: 'registrar-document-requests',
    query: {
      search: reference,
      request_id: requestId,
      focus: requestId ? `request-${requestId}` : undefined,
    },
  }
}

async function loadActivity() {
  loading.value = true
  error.value = ''

  try {
    activities.value = await api.registrarRecentActivity({ limit: 8, time_filter: 'all' })
  } catch (err) {
    error.value = activityError(err)
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  loadActivity()
  clock = window.setInterval(() => {
    currentTime.value = new Date()
  }, 60_000)
})

onBeforeUnmount(() => window.clearInterval(clock))
</script>

<template>
  <section class="dr-panel recent-activity" aria-labelledby="recent-document-activity-heading">
    <div class="recent-activity-heading">
      <div>
        <p class="record-eyebrow">Document workflow</p>
        <h2 id="recent-document-activity-heading">Recent activity</h2>
      </div>
      <button type="button" class="secondary compact-button" :disabled="loading" @click="loadActivity">Refresh</button>
    </div>

    <p v-if="error" class="notice error">{{ error }}</p>
    <p v-if="loading && !activities.length" class="empty">Loading recent activity&hellip;</p>
    <p v-else-if="!activities.length && !error" class="empty">No recent document request activity.</p>

    <div v-if="activities.length" class="recent-activity-list">
      <RouterLink
        v-for="activity in activities"
        :key="`${activity.type}-${activity.id}`"
        class="recent-activity-item"
        :to="activityRoute(activity)"
      >
        <span class="activity-marker" aria-hidden="true"></span>
        <span class="activity-summary">
          <strong>{{ activityHeadline(activity) }}</strong>
          <small>
            {{ activityReference(activity) }}
            <template v-if="activity.document_type?.document_name">
              &middot; {{ activity.document_type.document_name }}
            </template>
          </small>
        </span>
        <time :datetime="activity.occurred_at" :title="formatExactDateTime(activity.occurred_at)">
          {{ formatRelativeTime(activity.occurred_at, currentTime) }}
          <small>{{ formatExactDateTime(activity.occurred_at) }}</small>
        </time>
      </RouterLink>
    </div>
  </section>
</template>

<style scoped src="./documentRequest.css"></style>
