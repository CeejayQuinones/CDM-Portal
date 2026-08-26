<script setup>
import { onMounted, ref } from 'vue'
import { documentRequestService as api } from './documentRequestService'

const appointmentNeededRequests = ref([])
const appointments = ref([])
const bookingRequest = ref(null)
const appointmentDate = ref('')
const appointmentTime = ref('')
const slots = ref([])
const loading = ref(false)
const message = ref('')
const error = ref('')
const today = new Date().toISOString().slice(0, 10)
const requestError = (err) => err.response?.data?.message || 'The appointment could not be completed.'

async function refresh() {
  loading.value = true
  error.value = ''
  try {
    const overview = await api.appointmentOverview()
    appointmentNeededRequests.value = overview.requests_needing_appointment
    appointments.value = overview.appointments
  } catch (err) {
    error.value = requestError(err)
  } finally {
    loading.value = false
  }
}

async function loadSlots() {
  appointmentTime.value = ''
  slots.value = []
  if (!appointmentDate.value) return
  try {
    slots.value = await api.slots(appointmentDate.value)
  } catch (err) {
    error.value = requestError(err)
  }
}

async function book() {
  if (!bookingRequest.value) return
  error.value = ''
  message.value = ''
  loading.value = true
  try {
    await api.book(bookingRequest.value.id, {
      appointment_date: appointmentDate.value,
      appointment_time: appointmentTime.value,
    })
    bookingRequest.value = null
    appointmentDate.value = ''
    appointmentTime.value = ''
    slots.value = []
    message.value = 'Appointment booked. Registrar staff will confirm it.'
    await refresh()
  } catch (err) {
    error.value = requestError(err)
    await loadSlots()
  } finally {
    loading.value = false
  }
}

onMounted(refresh)
</script>

<template>
  <section class="page-header">
    <p class="page-kicker">Student Services</p>
    <h1 class="page-title">Appointments</h1>
    <p class="page-description">Book and track appointments for document requests that require one.</p>
  </section>

  <p v-if="message" class="notice success">{{ message }}</p>
  <p v-if="error" class="notice error">{{ error }}</p>

  <section class="dr-panel">
    <h2>Requests needing an appointment</h2>
    <p v-if="loading && !appointmentNeededRequests.length" class="empty">Loading appointments…</p>
    <p v-else-if="!appointmentNeededRequests.length" class="empty">No requests currently need an appointment.</p>
    <div v-for="item in appointmentNeededRequests" :key="item.id" class="request-line">
      <span>
        <strong>{{ item.document_type.document_name }}</strong>
        <small>Request #{{ item.id }} · {{ item.status.replaceAll('_', ' ') }}</small>
      </span>
      <button type="button" @click="bookingRequest = item">Book appointment</button>
    </div>
  </section>

  <section v-if="bookingRequest" class="dr-panel">
    <h2>Book: {{ bookingRequest.document_type.document_name }}</h2>
    <form class="form-grid" @submit.prevent="book">
      <label>
        Date
        <input v-model="appointmentDate" type="date" :min="today" required @change="loadSlots" />
      </label>
      <label>
        Available time
        <select v-model="appointmentTime" required :disabled="!appointmentDate">
          <option value="" disabled>Select a time</option>
          <option v-for="slot in slots.filter((slot) => slot.available)" :key="slot.time" :value="slot.time">
            {{ slot.time }}
          </option>
        </select>
      </label>
      <button :disabled="loading">Confirm appointment</button>
      <button type="button" class="secondary" @click="bookingRequest = null">Cancel</button>
    </form>
  </section>

  <section class="dr-panel">
    <h2>Your appointments</h2>
    <p v-if="!appointments.length" class="empty">No appointments booked yet.</p>
    <div v-for="appointment in appointments" :key="appointment.id" class="appointment">
      <span>
        <strong>{{ appointment.document_request.document_type.document_name }}</strong>
        · Request #{{ appointment.document_request_id }} · {{ appointment.appointment_date }} at
        {{ String(appointment.appointment_time).slice(0, 5) }}
      </span>
      <span class="badge" :class="appointment.status">{{ appointment.status.replaceAll('_', ' ') }}</span>
    </div>
  </section>
</template>

<style scoped src="./documentRequest.css"></style>
