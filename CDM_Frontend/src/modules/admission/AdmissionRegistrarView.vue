<script setup>
import AdmissionDialog from './components/AdmissionDialog.vue'
import { computed, onMounted, ref, watch } from 'vue'
import AdmissionConversionPanel from './components/AdmissionConversionPanel.vue'
import { useRoute } from 'vue-router'
import { useStepUpAuth } from '../../composables/useStepUpAuth'
import { admissionApi, admissionError } from './services/workflowService'
const props=defineProps({title:String,mode:String})
const route = useRoute()
const { runWithStepUp }=useStepUpAuth()
const data=ref(null), busy=ref(false), error=ref(''), search=ref(route.query.search || ''), status=ref(''), page=ref(1), selected=ref([]), action=ref(null), score=ref(null), reason=ref(''), latest=ref(true)
const inspecting = ref(null), resultInspection = ref(null), cycles = ref([]), cycle = ref(''), attempt = ref(''), tab = ref('')
const tabs = [{ value: '', label: 'All Results' }, { value: 'pending', label: 'Pending Review' }, { value: 'approved', label: 'Ready to Publish' }, { value: 'published', label: 'Published' }, { value: 'retake', label: 'Retake' }]
const allows = (row, kind) => row.allowed_actions?.includes(kind)
const batchAllowed = kind => selected.value.length > 0 && rows.value.filter(r => selected.value.includes(r.id)).every(r => allows(r, kind))
async function inspect(row) {
  busy.value = true; error.value = ''
  try { inspecting.value = await admissionApi('get', 'registrar/applicants/' + row.id) }
  catch (e) { error.value = admissionError(e) }
  finally { busy.value = false }
}
const readable = value => String(value || 'Not recorded').replaceAll('_', ' ').replaceAll('.', ' · ')
const endpoint=computed(()=>props.mode==='review'?'results':props.mode)
const rows=computed(()=>props.mode==='history'?data.value?.timeline?.data || []:data.value?.data || [])
async function load() {
  busy.value=true; error.value=''; selected.value=[]
  try { data.value=await admissionApi('get','registrar/'+endpoint.value,{search:search.value,status:status.value,page:page.value,latest:latest.value?1:0,cycle_id:cycle.value,attempt:attempt.value,tab:tab.value}) }
  catch(e) { error.value=admissionError(e) }
  finally { busy.value=false }
}
function begin(kind, row=null) {
  const items=row?[row]:rows.value.filter(r=>selected.value.includes(r.id))
  if(!items.length || !items.every(r => allows(r, kind))) return
  action.value={kind,items:items.map(r=>({id:r.id,version:r.version})),name:row?.name}
  score.value=row ? (row.official_score ?? Math.round(row.system_percentage)) : null
  reason.value=''
}
async function confirm() {
  if(busy.value) return
  busy.value=true; error.value=''
  try {
    const pending=action.value
    await runWithStepUp(()=>admissionApi('post','registrar/results/'+pending.kind,{results:pending.items,official_score:score.value,reason:reason.value || null}))
    action.value=null; await load()
  } catch(e) { error.value=admissionError(e); action.value=null }
  finally { busy.value=false }
}
watch(()=>props.mode,()=>{data.value=null;action.value=null;inspecting.value=null;resultInspection.value=null;tab.value='';cycle.value='';attempt.value='';status.value='';search.value=route.query.search || '';page.value=1;load()})
onMounted(async () => {
  await load()
  try { const response = await admissionApi('get', 'registrar/cycles'); cycles.value = Array.isArray(response) ? response : [] }
  catch (e) { error.value = admissionError(e) }
})
</script>
<template>
  <section class="admission-workflow"><header class="page-header"><p class="page-kicker">Admission</p><h1 class="page-title">{{ title }}</h1></header>
    <div v-if="mode === 'results'" class="toolbar" role="group" aria-label="Result sections"><button v-for="item in tabs" :key="item.value" :class="{ primary: tab === item.value }" :aria-pressed="tab === item.value" :disabled="busy" @click="tab = item.value; page = 1; load()">{{ item.label }}</button></div>
    <div class="toolbar filters"><select v-if="mode !== 'history'" v-model="cycle" aria-label="Admission cycle filter"><option value="">All cycles</option><option v-for="c in cycles" :key="c.id" :value="c.id">{{ c.name }}</option></select><select v-if="mode === 'results'" v-model="attempt" aria-label="Attempt filter"><option value="">All attempts</option><option value="1">Attempt 1</option><option value="2">Attempt 2</option></select><input v-if="mode !== 'history'" v-model="search" placeholder="Name or applicant number" aria-label="Search applicants"><select v-if="mode !== 'history'" v-model="status" aria-label="Status filter"><option value="">All statuses</option><option v-for="s in mode === 'applicants' ? ['draft','submitted','under_review','accepted','rejected','withdrawn','expired','converted'] : ['pending','approved','published']" :key="s">{{ s }}</option></select><label v-if="['results','review'].includes(mode)">Latest attempt only<input v-model="latest" type="checkbox"></label><button :disabled="busy" @click="page = 1; load()">Search / refresh</button></div>
    <p v-if="busy" class="placeholder-panel panel status-block" role="status">Loading or saving...</p><p v-if="error" class="placeholder-panel panel status-block" role="alert">{{ error }}</p>
    <div v-if="mode === 'results'" class="toolbar"><button class="primary" :disabled="busy || !batchAllowed('approve')" @click="begin('approve')">Approve selected using system scores</button><button class="primary" :disabled="busy || !batchAllowed('publish')" @click="begin('publish')">Publish selected</button></div>
    <p v-if="mode === 'history' && !busy && !error && !rows.length" class="placeholder-panel panel">No Admission history yet.</p>
    <ol v-if="mode === 'history'" class="admission-timeline" aria-label="Admission history timeline"><li v-for="row in rows" :key="row.id" class="placeholder-panel panel"><time>{{ new Date(row.created_at).toLocaleString() }}</time><h2>{{ row.action }}</h2><p>{{ row.subject }}<span v-if="row.applicant_number"> · {{ row.applicant_number }}</span><span v-if="row.cycle"> · {{ row.cycle }}</span></p><p>{{ row.summary }}</p><small>By {{ row.actor }}</small></li></ol>
    <div v-if="mode !== 'history'" class="placeholder-panel panel table-wrap" tabindex="0" role="region" :aria-label="title + ' table, scroll horizontally for more columns'"><table><thead><tr v-if="mode === 'applicants'"><th>Applicant</th><th>Cycle</th><th>Application</th><th>Exam / latest result</th><th>Acceptance / conversion</th><th>Created</th><th>Inspect</th></tr><tr v-else><th>Select</th><th>Applicant / attempt</th><th>System score</th><th>Official result</th><th>Review</th></tr></thead><tbody>
      <template v-for="row in rows" :key="row.id">
        <tr v-if="mode === 'applicants'"><td>{{ row.name }}<p>{{ row.applicant_number }}</p></td><td>{{ row.cycle }}</td><td><span class="badge">{{ readable(row.status) }}</span></td><td>{{ readable(row.exam_status) }}<p>{{ readable(row.latest_result) }}</p></td><td>{{ readable(row.acceptance_status) }}<p>{{ readable(row.conversion_status) }}</p></td><td>{{ new Date(row.created_at).toLocaleString() }}</td><td><button class="primary" :disabled="busy" @click="inspect(row)">Inspect applicant</button></td></tr>
        <tr v-else><td><input v-model="selected" type="checkbox" :value="row.id" :aria-label="'Select result '+row.id"></td><td>{{ row.name }}<p>{{ row.applicant_number }}</p><p>Attempt {{ row.attempt_number }} · Result {{ row.id }} · v{{ row.version }}</p></td><td>{{ row.system_percentage }}% · {{ row.system_passed ? 'PASSED' : 'NOT PASSED' }}<details><summary>Topic evidence</summary><p v-for="(count,topic) in row.category_scores" :key="topic">{{ topic }}: {{ count }} / {{ row.category_maximums[topic] }}</p></details></td><td><span class="badge">{{ readable(row.official_status) }}</span> · {{ row.outcome }}<p>Score: {{ row.official_score ?? 'Not approved' }}</p><p v-if="row.registrar_pass">Registrar pass · {{ row.internal_reason }}</p></td><td><button :disabled="busy" @click="resultInspection = row">Inspect</button><button class="primary" v-if="allows(row, 'approve') && row.official_status === 'pending'" :disabled="busy" @click="begin('approve',row)">Approve</button><button class="primary" v-if="allows(row, 'publish')" :disabled="busy" @click="begin('publish',row)">Publish</button><details v-if="allows(row, 'correct') || allows(row, 'override')"><summary>Other decisions</summary><button v-if="allows(row, 'correct')" :disabled="busy" @click="begin('correct',row)">Correct result</button><button v-if="allows(row, 'override')" :disabled="busy" @click="begin('override',row)">Exceptional Pass</button></details></td></tr>
      </template><tr v-if="!busy && !error && !rows.length"><td colspan="6">No matching records.</td></tr>
    </tbody></table></div>
    <div class="toolbar"><button :disabled="busy || page <= 1" @click="page--; load()">Previous page</button><span>Page {{ page }}</span><button :disabled="busy || page >= (mode === 'history' ? data?.timeline?.last_page : data?.last_page)" @click="page++; load()">Next page</button></div>
    <div v-if="inspecting" class="placeholder-panel panel"><h2>{{ inspecting.name }}</h2><p>{{ inspecting.applicant_number }} · {{ inspecting.cycle }}</p><p>Status: {{ readable(inspecting.status) }}</p><div class="toolbar"><router-link class="link-button primary" :to="{ path: '/registrar/admissions/results', query: { search: inspecting.applicant_number } }">Inspect exam results</router-link><button @click="inspecting = null">Close details</button></div><h3>Exam attempts</h3><p v-if="!inspecting.attempts?.length">No exam started.</p><p v-for="exam in inspecting.attempts" :key="exam.attempt_number">Attempt {{ exam.attempt_number }} · {{ readable(exam.status) }} · {{ exam.outcome || 'Awaiting result' }} · System {{ exam.system_percentage ?? '—' }}% · Official {{ exam.official_score ?? '—' }}</p><p>Latest result: {{ inspecting.latest_result }}</p><p>Acceptance: {{ readable(inspecting.acceptance_status) }} · Conversion: {{ readable(inspecting.conversion_status) }}</p><AdmissionConversionPanel :key="inspecting.id" :applicant-id="inspecting.id" @converted="inspecting.status = 'converted'; load()" /></div>
    <AdmissionDialog v-if="resultInspection" labelledby="result-inspection" @cancel="resultInspection = null"><h2 id="result-inspection">{{ resultInspection.name }} · Attempt {{ resultInspection.attempt_number }}</h2><p>{{ resultInspection.applicant_number }} · {{ resultInspection.cycle }}</p><p>Result {{ resultInspection.id }} · Version {{ resultInspection.version }} · {{ resultInspection.outcome }}</p><p>System: {{ resultInspection.system_percentage }}% · {{ resultInspection.system_passed ? 'PASSED' : 'NOT PASSED' }}</p><p>Official: {{ resultInspection.official_score ?? 'Not approved' }} · {{ resultInspection.official_status }}</p><p v-for="(count,topic) in resultInspection.category_scores" :key="topic">{{ topic }}: {{ count }} / {{ resultInspection.category_maximums[topic] }}</p><button @click="resultInspection = null">Close</button></AdmissionDialog>
    <AdmissionDialog v-if="action" labelledby="review-confirm" :busy="busy" @cancel="action = null"><form @submit.prevent="confirm"><h2 id="review-confirm">Confirm {{ action.kind }}</h2><p>{{ action.items.length }} result(s){{ action.name ? ' — '+action.name : '' }}</p><label v-if="['approve','correct'].includes(action.kind) && action.items.length === 1">Official score<input v-model.number="score" type="number" min="0" max="100" required></label><label v-if="['correct','override'].includes(action.kind)">Internal reason (hidden from applicant)<textarea v-model="reason" required maxlength="2000"></textarea></label><p v-if="action.kind === 'override'">Only eligible second failures can receive an exceptional pass. This publishes the result immediately.</p><div class="toolbar"><button type="button" :disabled="busy" @click="action = null">Cancel</button><button class="primary" :disabled="busy">Confirm</button></div></form></AdmissionDialog>
  </section>
</template>
<style src="./admission.css"></style>
