<script setup>
import { onMounted, reactive, ref } from 'vue'
import { documentRequestService as api } from './documentRequestService'

const types = ref([])
const editing = ref(null)
const loading = ref(false)
const message = ref('')
const error = ref('')
const form = reactive({
  document_name: '',
  description: '',
  requires_appointment: false,
  processing_fee: 0,
  processing_days: 1,
})
const errorMessage = (err) => err.response?.data?.message || 'The document type could not be saved.'
const resetForm = () => {
  editing.value = null
  Object.assign(form, {
    document_name: '',
    description: '',
    requires_appointment: false,
    processing_fee: 0,
    processing_days: 1,
  })
}
async function refresh() {
  loading.value = true
  try {
    types.value = await api.registrarDocumentTypes()
  } catch (err) {
    error.value = errorMessage(err)
  } finally {
    loading.value = false
  }
}
function edit(type) {
  editing.value = type
  Object.assign(form, {
    document_name: type.document_name,
    description: type.description || '',
    requires_appointment: Boolean(type.requires_appointment),
    processing_fee: type.processing_fee,
    processing_days: type.processing_days,
  })
  window.scrollTo({ top: 0, behavior: 'smooth' })
}
async function save() {
  message.value = ''
  error.value = ''
  try {
    const payload = {
      ...form,
      processing_fee: Number(form.processing_fee),
      processing_days: Number(form.processing_days),
    }
    if (editing.value) await api.updateDocumentType(editing.value.id, payload)
    else await api.createDocumentType(payload)
    message.value = editing.value ? 'Document type updated.' : 'Document type added.'
    resetForm()
    await refresh()
  } catch (err) {
    error.value = errorMessage(err)
  }
}
async function toggle(type) {
  const enabling = type.status !== 'active'
  if (!enabling && !window.confirm(`Disable “${type.document_name}”? Students will no longer be able to request it.`))
    return
  try {
    await api.updateDocumentType(type.id, {
      status: enabling ? 'active' : 'inactive',
    })
    message.value = `Document type ${enabling ? 'enabled' : 'disabled'}.`
    await refresh()
  } catch (err) {
    error.value = errorMessage(err)
  }
}
onMounted(refresh)
</script>

<template>
  <section class="page-header">
    <p class="page-kicker">Registrar Staff</p>
    <h1 class="page-title">Document Types</h1>
    <p class="page-description">
      Manage the documents students can request. Disabling a type preserves historical requests.
    </p>
  </section>
  <p v-if="message" class="notice success">{{ message }}</p>
  <p v-if="error" class="notice error">{{ error }}</p>
  <section class="dr-panel">
    <h2>{{ editing ? 'Edit document type' : 'Add document type' }}</h2>
    <form class="form-grid" @submit.prevent="save">
      <label class="wide">
        Document name
        <input v-model="form.document_name" required maxlength="150" />
      </label>
      <label class="wide">
        Description / instructions
        <textarea v-model="form.description" rows="3" maxlength="3000"></textarea>
      </label>
      <label>
        Processing fee
        <input v-model="form.processing_fee" type="number" min="0" step="0.01" required />
      </label>
      <label>
        Processing days
        <input v-model="form.processing_days" type="number" min="1" max="255" required />
      </label>
      <label class="checkbox">
        <input v-model="form.requires_appointment" type="checkbox" />
        Appointment required
      </label>
      <div class="actions wide">
        <button :disabled="loading">
          {{ editing ? 'Save changes' : 'Add document type' }}
        </button>
        <button v-if="editing" type="button" class="secondary" @click="resetForm">Cancel</button>
      </div>
    </form>
  </section>
  <section class="dr-panel">
    <h2>All document types</h2>
    <p v-if="loading" class="empty">Loading document types…</p>
    <div class="types-table" role="table">
      <div class="types-row types-head" role="row">
        <strong>Document Name</strong>
        <strong>Description</strong>
        <strong>Appointment Required</strong>
        <strong>Status</strong>
        <strong>Actions</strong>
      </div>
      <div v-for="type in types" :key="type.id" class="types-row" role="row">
        <strong>{{ type.document_name }}</strong>
        <span>{{ type.description || '—' }}</span>
        <span>{{ type.requires_appointment ? 'Yes' : 'No' }}</span>
        <span class="badge" :class="type.status">{{ type.status }}</span>
        <span class="actions">
          <button type="button" @click="edit(type)">Edit</button>
          <button type="button" :class="type.status === 'active' ? 'danger' : 'secondary'" @click="toggle(type)">
            {{ type.status === 'active' ? 'Disable' : 'Enable' }}
          </button>
        </span>
      </div>
    </div>
  </section>
</template>

<style scoped src="./documentRequest.css"></style>
