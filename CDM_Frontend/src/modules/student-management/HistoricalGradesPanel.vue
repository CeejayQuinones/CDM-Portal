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
const importStep = ref(1)
const importReachedStep = ref(1)
const templateDownloaded = ref(false)
const importedCount = ref(0)
const editingId = ref(null)
const importSteps = ['Choose format', 'Prepare file', 'Upload file', 'Review rows', 'Save grades']
const importColumns = [
  ['A', 'Student Number', 'Exact existing CDM student number. Do not enter a name.'],
  ['B', 'Academic Year', 'Use the configured format, for example 2025-2026.'],
  ['C', 'Semester', 'Use the exact configured semester name.'],
  ['D', 'Subject Code', 'Exact subject code from the student curriculum.'],
  ['E', 'Final Grade', 'Copy the official value exactly, such as 1.75 or 85.'],
  ['F', 'Official Result', 'Passed, Failed, or Incomplete only.'],
  ['G', 'Credited Units', 'Numeric units from 0 to 99.99.'],
  ['H', 'Source Reference', 'Official transcript, register, volume, or page reference.'],
]
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
  importStep.value = 1
  importReachedStep.value = 1
  templateDownloaded.value = false
  importedCount.value = 0
  if (fileInput.value) fileInput.value.value = ''
  resetForm()
}
function openImport() {
  close()
  mode.value = 'import'
}
function goImportStep(step) {
  importStep.value = step
  importReachedStep.value = Math.max(importReachedStep.value, step)
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
    templateDownloaded.value = true
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
    goImportStep(4)
  } catch (exception) { error.value = message(exception) } finally { busy.value = false }
}
async function confirmImport() {
  busy.value = true
  error.value = ''
  try {
    const { data } = await apiClient.post('/registrar/historical-grades/confirm', { preview_token: preview.value.preview_token })
    success.value = `${data.data.imported} historical grades imported.`
    importedCount.value = data.data.imported
    goImportStep(5)
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
      <div class="historical-heading-copy"><span class="historical-kicker">Official academic history</span><h2>Previous grades</h2><p>Record verified grades from another school, an older system, or an official Registrar archive. These records remain separate from CDM-published grade sheets.</p><div class="historical-flow-summary" aria-label="Previous grades workflow"><span><b>1</b> Prepare records</span><span><b>2</b> Validate every row</span><span><b>3</b> Confirm and audit</span></div></div>
      <div class="historical-actions">
        <button class="historical-primary-action" type="button" :disabled="busy" @click="openImport"><span aria-hidden="true">⇧</span> Import grades</button>
        <button v-if="studentId" type="button" :disabled="busy" @click="startManual()">Add manually</button>
      </div>
    </header>
    <p v-if="success" role="status">{{ success }}</p>
    <p v-if="error && mode !== 'import'" class="historical-error" role="alert">{{ error }}</p>
    <p v-if="loading">Loading previous grades…</p>

    <div v-if="mode === 'import'" class="historical-import-backdrop" @click.self="close">
      <section class="historical-import-dialog" role="dialog" aria-modal="true" aria-labelledby="historical-import-title">
        <header class="historical-dialog-header"><div><span class="historical-kicker">Registrar import</span><h3 id="historical-import-title">Import previous grades</h3></div><button type="button" aria-label="Close import" @click="close">×</button></header>
        <nav class="historical-import-progress" aria-label="Import progress">
          <button v-for="(label, index) in importSteps" :key="label" type="button" :class="{ active: importStep === index + 1, complete: importReachedStep > index + 1 }" :disabled="busy || importStep === 5 || index + 1 > importReachedStep || (index + 1 === 4 && !preview)" @click="goImportStep(index + 1)"><b>{{ index + 1 }}</b><span>{{ label }}</span></button>
        </nav>

        <form class="historical-import-body" @submit.prevent="previewFile">
          <p v-if="error" class="historical-dialog-error" role="alert">{{ error }}</p>
          <section v-if="importStep === 1" class="historical-import-step">
            <span class="historical-step-label">Step 1 of 5</span><h4>Choose how to prepare grades</h4><p>Use the optional Excel template, or export a CSV from Access. CSV columns can be in any order and familiar Access field names are recognized.</p>
            <div class="historical-callout"><strong>Before you continue</strong><ul><li>For Excel, keep the eight template headers and Instructions worksheet.</li><li>For Access CSV, include student number, academic year, semester, subject code, final grade, official result, credited units, and source reference.</li><li>Do not infer official result from remarks or grade value; confirm it against the official record.</li></ul></div>
            <button class="historical-download" type="button" :disabled="busy" @click="downloadTemplate">{{ busy ? 'Preparing…' : '↓ Download Excel template' }}</button>
            <p v-if="templateDownloaded" class="historical-success-note" role="status">Template download started. Open it and continue to the filling guide.</p>
            <footer><button type="button" @click="close">Cancel</button><button class="historical-primary-action" type="button" @click="goImportStep(2)">Continue to column guide</button></footer>
          </section>

          <section v-else-if="importStep === 2" class="historical-import-step">
            <span class="historical-step-label">Step 2 of 5</span><h4>Prepare one subject per row</h4><p>The first row contains headers. Each data row represents one student and one subject. The Excel template uses the column order below; CSV headers may be reordered.</p>
            <div class="historical-column-guide historical-scroll"><table><thead><tr><th>Column</th><th>Header</th><th>What to enter</th></tr></thead><tbody><tr v-for="column in importColumns" :key="column[0]"><td><b>{{ column[0] }}</b></td><td><strong>{{ column[1] }}</strong></td><td>{{ column[2] }}</td></tr></tbody></table></div>
            <div class="historical-rules"><div><strong>Accepted files</strong><span>.xlsx or .csv · Maximum 200 data rows · Maximum 2 MB</span></div><div><strong>Do not use</strong><span>Formulas, blank required cells, or duplicate rows</span></div><div><strong>Saving rule</strong><span>All rows must pass. One invalid row prevents the entire import.</span></div></div>
            <footer><button type="button" @click="goImportStep(1)">Back</button><button class="historical-primary-action" type="button" @click="goImportStep(3)">My file is ready</button></footer>
          </section>

          <section v-else-if="importStep === 3" class="historical-import-step">
            <span class="historical-step-label">Step 3 of 5</span><h4>Upload the completed file</h4><p>Select an Excel workbook or Access CSV. Validation reads the file only; no grade is saved at this step.</p>
            <label class="historical-file-picker"><span aria-hidden="true">⇧</span><strong>{{ file ? file.name : 'Choose Excel workbook or Access CSV' }}</strong><small>{{ file ? `${Math.ceil(file.size / 1024)} KB selected` : '.xlsx or .csv · up to 200 rows and 2 MB' }}</small><input ref="fileInput" type="file" accept=".xlsx,.csv,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required @change="selectFile" /></label>
            <p class="historical-safety-note"><strong>Safe preview:</strong> validating does not create, update, or publish any grade.</p>
            <footer><button type="button" @click="goImportStep(2)">Back</button><button class="historical-primary-action" type="submit" :disabled="busy || !file">{{ busy ? 'Checking every row…' : 'Validate and preview' }}</button></footer>
          </section>

          <section v-else-if="importStep === 4 && preview" class="historical-import-step historical-preview">
            <span class="historical-step-label">Step 4 of 5</span><h4>Review validation results</h4>
            <div class="historical-counts"><div><strong>{{ preview.counts.total }}</strong><span>Total rows</span></div><div data-state="valid"><strong>{{ preview.counts.valid }}</strong><span>Valid</span></div><div data-state="invalid"><strong>{{ preview.counts.invalid }}</strong><span>Invalid</span></div><div><strong>{{ preview.counts.duplicates }}</strong><span>Duplicates</span></div><div><strong>{{ preview.counts.manual_review }}</strong><span>Review</span></div></div>
            <p class="historical-preview-summary">{{ preview.counts.unknown_students }} unknown students · {{ preview.counts.unknown_subjects }} unknown subjects. {{ preview.message }}</p>
            <div class="historical-scroll historical-preview-table"><table><thead><tr><th>File row</th><th>Student Number</th><th>Academic term</th><th>Subject</th><th>Final grade / result</th><th>Validation</th></tr></thead><tbody><tr v-for="row in preview.rows" :key="row.row"><td>{{ row.row }}</td><td>{{ row.data.student_number }}</td><td>{{ row.data.academic_year }} · {{ row.data.semester }}</td><td>{{ row.data.subject_code }}</td><td>{{ row.data.final_grade }} · {{ row.data.official_result }}</td><td><span class="historical-row-status" :data-state="row.status">{{ row.status }}</span><small v-for="reason in row.errors" :key="reason">{{ reason }}</small></td></tr></tbody></table></div>
            <div v-if="preview.preview_token" class="historical-confirm-note"><strong>Ready to save</strong><span>Confirming will atomically create all {{ preview.counts.valid }} verified historical grade records and audit entries.</span></div>
            <div v-else class="historical-confirm-note invalid"><strong>Nothing can be saved yet</strong><span>Correct every reported row in the file, save it, and upload it again.</span></div>
            <footer><button type="button" @click="preview = null; file = null; goImportStep(3)">{{ preview.preview_token ? 'Choose another file' : 'Fix and upload again' }}</button><button v-if="preview.preview_token" class="historical-primary-action" type="button" :disabled="busy" @click="confirmImport">{{ busy ? 'Saving all grades…' : 'Confirm all grades' }}</button></footer>
          </section>

          <section v-else-if="importStep === 5" class="historical-import-step historical-import-complete">
            <span class="historical-complete-icon" aria-hidden="true">✓</span><span class="historical-step-label">Step 5 of 5</span><h4>Import complete</h4><p><strong>{{ importedCount }} historical grades</strong> were verified and saved with audit history. No CDM-published grade sheet was changed.</p>
            <footer><button class="historical-primary-action" type="button" @click="close">Done</button></footer>
          </section>
        </form>
      </section>
    </div>

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
.historical-panel{display:grid;gap:14px;padding:20px;border:1px solid var(--color-border,#dce4e0);border-radius:16px;background:linear-gradient(135deg,var(--color-surface,#fff) 0%,#fbfdfb 72%,#edf7f1 100%);box-shadow:0 10px 26px rgba(22,62,42,.06);color:var(--color-eerie-black,#172b20)}
.historical-heading{display:flex;justify-content:space-between;align-items:center;gap:24px;flex-wrap:wrap}.historical-heading-copy{display:grid;gap:5px;max-width:750px}.historical-kicker,.historical-step-label{color:#087c55;font-size:.72rem;font-weight:800;letter-spacing:.1em;text-transform:uppercase}.historical-heading h2,.historical-term h3,.historical-dialog-header h3,.historical-import-step h4{margin:0}.historical-heading h2{font-size:clamp(1.5rem,2.4vw,2rem)}.historical-heading p,.historical-form p,.historical-import-step>p{margin:0;color:var(--color-muted,#5c6b63);line-height:1.55}.historical-flow-summary{display:flex;gap:8px;flex-wrap:wrap;margin-top:8px}.historical-flow-summary span{display:flex;align-items:center;gap:7px;padding:7px 10px;border:1px solid #d7e7de;border-radius:999px;background:rgba(255,255,255,.75);font-size:.77rem;font-weight:700;color:#456056}.historical-flow-summary b{display:grid;place-items:center;width:20px;height:20px;border-radius:50%;background:#e0f3e9;color:#087c55}.historical-actions{display:flex;gap:8px;flex-wrap:wrap}.historical-actions button,.historical-panel button{border:1px solid var(--color-border,#dce4e0);border-radius:9px;padding:9px 13px;background:var(--color-surface,#fff);color:var(--color-eerie-black,#172b20);font:inherit;font-weight:650;cursor:pointer}.historical-panel button:hover:not(:disabled){border-color:#8dbca5;background:#f4faf6}.historical-panel button:disabled{opacity:.55;cursor:not-allowed}.historical-panel .historical-primary-action,.historical-panel .historical-download{border-color:#087c55;background:#087c55;color:#fff}.historical-panel .historical-primary-action:hover:not(:disabled),.historical-panel .historical-download:hover:not(:disabled){background:#056b49;border-color:#056b49}
.historical-form{display:grid;gap:12px;padding:15px;border:1px solid var(--color-border,#dce4e0);border-radius:10px;background:var(--color-surface,#fff)}.historical-fields{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:10px}.historical-form label{display:grid;gap:5px;font-size:.85rem}.historical-form input,.historical-form select,.historical-form textarea{min-width:0;padding:8px;border:1px solid var(--color-border,#dce4e0);border-radius:6px;background:var(--color-surface,#fff);color:var(--color-eerie-black,#172b20)}
.historical-import-backdrop{position:fixed;z-index:1200;inset:0;display:grid;place-items:center;padding:24px;background:rgba(8,39,27,.68);backdrop-filter:blur(4px)}.historical-import-dialog{display:grid;grid-template-rows:auto auto minmax(0,1fr);width:min(960px,calc(100vw - 32px));max-height:min(860px,calc(100vh - 32px));overflow:hidden;border:1px solid #cfe0d6;border-radius:18px;background:var(--color-surface,#fff);box-shadow:0 30px 80px rgba(2,28,18,.28)}.historical-dialog-header{display:flex;align-items:center;justify-content:space-between;padding:18px 22px;border-bottom:1px solid #dce7e1}.historical-dialog-header>div{display:grid;gap:4px}.historical-dialog-header h3{font-size:1.25rem}.historical-dialog-header>button{display:grid;place-items:center;width:36px;height:36px;padding:0;font-size:1.35rem}.historical-import-progress{position:relative;display:grid;grid-template-columns:repeat(5,1fr);padding:20px 22px 16px;background:#fbfdfc}.historical-import-progress::before{content:"";position:absolute;left:10%;right:10%;top:36px;height:1px;background:#cadbd2}.historical-import-progress button{position:relative;z-index:1;display:grid;justify-items:center;gap:7px;padding:0;border:0;background:transparent!important;color:#778981;font-size:.69rem}.historical-import-progress button b{display:grid;place-items:center;width:30px;height:30px;border:1px solid #bfd2c7;border-radius:50%;background:#fff;color:#6d8277}.historical-import-progress button.active{color:#087c55}.historical-import-progress button.active b{border-color:#087c55;background:#087c55;color:#fff}.historical-import-progress button.complete b{border-color:#72ad8f;background:#e1f3e9;color:#087c55}.historical-import-body{min-height:0;overflow-y:auto;padding:4px 22px 22px}.historical-import-step{display:grid;gap:14px}.historical-import-step h4{font-size:1.35rem}.historical-import-step footer{display:flex;justify-content:flex-end;gap:9px;padding-top:16px;border-top:1px solid #dce7e1}.historical-callout{padding:14px 16px;border-left:4px solid #c49a24;border-radius:8px;background:#fff9e9}.historical-callout ul{display:grid;gap:5px;margin:8px 0 0;padding-left:20px;color:#53675d}.historical-download{justify-self:start}.historical-success-note{padding:9px 12px;border-radius:8px;background:#e8f7ee;color:#087044!important;font-weight:700}
.historical-column-guide{border:1px solid #d7e4dd;border-radius:10px}.historical-column-guide table{min-width:690px}.historical-column-guide th{background:#e9f4ee;color:#335448;font-size:.72rem;letter-spacing:.06em;text-transform:uppercase}.historical-column-guide td:first-child b{display:grid;place-items:center;width:29px;height:29px;border-radius:7px;background:#dff1e7;color:#087c55}.historical-rules{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}.historical-rules div{display:grid;gap:5px;padding:12px;border:1px solid #dce7e1;border-radius:9px;background:#fafcfb}.historical-rules span{color:#5a6d64;font-size:.78rem;line-height:1.4}.historical-file-picker{display:grid!important;justify-items:center;gap:8px;padding:32px 20px!important;border:2px dashed #8bbda4!important;border-radius:13px!important;background:#f4faf6!important;text-align:center;cursor:pointer}.historical-file-picker>span{display:grid;place-items:center;width:48px;height:48px;border-radius:50%;background:#dff1e7;color:#087c55;font-size:1.45rem}.historical-file-picker small{color:#60736a}.historical-file-picker input{position:absolute;width:1px;height:1px;opacity:0;pointer-events:none}.historical-safety-note{padding:10px 12px;border-radius:8px;background:#eef4ff;color:#36506d!important}.historical-counts{display:grid;grid-template-columns:repeat(5,1fr);gap:8px}.historical-counts div{display:grid;gap:2px;padding:12px;border:1px solid #dce7e1;border-radius:9px;background:#fafcfb}.historical-counts strong{font-size:1.3rem}.historical-counts span{font-size:.72rem;color:#65776e}.historical-counts [data-state="valid"]{border-color:#9bcbb0;background:#ecf8f1;color:#087044}.historical-counts [data-state="invalid"]{border-color:#edb3b0;background:#fff0ef;color:#a92520}.historical-preview-summary{padding:10px 12px;border-radius:8px;background:#f3f6f4}.historical-preview-table{max-height:300px;border:1px solid #dce7e1;border-radius:10px}.historical-preview-table table{min-width:760px}.historical-preview-table thead{position:sticky;top:0;background:#e9f4ee}.historical-row-status{display:inline-block;padding:3px 7px;border-radius:999px;background:#eef2f0;font-size:.72rem;font-weight:800;text-transform:capitalize}.historical-row-status[data-state="valid"]{background:#dff3e7;color:#087044}.historical-row-status[data-state="invalid"]{background:#fde4e2;color:#a92520}.historical-confirm-note{display:grid;gap:3px;padding:12px 14px;border:1px solid #9bcbb0;border-radius:9px;background:#ecf8f1;color:#285b40}.historical-confirm-note.invalid{border-color:#edb3b0;background:#fff0ef;color:#8d2c27}.historical-confirm-note span{font-size:.82rem}.historical-import-complete{justify-items:center;padding:30px;text-align:center}.historical-complete-icon{display:grid;place-items:center;width:70px;height:70px;border-radius:50%;background:#dff3e7;color:#087c55;font-size:2rem;font-weight:900}.historical-import-complete footer{justify-content:center;width:100%}
.historical-scroll{overflow-x:auto}.historical-panel table{width:100%;border-collapse:collapse;font-size:.85rem}.historical-panel th,.historical-panel td{padding:9px;border-bottom:1px solid var(--color-border,#dce4e0);text-align:left;vertical-align:top}.historical-panel td small{display:block;margin-top:4px;color:var(--color-muted,#5c6b63)}.historical-error{color:#b42318}.historical-term{display:grid;gap:8px}
.historical-dialog-error{margin:0 0 12px;padding:10px 12px;border:1px solid #edb3b0;border-radius:8px;background:#fff0ef;color:#a92520;font-weight:700}
@media(max-width:720px){.historical-panel{padding:14px}.historical-heading{display:grid}.historical-import-backdrop{padding:0}.historical-import-dialog{width:100vw;max-height:100vh;height:100vh;border-radius:0}.historical-import-progress{padding-inline:8px}.historical-import-progress button span{display:none}.historical-import-body{padding-inline:14px}.historical-rules,.historical-counts{grid-template-columns:1fr}.historical-import-step footer{flex-wrap:wrap}.historical-import-step footer button{flex:1}.historical-panel table{min-width:650px}}
</style>
