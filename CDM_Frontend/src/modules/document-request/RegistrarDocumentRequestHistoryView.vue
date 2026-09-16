<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import PaginationControls from '../../components/PaginationControls.vue'
import { requestReference, studentName } from './documentRequestPresentation'
import { finalReason, finalizedAt, historyAppointmentStatus, historyAppointmentTime, historyDate, historyStatus, historyTimeline, latestHistoryAppointment } from './documentRequestHistoryPresentation'
import { documentRequestService as api } from './documentRequestService'

const route = useRoute()
const requests = ref([]), search = ref(''), status = ref(''), period = ref('all')
const loading = ref(true), error = ref(''), page = ref(1), lastPage = ref(1), total = ref(0)
const requestId = ref(null), appointmentId = ref(null), appliedFilters = ref(false)
const dialog = ref(null), selectedSummary = ref(null), details = ref(null), detailsLoading = ref(false), detailsError = ref('')
const statuses = ['Completed', 'Rejected', 'Cancelled']
const periods = [
  { value: 'all', label: 'All time' }, { value: 'today', label: 'Today' },
  { value: 'yesterday', label: 'Yesterday' }, { value: 'last_7_days', label: 'Last 7 days' },
  { value: 'this_week', label: 'This week' }, { value: 'this_month', label: 'This month' },
]
const selectedAppointment = computed(() => details.value?.appointments?.find(item => item.id === appointmentId.value) || latestHistoryAppointment(details.value))
const timeline = computed(() => historyTimeline(details.value))
const referenceFor = item => item?.request_reference || requestReference(item?.id)
const nameFor = item => studentName(item?.student) || 'Student name unavailable'
const documentFor = item => item?.document_type?.document_name || 'Document unavailable'
const queryValue = value => Array.isArray(value) ? value[0] : value
const positiveId = value => Number.isSafeInteger(Number(queryValue(value))) && Number(queryValue(value)) > 0 ? Number(queryValue(value)) : null
let listRequest = 0, detailRequest = 0
let disposed = false

async function refresh() {
  const request = ++listRequest
  loading.value = true
  error.value = ''
  appliedFilters.value = Boolean(search.value.trim() || status.value || period.value !== 'all' || requestId.value || appointmentId.value)
  try {
    const result = await api.registrarHistory({
      section: 'requests', search: search.value.trim() || undefined, request_status: status.value || undefined,
      time_filter: period.value, request_page: page.value,
      request_id: requestId.value || undefined, appointment_id: appointmentId.value || undefined,
    })
    if (request !== listRequest) return
    requests.value = (result.requests?.data || []).filter(item => historyStatus(item.status))
    page.value = result.requests?.current_page || 1
    lastPage.value = result.requests?.last_page || 1
    total.value = result.requests?.total ?? requests.value.length
  } catch {
    if (request === listRequest) error.value = 'Unable to load history'
  } finally {
    if (request === listRequest) loading.value = false
  }
  return request === listRequest
}

async function applyFilters() {
  requestId.value = appointmentId.value = null
  page.value = 1
  await refresh()
}
async function clearFilters() {
  search.value = status.value = ''
  period.value = 'all'
  await applyFilters()
}
async function changePage(value) {
  page.value = value
  await refresh()
}
async function loadDetails() {
  if (disposed || !selectedSummary.value) return
  const request = ++detailRequest
  detailsLoading.value = true
  detailsError.value = ''
  try {
    const result = await api.registrarRequest(selectedSummary.value.id)
    if (request === detailRequest) details.value = result
  } catch {
    if (request === detailRequest) detailsError.value = 'Unable to load request details'
  } finally {
    if (request === detailRequest) detailsLoading.value = false
  }
}
async function openDetails(item) {
  selectedSummary.value = item
  details.value = null
  detailsLoading.value = true
  detailsError.value = ''
  await nextTick()
  if (disposed || !selectedSummary.value || !dialog.value) return
  if (!dialog.value.open) dialog.value.showModal()
  await loadDetails()
}
function closeDetails() {
  detailRequest += 1
  if (dialog.value?.open) dialog.value.close()
  selectedSummary.value = details.value = null
  detailsLoading.value = false
}

watch(() => route.query, async query => {
  closeDetails()
  requestId.value = positiveId(query.request_id)
  appointmentId.value = positiveId(query.appointment_id)
  search.value = String(queryValue(query.search) || '')
  const queryStatus = String(queryValue(query.request_status) || '')
  status.value = statuses.some(value => value.toLowerCase() === queryStatus) ? queryStatus : ''
  const queryPeriod = String(queryValue(query.time_filter) || 'all')
  period.value = periods.some(value => value.value === queryPeriod) ? queryPeriod : 'all'
  page.value = 1
  const current = await refresh()
  if (!current || disposed || route.query !== query || error.value) return
  const focus = String(queryValue(query.focus) || '')
  const focusedItem = requests.value.find(item => focus === `request-${item.id}` || (focus.startsWith('appointment-') && focus === `appointment-${latestHistoryAppointment(item)?.id}`))
  if (focusedItem) await openDetails(focusedItem)
  else if ((requestId.value || appointmentId.value) && requests.value.length === 1) await openDetails(requests.value[0])
}, { immediate: true })

onBeforeUnmount(() => { disposed = true; listRequest += 1; closeDetails() })
</script>

<template>
  <section class="history-workspace">
    <header class="history-header"><p class="page-kicker">Registrar Staff</p><h1 class="page-title">Document Request History</h1></header>
    <form class="history-filters" aria-label="History filters" @submit.prevent="applyFilters">
      <label class="history-search">Search<input v-model="search" type="search" maxlength="100" placeholder="Student, document or request ID" /></label>
      <label>Status<select v-model="status" :disabled="loading" @change="applyFilters"><option value="">All</option><option v-for="value in statuses" :key="value" :value="value.toLowerCase()">{{ value }}</option></select></label>
      <label>Finalized date<select v-model="period" :disabled="loading" @change="applyFilters"><option v-for="option in periods" :key="option.value" :value="option.value">{{ option.label }}</option></select></label>
      <button type="submit" :disabled="loading">Search</button>
      <button v-if="appliedFilters" class="secondary-button" type="button" :disabled="loading" @click="clearFilters">Clear filters</button>
    </form>

    <div v-if="loading" class="history-state" role="status">Loading history...</div>
    <div v-else-if="error" class="history-state" role="alert"><p>{{ error }}</p><button type="button" @click="refresh">Retry</button></div>
    <div v-else-if="!requests.length" class="history-state" role="status"><p>{{ appliedFilters ? 'No results match your filters' : 'No history found' }}</p><button v-if="appliedFilters" type="button" @click="clearFilters">Clear filters</button></div>
    <template v-else>
      <p class="history-count" role="status">{{ total }} {{ total === 1 ? 'record' : 'records' }} · Newest finalized first</p>
      <div class="history-records" role="table" aria-label="Finalized document requests">
        <div role="rowgroup" class="history-column-head"><div class="history-record" role="row"><span role="columnheader">Student</span><span role="columnheader">Document / submitted</span><span role="columnheader">Result</span><span role="columnheader" aria-sort="descending">Finalized</span><span role="columnheader" class="visually-hidden">Details</span></div></div>
        <div role="rowgroup">
          <div v-for="item in requests" :key="item.id" class="history-record" role="row">
            <div role="cell" class="history-student"><strong>{{ nameFor(item) }}</strong><span>{{ item.student?.student_number || 'Student number unavailable' }}</span></div>
            <div role="cell" class="history-document"><strong>{{ documentFor(item) }}</strong><span>{{ referenceFor(item) }}</span><span>Submitted <time :datetime="item.created_at || item.request_date">{{ historyDate(item.created_at || item.request_date) }}</time></span></div>
            <div role="cell" class="history-result"><span class="history-status" :class="historyStatus(item.status).toLowerCase()">{{ historyStatus(item.status) }}</span><p v-if="historyStatus(item.status) !== 'Completed' && finalReason(item)" class="history-reason">{{ finalReason(item) }}</p></div>
            <div role="cell" class="history-dates"><span class="mobile-label">Finalized</span><time class="finalized-date" :datetime="finalizedAt(item)">{{ historyDate(finalizedAt(item)) }}</time><span v-if="latestHistoryAppointment(item)?.appointment_date">Appointment <time :datetime="latestHistoryAppointment(item).appointment_date">{{ historyDate(String(latestHistoryAppointment(item).appointment_date).slice(0, 10)) }}</time></span></div>
            <div role="cell" class="history-action"><button class="secondary-button" type="button" :aria-label="`View Details for ${nameFor(item)}, ${documentFor(item)}`" @click="openDetails(item)">View Details</button></div>
          </div>
        </div>
      </div>
      <PaginationControls :current-page="page" :last-page="lastPage" :total="total" :busy="loading" aria-label="History pages" @page-change="changePage" />
    </template>
  </section>

  <Teleport to="body">
    <dialog ref="dialog" class="history-details" aria-labelledby="history-details-title" @close="() => { if (!dialog?.open) closeDetails() }" @click="event => { if (event.target === dialog) closeDetails() }">
      <div class="history-details-inner">
        <header class="history-details-header"><h2 id="history-details-title">Request Details</h2><button type="button" class="history-close" aria-label="Close request details" title="Close request details" autofocus @click="closeDetails">&#215;</button></header>
        <p v-if="detailsLoading" class="history-state" role="status">Loading details...</p>
        <div v-else-if="detailsError" class="history-state" role="alert"><p>{{ detailsError }}</p><button type="button" @click="loadDetails">Retry</button></div>
        <template v-else-if="details">
          <section class="history-detail-section"><h3>Request Summary</h3><dl>
            <div><dt>Student</dt><dd>{{ nameFor(details) }}</dd></div><div><dt>Student Number</dt><dd>{{ details.student?.student_number || 'Not recorded' }}</dd></div>
            <div><dt>Document</dt><dd>{{ documentFor(details) }}</dd></div><div><dt>Purpose</dt><dd>{{ details.purpose || 'Not recorded' }}</dd></div>
            <div><dt>Request ID</dt><dd>{{ referenceFor(details) }}</dd></div>
            <div><dt>Submitted At</dt><dd>{{ historyDate(details.created_at || details.request_date, true) }}</dd></div>
          </dl></section>
          <section class="history-detail-section"><h3>Workflow Result</h3><dl>
            <div><dt>Final Status</dt><dd><span v-if="historyStatus(details.status)" class="history-status" :class="historyStatus(details.status).toLowerCase()">{{ historyStatus(details.status) }}</span><span v-else>Not recorded</span></dd></div>
            <div><dt>Finalized At</dt><dd>{{ historyDate(finalizedAt(details), true) }}</dd></div>
            <div v-if="finalReason(details)"><dt>Reason / Notes</dt><dd class="history-notes">{{ finalReason(details) }}</dd></div>
          </dl></section>
          <section class="history-detail-section"><h3>Appointment</h3><dl v-if="selectedAppointment">
            <div><dt>Appointment Date</dt><dd>{{ historyDate(String(selectedAppointment.appointment_date || '').slice(0, 10)) }}</dd></div>
            <div><dt>Appointment Time</dt><dd>{{ historyAppointmentTime(selectedAppointment.appointment_time) }}</dd></div>
            <div><dt>Appointment Status</dt><dd>{{ historyAppointmentStatus(selectedAppointment.status) }}</dd></div>
            <div><dt>Verification</dt><dd>{{ details.code_verified_at ? `Verified ${historyDate(details.code_verified_at, true)}` : 'Not recorded' }}</dd></div>
          </dl><p v-else class="history-muted">No appointment recorded.</p></section>
          <section class="history-detail-section"><h3>Timeline</h3><ol v-if="timeline.length" class="history-timeline"><li v-for="(event, index) in timeline" :key="index"><strong>{{ event.label }}</strong><time :datetime="event.at">{{ historyDate(event.at, true) }}</time></li></ol><p v-else class="history-muted">No timeline events recorded.</p></section>
        </template>
      </div>
    </dialog>
  </Teleport>
</template>

<style scoped>
.history-workspace{color:#263c30;min-width:0}.history-header{margin-bottom:24px}.history-filters{display:flex;align-items:end;flex-wrap:wrap;gap:12px;margin-bottom:24px}.history-filters label{display:grid;gap:6px;font-size:.76rem;font-weight:700}.history-search{flex:1 1 280px}.history-filters input,.history-filters select{width:100%;min-width:0;min-height:40px;border:1px solid #d5ded8;border-radius:6px;background:#fff;font-size:.85rem;padding:9px 11px}.history-filters select{min-width:140px}.history-workspace button,.history-details button{border:1px solid #106a2e;border-radius:6px;background:#106a2e;color:#fff;font:inherit;font-size:.8rem;font-weight:700;min-height:40px;padding:8px 14px;cursor:pointer}.history-workspace button:disabled{opacity:.5;cursor:wait}.history-workspace .secondary-button{background:#fff;color:#245238;border-color:#cbd7ce}.history-state{text-align:center;padding:50px 16px;color:#57685e}.history-count{color:#65756b;font-size:.78rem;margin:0 0 10px}.history-records{background:#fff;border-top:1px solid #dce4de}.history-record{display:grid;grid-template-columns:minmax(140px,1.2fr) minmax(150px,1.4fr) minmax(120px,1fr) minmax(150px,1.2fr) 112px;gap:18px;align-items:start;padding:18px 14px;border-bottom:1px solid #e5ebe7;font-size:.84rem}.history-column-head .history-record{padding-block:11px;background:#f1f5f2;color:#53675a;font-size:.72rem;font-weight:700}.history-record [role=cell]{min-width:0;overflow-wrap:anywhere}.history-student,.history-document,.history-dates{display:grid;gap:7px}.history-record strong{font-weight:650;line-height:1.4}.history-student>span,.history-document>span,.history-dates>span{font-size:.74rem;color:#64756a;line-height:1.45}.history-status{display:inline-block;border-radius:4px;padding:4px 8px;font-size:.73rem;font-weight:750;line-height:1.4}.history-status.completed{background:#e6f3e9;color:#176134}.history-status.rejected{background:#fcecea;color:#9b3932}.history-status.cancelled{background:#edf0f4;color:#526174}.history-reason{display:-webkit-box;-webkit-box-orient:vertical;-webkit-line-clamp:2;overflow:hidden;font-size:.76rem;line-height:1.45;color:#637168;margin:8px 0 0}.history-action button{width:100%;white-space:nowrap;padding-inline:10px}.mobile-label{display:none}.visually-hidden{position:absolute;width:1px;height:1px;padding:0;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap}.history-details{position:fixed;inset:0 0 0 auto;margin:0;width:min(540px,100vw);max-width:100vw;height:100dvh;max-height:100dvh;box-sizing:border-box;border:0;border-left:1px solid #dce4de;padding:0;background:#fff;color:#263c30;box-shadow:-12px 0 40px #163c2820;overflow-y:auto}.history-details::backdrop{background:#16271f66}.history-details-inner{min-height:100%;padding:0 26px 28px}.history-details-header{position:sticky;top:0;display:flex;justify-content:space-between;align-items:center;gap:20px;background:#fff;border-bottom:1px solid #dce4de;padding:20px 0;z-index:1}.history-details h2{font-size:1.15rem;margin:0}.history-details .history-close{font-size:1.5rem;line-height:1;width:38px;height:38px;min-height:38px;padding:0;background:#f1f5f2;border-color:#dce4de;color:#40584a}.history-detail-section{padding:22px 0;border-bottom:1px solid #e5ebe7}.history-detail-section:last-child{border:0}.history-details h3{font-size:.85rem;margin:0 0 16px}.history-details dl{display:grid;gap:14px;margin:0}.history-details dl>div{display:grid;grid-template-columns:132px minmax(0,1fr);gap:14px;align-items:start}.history-details dt{font-size:.76rem;color:#64756a}.history-details dd{margin:0;font-size:.84rem;line-height:1.5;overflow-wrap:anywhere}.history-notes{white-space:pre-wrap}.history-muted{font-size:.82rem;color:#64756a;margin:0}.history-timeline{list-style:none;padding:0 0 0 12px;margin:0}.history-timeline li{position:relative;display:grid;gap:5px;border-left:1px solid #d5e1d8;padding:0 0 20px 20px}.history-timeline li:last-child{padding-bottom:0;border-left-color:transparent}.history-timeline li::before{content:'';position:absolute;left:-4px;top:4px;width:7px;height:7px;background:#47835a;border-radius:50%}.history-timeline strong{font-size:.82rem;font-weight:650}.history-timeline time{font-size:.75rem;color:#64756a}
@media(max-width:1100px){.history-record{grid-template-columns:minmax(120px,1fr) minmax(120px,1.1fr) minmax(105px,1fr) minmax(130px,1fr) 108px;gap:12px;padding-inline:10px}}
@media(max-width:1000px){.history-filters{display:grid;grid-template-columns:1fr 1fr;gap:10px}.history-search{grid-column:1/-1}.history-filters select{min-width:0}.history-filters button{min-height:42px}.history-column-head{display:none}.history-records{border:0;background:transparent}.history-record{grid-template-columns:minmax(0,1fr) auto;gap:14px;padding:16px;margin-bottom:12px;border:1px solid #dce4de;border-radius:6px;background:#fff}.history-student{grid-column:1;grid-row:1}.history-result{grid-column:2;grid-row:1 / span 2;max-width:140px}.history-document{grid-column:1;grid-row:2}.history-dates{grid-column:1/-1;grid-row:3;gap:4px}.history-dates .mobile-label{display:block;font-size:.7rem;font-weight:700}.history-action{grid-column:1/-1;grid-row:4}.history-action button{min-height:44px}.history-reason{-webkit-line-clamp:3}.history-details-inner{padding-inline:20px}}
@media(max-width:400px){.history-details dl>div{grid-template-columns:1fr;gap:4px}.history-result{max-width:110px}.history-record{gap:12px;padding:14px}}
</style>
