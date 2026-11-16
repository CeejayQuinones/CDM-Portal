<script setup>
import { computed, ref } from 'vue'
import { apiClient } from '../../services/apiClient'
import { isStepUpCancelled, useStepUpAuth } from '../../composables/useStepUpAuth'
import LegacyMigrationReviewPanel from './LegacyMigrationReviewPanel.vue'

const { runWithStepUp } = useStepUpAuth()
const file = ref(null)
const input = ref(null)
const preview = ref(null)
const reviewed = ref(false)
const busy = ref(false)
const error = ref('')
const success = ref('')
const canConfirm = computed(() => Boolean(preview.value?.preview_token && reviewed.value && !busy.value))
const message = (exception) => Object.values(exception.response?.data?.errors || {}).flat().join(' ') || exception.response?.data?.message || 'Unable to complete the import.'

function clear() {
  file.value = null
  preview.value = null
  reviewed.value = false
  error.value = ''
  if (input.value) input.value.value = ''
}

function select(event) {
  const selected = event.target.files?.[0] || null
  clear()
  file.value = selected
}

async function template() {
  busy.value = true
  error.value = ''
  try {
    const response = await apiClient.get('/students/roster-import/template', { responseType: 'blob' })
    const url = URL.createObjectURL(response.data)
    const link = document.createElement('a')
    link.href = url
    link.download = 'student-alumni-roster-template.csv'
    link.click()
    setTimeout(() => URL.revokeObjectURL(url), 1000)
  } catch (exception) { error.value = message(exception) } finally { busy.value = false }
}

async function validate() {
  if (!file.value) return
  busy.value = true
  error.value = ''
  success.value = ''
  preview.value = null
  reviewed.value = false
  try {
    const body = new FormData()
    body.append('file', file.value, file.value.name)
    const response = await apiClient.post('/students/roster-import/preview', body)
    preview.value = response.data.data
  } catch (exception) { error.value = message(exception) } finally { busy.value = false }
}

async function confirm() {
  if (!canConfirm.value) return
  busy.value = true
  error.value = ''
  try {
    const response = await runWithStepUp(() => apiClient.post('/students/roster-import/confirm', { preview_token: preview.value.preview_token }))
    success.value = response.data.data.message
    clear()
  } catch (exception) {
    if (!isStepUpCancelled(exception)) error.value = message(exception)
  } finally { busy.value = false }
}
</script>

<template>
  <main class="roster-import">
    <header class="roster-heading">
      <div><p class="roster-kicker">Registrar / Student Records</p><h1>Import students and alumni</h1><p>Add existing school records without activating accounts or changing enrolled students.</p></div>
      <router-link to="/student-management">Back to records</router-link>
    </header>

    <LegacyMigrationReviewPanel />

    <section class="roster-card" aria-label="Import instructions">
      <h2>How it works</h2>
      <ol class="roster-steps"><li><strong>Download the template</strong><span>Use one row per student or alumni. Keep the exact CSV headers.</span></li><li><strong>Fill official record details</strong><span>Student number, name, program, curriculum, year, status, admission date and file reference are required. Use <em>graduated</em> for alumni. Leave unknown emails blank.</span></li><li><strong>Preview and confirm</strong><span>Correct every error first. The import creates inactive records only; each email must still be checked against the original enrollment file in that student's record before account activation.</span></li></ol>
      <p class="roster-hint"><strong>Required columns:</strong> student_number, first_name, last_name, course_code, curriculum_code, year_level, student_status, admission_date (YYYY-MM-DD), gender, record_reference. Optional columns hold middle name, suffix, enrollment email, birth date, contact number, and address.</p>
      <p class="roster-hint"><strong>Accepted values:</strong> student_status = regular, irregular, graduated, transferred, dropped, or leave_of_absence; gender = Male, Female, or Prefer not to say. Course and curriculum codes must already exist in CDM Portal.</p>
      <button type="button" class="roster-secondary" :disabled="busy" @click="template">Download CSV template</button>
    </section>

    <section class="roster-card" aria-label="Upload roster">
      <div class="roster-section-heading"><div><h2>Upload roster</h2><p>CSV, up to 1,000 rows and 2 MB. Existing student numbers are never overwritten.</p></div></div>
      <label class="roster-file"><span>Choose completed CSV file</span><input ref="input" type="file" accept=".csv,text/csv" @change="select" /><small>{{ file?.name || 'No file selected' }}</small></label>
      <div class="roster-actions"><button type="button" :disabled="!file || busy" @click="validate">{{ busy ? 'Working…' : 'Validate and preview' }}</button><button v-if="file || preview" type="button" class="roster-secondary" :disabled="busy" @click="clear">Cancel</button></div>
    </section>

    <p v-if="error" class="roster-error" role="alert">{{ error }}</p>
    <p v-if="success" class="roster-success" role="status">{{ success }}</p>

    <section v-if="preview" class="roster-card" aria-label="Roster preview">
      <div class="roster-section-heading"><div><h2>Review before import</h2><p>{{ preview.message }}</p></div><span>{{ preview.counts.valid }} valid · {{ preview.counts.invalid }} errors</span></div>
      <div class="roster-table-wrap"><table><thead><tr><th>CSV row</th><th>Student number</th><th>Name</th><th>Email on record</th><th>Program / curriculum</th><th>Year</th><th>Status</th><th>Review</th></tr></thead><tbody><tr v-for="row in preview.rows" :key="row.row"><td>{{ row.row }}</td><td>{{ row.data.student_number }}</td><td>{{ row.data.last_name }}, {{ row.data.first_name }}</td><td>{{ row.data.enrollment_email || 'Not recorded' }}</td><td>{{ row.data.course_code }} / {{ row.data.curriculum_code }}</td><td>{{ row.data.year_level }}</td><td>{{ row.data.student_status }}</td><td :class="row.errors.length ? 'roster-invalid' : 'roster-valid'">{{ row.errors.length ? row.errors.join(' ') : 'Ready' }}</td></tr></tbody></table></div>
      <div v-if="preview.preview_token" class="roster-confirm"><label><input v-model="reviewed" type="checkbox" /> I reviewed the official records and understand the imported accounts remain inactive until separately prepared and claimed.</label><button type="button" :disabled="!canConfirm" @click="confirm">Confirm {{ preview.counts.total }} records</button></div>
    </section>
  </main>
</template>

<style scoped>
.roster-import{max-width:1250px;margin:0 auto;padding:24px;display:grid;gap:16px;color:var(--color-eerie-black,#162822)}.roster-heading,.roster-section-heading{display:flex;align-items:start;justify-content:space-between;gap:16px}.roster-heading h1{font-size:1.7rem;margin:3px 0}.roster-heading p,.roster-section-heading p{color:var(--color-muted,#60726b);margin:4px 0;line-height:1.5}.roster-kicker{color:var(--color-dartmouth-green,#087443)!important;font-size:.75rem;font-weight:800;letter-spacing:.08em;text-transform:uppercase}.roster-heading a{color:var(--color-dartmouth-green,#087443);font-weight:700;white-space:nowrap}.roster-card{background:var(--color-surface,#fff);border:1px solid var(--color-border,#d9e5de);border-radius:14px;padding:20px;display:grid;gap:16px}.roster-card h2{font-size:1.1rem;margin:0}.roster-steps{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;list-style:none;margin:0;padding:0;counter-reset:step}.roster-steps li{border:1px solid var(--color-border,#d9e5de);border-radius:10px;padding:14px;display:grid;gap:6px;align-content:start;line-height:1.45}.roster-steps li::before{counter-increment:step;content:counter(step);display:grid;place-items:center;width:25px;height:25px;background:#e6f4eb;color:#087443;border-radius:50%;font-weight:800}.roster-steps span,.roster-file small{color:var(--color-muted,#60726b);font-size:.84rem}.roster-file{display:grid;gap:7px;max-width:530px;font-weight:700}.roster-file input{max-width:100%;border:1px dashed var(--color-border,#d9e5de);border-radius:9px;padding:12px;background:var(--color-surface,#fff)}.roster-actions,.roster-confirm{display:flex;align-items:center;gap:10px;flex-wrap:wrap}.roster-card button{border:1px solid #087443;border-radius:8px;background:#087443;color:white;font:inherit;font-weight:700;padding:10px 15px;cursor:pointer;justify-self:start}.roster-card button.roster-secondary{background:var(--color-surface,#fff);color:#087443}.roster-card button:disabled{opacity:.5;cursor:not-allowed}.roster-table-wrap{overflow:auto;border:1px solid var(--color-border,#d9e5de);border-radius:9px;max-height:470px}.roster-table-wrap table{border-collapse:collapse;width:100%;min-width:850px;font-size:.8rem}.roster-table-wrap th,.roster-table-wrap td{padding:9px;text-align:left;vertical-align:top;border-bottom:1px solid var(--color-border,#d9e5de)}.roster-table-wrap th{position:sticky;top:0;background:var(--color-surface,#fff)}.roster-valid{color:#087443}.roster-invalid,.roster-error{color:#a32626}.roster-error,.roster-success{padding:12px;border-radius:8px;background:#fde8e8;margin:0}.roster-success{background:#e1f5e8;color:#17623f}.roster-confirm{justify-content:space-between;border-top:1px solid var(--color-border,#d9e5de);padding-top:14px}.roster-confirm label{display:flex;align-items:start;gap:8px;max-width:650px;line-height:1.4}@media(max-width:800px){.roster-steps{grid-template-columns:1fr}.roster-heading,.roster-section-heading{flex-direction:column}.roster-import{padding:14px}}
.roster-hint{margin:0;color:var(--color-muted,#60726b);font-size:.83rem;line-height:1.5;overflow-wrap:anywhere}
</style>
