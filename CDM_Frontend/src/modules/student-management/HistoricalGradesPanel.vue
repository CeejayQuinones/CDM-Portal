<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { apiClient } from '../../services/apiClient'
import { createHistoricalGradesImportForm } from './historicalGradesImportForm'

const props = defineProps({ studentId: { type: [Number, String], default: null }, studentNumber: { type: String, default: '' } })
const options = ref({ academic_years: [], semesters: [] })
const records = ref([])
const loading = ref(false)
const busy = ref(false)
const error = ref('')
const success = ref('')
const mode = ref('')
const review = ref(false)
const preview = ref(null)
const file = ref(null)
const fileInput = ref(null)
const editingId = ref(null)
const fields = reactive({ academic_year: '', semester: '', subject_code: '', final_grade: '', official_result: 'Passed', credited_units: '', source_reference: '', verification_status: 'verified', reason: '' })
const grouped = computed(() => Object.entries(records.value.reduce((groups, record) => {
  const key = `${record.academic_year} · ${record.semester}`
  ;(groups[key] ||= []).push(record)
  return groups
}, {})))
const message = exception => Object.values(exception.response?.data?.errors || {}).flat().join(' ') || exception.response?.data?.message || 'Unable to complete the request.'

async function load() {
  loading.value = true
  error.value = ''
  try {
    const { data } = await apiClient.get('/registrar/historical-grades/options')
    options.value = data.data
    if (props.studentId) {
      const result = await apiClient.get(`/registrar/students/${props.studentId}/historical-grades`)
      records.value = result.data.data
    }
  } catch (exception) { error.value = message(exception) } finally { loading.value = false }
}
onMounted(load)
watch(() => props.studentId, load)

function resetForm() {
  Object.assign(fields, { academic_year: '', semester: '', subject_code: '', final_grade: '', official_result: 'Passed', credited_units: '', source_reference: '', verification_status: 'verified', reason: '' })
  editingId.value = null
  review.value = false
  error.value = ''
}
function startManual(record = null) {
  resetForm()
  if (record) {
    editingId.value = record.id
    Object.assign(fields, { academic_year: record.academic_year, semester: record.semester, subject_code: record.subject_code,
      final_grade: record.final_grade, official_result: record.official_result, credited_units: record.credited_units,
      source_reference: record.source_reference, verification_status: record.verification_status })
  }
  mode.value = 'manual'
}
function close() {
  mode.value = ''
  preview.value = null
  file.value = null
  if (fileInput.value) fileInput.value.value = ''
  resetForm()
}
async function downloadTemplate() {
  busy.value = true
  error.value = ''
  try {
    const response = await apiClient.get('/registrar/historical-grades/template', { responseType: 'blob' })
    const url = URL.createObjectURL(response.data)
    const link = document.createElement('a')
    link.href = url
    link.download = 'historical-grades-template.xlsx'
    link.click()
    setTimeout(() => URL.revokeObjectURL(url), 1000)
  } catch (exception) { error.value = message(exception) } finally { busy.value = false }
}
function selectFile(event) { file.value = event.target.files?.[0] || null; preview.value = null; error.value = '' }
async function previewFile() {
  if (!file.value) return
  busy.value = true
  error.value = ''
  try {
    const body = createHistoricalGradesImportForm(file.value)
    const { data } = await apiClient.post('/registrar/historical-grades/preview', body)
    preview.value = data.data
  } catch (exception) { error.value = message(exception) } finally { busy.value = false }
}
async function confirmImport() {
  busy.value = true
  error.value = ''
  try {
    const { data } = await apiClient.post('/registrar/historical-grades/confirm', { preview_token: preview.value.preview_token })
    success.value = `${data.data.imported} historical grades imported.`
    close()
    await load()
  } catch (exception) { error.value = message(exception) } finally { busy.value = false }
}
async function saveManual() {
  busy.value = true
  error.value = ''
  try {
    const endpoint = `/registrar/students/${props.studentId}/historical-grades`
    if (editingId.value) await apiClient.patch(`${endpoint}/${editingId.value}`, { ...fields })
    else await apiClient.post(endpoint, { ...fields })
    success.value = editingId.value ? 'Correction recorded in the audit history.' : 'Previous grade verified and saved.'
    close()
    await load()
  } catch (exception) { error.value = message(exception); review.value = false } finally { busy.value = false }
}
</script>

<template>
  <section class="historical-panel" aria-label="Historical grades">
    <header class="historical-heading">
      <div><h2>Previous grades</h2><p>Registrar verified results from official records, separate from CDM published grade sheets.</p></div>
      <div class="historical-actions">
        <button type="button" :disabled="busy" @click="downloadTemplate">Download Excel template</button>
        <button type="button" :disabled="busy" @click="close(); mode = 'import'">Import grades</button>
        <button v-if="studentId" type="button" :disabled="busy" @click="startManual()">Add previous grades</button>
      </div>
    </header>
    <p v-if="success" role="status">{{ success }}</p>
    <p v-if="error" class="historical-error" role="alert">{{ error }}</p>
    <p v-if="loading">Loading previous grades…</p>

    <form v-if="mode === 'import'" class="historical-form" @submit.prevent="previewFile">
      <h3>Import official records</h3>
      <p>Use the template. All rows must pass validation before anything is saved.</p>
      <label>Excel workbook (.xlsx, up to 200 rows and 2 MB)<input ref="fileInput" type="file" accept=".xlsx" required @change="selectFile" /></label>
      <div class="historical-actions"><button type="submit" :disabled="busy || !file">{{ busy ? 'Checking…' : 'Validate and preview' }}</button><button type="button" @click="close">Cancel</button></div>
      <div v-if="preview" class="historical-preview">
        <p><strong>{{ preview.counts.total }} rows:</strong> {{ preview.counts.valid }} valid, {{ preview.counts.invalid }} invalid, {{ preview.counts.duplicates }} duplicate, {{ preview.counts.unknown_students }} unknown Students, {{ preview.counts.unknown_subjects }} unknown subjects. {{ preview.counts.manual_review }} require review.</p>
        <div class="historical-scroll"><table><thead><tr><th>Row</th><th>Student</th><th>Term</th><th>Subject</th><th>Grade / result</th><th>Status</th></tr></thead><tbody><tr v-for="row in preview.rows" :key="row.row"><td>{{ row.row }}</td><td>{{ row.data.student_number }}</td><td>{{ row.data.academic_year }} · {{ row.data.semester }}</td><td>{{ row.data.subject_code }}</td><td>{{ row.data.final_grade }} · {{ row.data.official_result }}</td><td>{{ row.status }}<small v-for="reason in row.errors" :key="reason">{{ reason }}</small></td></tr></tbody></table></div>
        <p>{{ preview.message }}</p>
        <button v-if="preview.preview_token" type="button" :disabled="busy" @click="confirmImport">{{ busy ? 'Saving…' : 'Confirm all grades' }}</button>
      </div>
    </form>

    <form v-if="mode === 'manual'" class="historical-form" @submit.prevent="review ? saveManual() : review = true">
      <h3>{{ editingId ? 'Correct previous grade' : 'Add previous grade' }} · {{ studentNumber }}</h3>
      <div class="historical-fields">
        <label>Academic year<select v-model="fields.academic_year" required :disabled="review"><option value="">Select year</option><option v-for="year in options.academic_years" :key="year.id">{{ year.school_year }}</option></select></label>
        <label>Semester<select v-model="fields.semester" required :disabled="review"><option value="">Select semester</option><option v-for="term in options.semesters" :key="term.id">{{ term.semester_name }}</option></select></label>
        <label>Subject code<input v-model.trim="fields.subject_code" required maxlength="50" :disabled="review" placeholder="Exact code in Student curriculum" /></label>
        <label>Original final grade<input v-model.trim="fields.final_grade" required maxlength="40" :disabled="review" /></label>
        <label>Official result<select v-model="fields.official_result" required :disabled="review"><option>Passed</option><option>Failed</option><option>Incomplete</option></select></label>
        <label>Credited units<input v-model="fields.credited_units" type="number" min="0" max="99.99" step="0.01" required :disabled="review" /></label>
        <label>Source reference<input v-model.trim="fields.source_reference" required maxlength="255" :disabled="review" placeholder="Official register or document reference" /></label>
        <label v-if="editingId">Verification status<select v-model="fields.verification_status" :disabled="review"><option value="verified">Verified</option><option value="disputed">Disputed</option><option value="revoked">Revoked</option></select></label>
        <label v-if="editingId">Correction reason<textarea v-model.trim="fields.reason" required minlength="10" maxlength="1000" :disabled="review" /></label>
      </div>
      <p v-if="review" role="status">Review: {{ fields.academic_year }} · {{ fields.semester }} · {{ fields.subject_code }} · {{ fields.final_grade }} · {{ fields.official_result }} · {{ fields.credited_units }} units · {{ fields.source_reference }}. {{ editingId ? `Reason: ${fields.reason}` : '' }}</p>
      <div class="historical-actions"><button type="submit" :disabled="busy">{{ busy ? 'Saving…' : review ? 'Confirm and save' : 'Review entry' }}</button><button v-if="review" type="button" @click="review = false">Edit</button><button type="button" @click="close">Cancel</button></div>
    </form>

    <template v-if="studentId && !loading">
      <p v-if="!records.length">No previous grades recorded for this Student.</p>
      <div v-for="[term, entries] in grouped" :key="term" class="historical-term"><h3>{{ term }}</h3><div class="historical-scroll"><table><thead><tr><th>Subject</th><th>Grade</th><th>Result</th><th>Units</th><th>Source</th><th>Verification</th><th>Action</th></tr></thead><tbody><tr v-for="record in entries" :key="record.id"><td><strong>{{ record.subject_code }}</strong><small>{{ record.subject_name }}</small></td><td>{{ record.final_grade }}</td><td>{{ record.official_result }}</td><td>{{ record.credited_units }}</td><td>{{ record.source_type }} · {{ record.source_reference }}</td><td>{{ record.verification_status }}<small>{{ record.entered_by }} · {{ new Date(record.created_at).toLocaleDateString() }}</small><details v-if="record.events?.length"><summary>Audit history</summary><p v-for="(event, index) in record.events" :key="index">{{ event.action }} by {{ event.actor }} · {{ new Date(event.created_at).toLocaleString() }}<br />{{ event.reason || 'Initial entry' }}<template v-if="event.before"> · Previous: {{ event.before.final_grade }} / {{ event.before.official_result }} / {{ event.before.source_reference }}</template></p></details></td><td><button type="button" @click="startManual(record)">Correct</button></td></tr></tbody></table></div></div>
    </template>
  </section>
</template>

<style scoped>
.historical-panel{display:grid;gap:14px;padding:18px;border:1px solid var(--color-border,#dce4e0);border-radius:12px;background:var(--color-surface,#fff);color:var(--color-eerie-black,#172b20)}.historical-heading{display:flex;justify-content:space-between;align-items:start;gap:16px;flex-wrap:wrap}.historical-heading h2,.historical-term h3{margin:0}.historical-heading p,.historical-form p{margin:5px 0;color:var(--color-muted,#5c6b63)}.historical-actions{display:flex;gap:8px;flex-wrap:wrap}.historical-actions button,.historical-panel button{border:1px solid var(--color-border,#dce4e0);border-radius:7px;padding:8px 12px;background:var(--color-surface,#fff);color:var(--color-eerie-black,#172b20);cursor:pointer}.historical-panel button:disabled{opacity:.55;cursor:wait}.historical-form{display:grid;gap:12px;padding:15px;border:1px solid var(--color-border,#dce4e0);border-radius:10px}.historical-fields{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:10px}.historical-form label{display:grid;gap:5px;font-size:.85rem}.historical-form input,.historical-form select,.historical-form textarea{min-width:0;padding:8px;border:1px solid var(--color-border,#dce4e0);border-radius:6px;background:var(--color-surface,#fff);color:var(--color-eerie-black,#172b20)}.historical-scroll{overflow-x:auto}.historical-panel table{width:100%;border-collapse:collapse;font-size:.85rem}.historical-panel th,.historical-panel td{padding:9px;border-bottom:1px solid var(--color-border,#dce4e0);text-align:left;vertical-align:top}.historical-panel td small{display:block;color:var(--color-muted,#5c6b63)}.historical-error{color:#b42318}.historical-term{display:grid;gap:8px}@media(max-width:640px){.historical-panel{padding:12px}.historical-heading{display:grid}.historical-panel table{min-width:650px}}
</style>
