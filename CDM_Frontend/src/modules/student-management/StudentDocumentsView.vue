<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
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
const bulkSubmitting = ref(false)
const refreshingAnalyses = ref(false)
const canManageDocuments = computed(() => authStore.currentRole === ROLES.REGISTRAR_STAFF)
const isReplacing = computed(() => Boolean(uploadDocument.value?.has_file))
const activeAnalysisStatuses = ['pending', 'processing']
let analysisPollTimer = null

const aiSummary = computed(() => ({
  uploaded: documents.value.filter((document) => document.has_file).length,
  pending: documents.value.filter((document) => document.ai_analysis?.status === 'pending').length,
  processing: documents.value.filter((document) => document.ai_analysis?.status === 'processing').length,
  completed: documents.value.filter((document) => document.ai_analysis?.status === 'completed').length,
  failedOrNeedsReview: documents.value.filter(
    (document) =>
      document.ai_analysis?.status === 'failed' ||
      ['needs_review', 'likely_incorrect'].includes(document.ai_analysis?.recommendation),
  ).length,
}))
const bulkEligibleDocuments = computed(() =>
  documents.value.filter(
    (document) =>
      document.has_file &&
      document.is_ai_analysis_eligible &&
      !activeAnalysisStatuses.includes(document.ai_analysis?.status) &&
      document.ai_analysis?.status !== 'completed',
  ),
)
const hasActiveAnalyses = computed(() =>
  documents.value.some((document) => activeAnalysisStatuses.includes(document.ai_analysis?.status)),
)
const supportedDocumentLabels = computed(() => [
  ...new Set(
    documents.value
      .map((document) => document.ai_analysis_type?.label)
      .filter(Boolean),
  ),
])

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
const confidence = (value) =>
  value === null || value === undefined ? 'Not available' : `${(Number(value) * 100).toFixed(1)}%`
const checkEntries = (checks) => Object.entries(checks || {})
const checkLabel = (check, passed) => {
  const labels = {
    document_type_match: ['Document Type Matches', 'Document Type Does Not Match'],
    name_match: ['Student Name Matches', 'Student Name Needs Review'],
    date_of_birth_match: ['Date of Birth Matches', 'Date of Birth Needs Review'],
    student_number_match: ['Student Number Matches', 'Student Number Needs Review'],
    course_match: ['Course Matches', 'Course Needs Review'],
    year_level_match: ['Year Level Matches', 'Year Level Needs Review'],
    school_document_structure_present: ['Academic Record Structure Detected', 'Academic Record Structure Needs Review'],
    issuer_present: ['School / Issuer Detected', 'School / Issuer Needs Review'],
    readable: ['Document Is Readable', 'Readability Needs Review'],
    development_mock: ['Development Mock Check', 'Development Mock Needs Review'],
  }

  return labels[check]?.[passed === true ? 0 : 1] || title(check)
}

async function loadDocuments({ silent = false } = {}) {
  if (silent && refreshingAnalyses.value) return

  if (silent) refreshingAnalyses.value = true
  else {
    loading.value = true
    error.value = ''
  }

  try {
    const { data } = await apiClient.get(`/students/${route.params.id}/documents`)
    student.value = data.data.student
    documents.value = data.data.documents
  } catch (requestError) {
    if (!silent) {
      error.value =
        requestError.response?.status === 404
          ? 'This student record could not be found.'
          : requestError.response?.data?.message || 'Unable to load student documents.'
    }
  } finally {
    if (silent) refreshingAnalyses.value = false
    else loading.value = false
  }
}

function stopAnalysisPolling() {
  if (analysisPollTimer) window.clearInterval(analysisPollTimer)
  analysisPollTimer = null
}

function startAnalysisPolling() {
  if (analysisPollTimer) return
  analysisPollTimer = window.setInterval(() => loadDocuments({ silent: true }), 4_000)
}

async function analyzeAllDocuments() {
  if (!bulkEligibleDocuments.value.length || bulkSubmitting.value) return

  const eligibleIds = new Set(bulkEligibleDocuments.value.map((document) => document.id))
  bulkSubmitting.value = true
  actionError.value = ''
  success.value = ''

  try {
    const { data } = await apiClient.post(`/registrar/students/${student.value.id}/documents/analyze-all`)

    documents.value = documents.value.map((document) =>
      eligibleIds.has(document.id)
        ? {
            ...document,
            ai_analysis: {
              status: 'pending',
              detected_document_type: null,
              confidence: null,
              checks: null,
              issues: null,
              recommendation: null,
              provider: null,
              model: null,
              is_mock: false,
              analyzed_at: null,
            },
          }
        : document,
    )
    success.value = data.message
    await loadDocuments({ silent: true })
  } catch (requestError) {
    actionError.value = requestError.response?.data?.message || 'Unable to queue document analysis.'
  } finally {
    bulkSubmitting.value = false
  }
}

function replaceDocument(updatedDocument) {
  const index = documents.value.findIndex((document) => document.id === updatedDocument.id)
  if (index !== -1) documents.value.splice(index, 1, updatedDocument)
}

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
    success.value = data.message
  } catch (requestError) {
    if (isStepUpCancelled(requestError)) return
    actionError.value = requestError.response?.data?.message || 'Unable to delete the student document file.'
  } finally {
    activeAction.value = null
  }
}

watch(hasActiveAnalyses, (active) => {
  if (active) startAnalysisPolling()
  else stopAnalysisPolling()
})
watch(() => route.params.id, () => {
  stopAnalysisPolling()
  loadDocuments()
})
onMounted(loadDocuments)
onBeforeUnmount(stopAnalysisPolling)
</script>

<template>
  <section class="page-header">
    <p class="page-kicker">Registrar / Student Management</p>
    <h1 class="page-title">Student Documents</h1>
    <p class="page-description">
      {{
        canManageDocuments
          ? 'Manage securely stored student files and review saved analysis results.'
          : 'Review student document records.'
      }}
    </p>
  </section>
  <p v-if="success" class="notice success" role="status">{{ success }}</p>
  <p v-if="actionError" class="notice error" role="alert">{{ actionError }}</p>
  <section v-if="loading" class="panel state">Loading student documents…</section>
  <section v-else-if="error" class="panel state error" role="alert">
    {{ error }}
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
    <section v-if="canManageDocuments" class="ai-overview">
      <div class="ai-overview-heading">
        <div>
          <p class="analysis-kicker">AI document analysis</p>
          <h2>Analyze All Documents</h2>
          <p>
            Currently supports {{ supportedDocumentLabels.join(', ') || 'configured document types' }}.
            Missing and unsupported documents are skipped.
          </p>
        </div>
        <button
          class="analyze-all-button"
          type="button"
          :disabled="bulkSubmitting || !bulkEligibleDocuments.length"
          :title="!bulkEligibleDocuments.length ? 'No supported documents are ready for analysis.' : ''"
          @click="analyzeAllDocuments"
        >
          {{ bulkSubmitting ? 'Queueing…' : bulkEligibleDocuments.length ? 'Analyze All Documents' : 'No Documents to Analyze' }}
        </button>
      </div>
      <div class="ai-summary" aria-label="AI analysis summary">
        <div><span>Uploaded documents</span><strong>{{ aiSummary.uploaded }}</strong></div>
        <div><span>Pending</span><strong>{{ aiSummary.pending }}</strong></div>
        <div><span>Processing</span><strong>{{ aiSummary.processing }}</strong></div>
        <div><span>Completed</span><strong>{{ aiSummary.completed }}</strong></div>
        <div><span>Failed / Needs Review</span><strong>{{ aiSummary.failedOrNeedsReview }}</strong></div>
      </div>
      <p v-if="hasActiveAnalyses" class="polling-note" role="status">
        Analysis is running in the background. Status refreshes automatically.
      </p>
    </section>
    <section v-if="!documents.length" class="panel state">
      <h2>No document records</h2>
      <p>There are no student document records available yet.</p>
    </section>
    <section v-else class="documents">
      <article v-for="document in documents" :key="document.id" class="document-card">
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
        </dl>

        <div v-if="canManageDocuments" class="document-actions">
          <template v-if="document.has_file">
            <button type="button" :disabled="activeAction" @click="viewDocument(document)">
              {{ activeAction === `view-${document.id}` ? 'Opening…' : 'View' }}
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
          <button v-else class="primary" type="button" :disabled="activeAction" @click="openUpload(document)">
            Upload
          </button>
        </div>

        <section v-if="document.is_ai_analysis_eligible && document.has_file" class="ai-analysis">
          <div class="analysis-heading">
            <div>
              <p class="analysis-kicker">AI Analysis</p>
              <h3 v-if="document.ai_analysis?.status === 'completed'">
                {{ document.ai_analysis.is_mock ? 'Development Mock Result' : 'Stored Analysis Result' }}
              </h3>
              <h3 v-else-if="document.ai_analysis?.status === 'failed'">Analysis failed</h3>
              <h3 v-else-if="document.ai_analysis?.status === 'processing'">Analyzing document…</h3>
              <h3 v-else>Pending analysis…</h3>
            </div>
            <span v-if="document.ai_analysis?.status" class="analysis-status" :class="document.ai_analysis.status">
              {{ title(document.ai_analysis.status) }}
            </span>
          </div>

          <template v-if="document.ai_analysis?.status === 'completed'">
            <p v-if="document.ai_analysis.is_mock" class="mock-warning">
              Mock analysis does not inspect the real uploaded document.
            </p>
            <dl class="analysis-facts">
              <div>
                <dt>Provider</dt>
                <dd>{{ title(document.ai_analysis.provider) }}</dd>
              </div>
              <div>
                <dt>Detected Type</dt>
                <dd>{{ document.ai_analysis.detected_document_type || 'Not detected' }}</dd>
              </div>
              <div>
                <dt>Confidence</dt>
                <dd>{{ confidence(document.ai_analysis.confidence) }}</dd>
              </div>
              <div>
                <dt>Recommendation</dt>
                <dd>{{ title(document.ai_analysis.recommendation) }}</dd>
              </div>
            </dl>
            <div v-if="checkEntries(document.ai_analysis.checks).length" class="checks">
              <strong>Checks</strong>
              <ul>
                <li v-for="([check, passed]) in checkEntries(document.ai_analysis.checks)" :key="check">
                  <span :class="passed ? 'check-pass' : 'check-fail'">{{ passed ? '✓' : '!' }}</span>
                  {{ checkLabel(check, passed) }}
                </li>
              </ul>
            </div>
          </template>
          <p v-else-if="document.ai_analysis?.status === 'failed'" class="analysis-message">
            Registrar can still review the document manually.
          </p>
          <p v-else class="analysis-message">The saved analysis status will update after the background worker finishes.</p>
        </section>
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
</template>

<style scoped>
.panel,
.summary,
.ai-overview,
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
.ai-overview { background: linear-gradient(135deg, #f4faf6, #fffdf3); }
.ai-overview-heading {
  align-items: center;
  display: flex;
  gap: 20px;
  justify-content: space-between;
}
.ai-overview-heading h2 { font-size: 1.15rem; margin: 0; }
.ai-overview-heading p:not(.analysis-kicker) { color: var(--color-muted); margin: 5px 0 0; }
.analyze-all-button {
  background: var(--color-dartmouth-green);
  border: 0;
  border-radius: 7px;
  color: #fff;
  cursor: pointer;
  font-weight: 800;
  min-height: 42px;
  padding: 10px 16px;
}
.analyze-all-button:disabled { cursor: not-allowed; opacity: 0.55; }
.ai-summary {
  display: grid;
  gap: 10px;
  grid-template-columns: repeat(5, minmax(0, 1fr));
  margin-top: 16px;
}
.ai-summary div {
  background: rgba(255, 255, 255, 0.78);
  border: 1px solid var(--color-border);
  border-radius: 7px;
  display: flex;
  flex-direction: column;
  gap: 4px;
  padding: 10px;
}
.ai-summary span { color: var(--color-muted); font-size: 0.72rem; font-weight: 700; text-transform: uppercase; }
.ai-summary strong { color: var(--color-dartmouth-green); font-size: 1.15rem; }
.polling-note { color: var(--color-muted); font-size: 0.8rem; margin: 12px 0 0; }
.badge,
.analysis-status {
  border-radius: 999px;
  display: inline-block;
  font-size: 0.78rem;
  font-weight: 700;
  padding: 5px 9px;
}
.badge,
.badge.available,
.analysis-status.completed { background: var(--color-green-tint); color: var(--color-dartmouth-green); }
.badge.missing,
.analysis-status.failed { background: #fee2e2; color: #991b1b; }
.analysis-status.pending { background: #fff4cc; color: #8a6100; }
.analysis-status.processing { background: #e0f2fe; color: #075985; }
.documents { display: grid; gap: 18px; }
.document-card { margin: 0; }
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
.document-card > dl,
.analysis-facts {
  display: grid;
  gap: 12px;
  grid-template-columns: repeat(3, minmax(0, 1fr));
}
.document-card > dl > div,
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
.ai-analysis {
  background: linear-gradient(135deg, #f4faf6, #fffdf3);
  border: 1px solid var(--color-border);
  border-radius: 9px;
  margin-top: 18px;
  padding: 16px;
}
.analysis-kicker {
  color: var(--color-dark-spring-green);
  font-size: 0.72rem;
  font-weight: 800;
  letter-spacing: 0.09em;
  margin: 0 0 3px;
  text-transform: uppercase;
}
.analysis-heading h3 { font-size: 1rem; }
.mock-warning {
  background: #fff4cc;
  border-left: 4px solid #d69e00;
  color: #684b00;
  font-weight: 700;
  margin: 14px 0;
  padding: 10px 12px;
}
.analysis-facts { grid-template-columns: repeat(4, minmax(0, 1fr)); margin: 14px 0; }
.checks strong { font-size: 0.82rem; text-transform: uppercase; }
.checks ul { display: grid; gap: 7px; list-style: none; margin: 9px 0 0; padding: 0; }
.checks li { align-items: center; display: flex; gap: 8px; }
.check-pass,
.check-fail {
  align-items: center;
  border-radius: 50%;
  display: inline-flex;
  font-size: 0.72rem;
  font-weight: 900;
  height: 20px;
  justify-content: center;
  width: 20px;
}
.check-pass { background: #dcfce7; color: #166534; }
.check-fail { background: #fee2e2; color: #991b1b; }
.analysis-message { color: var(--color-muted); margin: 12px 0 0; }
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
  .ai-summary,
  .document-card > dl,
  .analysis-facts { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
@media (max-width: 500px) {
  .summary,
  .ai-summary,
  .document-card > dl,
  .analysis-facts { grid-template-columns: 1fr; }
  .panel,
  .summary,
  .document-card,
  .upload-modal { padding: 16px; }
  .remarks { grid-column: auto; }
  .document-actions > button,
  .back-bar button,
  .modal-actions button { flex: 1; }
  .doc-head,
  .analysis-heading,
  .ai-overview-heading { align-items: flex-start; }
  .ai-overview-heading { flex-direction: column; }
  .analyze-all-button { width: 100%; }
}
</style>
