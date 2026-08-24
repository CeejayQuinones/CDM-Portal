<script setup>
import { onMounted, ref } from 'vue'
import PaginationControls from '../../components/PaginationControls.vue'
import { documentRequestService as api } from './documentRequestService'

const appointments = ref([])
const date = ref('')
const status = ref('')
const loading = ref(false)
const message = ref('')
const error = ref('')
const page = ref(1)
const lastPage = ref(1)
const statuses = ['pending', 'confirmed']
const activeStatuses = ['pending', 'confirmed']
const requestError = (err) => err.response?.data?.message || 'The appointment could not be completed.'

async function refresh(resetPage = false) {
  if (resetPage) page.value = 1
  loading.value = true
  error.value = ''
  try {
    const result = await api.registrarAppointments({
      date: date.value || undefined,
      status: status.value || undefined,
      page: page.value,
    })
    appointments.value = result.data
    lastPage.value = result.last_page
  } catch (err) {
    error.value = requestError(err)
  } finally {
    loading.value = false
  }
}

async function changePage(nextPage) {
  page.value = nextPage
  await refresh()
}

async function updateStatus(appointment, nextStatus) {
  error.value = ''
  message.value = ''
  try {
    const updated = await api.updateAppointment(appointment.id, {
      status: nextStatus,
    })
    if (!activeStatuses.includes(updated.status) || (status.value && status.value !== updated.status)) {
      appointments.value = appointments.value.filter((item) => item.id !== updated.id)
    } else {
      appointments.value = appointments.value.map((item) => (item.id === updated.id ? updated : item))
    }
    message.value = 'Appointment updated.'
  } catch (err) {
    error.value = requestError(err)
  }
}

onMounted(refresh)
</script>

<template>
  <section class="page-header">
    <p class="page-kicker">Registrar Staff</p>
    <h1 class="page-title">Document Request Appointments</h1>
    <p class="page-description">Review and update appointments associated with document requests.</p>
  </section>

  <p v-if="message" class="notice success">{{ message }}</p>
  <p v-if="error" class="notice error">{{ error }}</p>

  <section class="dr-panel">
    <form class="toolbar" @submit.prevent="refresh(true)">
      <input v-model="date" type="date" />
      <select v-model="status">
        <option value="">All active statuses</option>
        <option v-for="value in statuses" :key="value" :value="value">
          {{ value.replaceAll('_', ' ') }}
        </option>
      </select>
      <button :disabled="loading">Filter</button>
    </form>
    <p v-if="loading && !appointments.length" class="empty">Loading appointments…</p>
    <p v-else-if="!appointments.length" class="empty">No matching appointments.</p>
    <div v-for="appointment in appointments" :key="appointment.id" class="appointment appointment-row">
      <span>
        <strong>{{ appointment.document_request.document_type.document_name }}</strong>
        · {{ appointment.student.user.profile.first_name }} {{ appointment.student.user.profile.last_name }} ({{
          appointment.student.student_number
        }}) · {{ appointment.appointment_date }} at
        {{ String(appointment.appointment_time).slice(0, 5) }}
      </span>
      <span class="badge" :class="appointment.status">{{ appointment.status.replaceAll('_', ' ') }}</span>
      <span class="actions">
        <button v-if="appointment.status === 'pending'" @click="updateStatus(appointment, 'confirmed')">Confirm</button>
        <button v-if="appointment.status === 'confirmed'" @click="updateStatus(appointment, 'completed')">
          Complete
        </button>
        <button
          v-if="['pending', 'confirmed'].includes(appointment.status)"
          class="secondary"
          @click="updateStatus(appointment, 'cancelled')"
        >
          Cancel
        </button>
        <button v-if="appointment.status === 'confirmed'" class="danger" @click="updateStatus(appointment, 'no_show')">
          No show
        </button>
      </span>
    </div>
    <PaginationControls
      :current-page="page"
      :last-page="lastPage"
      :busy="loading"
      aria-label="Registrar appointment pages"
      @page-change="changePage"
    />
  </section>
</template>

<style scoped src="./documentRequest.css"></style>
