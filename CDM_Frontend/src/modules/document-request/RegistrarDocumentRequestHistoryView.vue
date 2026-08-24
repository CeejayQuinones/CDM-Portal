<script setup>
import { onMounted, ref } from 'vue'
import { documentRequestService as api } from './documentRequestService'

const requests = ref([])
const appointments = ref([])
const search = ref('')
const requestStatus = ref('')
const appointmentStatus = ref('')
const loading = ref(false)
const error = ref('')
const requestPage = ref(1)
const appointmentPage = ref(1)
const lastRequestPage = ref(1)
const lastAppointmentPage = ref(1)
const finalRequestStatuses = ['released', 'rejected', 'cancelled']
const historicalAppointmentStatuses = ['cancelled', 'completed', 'no_show']
const requestError = (err) => err.response?.data?.message || 'Document request history could not be loaded.'
const completedDate = (item) => {
  const value = item.released_at || item.release_date || item.rejected_at
  return value ? String(value).slice(0, 10) : '—'
}

async function refresh(resetPages = false) {
  if (resetPages) {
    requestPage.value = 1
    appointmentPage.value = 1
  }
  loading.value = true
  error.value = ''
  try {
    const history = await api.registrarHistory({
      request_status: requestStatus.value || undefined,
      appointment_status: appointmentStatus.value || undefined,
      search: search.value || undefined,
      request_page: requestPage.value,
      appointment_page: appointmentPage.value,
    })
    requests.value = history.requests.data
    appointments.value = history.appointments.data
    lastRequestPage.value = history.requests.last_page
    lastAppointmentPage.value = history.appointments.last_page
  } catch (err) {
    error.value = requestError(err)
  } finally {
    loading.value = false
  }
}

async function changeRequestPage(nextPage) {
  requestPage.value = nextPage
  await refresh()
}

async function changeAppointmentPage(nextPage) {
  appointmentPage.value = nextPage
  await refresh()
}

onMounted(refresh)
</script>

<template>
  <section class="page-header">
    <p class="page-kicker">Registrar Staff</p>
    <h1 class="page-title">Document Request History</h1>
    <p class="page-description">Finalized requests and cancelled, completed, or no-show document appointments.</p>
  </section>

  <p v-if="error" class="notice error">{{ error }}</p>

  <section class="dr-panel">
    <form class="toolbar" @submit.prevent="refresh(true)">
      <input v-model="search" placeholder="Student number, name, or document" />
      <select v-model="requestStatus">
        <option value="">All final request statuses</option>
        <option v-for="value in finalRequestStatuses" :key="value" :value="value">{{ value }}</option>
      </select>
      <select v-model="appointmentStatus">
        <option value="">All historical appointment statuses</option>
        <option v-for="value in historicalAppointmentStatuses" :key="value" :value="value">{{ value.replaceAll('_', ' ') }}</option>
      </select>
      <button :disabled="loading">Search</button>
    </form>
  </section>

  <section class="dr-panel">
    <h2>Appointment history</h2>
    <p v-if="loading && !appointments.length" class="empty">Loading appointment history…</p>
    <p v-else-if="!appointments.length" class="empty">No matching historical appointments.</p>

    <div v-if="appointments.length" class="history-table" role="table">
      <div class="history-row appointment-history-row history-head" role="row">
        <strong>Student</strong>
        <strong>Document</strong>
        <strong>Appointment</strong>
        <strong>Appointment status</strong>
        <strong>Request status</strong>
        <strong>Registrar remarks</strong>
      </div>
      <div v-for="appointment in appointments" :key="appointment.id" class="history-row appointment-history-row" role="row">
        <span>
          <strong>{{ appointment.student.user.profile.first_name }} {{ appointment.student.user.profile.last_name }}</strong>
          <small>{{ appointment.student.student_number }}</small>
        </span>
        <span>{{ appointment.document_request.document_type.document_name }}</span>
        <span>{{ appointment.appointment_date }} at {{ String(appointment.appointment_time).slice(0, 5) }}</span>
        <span><span class="badge" :class="appointment.status">{{ appointment.status.replaceAll('_', ' ') }}</span></span>
        <span><span class="badge" :class="appointment.document_request.status">{{ appointment.document_request.status.replaceAll('_', ' ') }}</span></span>
        <span>{{ appointment.remarks || appointment.document_request.remarks || '—' }}</span>
      </div>
    </div>
    <div v-if="lastAppointmentPage > 1" class="pagination">
      <button class="secondary" :disabled="appointmentPage <= 1" @click="changeAppointmentPage(appointmentPage - 1)">Previous</button>
      <span>Page {{ appointmentPage }} of {{ lastAppointmentPage }}</span>
      <button class="secondary" :disabled="appointmentPage >= lastAppointmentPage" @click="changeAppointmentPage(appointmentPage + 1)">Next</button>
    </div>
  </section>

  <section class="dr-panel">
    <h2>Request history</h2>
    <p v-if="loading && !requests.length" class="empty">Loading request history…</p>
    <p v-else-if="!requests.length" class="empty">No matching finalized requests.</p>

    <div v-if="requests.length" class="history-table" role="table">
      <div class="history-row history-head" role="row">
        <strong>Student</strong>
        <strong>Document</strong>
        <strong>Request date</strong>
        <strong>Status</strong>
        <strong>Appointment</strong>
        <strong>Completed</strong>
        <strong>Registrar remarks</strong>
      </div>
      <div v-for="item in requests" :key="item.id" class="history-row" role="row">
        <span>
          <strong>{{ item.student.user.profile.first_name }} {{ item.student.user.profile.last_name }}</strong>
          <small>{{ item.student.student_number }}</small>
        </span>
        <span>{{ item.document_type.document_name }}</span>
        <span>{{ item.request_date }}</span>
        <span><span class="badge" :class="item.status">{{ item.status }}</span></span>
        <span v-if="item.latest_appointment">
          {{ item.latest_appointment.appointment_date }} at {{ String(item.latest_appointment.appointment_time).slice(0, 5) }}
          · {{ item.latest_appointment.status.replaceAll('_', ' ') }}
        </span>
        <span v-else>—</span>
        <span>{{ completedDate(item) }}</span>
        <span>{{ item.remarks || '—' }}</span>
      </div>
    </div>
    <div v-if="lastRequestPage > 1" class="pagination">
      <button class="secondary" :disabled="requestPage <= 1" @click="changeRequestPage(requestPage - 1)">Previous</button>
      <span>Page {{ requestPage }} of {{ lastRequestPage }}</span>
      <button class="secondary" :disabled="requestPage >= lastRequestPage" @click="changeRequestPage(requestPage + 1)">Next</button>
    </div>
  </section>
</template>

<style scoped src="./documentRequest.css"></style>
