<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import DocumentRequestDialog from './DocumentRequestDialog.vue'
import DocumentRequestEmptyState from './DocumentRequestEmptyState.vue'
import DocumentRequestPageHeader from './DocumentRequestPageHeader.vue'
import DocumentRequestTableSkeleton from './DocumentRequestTableSkeleton.vue'
import { documentRequestService as api } from './documentRequestService'

const types = ref([])
const editing = ref(null)
const modalOpen = ref(false)
const loading = ref(false)
const saving = ref(false)
const togglingId = ref(null)
const search = ref('')
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
const filteredTypes = computed(() => {
  const term = search.value.trim().toLowerCase()
  if (!term) return types.value

  return types.value.filter((type) =>
    [type.document_name, type.description, type.status].some((value) =>
      String(value || '').toLowerCase().includes(term),
    ),
  )
})

function clearForm() {
  editing.value = null
  Object.assign(form, {
    document_name: '',
    description: '',
    requires_appointment: false,
    processing_fee: 0,
    processing_days: 1,
  })
}

function openCreate() {
  clearForm()
  modalOpen.value = true
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
  modalOpen.value = true
}

function closeModal() {
  if (saving.value) return
  modalOpen.value = false
  clearForm()
}

async function refresh() {
  loading.value = true
  error.value = ''
  try {
    types.value = await api.registrarDocumentTypes()
  } catch (err) {
    error.value = errorMessage(err)
  } finally {
    loading.value = false
  }
}

async function save() {
  if (saving.value) return
  saving.value = true
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
    modalOpen.value = false
    clearForm()
    await refresh()
  } catch (err) {
    error.value = errorMessage(err)
  } finally {
    saving.value = false
  }
}

async function toggle(type) {
  const enabling = type.status !== 'active'
  if (!enabling && !window.confirm(`Disable “${type.document_name}”? Students will no longer be able to request it.`))
    return

  togglingId.value = type.id
  message.value = ''
  error.value = ''
  try {
    await api.updateDocumentType(type.id, { status: enabling ? 'active' : 'inactive' })
    message.value = `Document type ${enabling ? 'enabled' : 'disabled'}.`
    await refresh()
  } catch (err) {
    error.value = errorMessage(err)
  } finally {
    togglingId.value = null
  }
}

onMounted(refresh)
</script>

<template>
  <DocumentRequestPageHeader
    eyebrow="Registrar Staff"
    title="Document Types"
    description="Manage the documents students can request. Disabling a type preserves historical requests."
  >
    <template #actions>
      <button type="button" class="dr-button dr-button--primary" @click="openCreate">Add Document Type</button>
    </template>
  </DocumentRequestPageHeader>

  <p v-if="message" class="notice success" role="status">{{ message }}</p>
  <p v-if="error" class="notice error" role="alert">{{ error }}</p>

  <section class="dr-filter-bar document-type-filter" aria-label="Document type filters">
    <label>
      Search
      <input v-model="search" type="search" placeholder="Document type, description, or status" />
    </label>
    <button type="button" class="dr-button dr-button--secondary" :disabled="!search" @click="search = ''">Reset</button>
  </section>

  <section class="dr-table-panel" aria-labelledby="document-types-table-title">
    <header class="dr-records-heading">
      <div><h2 id="document-types-table-title">Available document types</h2><p>{{ filteredTypes.length }} record{{ filteredTypes.length === 1 ? '' : 's' }}</p></div>
    </header>
    <DocumentRequestTableSkeleton v-if="loading && !types.length" :columns="6" :rows="5" label="Loading document types" />
    <DocumentRequestEmptyState
      v-else-if="!filteredTypes.length"
      :message="search ? 'No document types match your search.' : 'No document types found.'"
    />
    <div v-else class="dr-table-scroll">
      <table class="dr-table document-types-records-table">
        <thead><tr><th scope="col">Document Type</th><th scope="col">Description</th><th scope="col">Requirements</th><th scope="col">Processing Time</th><th scope="col">Status</th><th scope="col">Action</th></tr></thead>
        <tbody>
          <tr v-for="type in filteredTypes" :key="type.id">
            <td><strong>{{ type.document_name }}</strong><small>₱{{ Number(type.processing_fee || 0).toFixed(2) }} fee</small></td>
            <td>{{ type.description || 'No description provided.' }}</td>
            <td>{{ type.requires_appointment ? 'Appointment required' : 'No appointment required' }}</td>
            <td>{{ type.processing_days }} business day{{ Number(type.processing_days) === 1 ? '' : 's' }}</td>
            <td><span class="dr-type-status" :class="type.status">{{ type.status === 'active' ? 'Active' : 'Inactive' }}</span></td>
            <td>
              <div class="dr-table-actions">
                <button type="button" class="dr-button dr-button--secondary" @click="edit(type)">Edit</button>
                <button
                  type="button"
                  class="dr-button"
                  :class="type.status === 'active' ? 'dr-button--danger' : 'dr-button--secondary'"
                  :disabled="togglingId === type.id"
                  @click="toggle(type)"
                >
                  {{ togglingId === type.id ? 'Updating…' : type.status === 'active' ? 'Disable' : 'Enable' }}
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>

  <DocumentRequestDialog v-if="modalOpen" labelledby="document-type-dialog-title" :busy="saving" @cancel="closeModal">
    <header class="dr-dialog-header">
      <div>
        <h2 id="document-type-dialog-title">{{ editing ? 'Edit Document Type' : 'Add Document Type' }}</h2>
        <p>Set the student-facing document information and processing requirements.</p>
      </div>
      <button type="button" class="dr-dialog-close" aria-label="Close document type form" :disabled="saving" @click="closeModal">×</button>
    </header>
    <form @submit.prevent="save">
      <div class="dr-dialog-body document-type-form">
        <label class="wide">Document name<input v-model="form.document_name" required maxlength="150" /></label>
        <label class="wide">Description / instructions<textarea v-model="form.description" rows="3" maxlength="3000"></textarea></label>
        <label>Processing fee<input v-model="form.processing_fee" type="number" min="0" step="0.01" required /></label>
        <label>Processing days<input v-model="form.processing_days" type="number" min="1" max="255" required /></label>
        <label class="checkbox wide"><input v-model="form.requires_appointment" type="checkbox" /> Appointment required</label>
      </div>
      <footer class="dr-dialog-footer">
        <button type="button" class="dr-button dr-button--secondary" :disabled="saving" @click="closeModal">Cancel</button>
        <button type="submit" class="dr-button dr-button--primary" :disabled="saving">{{ saving ? 'Saving…' : editing ? 'Save Changes' : 'Add Document Type' }}</button>
      </footer>
    </form>
  </DocumentRequestDialog>
</template>

<style scoped src="./documentRequest.css"></style>
