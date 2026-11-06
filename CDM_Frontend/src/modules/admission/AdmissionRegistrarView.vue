<script setup>
import AdmissionStaffWorkspace from './components/AdmissionStaffWorkspace.vue'
import AdmissionDialog from './components/AdmissionDialog.vue'
import { computed, onMounted, ref, watch } from 'vue'
import AdmissionHandoffPanel from './components/AdmissionHandoffPanel.vue'
import { useRoute } from 'vue-router'
import { useStepUpAuth } from '../../composables/useStepUpAuth'
import { admissionApi, admissionError } from './services/workflowService'
import CdmBadge from '../../design-system/CdmBadge.vue'
import CdmButton from '../../design-system/CdmButton.vue'
import CdmCard from '../../design-system/CdmCard.vue'
import CdmEmptyState from '../../design-system/CdmEmptyState.vue'
import CdmInput from '../../design-system/CdmInput.vue'
import CdmLoadingState from '../../design-system/CdmLoadingState.vue'
import CdmSelect from '../../design-system/CdmSelect.vue'
const props=defineProps({title:String,mode:String})
const route = useRoute()
const { runWithStepUp }=useStepUpAuth()
const data=ref(null), busy=ref(false), error=ref(''), search=ref(route.query.search || ''), status=ref(''), page=ref(1), selected=ref([]), action=ref(null), score=ref(null), reason=ref(''), latest=ref(true)
const inspecting = ref(null), resultInspection = ref(null), cycles = ref([]), cycle = ref(''), attempt = ref(''), tab = ref('')
const filtersOpen = ref(false), historyInspection = ref(null)
const sortOrder = ref('newest')
const tabs = [{ value: '', label: 'All Results' }, { value: 'pending', label: 'Pending Review' }, { value: 'approved', label: 'Ready to Publish' }, { value: 'published', label: 'Published' }, { value: 'retake', label: 'Retake' }]
const allows = (row, kind) => row.allowed_actions?.includes(kind)
const batchAllowed = kind => selected.value.length > 0 && rows.value.filter(r => selected.value.includes(r.id)).every(r => allows(r, kind))
async function inspect(row) {
  busy.value = true; error.value = ''
  try { inspecting.value = await admissionApi('get', 'registrar/applicants/' + row.id) }
  catch (e) { error.value = admissionError(e) }
  finally { busy.value = false }
}
async function refreshApplicant() {
  if (!inspecting.value) return
  const applicant = inspecting.value
  await load()
  if (inspecting.value) await inspect(applicant)
}
const readable = value => String(value || 'Not recorded').replaceAll('_', ' ').replaceAll('.', ' · ')
const endpoint=computed(()=>props.mode==='review'?'results':props.mode)
const rows=computed(()=>{
  const records=props.mode==='history'?data.value?.timeline?.data || []:data.value?.data || []
  if(props.mode!=='history' || !search.value.trim()) return records
  const query=search.value.trim().toLowerCase()
  return records.filter(row=>[row.subject,row.applicant_number,row.action,row.actor,row.cycle,row.summary].some(value=>String(value || '').toLowerCase().includes(query)))
})
const displayRows = computed(() => {
  const records = [...rows.value]
  if (props.mode !== 'applicants') return records
  if (sortOrder.value === 'name') return records.sort((left, right) => String(left.name || '').localeCompare(String(right.name || '')))
  const direction = sortOrder.value === 'oldest' ? 1 : -1
  return records.sort((left, right) => direction * (new Date(left.created_at || 0) - new Date(right.created_at || 0)))
})
const activeFilterCount=computed(()=>[cycle.value,status.value,attempt.value,(['results','review'].includes(props.mode) && !latest.value) ? 'all-attempts' : ''].filter(Boolean).length)
const totalRecords=computed(()=>props.mode==='history' ? data.value?.timeline?.total || rows.value.length : data.value?.total || rows.value.length)
const lastPage=computed(()=>props.mode==='history' ? data.value?.timeline?.last_page || 1 : data.value?.last_page || 1)
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
watch(()=>props.mode,()=>{data.value=null;action.value=null;inspecting.value=null;resultInspection.value=null;historyInspection.value=null;filtersOpen.value=false;tab.value='';cycle.value='';attempt.value='';status.value='';search.value=route.query.search || '';page.value=1;load()})
onMounted(async () => {
  await load()
  try { const response = await admissionApi('get', 'registrar/cycles'); cycles.value = Array.isArray(response) ? response : [] }
  catch (e) { error.value = admissionError(e) }
})
</script>
<template>
  <AdmissionStaffWorkspace :title="title" description="Search, review, and manage authorized Admission records.">
    <div class="admission-record-workspace">
      <nav v-if="mode === 'results'" class="admission-status-tabs" aria-label="Result sections">
        <button v-for="item in tabs" :key="item.value" type="button" :class="{ active: tab === item.value }" :aria-pressed="tab === item.value" :disabled="busy" @click="tab = item.value; page = 1; load()">{{ item.label }}</button>
      </nav>

      <CdmCard class="admission-record-toolbar" aria-label="Search and filters">
        <form class="admission-search-form" @submit.prevent="page = 1; load()">
          <CdmInput v-model="search" :label="mode === 'history' ? 'Search history' : 'Find an applicant'" :placeholder="mode === 'history' ? 'Applicant, event, actor, or cycle' : 'Applicant name or number'" type="search" autocomplete="off" />
          <CdmButton type="submit" :loading="busy">Search</CdmButton>
          <CdmButton type="button" variant="outline" aria-controls="admission-list-filters" :aria-expanded="filtersOpen" @click="filtersOpen = !filtersOpen">Filters <span v-if="activeFilterCount" class="filter-count">{{ activeFilterCount }}</span></CdmButton>
        </form>
        <div v-if="filtersOpen" id="admission-list-filters" class="admission-filter-grid">
          <CdmSelect v-if="mode !== 'history'" v-model="cycle" label="Admission cycle"><option value="">All cycles</option><option v-for="c in cycles" :key="c.id" :value="c.id">{{ c.name }}</option></CdmSelect>
          <CdmSelect v-if="mode === 'results'" v-model="attempt" label="Exam attempt"><option value="">All attempts</option><option value="1">Attempt 1</option><option value="2">Attempt 2</option></CdmSelect>
          <CdmSelect v-if="mode !== 'history'" v-model="status" label="Status"><option value="">All statuses</option><option v-for="s in mode === 'applicants' ? ['draft','submitted','under_review','accepted','rejected','withdrawn','expired','converted'] : ['pending','approved','published']" :key="s" :value="s">{{ readable(s) }}</option></CdmSelect>
          <label v-if="['results','review'].includes(mode)" class="admission-check"><input v-model="latest" type="checkbox"> <span>Latest attempt only</span></label>
          <CdmButton type="button" size="sm" @click="page = 1; load()">Apply filters</CdmButton>
        </div>
      </CdmCard>

      <div class="admission-list-summary"><p><strong>{{ totalRecords }}</strong> {{ totalRecords === 1 ? 'record' : 'records' }}</p><span>Page {{ page }} of {{ lastPage }}</span></div>
      <p v-if="error" class="admission-record-alert" role="alert">{{ error }}</p>
      <CdmLoadingState v-if="busy && !data" label="Loading Admission records…" />

      <CdmCard v-if="mode === 'results' && selected.length" class="admission-batch-bar">
        <strong>{{ selected.length }} selected</strong>
        <div><CdmButton size="sm" :disabled="busy || !batchAllowed('approve')" @click="begin('approve')">Approve with system scores</CdmButton><CdmButton size="sm" variant="outline" :disabled="busy || !batchAllowed('publish')" @click="begin('publish')">Publish selected</CdmButton></div>
      </CdmCard>

      <div class="admission-record-layout" :class="{ 'has-detail': mode === 'applicants' && inspecting }">
        <section v-if="mode === 'applicants'" class="admission-directory" aria-label="Admission applicants">
          <header class="admission-directory-heading"><div><strong>Applicant directory</strong><span>Application, examination, and admission status in one review list.</span></div><label>Sort<select v-model="sortOrder"><option value="newest">Newest submitted</option><option value="oldest">Oldest submitted</option><option value="name">Applicant name</option></select></label></header>
          <CdmEmptyState v-if="!busy && !displayRows.length" title="No matching applicants" description="Adjust the search or status filters." />
          <div v-else class="admission-applicant-list" role="list">
            <article v-for="row in displayRows" :key="row.id" class="admission-applicant-row" :class="{ selected: inspecting?.id === row.id }" role="listitem">
              <span class="admission-applicant-primary"><strong>{{ row.name }}</strong><small>{{ row.applicant_number }} · {{ row.cycle }}</small></span>
              <span><small>Application</small><CdmBadge :status="row.status">{{ readable(row.status) }}</CdmBadge></span>
              <span><small>Examination</small><CdmBadge :status="row.latest_result || row.exam_status">{{ readable(row.latest_result || row.exam_status) }}</CdmBadge></span>
              <span><small>Admission</small><CdmBadge :status="row.acceptance_status">{{ readable(row.acceptance_status) }}</CdmBadge></span>
              <time :datetime="row.created_at">{{ new Date(row.created_at).toLocaleDateString() }}</time><CdmButton size="sm" variant="outline" :disabled="busy" @click="inspect(row)">Open record</CdmButton>
            </article>
          </div>
        </section>

        <section v-else-if="mode === 'results' || mode === 'review'" class="admission-results-workspace" aria-label="Admission examination results">
          <CdmEmptyState v-if="!busy && !rows.length" title="No matching results" description="No examination results match the selected publication filters." />
          <article v-for="row in rows" :key="row.id" class="admission-result-row">
            <label class="admission-result-select"><input v-model="selected" type="checkbox" :value="row.id" :aria-label="'Select result '+row.id"><span class="sr-only">Select</span></label>
            <div class="admission-result-person"><strong>{{ row.name }}</strong><small>{{ row.applicant_number }} · {{ row.cycle }} · Attempt {{ row.attempt_number }}</small></div>
            <div class="admission-result-score"><small>System score</small><strong>{{ row.system_percentage }}%</strong><span>{{ row.system_passed ? 'Passed' : 'Not passed' }}</span></div>
            <div class="admission-result-official"><small>Registrar decision</small><CdmBadge :status="row.official_status">{{ readable(row.official_status) }}</CdmBadge><span>{{ row.outcome }} · {{ row.official_score ?? 'Not approved' }}</span></div>
            <div class="admission-row-actions"><CdmButton size="sm" variant="ghost" @click="resultInspection = row">View</CdmButton><CdmButton v-if="allows(row, 'approve') && row.official_status === 'pending'" size="sm" :disabled="busy" @click="begin('approve',row)">Approve</CdmButton><CdmButton v-if="allows(row, 'publish')" size="sm" variant="outline" :disabled="busy" @click="begin('publish',row)">Publish</CdmButton><details v-if="allows(row, 'correct') || allows(row, 'override')"><summary>More</summary><button v-if="allows(row, 'correct')" type="button" :disabled="busy" @click="begin('correct',row)">Correct result</button><button v-if="allows(row, 'override')" type="button" :disabled="busy" @click="begin('override',row)">Exceptional Pass</button></details></div>
          </article>
        </section>

        <section v-else class="admission-history-archive" aria-label="Admission history">
          <CdmEmptyState v-if="!busy && !rows.length" title="No Admission history yet" description="Historical Admission activity will appear here." />
          <article v-for="row in rows" :key="row.id" class="admission-history-entry">
            <span class="admission-history-marker" aria-hidden="true"></span><div><small>{{ new Date(row.created_at).toLocaleString() }}</small><h3>{{ row.action }}</h3><p>{{ row.subject }} · {{ row.applicant_number || row.cycle || 'System event' }}</p><span>Recorded by {{ row.actor }}</span></div><CdmButton size="sm" variant="outline" @click="historyInspection = row">View</CdmButton>
          </article>
        </section>

        <aside v-if="mode === 'applicants' && inspecting" class="admission-record-detail" aria-labelledby="applicant-inspection">
          <header><div><small>Applicant record</small><h2 id="applicant-inspection">{{ inspecting.name }}</h2><p>{{ inspecting.applicant_number }} · {{ inspecting.cycle }}</p></div><CdmButton size="icon" variant="ghost" aria-label="Close applicant record" @click="inspecting = null">×</CdmButton></header>
          <div class="admission-detail-scroll"><section><h3>Application overview</h3><dl><div><dt>Application</dt><dd><CdmBadge :status="inspecting.status">{{ readable(inspecting.status) }}</CdmBadge></dd></div><div><dt>Latest result</dt><dd>{{ readable(inspecting.latest_result) }}</dd></div><div><dt>Admission</dt><dd>{{ readable(inspecting.acceptance_status) }}</dd></div><div><dt>Student identity</dt><dd>{{ readable(inspecting.student_identity_status) }}</dd></div></dl></section><section><h3>Exam attempts</h3><CdmEmptyState v-if="!inspecting.attempts?.length" title="No exam started" description="This applicant has no examination attempt." /><div v-for="exam in inspecting.attempts" :key="exam.attempt_number" class="admission-attempt"><strong>Attempt {{ exam.attempt_number }}</strong><span>{{ readable(exam.status) }} · {{ exam.outcome || 'Awaiting result' }}</span><small>System {{ exam.system_percentage ?? '—' }}% · Official {{ exam.official_score ?? '—' }}</small></div><CdmButton size="sm" variant="outline" :to="{ path: '/registrar/admissions/results', query: { search: inspecting.applicant_number } }">Inspect exam results</CdmButton></section><section><h3>Decision and Enrollment handoff</h3><AdmissionHandoffPanel :key="inspecting.id" :applicant-id="inspecting.id" @completed="refreshApplicant" /></section></div>
        </aside>
      </div>

      <nav class="admission-pagination" aria-label="Record pages"><CdmButton variant="outline" size="sm" :disabled="busy || page <= 1" @click="page--; load()">Previous</CdmButton><span>Page {{ page }} of {{ lastPage }}</span><CdmButton variant="outline" size="sm" :disabled="busy || page >= lastPage" @click="page++; load()">Next</CdmButton></nav>
    </div>

    <AdmissionDialog v-if="resultInspection" labelledby="result-inspection" wide @cancel="resultInspection = null"><header class="admission-dialog-header"><div><h2 id="result-inspection">{{ resultInspection.name }} · Attempt {{ resultInspection.attempt_number }}</h2><p>{{ resultInspection.applicant_number }} · {{ resultInspection.cycle }}</p></div><button type="button" aria-label="Close result details" @click="resultInspection = null">Close</button></header><div class="admission-dialog-body"><p>Result {{ resultInspection.id }} · Version {{ resultInspection.version }} · {{ resultInspection.outcome }}</p><p>System: {{ resultInspection.system_percentage }}% · {{ resultInspection.system_passed ? 'PASSED' : 'NOT PASSED' }}</p><p>Official: {{ resultInspection.official_score ?? 'Not approved' }} · {{ resultInspection.official_status }}</p><section class="admission-detail-section"><h3>Topic evidence</h3><p v-for="(count,topic) in resultInspection.category_scores" :key="topic">{{ topic }}: {{ count }} / {{ resultInspection.category_maximums[topic] }}</p></section></div></AdmissionDialog>
    <AdmissionDialog v-if="historyInspection" labelledby="history-inspection" wide @cancel="historyInspection = null"><header class="admission-dialog-header"><div><h2 id="history-inspection">{{ historyInspection.action }}</h2><p>{{ historyInspection.subject }}<span v-if="historyInspection.applicant_number"> · {{ historyInspection.applicant_number }}</span></p></div><button type="button" aria-label="Close history details" @click="historyInspection = null">Close</button></header><div class="admission-dialog-body"><section class="admission-detail-section"><h3>Event details</h3><p>{{ historyInspection.summary }}</p><div class="admission-details"><p><strong>Actor</strong><br>{{ historyInspection.actor }}</p><p><strong>Cycle</strong><br>{{ historyInspection.cycle || 'Not recorded' }}</p><p><strong>Date</strong><br>{{ new Date(historyInspection.created_at).toLocaleString() }}</p></div></section></div></AdmissionDialog>
    <AdmissionDialog v-if="action" labelledby="review-confirm" :busy="busy" @cancel="action = null"><form @submit.prevent="confirm"><h2 id="review-confirm">Confirm {{ action.kind }}</h2><p>{{ action.items.length }} result(s){{ action.name ? ' — '+action.name : '' }}</p><label v-if="['approve','correct'].includes(action.kind) && action.items.length === 1">Official score<input v-model.number="score" type="number" min="0" max="100" required></label><label v-if="['correct','override'].includes(action.kind)">Internal reason (hidden from applicant)<textarea v-model="reason" required maxlength="2000"></textarea></label><p v-if="action.kind === 'override'">Only eligible second failures can receive an exceptional pass. This publishes the result immediately.</p><div class="toolbar"><button type="button" :disabled="busy" @click="action = null">Cancel</button><button class="primary" :disabled="busy">Confirm</button></div></form></AdmissionDialog>
  </AdmissionStaffWorkspace>
</template>
<style src="./admission.css"></style>
<style scoped>
.admission-record-workspace { display: grid; gap: 12px; }
.admission-status-tabs { display: flex; gap: 3px; overflow-x: auto; padding-bottom: 2px; border-bottom: 1px solid var(--cdm-border); }
.admission-status-tabs button { min-height: 38px; padding: 7px 12px; border: 0; border-bottom: 2px solid transparent; background: transparent; color: var(--cdm-text-muted); font: inherit; font-size: 12px; font-weight: 700; white-space: nowrap; }
.admission-status-tabs button.active { border-bottom-color: var(--cdm-primary); color: var(--cdm-primary); }
.admission-status-tabs button:focus-visible { outline: 2px solid var(--cdm-focus); outline-offset: -2px; }
.admission-record-toolbar :deep(.cdm-card__content) { display: grid; gap: 12px; }
.admission-search-form { display: grid; grid-template-columns: minmax(240px,1fr) auto auto; align-items: end; gap: 8px; }
.filter-count { min-width: 19px; min-height: 19px; display: inline-grid; place-items: center; border-radius: 999px; background: var(--cdm-primary); color: var(--cdm-on-primary); font-size: 10px; }
.admission-filter-grid { display: grid; grid-template-columns: repeat(3,minmax(150px,1fr)) auto auto; align-items: end; gap: 10px; padding-top: 12px; border-top: 1px solid var(--cdm-border); }
.admission-check { min-height: var(--cdm-control-md); display: flex; align-items: center; gap: 8px; color: var(--cdm-text-secondary); font-size: 12px; font-weight: 700; }
.admission-check input { width: 17px; height: 17px; }
.admission-list-summary { display: flex; align-items: center; justify-content: space-between; gap: 12px; color: var(--cdm-text-muted); font-size: 12px; }
.admission-list-summary p { margin: 0; }
.admission-list-summary strong { color: var(--cdm-text); font-size: 14px; }
.admission-record-alert { margin: 0; padding: 10px 12px; border: 1px solid var(--cdm-danger); border-radius: var(--cdm-radius-sm); background: var(--cdm-danger-soft); color: var(--cdm-danger); font-size: 12px; }
.admission-batch-bar :deep(.cdm-card__content) { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.admission-batch-bar div { display: flex; flex-wrap: wrap; gap: 7px; }
.admission-record-layout { display: grid; grid-template-columns: minmax(0,1fr); gap: 12px; min-width: 0; }
.admission-record-layout.has-detail { grid-template-columns: minmax(580px,1.55fr) minmax(310px,.75fr); align-items: start; }
.admission-record-layout :deep(td strong),.admission-record-layout :deep(td small) { display: block; }
.admission-record-layout :deep(td small) { margin-top: 4px; color: var(--cdm-text-muted); font-size: 10px; }
.admission-record-layout :deep(tr.selected td) { background: var(--cdm-primary-soft); }
.admission-row-actions { display: flex; align-items: center; flex-wrap: wrap; gap: 5px; }
.admission-row-actions details { position: relative; }
.admission-row-actions summary { cursor: pointer; color: var(--cdm-primary); font-size: 11px; font-weight: 700; }
.admission-row-actions details button { display: block; width: 100%; padding: 7px; border: 0; background: transparent; color: var(--cdm-text); text-align: left; }
.admission-record-detail { position: sticky; top: 0; max-height: calc(100dvh - 110px); display: flex; flex-direction: column; overflow: hidden; border: 1px solid var(--cdm-border); border-radius: var(--cdm-radius-md); background: var(--cdm-surface); box-shadow: var(--cdm-shadow-sm); color: var(--cdm-text); }
.admission-record-detail > header { display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; padding: 15px; border-bottom: 1px solid var(--cdm-border); }
.admission-record-detail header small { color: var(--cdm-primary); font-size: 9px; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; }
.admission-record-detail h2 { margin: 4px 0 2px; font-size: 16px; }
.admission-record-detail header p { margin: 0; color: var(--cdm-text-muted); font-size: 11px; }
.admission-detail-scroll { display: grid; gap: 18px; padding: 15px; overflow-y: auto; }
.admission-detail-scroll section { display: grid; gap: 10px; }
.admission-detail-scroll section + section { padding-top: 16px; border-top: 1px solid var(--cdm-border); }
.admission-detail-scroll h3 { margin: 0; font-size: 12px; }
.admission-detail-scroll dl { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin: 0; }
.admission-detail-scroll dl div { min-width: 0; }
.admission-detail-scroll dt { color: var(--cdm-text-muted); font-size: 9px; font-weight: 800; text-transform: uppercase; }
.admission-detail-scroll dd { margin: 4px 0 0; font-size: 11px; }
.admission-attempt { display: grid; gap: 3px; padding: 10px; border: 1px solid var(--cdm-border); border-radius: var(--cdm-radius-sm); background: var(--cdm-surface-muted); font-size: 11px; }
.admission-attempt small { color: var(--cdm-text-muted); }
.admission-pagination { display: flex; align-items: center; justify-content: flex-end; gap: 10px; color: var(--cdm-text-muted); font-size: 11px; }
.sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip-path: inset(50%); white-space: nowrap; }
@media (max-width: 1180px) { .admission-record-layout.has-detail { grid-template-columns: 1fr; } .admission-record-detail { position: static; max-height: none; } }
@media (max-width: 760px) { .admission-search-form { grid-template-columns: 1fr 1fr; } .admission-search-form :deep(.cdm-field) { grid-column: 1 / -1; } .admission-filter-grid { grid-template-columns: 1fr 1fr; } }
@media (max-width: 480px) { .admission-search-form,.admission-filter-grid { grid-template-columns: 1fr; } .admission-batch-bar :deep(.cdm-card__content) { align-items: stretch; flex-direction: column; } .admission-pagination { justify-content: space-between; } }
</style>
