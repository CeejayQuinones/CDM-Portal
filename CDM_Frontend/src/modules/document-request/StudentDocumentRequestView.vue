<script setup>
import { computed, onMounted, ref } from 'vue'
import { documentRequestService as api } from './documentRequestService'

const documentTypes = ref([])
const requests = ref([])
const selectedTypeId = ref('')
const quantity = ref(1)
const purpose = ref('')
const loading = ref(false)
const message = ref('')
const error = ref('')

const selectedType = computed(() => documentTypes.value.find((item) => item.id === Number(selectedTypeId.value)))
const requestError = (err) => err.response?.data?.message || 'The request could not be completed.'
const formatMoney = (value) => Number(value).toFixed(2)
const documentTypeLabel = (type) => Number(type.processing_fee) > 0
  ? `${type.document_name} — ₱${formatMoney(type.processing_fee)}`
  : type.document_name

async function loadRequests() {
  requests.value = await api.myRequests()
}

async function refresh() {
  loading.value = true
  error.value = ''
  try {
    ;[documentTypes.value, requests.value] = await Promise.all([api.documentTypes(), api.myRequests()])
  } catch (err) {
    error.value = requestError(err)
  } finally {
    loading.value = false
  }
}

async function submitRequest() {
  error.value = ''
  message.value = ''
  loading.value = true
  try {
    await api.createRequest({
      document_type_id: Number(selectedTypeId.value),
      quantity: Number(quantity.value),
      purpose: purpose.value || null,
    })
    selectedTypeId.value = ''
    quantity.value = 1
    purpose.value = ''
    message.value = 'Document request submitted. Book an appointment from Appointments if one is required.'
    await loadRequests()
  } catch (err) {
    error.value = requestError(err)
  } finally {
    loading.value = false
  }
}

onMounted(refresh)
</script>

<template>
  <section class="page-header">
    <p class="page-kicker">Student Services</p>
    <h1 class="page-title">Document Requests</h1>
    <p class="page-description">Request an official document and track its processing status.</p>
  </section>

  <p v-if="message" class="notice success">{{ message }}</p>
  <p v-if="error" class="notice error">{{ error }}</p>

  <section class="dr-panel">
    <h2>Request a document</h2>
    <form class="form-grid" @submit.prevent="submitRequest">
      <label>
        Document
        <select v-model="selectedTypeId" required>
          <option value="" disabled>Select a document</option>
          <option v-for="type in documentTypes" :key="type.id" :value="type.id">{{ documentTypeLabel(type) }}</option>
        </select>
      </label>
      <label>Copies<input v-model="quantity" type="number" min="1" max="10" required /></label>
      <label class="wide">Purpose<textarea v-model="purpose" rows="2" placeholder="Optional purpose"></textarea></label>
      <p v-if="selectedType" class="form-note wide">
        Processing time: {{ selectedType.processing_days }} day(s).
        {{ selectedType.requires_appointment ? 'An appointment is required.' : 'No appointment is required.' }}
      </p>
      <button :disabled="loading">Submit request</button>
    </form>
  </section>

  <section class="dr-panel">
    <h2>Your request status and history</h2>
    <p v-if="loading && !requests.length" class="empty">Loading requests…</p>
    <p v-else-if="!requests.length" class="empty">No document requests yet.</p>
    <div v-for="item in requests" :key="item.id" class="request-card">
      <div>
        <strong>{{ item.document_type.document_name }}</strong>
        <p>
          Requested {{ item.request_date }} · {{ item.quantity }} copy/copies
          <template v-if="Number(item.total_fee) > 0"> · ₱{{ formatMoney(item.total_fee) }}</template>
        </p>
        <p v-if="item.remarks">Registrar remarks: {{ item.remarks }}</p>
      </div>
      <span class="badge" :class="item.status">{{ item.status.replaceAll('_', ' ') }}</span>
      <div v-for="appointment in item.appointments" :key="appointment.id" class="appointment">
        Appointment: {{ appointment.appointment_date }} at {{ String(appointment.appointment_time).slice(0, 5) }} — {{ appointment.status }}
      </div>
    </div>
  </section>
</template>

<style scoped src="./documentRequest.css"></style>
