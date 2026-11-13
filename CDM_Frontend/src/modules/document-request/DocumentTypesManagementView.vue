<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
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
const deletingId = ref(null)
const deletedType = ref(null)
const undoing = ref(false)
const search = ref('')
const message = ref('')
const error = ref('')
let messageTimer = null
let errorTimer = null
const form = reactive({
  document_name: '',
  description: '',
  workflow_category: 'official_issuance',
  requires_appointment: false,
  requires_physical_retrieval: false,
  student_requestable: true,
  student_uploadable: false,
  processing_fee: 0,
  processing_days: 1,
})
const errorMessage = (err) => err.response?.data?.message || 'The document type action could not be completed.'
const filteredTypes = computed(() => {
  const term = search.value.trim().toLowerCase()
  if (!term) return types.value

  return types.value.filter((type) =>
    [type.document_name, type.description, type.status].some((value) =>
      String(value || '').toLowerCase().includes(term),
    ),
  )
})

function clearMessage() {
  if (messageTimer) window.clearTimeout(messageTimer)
  messageTimer = null
  message.value = ''
}

function showMessage(value) {
  clearMessage()
  message.value = value
  messageTimer = window.setTimeout(() => {
    message.value = ''
    messageTimer = null
  }, 4000)
}

function clearError() {
  if (errorTimer) window.clearTimeout(errorTimer)
  errorTimer = null
  error.value = ''
}

function showError(err) {
  clearError()
  error.value = errorMessage(err)
  errorTimer = window.setTimeout(() => {
    error.value = ''
    errorTimer = null
  }, 7000)
}

function clearForm() {
  editing.value = null
  Object.assign(form, {
    document_name: '',
    description: '',
    workflow_category: 'official_issuance',
    requires_appointment: false,
    requires_physical_retrieval: false,
    student_requestable: true,
    student_uploadable: false,
    processing_fee: 0,
    processing_days: 1,
  })
}

function openCreate() {
  clearForm()
  modalOpen.value = true
}

const restorablePayload = (type) => ({
  document_name: type.document_name,
  description: type.description || '',
  workflow_category: type.workflow_category,
  requires_appointment: Boolean(type.requires_appointment),
  requires_physical_retrieval: Boolean(type.requires_physical_retrieval),
  student_requestable: Boolean(type.student_requestable),
  student_uploadable: Boolean(type.student_uploadable),
  processing_fee: Number(type.processing_fee),
  processing_days: Number(type.processing_days),
  status: type.status,
})

function edit(type) {
  editing.value = type
  Object.assign(form, {
    document_name: type.document_name,
    description: type.description || '',
    workflow_category: type.workflow_category,
    requires_appointment: Boolean(type.requires_appointment),
    requires_physical_retrieval: Boolean(type.requires_physical_retrieval),
    student_requestable: Boolean(type.student_requestable),
    student_uploadable: Boolean(type.student_uploadable),
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
  clearError()
  try {
    types.value = await api.registrarDocumentTypes()
  } catch (err) {
    showError(err)
  } finally {
    loading.value = false
  }
}

async function save() {
  if (saving.value) return
  saving.value = true
  clearMessage()
  clearError()
  try {
    const payload = {
      ...form,
      student_uploadable: form.workflow_category === 'supporting_record' && form.student_uploadable,
      processing_fee: Number(form.processing_fee),
      processing_days: Number(form.processing_days),
    }
    if (editing.value) await api.updateDocumentType(editing.value.id, payload)
    else await api.createDocumentType(payload)
    showMessage(editing.value ? 'Document type updated.' : 'Document type added.')
    modalOpen.value = false
    clearForm()
    await refresh()
  } catch (err) {
    showError(err)
  } finally {
    saving.value = false
  }
}

async function remove(type) {
  if (!window.confirm(`Delete “${type.document_name}”? Only unused document types can be deleted. You can undo immediately afterward.`)) return
  deletingId.value = type.id
  clearMessage()
  clearError()
  try {
    deletedType.value = await api.deleteDocumentType(type.id)
    showMessage(`“${type.document_name}” was deleted.`)
    await refresh()
  } catch (err) {
    showError(err)
  } finally {
    deletingId.value = null
  }
}

async function undoDelete() {
  if (!deletedType.value || undoing.value) return
  const snapshot = { ...deletedType.value }
  undoing.value = true
  clearError()
  try {
    await api.createDocumentType(restorablePayload(snapshot))
    deletedType.value = null
    showMessage(`“${snapshot.document_name}” was restored.`)
    await refresh()
  } catch (err) {
    showError(err)
  } finally {
    undoing.value = false
  }
}

async function toggle(type, event) {
  const enabling = type.status !== 'active'
  if (!enabling && !window.confirm(`Disable “${type.document_name}”? Students will no longer be able to request it.`)) {
    if (event?.target) event.target.checked = true
    return
  }

  togglingId.value = type.id
  clearMessage()
  clearError()
  try {
    await api.updateDocumentType(type.id, { status: enabling ? 'active' : 'inactive' })
    showMessage(`Document type ${enabling ? 'enabled' : 'disabled'}.`)
    await refresh()
  } catch (err) {
    if (event?.target) event.target.checked = !enabling
    showError(err)
  } finally {
    togglingId.value = null
  }
}

function handleRowAction(type, event) {
  const action = event.target.value
  event.target.value = ''
  if (action === 'edit') edit(type)
  if (action === 'delete') remove(type)
}

onMounted(refresh)
onBeforeUnmount(() => {
  if (messageTimer) window.clearTimeout(messageTimer)
  if (errorTimer) window.clearTimeout(errorTimer)
})
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

  <div class="document-type-toast-region" aria-live="polite" aria-atomic="true">
    <Transition name="document-type-toast"><p v-if="message" class="notice success" role="status">{{ message }}</p></Transition>
    <Transition name="document-type-toast"><p v-if="error" class="notice error" role="alert">{{ error }}</p></Transition>
  </div>
  <div class="document-type-undo-dock">
    <button type="button" class="dr-button dr-button--secondary" :disabled="!deletedType || undoing" :title="deletedType ? `Restore ${deletedType.document_name}` : 'No deleted document type to restore'" @click="undoDelete">{{ undoing ? 'Restoring…' : '↶ Undo delete' }}</button>
  </div>

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
        <thead><tr><th scope="col">Document Type</th><th scope="col">Description</th><th scope="col">Workflow</th><th scope="col">Requirements</th><th scope="col">Processing Time</th><th scope="col">Status</th><th scope="col">Action</th></tr></thead>
        <tbody>
          <tr v-for="type in filteredTypes" :key="type.id">
            <td><strong>{{ type.document_name }}</strong><small>₱{{ Number(type.processing_fee || 0).toFixed(2) }} fee</small></td>
            <td>{{ type.description || 'No description provided.' }}</td>
            <td><strong>{{ type.workflow_category === 'supporting_record' ? 'Supporting record' : 'Official issuance' }}</strong><small>{{ type.student_requestable ? 'Student request enabled' : 'Not requestable' }} · {{ type.student_uploadable ? 'Student upload enabled' : 'No student upload' }}</small></td>
            <td>{{ type.requires_appointment ? 'Appointment required' : 'No appointment required' }}<small>{{ type.requires_physical_retrieval ? 'Physical folder retrieval' : 'No physical retrieval' }}</small></td>
            <td>{{ type.processing_days }} business day{{ Number(type.processing_days) === 1 ? '' : 's' }}</td>
            <td><label class="dr-status-switch"><input type="checkbox" :checked="type.status === 'active'" :disabled="togglingId === type.id" :aria-label="`${type.status === 'active' ? 'Disable' : 'Enable'} ${type.document_name}`" @change="toggle(type, $event)"><span class="dr-status-switch-track" aria-hidden="true"></span><span>{{ togglingId === type.id ? 'Updating…' : type.status === 'active' ? 'Active' : 'Inactive' }}</span></label></td>
            <td>
              <select class="dr-row-action-select" :disabled="deletingId === type.id" :aria-label="`Actions for ${type.document_name}`" @change="handleRowAction(type, $event)"><option value="">{{ deletingId === type.id ? 'Deleting…' : 'Actions' }}</option><option value="edit">Edit</option><option value="delete">Delete</option></select>
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
        <label class="wide">Workflow
          <select v-model="form.workflow_category" @change="form.student_uploadable = false">
            <option value="official_issuance">Registrar-issued official document</option>
            <option value="supporting_record">Student-submitted supporting record</option>
          </select>
        </label>
        <label>Processing fee<input v-model="form.processing_fee" type="number" min="0" step="0.01" required /></label>
        <label>Processing days<input v-model="form.processing_days" type="number" min="1" max="255" required /></label>
        <label class="checkbox wide"><input v-model="form.requires_appointment" type="checkbox" /> Appointment required</label>
        <label class="checkbox wide"><input v-model="form.requires_physical_retrieval" type="checkbox" /> Requires Physical Record Retrieval for new requests</label>
        <p class="wide document-type-policy-note">Existing requests are not added to the retrieval queue when this setting changes.</p>
        <label class="checkbox wide"><input v-model="form.student_requestable" type="checkbox" /> Students may request this document from the Registrar</label>
        <label v-if="form.workflow_category === 'supporting_record'" class="checkbox wide"><input v-model="form.student_uploadable" type="checkbox" /> Students may upload this supporting record</label>
        <p v-else class="wide document-type-policy-note">Official documents cannot be uploaded or issued by Students.</p>
      </div>
      <footer class="dr-dialog-footer">
        <button type="button" class="dr-button dr-button--secondary" :disabled="saving" @click="closeModal">Cancel</button>
        <button type="submit" class="dr-button dr-button--primary" :disabled="saving">{{ saving ? 'Saving…' : editing ? 'Save Changes' : 'Add Document Type' }}</button>
      </footer>
    </form>
  </DocumentRequestDialog>
</template>

<style scoped src="./documentRequest.css"></style>
