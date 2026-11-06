<script setup>
import { computed, nextTick, onBeforeUnmount, ref } from 'vue'
import { apiClient } from '../../services/apiClient'

const props = defineProps({
  attention: { type: Object, required: true },
})
const emit = defineEmits(['refresh'])

const search = ref('')
const statusFilter = ref('all')
const selectedStudent = ref(null)
const selectedDocumentTypeId = ref(null)
const reviewLoading = ref(false)
const reviewError = ref('')
const reviewSuccess = ref('')
const studentDetails = ref(null)
const studentDocuments = ref([])
const previewState = ref({ status: 'idle', url: null })
const activeAction = ref('')
const pendingAction = ref(null)
const actionNote = ref('')
const reviewClose = ref(null)
let reviewTrigger = null

const statusOptions = [
  ['all', 'All attention'],
  ['digital_missing', 'Missing'],
  ['awaiting_review', 'Awaiting review'],
  ['needs_resubmission', 'Needs resubmission'],
  ['hard_copy_required', 'Hard copy required'],
]

const documentTypes = computed(() => {
  if (props.attention.document_types?.length) return props.attention.document_types
  const types = new Map()
  for (const item of props.attention.items || []) {
    types.set(item.document_type_id, { id: item.document_type_id, name: item.document_type })
  }
  return [...types.values()]
})

const matrixStudents = computed(() => {
  if (props.attention.matrix_students?.length) return props.attention.matrix_students
  const grouped = new Map()
  for (const item of props.attention.items || []) {
    const row = grouped.get(item.student_id) || {
      student_id: item.student_id,
      student_number: item.student_number,
      student_name: item.student_name,
      requirements: [],
    }
    row.requirements.push(item)
    grouped.set(item.student_id, row)
  }
  return [...grouped.values()]
})

const filteredStudents = computed(() => {
  const query = search.value.trim().toLowerCase()
  return matrixStudents.value.filter((student) => {
    const matchesSearch = !query || `${student.student_number} ${student.student_name}`.toLowerCase().includes(query)
    const matchesStatus = statusFilter.value === 'all' || student.requirements.some(
      requirement => requirement.attention_reasons?.includes(statusFilter.value),
    )
    return matchesSearch && matchesStatus
  })
})
const matrixStudentTotal = computed(() => props.attention.requirements_students_total ?? matrixStudents.value.length)

const summary = computed(() => {
  const requirements = matrixStudents.value.flatMap(student => student.requirements || [])
  const count = reason => requirements.filter(item => item.attention_reasons?.includes(reason)).length
  return [
    { label: 'Students needing attention', value: matrixStudentTotal.value, icon: 'ST' },
    { label: 'Missing documents', value: count('digital_missing'), icon: '!' },
    { label: 'Awaiting review', value: count('awaiting_review'), icon: '◷' },
    { label: 'Other unresolved', value: requirements.filter(item => item.attention_reasons?.some(reason => ['needs_resubmission', 'hard_copy_required'].includes(reason))).length, icon: '•' },
  ]
})

const selectedDocument = computed(() => studentDocuments.value.find(
  document => Number(document.document_type_id) === Number(selectedDocumentTypeId.value),
) || null)

const selectedRequirement = computed(() => selectedStudent.value?.requirements?.find(
  requirement => Number(requirement.document_type_id) === Number(selectedDocumentTypeId.value),
) || null)

const actionTitle = computed(() => ({
  verify: 'Verify digital copy',
  'needs-resubmission': 'Request resubmission',
  require: 'Require hard copy',
  receive: 'Mark hard copy received',
  reopen: 'Require hard copy again',
}[pendingAction.value] || 'Update requirement'))

const requiresActionNote = computed(() => ['needs-resubmission', 'reopen'].includes(pendingAction.value))
const displayStatus = value => String(value || 'not available').replaceAll('_', ' ').replace(/\b\w/g, letter => letter.toUpperCase())
const requirementFor = (student, documentTypeId) => student.requirements?.find(
  requirement => Number(requirement.document_type_id) === Number(documentTypeId),
) || null

function primaryStatus(requirement) {
  if (!requirement) return 'not_required'
  if (requirement.digital_status === 'missing') return 'missing'
  if (requirement.digital_status === 'needs_resubmission') return 'needs_resubmission'
  if (['submitted', 'under_review'].includes(requirement.digital_status)) return 'awaiting_review'
  if (requirement.physical_status === 'required') return 'hard_copy_required'
  if (requirement.digital_status === 'verified') return 'accepted'
  return 'not_required'
}

function statusPresentation(requirement) {
  const status = primaryStatus(requirement)
  return {
    status,
    icon: {
      missing: '✕', awaiting_review: '◷', needs_resubmission: '↻',
      hard_copy_required: '↓', accepted: '✓', not_required: '—',
    }[status],
    label: {
      missing: 'Missing', awaiting_review: 'Awaiting review', needs_resubmission: 'Needs resubmission',
      hard_copy_required: 'Hard copy required', accepted: 'Accepted', not_required: 'Not required',
    }[status],
  }
}

function statusTooltip(requirement) {
  const presentation = statusPresentation(requirement)
  if (!requirement) return presentation.label
  return `${presentation.label}. Digital: ${displayStatus(requirement.digital_status)}. Physical: ${requirement.physical_status === 'submitted' ? 'Received' : displayStatus(requirement.physical_status)}.`
}

function revokePreview() {
  if (previewState.value.url) URL.revokeObjectURL(previewState.value.url)
  previewState.value = { status: 'idle', url: null }
}

async function loadPreview(document) {
  revokePreview()
  if (!document?.has_file || document.file_kind !== 'image') return
  previewState.value = { status: 'loading', url: null }
  try {
    const response = await apiClient.get(`/registrar/student-documents/${document.id}/view`, { responseType: 'blob' })
    if (!String(response.data?.type || '').startsWith('image/')) throw new Error('Unsupported preview')
    previewState.value = { status: 'ready', url: URL.createObjectURL(response.data) }
  } catch {
    previewState.value = { status: 'error', url: null }
  }
}

async function selectDocument(documentTypeId) {
  selectedDocumentTypeId.value = documentTypeId
  pendingAction.value = null
  actionNote.value = ''
  reviewError.value = ''
  await loadPreview(selectedDocument.value)
}

async function loadStudentDocuments(student, documentTypeId) {
  reviewLoading.value = true
  reviewError.value = ''
  reviewSuccess.value = ''
  revokePreview()
  try {
    const { data } = await apiClient.get(`/students/${student.student_id}/documents`)
    studentDetails.value = data.data.student
    const stored = new Map(data.data.documents.map(document => [Number(document.document_type_id), document]))
    studentDocuments.value = data.data.requirements.map(requirement => ({
      ...requirement,
      ...(stored.get(Number(requirement.document_type_id)) || {}),
    }))
    await selectDocument(documentTypeId || studentDocuments.value.find(document => document.unresolved)?.document_type_id || studentDocuments.value[0]?.document_type_id)
  } catch (error) {
    reviewError.value = error.response?.data?.message || 'Unable to load this student\'s document requirements.'
  } finally {
    reviewLoading.value = false
  }
}

async function openReview(student, documentTypeId, event) {
  reviewTrigger = event?.currentTarget || document.activeElement
  selectedStudent.value = student
  selectedDocumentTypeId.value = documentTypeId
  await loadStudentDocuments(student, documentTypeId)
  await nextTick()
  reviewClose.value?.focus()
}

function closeReview() {
  selectedStudent.value = null
  studentDetails.value = null
  studentDocuments.value = []
  selectedDocumentTypeId.value = null
  reviewError.value = ''
  reviewSuccess.value = ''
  pendingAction.value = null
  revokePreview()
  nextTick(() => reviewTrigger?.focus?.())
}

async function openStoredDocument(document) {
  if (!document?.has_file) return
  if (document.file_kind === 'image') {
    if (previewState.value.status !== 'ready') await loadPreview(document)
    if (previewState.value.url) {
      const popup = window.open(previewState.value.url, '_blank')
      if (popup) popup.opener = null
    }
    return
  }
  const popup = window.open('', '_blank')
  if (popup) popup.opener = null
  activeAction.value = `view-${document.id}`
  reviewError.value = ''
  try {
    const response = await apiClient.get(`/registrar/student-documents/${document.id}/view`, { responseType: 'blob' })
    const url = URL.createObjectURL(response.data)
    if (popup) popup.location.href = url
    else window.location.href = url
    window.setTimeout(() => URL.revokeObjectURL(url), 60_000)
  } catch (error) {
    popup?.close()
    reviewError.value = error.response?.data?.message || 'Unable to open this document.'
  } finally {
    activeAction.value = ''
  }
}

function beginAction(action) {
  pendingAction.value = action
  actionNote.value = action === 'require' ? (selectedDocument.value?.physical_copy_instruction || '') : ''
  reviewError.value = ''
  reviewSuccess.value = ''
}

async function confirmAction() {
  const document = selectedDocument.value
  if (!document || !pendingAction.value || (requiresActionNote.value && !actionNote.value.trim())) return
  const action = pendingAction.value
  activeAction.value = action
  reviewError.value = ''
  reviewSuccess.value = ''
  try {
    const digital = ['verify', 'needs-resubmission'].includes(action)
    const url = digital
      ? `/student-documents/${document.id}/${action}`
      : `/students/${selectedStudent.value.student_id}/record-requirements/${document.document_type_id}/${action}`
    const payload = digital
      ? { version: document.version, ...(action === 'needs-resubmission' ? { reason: actionNote.value.trim() } : {}) }
      : action === 'require'
        ? { instruction: actionNote.value.trim() || null }
        : action === 'reopen'
          ? { reason: actionNote.value.trim() }
          : {}
    const { data } = await apiClient.patch(url, payload)
    reviewSuccess.value = data.message
    pendingAction.value = null
    actionNote.value = ''
    await loadStudentDocuments(selectedStudent.value, document.document_type_id)
    reviewSuccess.value = data.message
    emit('refresh')
  } catch (error) {
    const message = error.response?.data?.message || Object.values(error.response?.data?.errors || {}).flat()[0] || 'Unable to update this requirement.'
    if (error.response?.status === 409) await loadStudentDocuments(selectedStudent.value, document.document_type_id)
    reviewError.value = message
  } finally {
    activeAction.value = ''
  }
}

onBeforeUnmount(revokePreview)
</script>

<template>
  <div class="requirements-workspace" :class="{ 'has-review': selectedStudent }">
    <section class="requirements-main" aria-label="Student document requirements matrix">
      <div class="requirements-summary" aria-label="Document requirement summary">
        <article v-for="metric in summary" :key="metric.label">
          <span aria-hidden="true">{{ metric.icon }}</span>
          <div><strong>{{ metric.value }}</strong><small>{{ metric.label }}</small></div>
        </article>
      </div>

      <div class="requirements-toolbar">
        <label class="matrix-search">
          <span class="sr-only">Search students</span>
          <span aria-hidden="true">⌕</span>
          <input v-model.trim="search" type="search" placeholder="Search student number or name" />
        </label>
        <label class="matrix-filter">
          <span>Status</span>
          <select v-model="statusFilter">
            <option v-for="([value, label]) in statusOptions" :key="value" :value="value">{{ label }}</option>
          </select>
        </label>
        <span class="result-count">Showing {{ filteredStudents.length }} of {{ matrixStudentTotal }} students</span>
      </div>

      <div v-if="filteredStudents.length" class="requirements-matrix-scroll">
        <table class="requirements-matrix">
          <thead>
            <tr>
              <th class="student-column">Student</th>
              <th v-for="type in documentTypes" :key="type.id" :title="type.name">{{ type.name }}</th>
              <th class="review-column">Review</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="student in filteredStudents" :key="student.student_id" :class="{ selected: selectedStudent?.student_id === student.student_id }">
              <th class="student-column" scope="row">
                <strong>{{ student.student_number }}</strong>
                <span>{{ student.student_name }}</span>
              </th>
              <td v-for="type in documentTypes" :key="type.id">
                <button
                  class="requirement-cell"
                  :data-status="statusPresentation(requirementFor(student, type.id)).status"
                  type="button"
                  :title="statusTooltip(requirementFor(student, type.id))"
                  :aria-label="`${type.name} for ${student.student_name}: ${statusPresentation(requirementFor(student, type.id)).label}. Review requirement.`"
                  @click="openReview(student, type.id, $event)"
                >
                  <span aria-hidden="true">{{ statusPresentation(requirementFor(student, type.id)).icon }}</span>
                  <small>{{ statusPresentation(requirementFor(student, type.id)).label }}</small>
                </button>
              </td>
              <td class="review-column">
                <button class="row-review" type="button" @click="openReview(student, null, $event)">Review</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <div v-else class="matrix-empty">
        <span aria-hidden="true">✓</span>
        <strong>No students match this view</strong>
        <p>Try another status or search term.</p>
      </div>
    </section>

    <aside v-if="selectedStudent" class="requirement-review-panel" aria-label="Document requirement review">
      <header>
        <div>
          <p>Student review</p>
          <h3>{{ selectedStudent.student_name }}</h3>
          <span>{{ selectedStudent.student_number }}</span>
        </div>
        <button ref="reviewClose" type="button" aria-label="Close student review panel" @click="closeReview">×</button>
      </header>

      <div v-if="reviewLoading" class="panel-state" role="status">Loading document record…</div>
      <div v-else class="review-content">
        <p v-if="reviewError" class="review-notice error" role="alert">{{ reviewError }}</p>
        <p v-if="reviewSuccess" class="review-notice success" role="status">{{ reviewSuccess }}</p>

        <label class="document-picker">
          <span>Requirement</span>
          <select :value="selectedDocumentTypeId" @change="selectDocument(Number($event.target.value))">
            <option v-for="document in studentDocuments" :key="document.document_type_id" :value="document.document_type_id">
              {{ document.name }}
            </option>
          </select>
        </label>

        <template v-if="selectedDocument">
          <section class="document-preview-panel">
            <div v-if="!selectedDocument.has_file" class="preview-state"><span aria-hidden="true">◇</span><strong>No digital copy</strong></div>
            <div v-else-if="selectedDocument.file_kind === 'image' && previewState.status === 'loading'" class="preview-state" role="status">Loading preview…</div>
            <div v-else-if="selectedDocument.file_kind === 'image' && previewState.status === 'error'" class="preview-state error" role="alert"><strong>Preview unavailable</strong><button type="button" @click="loadPreview(selectedDocument)">Retry</button></div>
            <img v-else-if="selectedDocument.file_kind === 'image' && previewState.url" :src="previewState.url" :alt="`${selectedDocument.name} preview`" />
            <button v-else class="file-preview" type="button" :disabled="activeAction" @click="openStoredDocument(selectedDocument)"><strong>{{ selectedDocument.file_kind === 'pdf' ? 'PDF document' : 'Stored document' }}</strong><span>Open authorized file</span></button>
          </section>

          <dl class="requirement-facts">
            <div><dt>Digital</dt><dd>{{ displayStatus(selectedDocument.digital_status) }}</dd></div>
            <div><dt>Physical</dt><dd>{{ selectedDocument.physical_status === 'submitted' ? 'Received' : displayStatus(selectedDocument.physical_status) }}</dd></div>
            <div><dt>Submitted</dt><dd>{{ selectedDocument.submitted_date || 'Not submitted' }}</dd></div>
            <div><dt>Version</dt><dd>{{ selectedDocument.version || 0 }}</dd></div>
          </dl>
          <p v-if="selectedDocument.remarks" class="requirement-note"><strong>Review note</strong>{{ selectedDocument.remarks }}</p>
          <p v-if="selectedDocument.physical_copy_instruction" class="requirement-note"><strong>Hard-copy instruction</strong>{{ selectedDocument.physical_copy_instruction }}</p>

          <div class="review-actions" aria-label="Requirement actions">
            <button v-if="selectedDocument.has_file" type="button" :disabled="activeAction" @click="openStoredDocument(selectedDocument)">{{ selectedDocument.file_kind === 'image' ? 'View image' : 'View file' }}</button>
            <button v-if="selectedDocument.has_file && selectedDocument.verification_status === 'pending'" class="primary" type="button" :disabled="activeAction" @click="beginAction('verify')">Verify</button>
            <button v-if="selectedDocument.has_file && selectedDocument.verification_status === 'pending'" type="button" :disabled="activeAction" @click="beginAction('needs-resubmission')">Resubmit</button>
            <button v-if="selectedDocument.physical_status === 'not_required'" type="button" :disabled="activeAction" @click="beginAction('require')">Require hard copy</button>
            <button v-if="selectedDocument.physical_status === 'required'" class="primary" type="button" :disabled="activeAction" @click="beginAction('receive')">Mark received</button>
            <button v-if="selectedDocument.physical_status === 'submitted'" type="button" :disabled="activeAction" @click="beginAction('reopen')">Require again</button>
          </div>

          <form v-if="pendingAction" class="action-confirmation" @submit.prevent="confirmAction">
            <strong>{{ actionTitle }}</strong>
            <label v-if="pendingAction !== 'verify' && pendingAction !== 'receive'">
              <span>{{ pendingAction === 'require' ? 'Instruction (optional)' : 'Reason' }}</span>
              <textarea v-model.trim="actionNote" rows="3" :required="requiresActionNote"></textarea>
            </label>
            <div><button type="button" :disabled="activeAction" @click="pendingAction = null">Cancel</button><button class="primary" type="submit" :disabled="activeAction || (requiresActionNote && !actionNote.trim())">{{ activeAction ? 'Saving…' : 'Confirm' }}</button></div>
          </form>
        </template>
        <p v-else-if="!reviewError" class="panel-state">No configured requirements are available.</p>
      </div>
    </aside>
  </div>
</template>

<style scoped>
.requirements-workspace{display:grid;grid-template-columns:minmax(0,1fr);min-height:0;height:100%;background:var(--bg-surface);color:var(--text-primary)}
.requirements-workspace.has-review{grid-template-columns:minmax(0,1fr) minmax(300px,350px)}
.requirements-main{display:grid;grid-template-rows:auto auto minmax(0,1fr);gap:12px;min-width:0;min-height:0;padding:16px 20px 20px}
.requirements-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:9px}
.requirements-summary article{display:flex;align-items:center;gap:10px;min-width:0;padding:10px 12px;border:1px solid var(--color-border);border-radius:10px;background:var(--color-surface)}
.requirements-summary article>span{display:grid;place-items:center;flex:0 0 30px;width:30px;height:30px;border-radius:8px;background:var(--color-green-tint);color:var(--color-primary);font-size:.72rem;font-weight:900}
.requirements-summary div{display:grid;min-width:0}.requirements-summary strong{font-size:1.05rem}.requirements-summary small{overflow:hidden;color:var(--color-text-muted);font-size:.68rem;text-overflow:ellipsis;white-space:nowrap}
.requirements-toolbar{display:grid;grid-template-columns:minmax(230px,1fr) minmax(170px,220px) auto;align-items:end;gap:10px}
.matrix-search{display:flex;align-items:center;gap:8px;min-height:40px;padding:0 11px;border:1px solid var(--color-border);border-radius:8px;background:var(--color-surface)}
.matrix-search input{min-width:0;width:100%;height:38px;border:0;background:transparent;color:var(--text-primary);outline:0}
.matrix-filter{display:grid;gap:4px}.matrix-filter>span{color:var(--color-text-muted);font-size:.68rem;font-weight:800;text-transform:uppercase}.matrix-filter select,.document-picker select{width:100%;min-height:40px;border:1px solid var(--color-border);border-radius:8px;background:var(--color-surface);color:var(--text-primary);padding:7px 10px}
.result-count{align-self:center;color:var(--color-text-muted);font-size:.75rem;font-weight:700;white-space:nowrap}
.requirements-matrix-scroll{min-height:0;overflow:auto;border:1px solid var(--color-border);border-radius:10px;background:var(--color-surface)}
.requirements-matrix{width:100%;min-width:max-content;border-collapse:separate;border-spacing:0;font-size:.78rem}
.requirements-matrix th,.requirements-matrix td{height:58px;padding:7px 9px;border-bottom:1px solid var(--color-border);text-align:center;vertical-align:middle}
.requirements-matrix thead th{position:sticky;top:0;z-index:3;height:42px;background:color-mix(in srgb,var(--color-primary) 7%,var(--color-surface));color:var(--color-text-muted);font-size:.68rem;letter-spacing:.025em;text-transform:uppercase}
.requirements-matrix tbody tr:last-child>*{border-bottom:0}.requirements-matrix tbody tr.selected{background:color-mix(in srgb,var(--color-primary) 5%,transparent)}
.requirements-matrix .student-column{position:sticky;left:0;z-index:2;width:210px;min-width:210px;max-width:210px;border-right:1px solid var(--color-border);background:var(--color-surface);text-align:left}
.requirements-matrix thead .student-column{z-index:4;background:color-mix(in srgb,var(--color-primary) 7%,var(--color-surface))}
.requirements-matrix .student-column strong,.requirements-matrix .student-column span{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.requirements-matrix .student-column span{margin-top:3px;color:var(--color-text-muted);font-size:.72rem;font-weight:500}
.requirements-matrix td{min-width:126px}.review-column{position:sticky;right:0;z-index:2;min-width:82px;background:var(--color-surface);border-left:1px solid var(--color-border)}.requirements-matrix thead .review-column{z-index:4;background:color-mix(in srgb,var(--color-primary) 7%,var(--color-surface))}
.requirement-cell{display:inline-grid;grid-template-columns:auto minmax(0,auto);align-items:center;gap:5px;min-height:31px;padding:5px 8px;border:1px solid var(--color-border);border-radius:999px;background:var(--color-surface);color:var(--text-primary);cursor:pointer}.requirement-cell small{font-size:.67rem;font-weight:800;white-space:nowrap}.requirement-cell>span{font-weight:900}.requirement-cell[data-status="accepted"]{border-color:#9fd6b2;background:#e8f7ed;color:#166534}.requirement-cell[data-status="awaiting_review"]{border-color:#9bc6e8;background:#eef7ff;color:#075985}.requirement-cell[data-status="missing"],.requirement-cell[data-status="needs_resubmission"],.requirement-cell[data-status="hard_copy_required"]{border-color:#edc980;background:#fff8df;color:#855600}.requirement-cell[data-status="not_required"]{color:var(--color-text-muted)}
.row-review,.review-actions button,.action-confirmation button,.preview-state button{min-height:34px;padding:6px 10px;border:1px solid var(--color-border);border-radius:7px;background:var(--color-surface);color:var(--color-primary);font-weight:800;cursor:pointer}.row-review{border-color:var(--color-primary)}
.requirement-cell:focus-visible,.row-review:focus-visible,.review-actions button:focus-visible,.requirement-review-panel header button:focus-visible{outline:3px solid var(--focus-ring,color-mix(in srgb,var(--color-primary) 35%,transparent));outline-offset:2px}
.matrix-empty{display:grid;place-items:center;align-content:center;min-height:240px;color:var(--color-text-muted);text-align:center}.matrix-empty>span{display:grid;place-items:center;width:44px;height:44px;border-radius:50%;background:var(--color-green-tint);color:var(--color-primary);font-size:1.2rem}.matrix-empty strong{margin-top:10px;color:var(--text-primary)}.matrix-empty p{margin:4px 0}
.requirement-review-panel{display:flex;flex-direction:column;min-width:0;min-height:0;border-left:1px solid var(--color-border);background:color-mix(in srgb,var(--color-primary) 3%,var(--color-surface))}.requirement-review-panel>header{display:flex;align-items:start;justify-content:space-between;gap:12px;padding:16px;border-bottom:1px solid var(--color-border)}.requirement-review-panel header p{margin:0;color:var(--color-primary);font-size:.66rem;font-weight:900;letter-spacing:.08em;text-transform:uppercase}.requirement-review-panel h3{margin:3px 0 1px;font-size:1rem}.requirement-review-panel header span{color:var(--color-text-muted);font-size:.75rem}.requirement-review-panel header button{display:grid;place-items:center;flex:0 0 32px;width:32px;height:32px;border:0;border-radius:50%;background:var(--color-green-tint);color:var(--color-primary);font-size:1.2rem;cursor:pointer}
.review-content{min-height:0;overflow:auto;padding:14px}.panel-state{display:grid;place-items:center;min-height:160px;color:var(--color-text-muted);padding:20px;text-align:center}.review-notice{margin:0 0 10px;padding:9px 10px;border-radius:7px;font-size:.76rem}.review-notice.error{background:#feecec;color:#991b1b}.review-notice.success{background:#e7f7ec;color:#166534}
.document-picker{display:grid;gap:5px}.document-picker>span{color:var(--color-text-muted);font-size:.68rem;font-weight:800;text-transform:uppercase}.document-preview-panel{display:grid;place-items:stretch;min-height:190px;max-height:250px;margin-top:12px;overflow:hidden;border:1px solid var(--color-border);border-radius:9px;background:var(--color-surface)}.document-preview-panel img{width:100%;height:100%;min-height:190px;max-height:250px;object-fit:contain}.preview-state,.file-preview{display:grid;place-items:center;align-content:center;gap:7px;min-height:190px;padding:16px;border:0;background:transparent;color:var(--color-text-muted);text-align:center}.preview-state.error{color:#991b1b}.file-preview{cursor:pointer}.file-preview span{font-size:.75rem}
.requirement-facts{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin:12px 0}.requirement-facts div{padding:9px;border:1px solid var(--color-border);border-radius:8px;background:var(--color-surface)}.requirement-facts dt{color:var(--color-text-muted);font-size:.65rem;font-weight:800;text-transform:uppercase}.requirement-facts dd{margin:3px 0 0;font-size:.78rem;font-weight:800}.requirement-note{display:grid;gap:3px;margin:8px 0;padding:9px;border-left:3px solid var(--color-primary);background:var(--color-surface);font-size:.75rem}.review-actions{display:flex;flex-wrap:wrap;gap:7px;margin-top:12px}.review-actions button.primary,.action-confirmation button.primary{border-color:var(--color-primary);background:var(--color-primary);color:#fff}.review-actions button:disabled,.action-confirmation button:disabled{cursor:not-allowed;opacity:.55}
.action-confirmation{display:grid;gap:9px;margin-top:12px;padding:11px;border:1px solid var(--color-border);border-radius:9px;background:var(--color-surface)}.action-confirmation>strong{font-size:.82rem}.action-confirmation label{display:grid;gap:4px}.action-confirmation label span{font-size:.7rem;font-weight:800}.action-confirmation textarea{resize:vertical;min-height:70px;border:1px solid var(--color-border);border-radius:7px;background:var(--bg-surface);color:var(--text-primary);padding:8px}.action-confirmation>div{display:flex;justify-content:flex-end;gap:7px}
.sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
@media(max-width:1100px){.requirements-workspace.has-review{grid-template-columns:minmax(0,1fr) minmax(280px,320px)}.requirements-summary{grid-template-columns:repeat(2,minmax(0,1fr))}.requirement-cell small{display:none}.requirements-matrix td{min-width:72px}}
@media(max-width:820px){.requirements-workspace.has-review{position:relative;display:block}.requirement-review-panel{position:absolute;inset:0 0 0 auto;width:min(92%,360px);z-index:6;box-shadow:-16px 0 40px rgb(0 0 0 / 18%)}.requirements-toolbar{grid-template-columns:1fr 180px}.result-count{display:none}}
@media(prefers-reduced-motion:reduce){*{scroll-behavior:auto!important}}
</style>
