<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { admissionApi, admissionError, topics } from './services/workflowService'
const props = defineProps({ title: String, mode: String })
const data = ref(null), error = ref(''), busy = ref(false), form = ref(null), search = ref(''), page = ref(1)
const topicFilter = ref(''), fieldErrors = ref({})
const dateLabel = value => value ? new Date(value).toLocaleString() : 'Not set'
const programStatus = row => data.value?.settings?.find(s => s.course_id === row.id)?.status || 'Not configured'
const programSubjects = ref(''), programCareers = ref('')
const rows = computed(() => props.mode === 'cycles' ? data.value?.cycles || [] : props.mode === 'questions' ? data.value?.questions?.data || [] : data.value?.courses || [])
async function load() {
  busy.value = true; error.value = ''
  try { data.value = await admissionApi('get', 'admin/' + props.mode, { search: search.value, topic: topicFilter.value, page: page.value }) }
  catch (e) { error.value = admissionError(e) }
  finally { busy.value = false }
}
function edit(row) {
  error.value = ''; fieldErrors.value = {}
  if (props.mode === 'cycles') {
    form.value = row ? { ...row, expected_updated_at: row.updated_at } : { code: '', name: '', academic_year_id: data.value.academic_years[0]?.id, status: 'draft', opens_at: '', closes_at: '', confirmation_closes_at: '' }
    for (const key of ['opens_at','closes_at','confirmation_closes_at']) {
      if (form.value[key]) {
        const date = new Date(form.value[key])
        form.value[key] = new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0,16)
      }
    }
  } else if (props.mode === 'questions') {
    form.value = row ? { ...row } : { question_code: '', topic: topics[0], question_text: '', option_a: '', option_b: '', option_c: '', option_d: '', correct_answer: 'A', difficulty: 'medium', status: 'draft' }
  } else {
    const setting = data.value.settings.find(s => s.course_id === row.id)
    form.value = setting ? JSON.parse(JSON.stringify(setting)) : { course_id: row.id, status: 'inactive', is_recommendable: false, program_type: 'degree', description: '', duration: '', subjects: [], career_paths: [], recommendation_profile: Object.fromEntries(topics.map(t => [t, 0])), display_order: 0 }
    programSubjects.value = form.value.subjects?.join('\n') || ''
    programCareers.value = form.value.career_paths?.join('\n') || ''
  }
}
async function save() {
  if (busy.value) return
  if (props.mode === 'cycles' && ['open','closed'].includes(form.value.status) && !window.confirm('Save this cycle as ' + form.value.status + '? This changes application availability.')) return
  busy.value = true; error.value = ''; fieldErrors.value = {}
  try {
    const body = { ...form.value }
    if (props.mode === 'cycles') for (const key of ['opens_at','closes_at','confirmation_closes_at']) body[key] = new Date(body[key]).toISOString()
    if (props.mode === 'programs') {
      body.subjects = programSubjects.value.split('\n').map(s=>s.trim()).filter(Boolean)
      body.career_paths = programCareers.value.split('\n').map(s=>s.trim()).filter(Boolean)
    }
    const id = props.mode === 'programs' ? body.course_id : body.id
    await admissionApi(id ? 'put' : 'post', 'admin/' + props.mode + (id ? '/' + id : ''), body)
    form.value = null
    await load()
  } catch (e) { error.value = admissionError(e); fieldErrors.value = e?.response?.status === 422 ? e.response.data?.errors || {} : {} }
  finally { busy.value = false }
}
async function remove(row) {
  if (busy.value || !window.confirm('Delete this unused question? Assigned questions must be retired instead.')) return
  busy.value = true
  try { await admissionApi('delete', 'admin/questions/' + row.id, { version: row.version }); await load() }
  catch (e) { error.value = admissionError(e) }
  finally { busy.value = false }
}
async function changeStatus(row, status) { edit(row); form.value.status = status; await save() }
watch(() => props.mode, () => { topicFilter.value = ''; search.value = ''; fieldErrors.value = {}; data.value = null; form.value = null; page.value = 1; load() })
onMounted(load)
</script>
<template>
  <section class="admission-workflow">
    <h1>{{ title }}</h1>
    <div class="toolbar">
      <button :disabled="busy" @click="load">Reload</button>
      <button v-if="mode !== 'programs'" :disabled="busy || !data" @click="edit(null)">New {{ mode === 'cycles' ? 'cycle' : 'question' }}</button>
      <template v-if="mode === 'questions'"><input v-model="search" aria-label="Search questions" placeholder="Search questions"><select v-model="topicFilter" aria-label="Filter by topic"><option value="">All topics</option><option v-for="topic in topics" :key="topic">{{ topic }}</option></select><button :disabled="busy" @click="page = 1; load()">Search</button></template>
    </div>
    <p v-if="busy" role="status">Loading or saving...</p><p v-if="error" role="alert">{{ error }}</p>
    <div v-if="mode === 'questions' && data" class="panel"><h2>Exam readiness: 20 active questions per topic required</h2><p v-if="topics.some(topic => (data.counts[topic] || 0) < 20)" role="status">Not exam-ready. Each topic needs at least 20 active questions before applicants can start.</p><div class="grid"><div v-for="topic in topics" :key="topic" class="readiness"><strong>{{ topic }}</strong><p>{{ data.counts[topic] || 0 }} / 20 active</p><span class="badge">{{ (data.counts[topic] || 0) >= 20 ? 'Ready' : 'Needs questions' }}</span></div></div></div>
    <form v-if="form" class="panel" @submit.prevent="save">
      <h2>{{ form.id ? 'Edit' : 'Configure' }} {{ mode }}</h2>
      <template v-if="mode === 'cycles'">
        <div class="grid">
          <label>Code<input v-model="form.code" required maxlength="16"><small v-if="fieldErrors.code" class="field-error">{{ fieldErrors.code.join(" ") }}</small></label>
          <label>Name<input v-model="form.name" required><small v-if="fieldErrors.name" class="field-error">{{ fieldErrors.name.join(" ") }}</small></label>
          <label>Academic year<select v-model="form.academic_year_id" required><option v-for="year in data.academic_years" :key="year.id" :value="year.id">{{ year.school_year }}</option></select></label>
          <label>Status<select v-model="form.status"><option v-for="s in ['draft','open','closed','archived']" :key="s">{{ s }}</option></select></label>
          <label>Applications open (local time)<input v-model="form.opens_at" type="datetime-local" required><small v-if="fieldErrors.opens_at" class="field-error">{{ fieldErrors.opens_at.join(" ") }}</small></label>
          <label>Applications close (local time)<input v-model="form.closes_at" type="datetime-local" required><small v-if="fieldErrors.closes_at" class="field-error">{{ fieldErrors.closes_at.join(" ") }}</small></label>
          <label>Confirmation closes (local time)<input v-model="form.confirmation_closes_at" type="datetime-local" required><small v-if="fieldErrors.confirmation_closes_at" class="field-error">{{ fieldErrors.confirmation_closes_at.join(" ") }}</small></label>
        </div>
      </template>
      <template v-else-if="mode === 'questions'">
        <div class="grid"><label>Question code<input v-model="form.question_code" required maxlength="64"></label><label>Topic<select v-model="form.topic"><option v-for="t in topics" :key="t">{{ t }}</option></select></label></div>
        <label>Question<textarea v-model="form.question_text" required rows="3"></textarea></label>
        <div class="grid"><label v-for="key in ['a','b','c','d']" :key="key">Option {{ key.toUpperCase() }}<input v-model="form['option_'+key]" required></label></div>
        <div class="grid"><label>Correct answer<select v-model="form.correct_answer"><option v-for="a in ['A','B','C','D']" :key="a">{{ a }}</option></select></label><label>Difficulty<select v-model="form.difficulty"><option>easy</option><option>medium</option><option>hard</option></select></label><label>Status<select v-model="form.status"><option>draft</option><option>active</option><option>retired</option></select></label></div>
      </template>
      <template v-else>
        <p>Choose whether applicants can receive this program as a recommendation. Use school-approved topic weights.</p>
        <div class="grid"><label>Status<select v-model="form.status"><option>active</option><option>inactive</option></select></label><label>Recommendable<input v-model="form.is_recommendable" type="checkbox"></label><label>Type<select v-model="form.program_type"><option>degree</option><option>certificate</option></select></label><label>Duration<input v-model="form.duration"></label><label>Display order<input v-model.number="form.display_order" type="number" min="0"></label></div>
        <label>Description<textarea v-model="form.description"></textarea></label>
        <div class="grid"><label>Subjects (one per line)<textarea v-model="programSubjects"></textarea></label><label>Career paths (one per line)<textarea v-model="programCareers"></textarea></label></div>
        <div class="grid"><label v-for="(_,topic) in form.recommendation_profile" :key="topic">{{ topic }} weight<input v-model.number="form.recommendation_profile[topic]" type="number" min="0" max="1" step="0.01"></label></div>
      </template>
      <div class="toolbar"><button class="primary" :disabled="busy">Save</button><button type="button" :disabled="busy" @click="form = null">Cancel</button></div>
    </form>
    <div class="panel table-wrap"><table><thead><tr><th>Code</th><th>{{ mode === 'questions' ? 'Question / topic' : 'Name' }}</th><th>Status</th><th v-if="mode === 'cycles'">Application dates</th><th>Actions</th></tr></thead><tbody>
      <tr v-for="row in rows" :key="row.id"><td>{{ row.code || row.question_code || row.course_code }}</td><td>{{ row.name || row.question_text || row.course_name }}<p v-if="row.topic" class="muted">{{ row.topic }}</p></td><td><span class="badge" :class="{ positive: row.status === 'open' || row.status === 'active' }">{{ mode === 'programs' ? programStatus(row) : row.status }}</span></td><td v-if="mode === 'cycles'">Opens: {{ dateLabel(row.opens_at) }}<br>Closes: {{ dateLabel(row.closes_at) }}<br>Confirmation: {{ dateLabel(row.confirmation_closes_at) }}</td><td><button :disabled="busy" @click="edit(row)">Edit</button> <button v-if="mode === 'cycles' && ['draft','closed'].includes(row.status)" :disabled="busy" @click="changeStatus(row, 'open')">Open</button><button v-if="mode === 'cycles' && row.status === 'open'" :disabled="busy" @click="changeStatus(row, 'closed')">Close</button> <button v-if="mode === 'questions'" :disabled="busy" @click="remove(row)">Delete</button></td></tr>
      <tr v-if="!busy && !error && !rows.length"><td colspan="4">No records yet.</td></tr>
    </tbody></table></div>
    <div v-if="mode === 'questions' && data" class="toolbar"><button :disabled="busy || page <= 1" @click="page--; load()">Previous page</button><span>Page {{ page }} of {{ data.questions.last_page }}</span><button :disabled="busy || page >= data.questions.last_page" @click="page++; load()">Next page</button></div>
  </section>
</template>
<style src="./admission.css"></style>
