<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import AdmissionConversionPanel from './components/AdmissionConversionPanel.vue'
import { useRoute } from 'vue-router'
import { useStepUpAuth } from '../../composables/useStepUpAuth'
import { admissionApi, admissionError } from './services/workflowService'
const props=defineProps({title:String,mode:String})
const route = useRoute()
const { runWithStepUp }=useStepUpAuth()
const data=ref(null), busy=ref(false), error=ref(''), search=ref(route.query.search || ''), status=ref(''), page=ref(1), selected=ref([]), action=ref(null), score=ref(null), reason=ref(''), latest=ref(true)
const inspecting = ref(null)
const readable = value => String(value || 'Not recorded').replaceAll('_', ' ').replaceAll('.', ' · ')
const endpoint=computed(()=>props.mode==='review'?'results':props.mode)
const rows=computed(()=>props.mode==='history'?data.value?.decisions?.data || []:data.value?.data || [])
async function load() {
  busy.value=true; error.value=''; selected.value=[]
  try { data.value=await admissionApi('get','registrar/'+endpoint.value,{search:search.value,status:status.value,page:page.value,latest:latest.value?1:0}) }
  catch(e) { error.value=admissionError(e) }
  finally { busy.value=false }
}
function begin(kind, row=null) {
  const items=row?[row]:rows.value.filter(r=>selected.value.includes(r.id))
  if(!items.length) return
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
watch(()=>props.mode,()=>{data.value=null;action.value=null;inspecting.value=null;status.value='';search.value=route.query.search || '';page.value=1;load()})
onMounted(load)
</script>
<template>
  <section class="admission-workflow"><h1>{{ title }}</h1>
    <div class="toolbar"><input v-if="mode !== 'history'" v-model="search" placeholder="Name or applicant number" aria-label="Search applicants"><select v-if="mode !== 'history'" v-model="status" aria-label="Status filter"><option value="">All statuses</option><option v-for="s in mode === 'applicants' ? ['draft','submitted','under_review','accepted','rejected','withdrawn','expired','converted'] : ['pending','approved','published']" :key="s">{{ s }}</option></select><label v-if="['results','review'].includes(mode)">Latest attempt only<input v-model="latest" type="checkbox"></label><button :disabled="busy" @click="page = 1; load()">Search / refresh</button></div>
    <p v-if="busy" role="status">Loading or saving...</p><p v-if="error" role="alert">{{ error }}</p>
    <div v-if="['results','review'].includes(mode)" class="toolbar"><button :disabled="busy || !selected.length" @click="begin('approve')">Approve selected using system scores</button><button :disabled="busy || !selected.length" @click="begin('publish')">Publish selected</button><details><summary>Exceptional decisions</summary><button :disabled="busy || !selected.length" @click="begin('override')">Registrar pass selected</button></details></div>
    <div class="panel table-wrap"><table><thead><tr v-if="mode === 'applicants'"><th>Applicant</th><th>Cycle</th><th>Status</th><th>Created</th><th>Inspect</th></tr><tr v-else-if="mode === 'history'"><th>Result</th><th>Action</th><th>Version</th><th>Actor</th><th>Internal reason / decision</th><th>Date</th></tr><tr v-else><th>Select</th><th>Applicant / attempt</th><th>System score</th><th>Official result</th><th>Review</th></tr></thead><tbody>
      <template v-for="row in rows" :key="row.id">
        <tr v-if="mode === 'applicants'"><td>{{ row.name }}<p>{{ row.applicant_number }}</p></td><td>{{ row.cycle }}</td><td>{{ row.status }}</td><td>{{ new Date(row.created_at).toLocaleString() }}</td><td><button :disabled="busy" @click="inspecting = row">Inspect applicant</button></td></tr>
        <tr v-else-if="mode === 'history'"><td>{{ row.result_id }}</td><td>{{ readable(row.action) }}</td><td>{{ row.result_version }}</td><td>{{ row.actor_user_id }}</td><td>{{ row.internal_reason || 'Routine result decision' }}<details><summary>Before / after</summary><div v-for="(value,key) in row.after" :key="key" class="history-change"><strong>{{ readable(key) }}</strong><span>{{ row.before?.[key] ?? 'Not recorded' }} → {{ value ?? 'Not recorded' }}</span></div></details></td><td>{{ new Date(row.created_at).toLocaleString() }}</td></tr>
        <tr v-else><td><input v-model="selected" type="checkbox" :value="row.id" :aria-label="'Select result '+row.id"></td><td>{{ row.name }}<p>{{ row.applicant_number }}</p><p>Attempt {{ row.attempt_number }} · Result {{ row.id }} · v{{ row.version }}</p></td><td>{{ row.system_percentage }}<details><summary>Topic evidence</summary><p v-for="(count,topic) in row.category_scores" :key="topic">{{ topic }}: {{ count }} / {{ row.category_maximums[topic] }}</p></details></td><td>{{ row.official_status }} · {{ row.outcome }}<p>Score: {{ row.official_score ?? 'Not approved' }}</p><p v-if="row.registrar_pass">Registrar pass · {{ row.internal_reason }}</p></td><td><button v-if="row.official_status === 'pending'" :disabled="busy" @click="begin('approve',row)">Approve</button><button v-if="row.official_status === 'approved'" :disabled="busy" @click="begin('publish',row)">Publish</button><details v-if="row.official_status === 'published' || (row.attempt_number === 2 && !row.system_passed && !row.registrar_pass)"><summary>Other decisions</summary><button v-if="row.official_status === 'published'" :disabled="busy" @click="begin('correct',row)">Correct result</button><button v-if="row.attempt_number === 2 && !row.system_passed && !row.registrar_pass" :disabled="busy" @click="begin('override',row)">Registrar pass</button></details></td></tr>
      </template><tr v-if="!busy && !error && !rows.length"><td colspan="6">No matching records.</td></tr>
    </tbody></table></div>
    <div class="toolbar"><button :disabled="busy || page <= 1" @click="page--; load()">Previous page</button><span>Page {{ page }}</span><button :disabled="busy || page >= (mode === 'history' ? data?.decisions?.last_page : data?.last_page)" @click="page++; load()">Next page</button></div>
    <details v-if="mode === 'history' && data?.events?.length" class="panel"><summary>Recent Admission workflow events</summary><p v-for="event in data.events" :key="event.id">{{ event.created_at }} · {{ readable(event.action) }} · {{ event.subject_type }} {{ event.subject_id }}</p></details>
    <div v-if="inspecting" class="panel"><h2>{{ inspecting.name }}</h2><p>{{ inspecting.applicant_number }} · {{ inspecting.cycle }}</p><p>Status: {{ readable(inspecting.status) }}</p><div class="toolbar"><router-link class="link-button primary" :to="{ path: '/registrar/admissions/results', query: { search: inspecting.applicant_number } }">Inspect exam results</router-link><button @click="inspecting = null">Close details</button></div><AdmissionConversionPanel :key="inspecting.id" :applicant-id="inspecting.id" @converted="inspecting.status = 'converted'; load()" /></div>
    <dialog v-if="action" open aria-labelledby="review-confirm"><form @submit.prevent="confirm"><h2 id="review-confirm">Confirm {{ action.kind }}</h2><p>{{ action.items.length }} result(s){{ action.name ? ' — '+action.name : '' }}</p><label v-if="['approve','correct'].includes(action.kind) && action.items.length === 1">Official score<input v-model.number="score" type="number" min="0" max="100" required></label><label v-if="['correct','override'].includes(action.kind)">Internal reason (hidden from applicant)<textarea v-model="reason" required maxlength="2000"></textarea></label><p v-if="action.kind === 'override'">Only eligible second failures can receive an exceptional pass. This publishes the result immediately.</p><div class="toolbar"><button type="button" :disabled="busy" @click="action = null">Cancel</button><button class="primary" :disabled="busy">Confirm</button></div></form></dialog>
  </section>
</template>
<style src="./admission.css"></style>
