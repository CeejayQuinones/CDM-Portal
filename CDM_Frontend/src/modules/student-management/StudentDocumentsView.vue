<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ROLES } from '../../config/accessControl'
import { isStepUpCancelled, useStepUpAuth } from '../../composables/useStepUpAuth'
import { apiClient } from '../../services/apiClient'
import { useAuthStore } from '../../stores/authStore'

const MAX_FILE_SIZE_MB = 10
const ACCEPTED_TYPES = ['image/jpeg', 'image/png', 'application/pdf']
const route = useRoute()
const router = useRouter()
const authStore = useAuthStore()
const { runWithStepUp } = useStepUpAuth()
const student = ref(null)
const documents = ref([])
const loading = ref(false)
const error = ref('')
const actionError = ref('')
const success = ref('')
const activeAction = ref(null)
const uploadDocument = ref(null)
const selectedFile = ref(null)
const fileInput = ref(null)
const uploading = ref(false)
const uploadProgress = ref(0)
const uploadError = ref('')
const uploadSuccess = ref('')
const previewStates = ref({})
const viewerDocument = ref(null)
const viewerCloseButton = ref(null)
const canManageDocuments = computed(() => [ROLES.REGISTRAR_STAFF, ROLES.ADMIN].includes(authStore.currentRole))
const requirementAction = ref(null)
const requirementNote = ref('')
const requirementActionButton = ref(null)
const isReplacing = computed(() => Boolean(uploadDocument.value?.has_file))
let viewerTrigger = null
let previousBodyOverflow = ''

const title = (value) =>
  value
    ? String(value)
        .replaceAll('_', ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase())
    : 'Not available'
const date = (value) =>
  value
    ? new Intl.DateTimeFormat('en-PH', { dateStyle: 'long' }).format(new Date(`${String(value).slice(0, 10)}T00:00:00`))
    : 'Not available'
async function loadDocuments() {
  loading.value = true
  error.value = ''

  try {
    const { data } = await apiClient.get(`/students/${route.params.id}/documents`)
    student.value = data.data.student
    const stored = new Map(data.data.documents.map(document => [document.document_type_id, document]))
    documents.value = data.data.requirements.map(requirement => ({ ...requirement, ...(stored.get(requirement.document_type_id) || {}) }))
    syncDocumentPreviews()
    await nextTick()
    if (route.query.document) window.document.getElementById(`requirement-${route.query.document}`)?.scrollIntoView({ block: 'center' })
  } catch (requestError) {
    error.value =
      requestError.response?.status === 404
        ? 'This student record could not be found.'
        : requestError.response?.data?.message || 'Unable to load student documents.'
  } finally {
    loading.value = false
  }
}

function revokeDocumentPreview(documentId) {
  const preview = previewStates.value[documentId]
  if (preview?.url) URL.revokeObjectURL(preview.url)
  const next = { ...previewStates.value }
  delete next[documentId]
  previewStates.value = next
}

function clearDocumentPreviews() {
  Object.values(previewStates.value).forEach((preview) => {
    if (preview?.url) URL.revokeObjectURL(preview.url)
  })
  previewStates.value = {}
}

function syncDocumentPreviews() {
  const currentIds = new Set(documents.value.map((document) => String(document.id)))

  Object.keys(previewStates.value).forEach((documentId) => {
    if (!currentIds.has(documentId)) revokeDocumentPreview(documentId)
  })

  documents.value.forEach((document) => {
    if (document.has_file && document.file_kind === 'image' && !previewStates.value[document.id]) {
      loadDocumentPreview(document)
    } else if ((!document.has_file || document.file_kind !== 'image') && previewStates.value[document.id]) {
      revokeDocumentPreview(document.id)
    }
  })
}

async function loadDocumentPreview(document, force = false) {
  if (!document.has_file || document.file_kind !== 'image') return false
  const existing = previewStates.value[document.id]
  if (!force && ['loading', 'ready'].includes(existing?.status)) return existing.status === 'ready'

  if (existing?.url) URL.revokeObjectURL(existing.url)
  const requestToken = Symbol(`preview-${document.id}`)
  previewStates.value = {
    ...previewStates.value,
    [document.id]: { status: 'loading', url: null, requestToken },
  }

  try {
    const response = await apiClient.get(`/registrar/student-documents/${document.id}/view`, {
      responseType: 'blob',
    })
    if (!String(response.data?.type || '').startsWith('image/')) {
      throw new Error('The stored file is not a supported image preview.')
    }

    const url = URL.createObjectURL(response.data)
    if (
      previewStates.value[document.id]?.requestToken !== requestToken ||
      !documents.value.some((item) => item.id === document.id && item.has_file)
    ) {
      URL.revokeObjectURL(url)
      return false
    }
    previewStates.value = {
      ...previewStates.value,
      [document.id]: { status: 'ready', url },
    }
    return true
  } catch {
    if (previewStates.value[document.id]?.requestToken !== requestToken) return false
    previewStates.value = {
      ...previewStates.value,
      [document.id]: { status: 'error', url: null },
    }
    return false
  }
}

async function openImageViewer(document, event) {
  let ready = previewStates.value[document.id]?.status === 'ready'
  if (!ready) ready = await loadDocumentPreview(document, previewStates.value[document.id]?.status === 'loading')
  if (!ready) return

  viewerTrigger = event?.currentTarget || window.document.activeElement
  previousBodyOverflow = window.document.body.style.overflow
  window.document.body.style.overflow = 'hidden'
  viewerDocument.value = document
  await nextTick()
  viewerCloseButton.value?.focus()
}

function closeImageViewer() {
  if (!viewerDocument.value) return
  viewerDocument.value = null
  window.document.body.style.overflow = previousBodyOverflow
  nextTick(() => viewerTrigger?.focus?.())
}

function handleViewerKeydown(event) {
  if (event.key === 'Escape') closeImageViewer()
  if (event.key === 'Tab') {
    event.preventDefault()
    viewerCloseButton.value?.focus()
  }
}

function replaceDocument(updatedDocument) {
  const index = documents.value.findIndex((document) => document.id === updatedDocument.id)
  if (index !== -1) documents.value.splice(index, 1, updatedDocument)
}

function openRequirementAction(action, document, event) {
  requirementAction.value = { action, document }
  requirementNote.value = action === 'require' ? (document.physical_copy_instruction || '') : ''
  requirementActionButton.value = event?.currentTarget || null
}

function closeRequirementAction() {
  if (activeAction.value) return
  requirementAction.value = null
  requirementNote.value = ''
  nextTick(() => requirementActionButton.value?.focus?.())
}

async function submitRequirementAction() {
  const context = requirementAction.value
  if (!context) return
  const { action, document } = context
  activeAction.value = `requirement-${action}-${document.document_type_id}`
  actionError.value = ''
  success.value = ''
  try {
    const digital = ['verify', 'needs-resubmission'].includes(action)
    const url = digital
      ? `/student-documents/${document.id}/${action}`
      : `/students/${student.value.id}/record-requirements/${document.document_type_id}/${action}`
    const payload = digital
      ? { version: document.version, ...(action === 'needs-resubmission' ? { reason: requirementNote.value } : {}) }
      : action === 'require'
        ? { instruction: requirementNote.value || null }
        : action === 'reopen'
          ? { reason: requirementNote.value }
          : {}
    const { data } = await apiClient.patch(url, payload)
    success.value = data.message
    activeAction.value = null
    closeRequirementAction()
    await loadDocuments({ silent: true })
  } catch (requestError) {
    actionError.value = requestError.response?.data?.message || Object.values(requestError.response?.data?.errors || {}).flat()[0] || 'Unable to update this requirement.'
    if (requestError.response?.status === 409) await loadDocuments({ silent: true })
  } finally {
    activeAction.value = null
  }
}

const actionTitle = computed(() => ({
  verify: 'Verify Digital Copy', 'needs-resubmission': 'Request Digital Resubmission',
  require: 'Require Hard Copy', receive: 'Mark Hard Copy Received', reopen: 'Require Hard Copy Again',
}[requirementAction.value?.action] || 'Update Requirement'))

function openUpload(document) {
  uploadDocument.value = document
  selectedFile.value = null
  uploadProgress.value = 0
  uploadError.value = ''
  uploadSuccess.value = ''
  if (fileInput.value) fileInput.value.value = ''
}

function closeUpload() {
  if (uploading.value) return
  uploadDocument.value = null
  selectedFile.value = null
  uploadError.value = ''
  uploadSuccess.value = ''
}

function selectFile(event) {
  const file = event.target.files?.[0] || null
  uploadError.value = ''
  uploadSuccess.value = ''

  if (!file) {
    selectedFile.value = null
    return
  }

  if (!ACCEPTED_TYPES.includes(file.type)) {
    uploadError.value = 'Select a JPEG, PNG, or PDF file.'
    event.target.value = ''
    selectedFile.value = null
    return
  }

  if (file.size > MAX_FILE_SIZE_MB * 1024 * 1024) {
    uploadError.value = `The document must not be larger than ${MAX_FILE_SIZE_MB} MB.`
    event.target.value = ''
    selectedFile.value = null
    return
  }

  selectedFile.value = file
}

async function submitUpload() {
  if (!selectedFile.value || !uploadDocument.value) {
    uploadError.value = 'Select a document to upload.'
    return
  }

  uploading.value = true
  uploadProgress.value = 0
  uploadError.value = ''
  uploadSuccess.value = ''
  actionError.value = ''
  success.value = ''

  try {
    const formData = new FormData()
    formData.append('file', selectedFile.value)
    const { data } = await apiClient.post(
      `/registrar/student-documents/${uploadDocument.value.id}/upload`,
      formData,
      {
        headers: { 'Content-Type': 'multipart/form-data' },
        onUploadProgress: (event) => {
          if (event.total) uploadProgress.value = Math.round((event.loaded * 100) / event.total)
        },
      },
    )

    replaceDocument(data.data)
    if (data.data.file_kind === 'image') await loadDocumentPreview(data.data, true)
    else revokeDocumentPreview(data.data.id)
    uploadDocument.value = data.data
    uploadProgress.value = 100
    uploadSuccess.value = data.message
    success.value = data.message
    selectedFile.value = null
    if (fileInput.value) fileInput.value.value = ''
  } catch (requestError) {
    uploadError.value =
      requestError.response?.data?.errors?.file?.[0] ||
      requestError.response?.data?.message ||
      'Unable to upload the student document.'
  } finally {
    uploading.value = false
  }
}

async function viewDocument(document) {
  const preview = window.open('', '_blank')
  if (preview) preview.opener = null
  activeAction.value = `view-${document.id}`
  actionError.value = ''

  try {
    const response = await apiClient.get(`/registrar/student-documents/${document.id}/view`, {
      responseType: 'blob',
    })
    const url = URL.createObjectURL(response.data)
    if (preview) preview.location.href = url
    else window.location.href = url
    window.setTimeout(() => URL.revokeObjectURL(url), 60_000)
  } catch (requestError) {
    if (preview) preview.close()
    actionError.value = requestError.response?.data?.message || 'Unable to open the student document.'
  } finally {
    activeAction.value = null
  }
}

function openStoredDocument(document, event) {
  if (document.file_kind === 'image') return openImageViewer(document, event)
  return viewDocument(document)
}

async function downloadDocument(document) {
  activeAction.value = `download-${document.id}`
  actionError.value = ''

  try {
    const response = await apiClient.get(`/registrar/student-documents/${document.id}/download`, {
      responseType: 'blob',
    })
    const disposition = response.headers['content-disposition'] || ''
    const filename = disposition.match(/filename="?([^";]+)"?/i)?.[1] || `student-document-${document.id}`
    const url = URL.createObjectURL(response.data)
    const link = window.document.createElement('a')
    link.href = url
    link.download = filename
    link.click()
    URL.revokeObjectURL(url)
  } catch (requestError) {
    actionError.value = requestError.response?.data?.message || 'Unable to download the student document.'
  } finally {
    activeAction.value = null
  }
}

async function deleteDocumentFile(document) {
  if (!window.confirm(`Delete the stored file for ${document.name}? The document will be marked missing.`)) return

  activeAction.value = `delete-${document.id}`
  actionError.value = ''
  success.value = ''

  try {
    const { data } = await runWithStepUp(() =>
      apiClient.delete(`/registrar/student-documents/${document.id}/file`),
    )
    replaceDocument(data.data)
    revokeDocumentPreview(data.data.id)
    success.value = data.message
  } catch (requestError) {
    if (isStepUpCancelled(requestError)) return
    actionError.value = requestError.response?.data?.message || 'Unable to delete the student document file.'
  } finally {
    activeAction.value = null
  }
}

watch(() => route.params.id, () => {
  clearDocumentPreviews()
  closeImageViewer()
  loadDocuments()
})
onMounted(loadDocuments)
onBeforeUnmount(() => {
  clearDocumentPreviews()
  closeImageViewer()
})
</script>

<template>
  <section class="page-header">
    <p class="page-kicker">Registrar / Student Management</p>
    <h1 class="page-title">Student Documents</h1>
    <p class="page-description">
      {{
        canManageDocuments
          ? 'Manage securely stored student files and review submissions.'
          : 'Review student document records.'
      }}
    </p>
  </section>
  <p v-if="success" class="notice success" role="status">{{ success }}</p>
  <p v-if="actionError" class="notice error" role="alert">{{ actionError }}</p>
  <section v-if="loading" class="panel state">Loading student documents…</section>
  <section v-else-if="error" class="panel state error" role="alert">
    {{ error }} <button type="button" @click="loadDocuments">Retry</button>
  </section>
  <template v-else-if="student">
    <section class="summary">
      <div>
        <p>Student Number</p>
        <strong>{{ student.student_number }}</strong>
      </div>
      <div>
        <p>Name</p>
        <strong>{{ student.full_name }}</strong>
      </div>
      <div>
        <p>Course</p>
        <strong>{{ student.course?.code }} — {{ student.course?.name }}</strong>
      </div>
      <div>
        <p>Year</p>
        <strong>Year {{ student.year_level }}</strong>
      </div>
      <div>
        <p>Status</p>
        <span class="badge">{{ title(student.student_status) }}</span>
      </div>
    </section>
    <section v-if="!documents.length" class="panel state">
      <h2>No document records</h2>
      <p>There are no student document records available yet.</p>
    </section>
    <section v-else class="documents">
      <article v-for="document in documents" :id="`requirement-${document.document_type_id}`" :key="document.id || `type-${document.document_type_id}`" class="document-card">
        <aside class="document-preview" :aria-label="`${document.name} preview`">
          <div v-if="!document.has_file" class="preview-placeholder missing-preview">
            <span class="preview-icon" aria-hidden="true">◇</span>
            <strong>No document uploaded</strong>
          </div>
          <template v-else-if="document.file_kind === 'image'">
            <div v-if="previewStates[document.id]?.status === 'loading'" class="preview-placeholder" role="status">
              <span class="preview-spinner" aria-hidden="true"></span>
              <strong>Loading preview…</strong>
            </div>
            <div v-else-if="previewStates[document.id]?.status === 'error'" class="preview-placeholder preview-error" role="alert">
              <span class="preview-icon" aria-hidden="true">!</span>
              <strong>Failed to load preview</strong>
              <button type="button" @click="loadDocumentPreview(document, true)">Retry</button>
            </div>
            <button
              v-else-if="previewStates[document.id]?.url"
              class="image-preview-button"
              type="button"
              :aria-label="`Open ${document.name} image full screen`"
              @click="openImageViewer(document, $event)"
            >
              <img :src="previewStates[document.id].url" :alt="`${document.name} document preview`" />
              <span>Click to enlarge</span>
            </button>
          </template>
          <button
            v-else
            class="preview-placeholder file-preview-button"
            type="button"
            :disabled="activeAction === `view-${document.id}`"
            @click="viewDocument(document)"
          >
            <span class="preview-file-type">{{ document.file_kind === 'pdf' ? 'PDF' : 'FILE' }}</span>
            <strong>{{ document.file_kind === 'pdf' ? 'PDF Document' : 'Document File' }}</strong>
            <span>{{ activeAction === `view-${document.id}` ? 'Opening…' : 'Click to View' }}</span>
          </button>
        </aside>
        <div class="document-information">
          <div class="doc-head">
            <div>
              <h2>{{ document.name }}</h2>
              <p class="file-state">{{ document.has_file ? 'Stored file available' : 'No file stored' }}</p>
            </div>
            <span class="badge" :class="document.has_file ? 'available' : 'missing'">
              {{ document.has_file ? 'Available' : 'Missing' }}
            </span>
          </div>
          <dl>
            <div>
              <dt>Submitted Date</dt>
              <dd>{{ date(document.submitted_date) }}</dd>
            </div>
            <div>
              <dt>Verification</dt>
              <dd>{{ title(document.verification_status) }}</dd>
            </div>
            <div>
              <dt>Verified By</dt>
              <dd>{{ document.verified_by || 'Not available' }}</dd>
            </div>
            <div class="remarks">
              <dt>Remarks</dt>
              <dd>{{ document.remarks || 'No remarks' }}</dd>
            </div>
            <div><dt>Digital Copy</dt><dd>{{ title(document.digital_status) }}</dd></div>
            <div><dt>Physical Copy</dt><dd>{{ document.physical_status === 'submitted' ? 'Received' : title(document.physical_status) }}</dd></div>
          </dl>

          <p v-if="document.physical_status === 'required'" class="physical-instruction"><strong>Hard-copy instruction:</strong> {{ document.physical_copy_instruction || 'Please submit the physical/original copy to the Registrar Office.' }}</p>

          <div v-if="canManageDocuments" class="document-actions">
            <template v-if="document.has_file">
              <button type="button" :disabled="activeAction" @click="openStoredDocument(document, $event)">
                {{ document.file_kind === 'image' ? 'Full Screen' : activeAction === `view-${document.id}` ? 'Opening…' : 'View' }}
              </button>
              <button type="button" :disabled="activeAction" @click="downloadDocument(document)">
                {{ activeAction === `download-${document.id}` ? 'Downloading…' : 'Download' }}
              </button>
              <button class="primary" type="button" :disabled="activeAction" @click="openUpload(document)">
                Replace
              </button>
              <details class="more-menu">
                <summary>More</summary>
                <button class="danger" type="button" :disabled="activeAction" @click="deleteDocumentFile(document)">
                  {{ activeAction === `delete-${document.id}` ? 'Deleting…' : 'Delete File' }}
                </button>
              </details>
            </template>
            <button v-else-if="document.id" class="primary" type="button" :disabled="activeAction" @click="openUpload(document)">
              Upload
            </button>
            <button v-if="document.id && document.has_file && document.verification_status === 'pending'" class="primary" type="button" :disabled="activeAction" @click="openRequirementAction('verify', document, $event)">Verify</button>
            <button v-if="document.id && document.has_file && document.verification_status === 'pending'" type="button" :disabled="activeAction" @click="openRequirementAction('needs-resubmission', document, $event)">Needs Resubmission</button>
            <button v-if="document.physical_status === 'not_required'" type="button" :disabled="activeAction" @click="openRequirementAction('require', document, $event)">Require Hard Copy</button>
            <button v-if="document.physical_status === 'required'" class="primary" type="button" :disabled="activeAction" @click="openRequirementAction('receive', document, $event)">Mark Hard Copy Received</button>
            <button v-if="document.physical_status === 'submitted'" type="button" :disabled="activeAction" @click="openRequirementAction('reopen', document, $event)">Require Again</button>
          </div>

        </div>
      </article>
    </section>
    <footer class="back-bar">
      <button type="button" @click="router.push({ name: 'student-details', params: { id: student.id } })">
        ← Back to Student Profile
      </button>
    </footer>
  </template>

  <div v-if="uploadDocument" class="modal-backdrop" role="presentation" @mousedown.self="closeUpload">
    <section class="upload-modal" role="dialog" aria-modal="true" aria-labelledby="upload-title">
      <div class="modal-heading">
        <div>
          <p class="page-kicker">Student document</p>
          <h2 id="upload-title">{{ isReplacing ? 'Replace' : 'Upload' }} {{ uploadDocument.name }}</h2>
        </div>
        <button class="close-button" type="button" :disabled="uploading" aria-label="Close upload dialog" @click="closeUpload">
          ×
        </button>
      </div>
      <p class="upload-help">Accepted formats: JPEG, PNG, or PDF. Maximum file size: {{ MAX_FILE_SIZE_MB }} MB.</p>
      <form @submit.prevent="submitUpload">
        <label class="file-picker">
          <span>Select file</span>
          <input
            ref="fileInput"
            type="file"
            accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf"
            :disabled="uploading"
            @change="selectFile"
          />
        </label>
        <p class="selected-file"><strong>Selected:</strong> {{ selectedFile?.name || 'No file selected' }}</p>
        <div v-if="uploading || uploadProgress" class="progress" :aria-label="`Upload progress: ${uploadProgress}%`">
          <span :style="{ width: `${uploadProgress}%` }"></span>
        </div>
        <p v-if="uploading" class="upload-state" role="status">Uploading… {{ uploadProgress }}%</p>
        <p v-if="uploadError" class="modal-notice error" role="alert">{{ uploadError }}</p>
        <p v-if="uploadSuccess" class="modal-notice success" role="status">{{ uploadSuccess }}</p>
        <div class="modal-actions">
          <button type="button" :disabled="uploading" @click="closeUpload">{{ uploadSuccess ? 'Done' : 'Cancel' }}</button>
          <button class="primary" type="submit" :disabled="uploading || !selectedFile">
            {{ uploading ? 'Uploading…' : isReplacing ? 'Replace File' : 'Upload File' }}
          </button>
        </div>
      </form>
    </section>
  </div>

  <div v-if="requirementAction" class="modal-backdrop" role="presentation" @mousedown.self="closeRequirementAction">
    <section class="upload-modal" role="dialog" aria-modal="true" aria-labelledby="requirement-action-title">
      <div class="modal-heading"><div><p class="page-kicker">Student Record requirement</p><h2 id="requirement-action-title">{{ actionTitle }}</h2></div><button class="close-button" type="button" aria-label="Close requirement dialog" :disabled="activeAction" @click="closeRequirementAction">×</button></div>
      <p><strong>{{ requirementAction.document.name }}</strong> for {{ student?.student_number }} · {{ student?.full_name }}</p>
      <label v-if="['needs-resubmission', 'reopen'].includes(requirementAction.action)" class="file-picker"><span>Reason (required)</span><textarea v-model.trim="requirementNote" maxlength="1000" /></label>
      <label v-else-if="requirementAction.action === 'require'" class="file-picker"><span>Instruction (optional)</span><textarea v-model.trim="requirementNote" maxlength="1000" placeholder="Please submit the physical/original copy to the Registrar Office." /></label>
      <p v-else>Confirm this action. Digital verification and physical-copy receipt remain separate.</p>
      <div class="modal-actions"><button type="button" :disabled="activeAction" @click="closeRequirementAction">Cancel</button><button class="primary" type="button" :disabled="activeAction || (['needs-resubmission', 'reopen'].includes(requirementAction.action) && !requirementNote)" @click="submitRequirementAction">{{ activeAction ? 'Saving…' : 'Confirm' }}</button></div>
    </section>
  </div>

  <Teleport to="body">
    <div
      v-if="viewerDocument"
      class="image-viewer-backdrop"
      role="presentation"
      @mousedown.self="closeImageViewer"
      @keydown="handleViewerKeydown"
    >
      <section
        class="image-viewer"
        role="dialog"
        aria-modal="true"
        :aria-label="`${viewerDocument.name} full-screen preview`"
      >
        <div class="image-viewer-heading">
          <strong>{{ viewerDocument.name }}</strong>
          <button ref="viewerCloseButton" type="button" aria-label="Close full-screen preview" @click="closeImageViewer">×</button>
        </div>
        <img
          :src="previewStates[viewerDocument.id]?.url"
          :alt="`${viewerDocument.name} full-size document preview`"
        />
      </section>
    </div>
  </Teleport>
</template>

<style scoped>
.panel,
.summary,
.document-card {
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: 10px;
  box-shadow: var(--shadow-soft);
  margin-bottom: 20px;
  padding: 22px;
}
.notice,
.modal-notice {
  border-radius: 7px;
  padding: 11px 13px;
}
.notice { margin: 0 0 18px; }
.success { background: #e2f4e7; color: #106a2e; }
.error { background: #fce8e8; color: #9c2222; }
.summary {
  display: grid;
  gap: 14px;
  grid-template-columns: repeat(5, minmax(0, 1fr));
}
.summary p,
dt {
  color: var(--color-muted);
  font-size: 0.75rem;
  font-weight: 700;
  margin: 0 0 5px;
  text-transform: uppercase;
}
.summary strong { font-size: 0.92rem; overflow-wrap: anywhere; }
.badge {
  border-radius: 999px;
  display: inline-block;
  font-size: 0.78rem;
  font-weight: 700;
  padding: 5px 9px;
}
.badge,
.badge.available { background: var(--color-green-tint); color: var(--color-dartmouth-green); }
.badge.missing { background: #fee2e2; color: #991b1b; }
.documents { display: grid; gap: 18px; min-width: 0; }
.document-card {
  display: grid;
  gap: 22px;
  grid-template-columns: clamp(180px, 20vw, 230px) minmax(0, 1fr);
  margin: 0;
  min-width: 0;
}
.document-information { min-width: 0; }
.document-preview {
  align-self: start;
  aspect-ratio: 4 / 5;
  background: var(--color-anti-flash-white);
  border: 1px solid var(--color-border);
  border-radius: 9px;
  display: grid;
  min-height: 220px;
  overflow: hidden;
  place-items: stretch;
}
.preview-placeholder,
.image-preview-button {
  align-items: center;
  background: transparent;
  border: 0;
  color: var(--color-muted);
  display: flex;
  flex-direction: column;
  gap: 9px;
  justify-content: center;
  min-height: 100%;
  padding: 18px;
  text-align: center;
  width: 100%;
}
.image-preview-button,
.file-preview-button { cursor: pointer; }
.image-preview-button img {
  height: 100%;
  inset: 0;
  object-fit: contain;
  position: absolute;
  width: 100%;
}
.image-preview-button {
  overflow: hidden;
  padding: 0;
  position: relative;
}
.image-preview-button span {
  background: rgba(9, 28, 19, 0.82);
  bottom: 0;
  color: #fff;
  font-size: 0.76rem;
  font-weight: 800;
  left: 0;
  padding: 8px;
  position: absolute;
  right: 0;
}
.preview-icon {
  align-items: center;
  border: 2px solid currentColor;
  border-radius: 50%;
  display: inline-flex;
  font-size: 1.25rem;
  height: 42px;
  justify-content: center;
  width: 42px;
}
.preview-file-type {
  background: #991b1b;
  border-radius: 5px;
  color: #fff;
  font-size: 0.75rem;
  font-weight: 900;
  padding: 7px 9px;
}
.preview-error button {
  background: var(--color-dartmouth-green);
  border: 0;
  border-radius: 6px;
  color: #fff;
  cursor: pointer;
  padding: 7px 12px;
}
.preview-spinner {
  animation: preview-spin 800ms linear infinite;
  border: 3px solid var(--color-border);
  border-radius: 50%;
  border-top-color: var(--color-dartmouth-green);
  height: 30px;
  width: 30px;
}
@keyframes preview-spin { to { transform: rotate(360deg); } }
.doc-head,
.analysis-heading,
.modal-heading {
  align-items: center;
  display: flex;
  gap: 12px;
  justify-content: space-between;
}
.doc-head h2,
.analysis-heading h3,
.modal-heading h2 { margin: 0; }
.doc-head h2 { font-size: 1.1rem; }
.file-state { color: var(--color-muted); font-size: 0.82rem; margin: 4px 0 0; }
.document-information > dl,
.analysis-facts {
  display: grid;
  gap: 12px;
  grid-template-columns: repeat(3, minmax(0, 1fr));
}
.document-information > dl > div,
.analysis-facts > div {
  background: var(--color-anti-flash-white);
  border-radius: 6px;
  padding: 10px;
}
.document-card dd { margin: 0; overflow-wrap: anywhere; }
.remarks { grid-column: span 2; }
.document-actions,
.back-bar,
.modal-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 16px;
}
.document-actions button,
.document-actions summary,
.back-bar button,
.modal-actions button,
.close-button {
  background: var(--color-anti-flash-white);
  border: 0;
  border-radius: 6px;
  color: var(--color-dartmouth-green);
  cursor: pointer;
  min-height: 38px;
  padding: 8px 12px;
}
.document-actions .primary,
.back-bar button,
.modal-actions .primary { background: var(--color-dartmouth-green); color: #fff; }
.document-actions button:disabled,
.modal-actions button:disabled,
.close-button:disabled { cursor: not-allowed; opacity: 0.55; }
.more-menu { position: relative; }
.more-menu summary { align-items: center; display: flex; list-style: none; }
.more-menu summary::-webkit-details-marker { display: none; }
.more-menu .danger { background: #fff; border: 1px solid #fecaca; color: #991b1b; margin-top: 5px; }
.state { color: var(--color-muted); text-align: center; }
.state h2 { color: var(--color-eerie-black); margin-top: 0; }
.modal-backdrop {
  align-items: center;
  background: rgba(16, 24, 20, 0.58);
  display: flex;
  inset: 0;
  justify-content: center;
  padding: 18px;
  position: fixed;
  z-index: 1000;
}
.upload-modal {
  background: var(--color-surface);
  border-radius: 12px;
  box-shadow: 0 24px 60px rgba(0, 0, 0, 0.24);
  max-width: 540px;
  padding: 24px;
  width: 100%;
}
.image-viewer-backdrop {
  align-items: center;
  background: rgba(3, 9, 6, 0.92);
  display: flex;
  inset: 0;
  justify-content: center;
  padding: 20px;
  position: fixed;
  z-index: 1200;
}
.image-viewer {
  display: flex;
  flex-direction: column;
  height: min(92vh, 1000px);
  max-width: 1400px;
  min-width: 0;
  width: min(94vw, 1400px);
}
.image-viewer-heading {
  align-items: center;
  color: #fff;
  display: flex;
  gap: 16px;
  justify-content: space-between;
  padding: 0 0 12px;
}
.image-viewer-heading button {
  align-items: center;
  background: rgba(255, 255, 255, 0.12);
  border: 1px solid rgba(255, 255, 255, 0.3);
  border-radius: 50%;
  color: #fff;
  cursor: pointer;
  display: inline-flex;
  font-size: 1.55rem;
  height: 42px;
  justify-content: center;
  width: 42px;
}
.image-viewer img {
  background: rgba(255, 255, 255, 0.04);
  border-radius: 10px;
  height: calc(100% - 54px);
  object-fit: contain;
  width: 100%;
}
.close-button { color: var(--color-muted); font-size: 1.5rem; padding: 2px 10px; }
.upload-help,
.selected-file,
.upload-state { color: var(--color-muted); font-size: 0.86rem; }
.file-picker { display: grid; font-size: 0.82rem; font-weight: 700; gap: 7px; margin-top: 18px; }
.file-picker input { border: 1px dashed var(--color-border); border-radius: 8px; padding: 13px; }
.progress { background: var(--color-anti-flash-white); border-radius: 999px; height: 8px; overflow: hidden; }
.progress span { background: var(--color-dartmouth-green); display: block; height: 100%; transition: width 180ms ease; }
.modal-notice { margin: 12px 0 0; }
.modal-actions { justify-content: flex-end; }
@media (max-width: 850px) {
  .summary,
  .document-information > dl { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .document-card { grid-template-columns: minmax(160px, 200px) minmax(0, 1fr); }
}
@media (max-width: 700px) {
  .document-card { grid-template-columns: 1fr; }
  .document-preview { aspect-ratio: 16 / 10; min-height: 190px; width: 100%; }
}
@media (max-width: 500px) {
  .summary,
  .document-information > dl { grid-template-columns: 1fr; }
  .panel,
  .summary,
  .document-card,
  .upload-modal { padding: 16px; }
  .remarks { grid-column: auto; }
  .document-actions > button,
  .back-bar button,
  .modal-actions button { flex: 1; }
  .doc-head { align-items: flex-start; }
}
</style>
