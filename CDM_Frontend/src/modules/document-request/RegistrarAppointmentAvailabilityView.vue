<script setup>
import { onMounted, reactive, ref } from 'vue'
import { documentRequestService as api } from './documentRequestService'

const blockedDates = ref([])
const editingId = ref(null)
const loading = ref(false)
const submitting = ref(false)
const message = ref('')
const error = ref('')
const types = [
  { value: 'holiday', label: 'Holiday' },
  { value: 'maintenance', label: 'Maintenance' },
  { value: 'office_closure', label: 'Office Closure' },
  { value: 'school_event', label: 'School Event' },
  { value: 'other', label: 'Other' },
]
const form = reactive({
  blocked_date: '',
  type: 'office_closure',
  reason: '',
  is_active: true,
})
const requestError = (err) =>
  Object.values(err.response?.data?.errors || {})[0]?.[0] ||
  err.response?.data?.message ||
  'The blocked date could not be saved.'

function dateLabel(date) {
  return new Intl.DateTimeFormat('en-PH', { dateStyle: 'medium' }).format(new Date(`${date}T00:00:00`))
}

function typeLabel(type) {
  return types.find((option) => option.value === type)?.label || type.replaceAll('_', ' ')
}

function resetForm() {
  editingId.value = null
  Object.assign(form, {
    blocked_date: '',
    type: 'office_closure',
    reason: '',
    is_active: true,
  })
}

function edit(blockedDate) {
  editingId.value = blockedDate.id
  Object.assign(form, {
    blocked_date: blockedDate.blocked_date,
    type: blockedDate.type,
    reason: blockedDate.reason,
    is_active: blockedDate.is_active,
  })
  window.scrollTo({ top: 0, behavior: 'smooth' })
}

async function refresh() {
  loading.value = true
  error.value = ''
  try {
    blockedDates.value = await api.appointmentBlockedDates()
  } catch (err) {
    error.value = requestError(err)
  } finally {
    loading.value = false
  }
}

async function save() {
  submitting.value = true
  message.value = ''
  error.value = ''
  try {
    const payload = { ...form }
    const response = editingId.value
      ? await api.updateAppointmentBlockedDate(editingId.value, payload)
      : await api.createAppointmentBlockedDate(payload)

    message.value = response.message
    resetForm()
    await refresh()
  } catch (err) {
    error.value = requestError(err)
  } finally {
    submitting.value = false
  }
}

async function toggleActive(blockedDate) {
  message.value = ''
  error.value = ''
  try {
    const response = await api.updateAppointmentBlockedDate(blockedDate.id, {
      is_active: !blockedDate.is_active,
    })
    message.value = response.message
    await refresh()
  } catch (err) {
    error.value = requestError(err)
  }
}

async function remove(blockedDate) {
  if (!window.confirm(`Delete the block for ${dateLabel(blockedDate.blocked_date)}?`)) return

  message.value = ''
  error.value = ''
  try {
    const response = await api.deleteAppointmentBlockedDate(blockedDate.id)
    message.value = response.message
    if (editingId.value === blockedDate.id) resetForm()
    await refresh()
  } catch (err) {
    error.value = requestError(err)
  }
}

onMounted(refresh)
</script>

<template>
  <section class="page-header">
    <p class="page-kicker">Registrar Staff</p>
    <h1 class="page-title">Appointment Availability</h1>
    <p class="page-description">Manage holidays, closures, events, and other dates unavailable for new appointments.</p>
  </section>

  <p v-if="message" class="notice success">{{ message }}</p>
  <p v-if="error" class="notice error">{{ error }}</p>

  <section class="dr-panel availability-weekend-note">
    <strong>Weekend closure is enabled</strong>
    <p>Saturday and Sunday are automatically unavailable. No blocked-date records are needed for weekends.</p>
  </section>

  <section class="dr-panel">
    <h2>{{ editingId ? 'Edit blocked date' : 'Block a date' }}</h2>
    <form class="form-grid" @submit.prevent="save">
      <label>
        Date
        <input v-model="form.blocked_date" type="date" required />
      </label>
      <label>
        Type
        <select v-model="form.type" required>
          <option v-for="option in types" :key="option.value" :value="option.value">{{ option.label }}</option>
        </select>
      </label>
      <label class="wide">
        Reason
        <input v-model.trim="form.reason" maxlength="255" placeholder="Why is this date unavailable?" required />
      </label>
      <label class="checkbox wide">
        <input v-model="form.is_active" type="checkbox" />
        Active block
      </label>
      <span class="actions wide">
        <button :disabled="submitting">{{ submitting ? 'Saving…' : editingId ? 'Save changes' : 'Block date' }}</button>
        <button v-if="editingId" type="button" class="secondary" @click="resetForm">Cancel edit</button>
      </span>
    </form>
  </section>

  <section class="dr-panel">
    <h2>Blocked dates</h2>
    <p v-if="loading && !blockedDates.length" class="empty">Loading blocked dates…</p>
    <p v-else-if="!blockedDates.length" class="empty">No dates have been blocked.</p>
    <div v-else class="availability-table">
      <div class="availability-row availability-head" aria-hidden="true">
        <span>Date</span>
        <span>Type</span>
        <span>Reason</span>
        <span>Status</span>
        <span>Actions</span>
      </div>
      <div v-for="blockedDate in blockedDates" :key="blockedDate.id" class="availability-row">
        <strong>{{ dateLabel(blockedDate.blocked_date) }}</strong>
        <span>{{ typeLabel(blockedDate.type) }}</span>
        <span>{{ blockedDate.reason }}</span>
        <span class="badge" :class="blockedDate.is_active ? 'active' : 'inactive'">
          {{ blockedDate.is_active ? 'Active' : 'Inactive' }}
        </span>
        <span class="actions">
          <button type="button" class="compact-button" @click="edit(blockedDate)">Edit</button>
          <button type="button" class="compact-button secondary" @click="toggleActive(blockedDate)">
            {{ blockedDate.is_active ? 'Deactivate' : 'Activate' }}
          </button>
          <button type="button" class="compact-button danger" @click="remove(blockedDate)">Delete</button>
        </span>
      </div>
    </div>
  </section>
</template>

<style scoped src="./documentRequest.css"></style>
