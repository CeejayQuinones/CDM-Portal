<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import PaginationControls from '../../components/PaginationControls.vue'
import CdmButton from '../../design-system/CdmButton.vue'
import DocumentRequestStatusBadge from './DocumentRequestStatusBadge.vue'
import { documentRequestService as api } from './documentRequestService'
import { groupStudentSubmissions } from './documentRequestWorkspace'

const emit = defineEmits(['process', 'retrieval-change'])
const route = useRoute()
const queryValue = (value) => Array.isArray(value) ? value[0] : value
const positiveId = (value) => { const id = Number(queryValue(value)); return Number.isInteger(id) && id > 0 ? id : null }
const students = ref([])
const search = ref(String(queryValue(route.query.student_search) || ''))
const status = ref(['pending', 'approved'].includes(queryValue(route.query.student_status)) ? queryValue(route.query.student_status) : '')
const page = ref(positiveId(route.query.student_page) || 1)
const lastPage = ref(1)
const total = ref(0)
const loading = ref(false)
const listError = ref('')
const message = ref('')
const selectedStudentId = ref(null)
const detail = ref(null)
const detailLoading = ref(false)
const detailError = ref('')
const detailPage = ref(1)
const sameLocation = ref(null)
const locationLoading = ref(false)
const locationError = ref('')
const locationPage = ref(1)
const backStack = ref([])
const selectedIds = ref([])
const rejectionReason = ref('')
const rejecting = ref(false)
const retrievalBusy = ref(false)
const detailHeading = ref(null)
const listHeading = ref(null)
let searchTimer
let listSequence = 0
let detailSequence = 0
let locationSequence = 0

const submissions = computed(() => groupStudentSubmissions(detail.value?.requests?.data || []))
const activeTask = computed(() => detail.value?.retrieval_task || null)
const location = computed(() => detail.value?.student?.physical_record_location?.cabinet_slot || null)
const retrievalRequestIds = computed(() => new Set(detail.value?.retrieval_request_ids || []))
const studentName = (student) => [student?.user?.profile?.first_name, student?.user?.profile?.last_name].filter(Boolean).join(' ') || 'Student record'
const activeCount = (student) => student?.active_requests_count ?? detail.value?.requests?.total ?? 0
const requestDate = (value) => value ? new Intl.DateTimeFormat('en-PH', { month: 'short', day: 'numeric', year: 'numeric', timeZone: 'Asia/Manila' }).format(new Date(value)) : 'Date unavailable'
const requestError = (error, fallback) => error.response?.data?.message || fallback

async function loadStudents(resetPage = false) {
  clearTimeout(searchTimer)
  if (resetPage) page.value = 1
  const sequence = ++listSequence
  loading.value = true
  listError.value = ''
  try {
    const result = await api.registrarRequestStudents({ search: search.value || undefined, status: status.value || undefined, page: page.value })
    if (sequence !== listSequence) return
    students.value = result.data
    page.value = result.current_page
    lastPage.value = result.last_page
    total.value = result.total
  } catch (error) {
    if (sequence === listSequence) listError.value = requestError(error, 'Student requests could not be loaded.')
  } finally {
    if (sequence === listSequence) loading.value = false
  }
}

async function loadDetail(nextPage = detailPage.value) {
  if (!selectedStudentId.value) return
  detailPage.value = nextPage
  const id = selectedStudentId.value
  const sequence = ++detailSequence
  detailLoading.value = true
  detailError.value = ''
  try {
    const result = await api.registrarRequestStudent(id, { page: nextPage })
    if (sequence !== detailSequence || id !== selectedStudentId.value) return
    detail.value = result
    selectedIds.value = []
    await nextTick()
    detailHeading.value?.focus()
  } catch (error) {
    if (sequence === detailSequence) detailError.value = requestError(error, 'Student request details could not be loaded.')
  } finally {
    if (sequence === detailSequence) detailLoading.value = false
  }
}

async function openStudent(id) {
  if (id === selectedStudentId.value) return
  if (selectedStudentId.value && detail.value) {
    backStack.value.push({ id: selectedStudentId.value, detail: detail.value, detailPage: detailPage.value, sameLocation: sameLocation.value, locationPage: locationPage.value })
  }
  selectedStudentId.value = id
  detail.value = null
  detailPage.value = 1
  sameLocation.value = null
  locationPage.value = 1
  locationError.value = ''
  locationSequence++
  selectedIds.value = []
  await loadDetail(1)
}

async function closeStudent() {
  const id = selectedStudentId.value
  selectedStudentId.value = null
  detail.value = null
  backStack.value = []
  detailSequence++
  locationSequence++
  await nextTick()
  const trigger = document.getElementById(`student-request-${id}`)
  if (trigger) trigger.focus()
  else listHeading.value?.focus()
}

async function goBack() {
  const previous = backStack.value.pop()
  if (!previous) return closeStudent()
  selectedStudentId.value = previous.id
  detail.value = previous.detail
  detailPage.value = previous.detailPage
  sameLocation.value = previous.sameLocation
  locationPage.value = previous.locationPage
  detailError.value = ''
  locationError.value = ''
  detailSequence++
  locationSequence++
  selectedIds.value = []
  await nextTick()
  detailHeading.value?.focus()
}

async function findSameLocation(nextPage = 1) {
  if (!selectedStudentId.value || !location.value?.cabinet?.cabinet_code || !location.value?.slot_code) return
  const id = selectedStudentId.value
  const sequence = ++locationSequence
  locationPage.value = nextPage
  locationLoading.value = true
  locationError.value = ''
  try {
    const result = await api.sameLocationRequests(id, { page: nextPage })
    if (sequence === locationSequence && id === selectedStudentId.value) sameLocation.value = result
  } catch (error) {
    if (sequence === locationSequence) locationError.value = requestError(error, 'Requests in this location could not be loaded.')
  } finally {
    if (sequence === locationSequence) locationLoading.value = false
  }
}

async function rejectSelected() {
  if (rejecting.value || !selectedIds.value.length || !rejectionReason.value.trim()) return
  if (!window.confirm(`Reject ${selectedIds.value.length} selected pending requests? This cannot be undone.`)) return
  rejecting.value = true
  detailError.value = ''
  try {
    await api.bulkRejectRequests({ request_ids: selectedIds.value, reason: rejectionReason.value.trim() })
    message.value = `${selectedIds.value.length} pending requests rejected.`
    rejectionReason.value = ''
    selectedIds.value = []
    await Promise.all([loadStudents(), loadDetail(detailPage.value)])
    sameLocation.value = null
  } catch (error) {
    detailError.value = requestError(error, 'Selected requests could not be rejected.')
  } finally {
    rejecting.value = false
  }
}

async function transitionFolder(action) {
  if (!activeTask.value || retrievalBusy.value) return
  if (!window.confirm(`Confirm ${action === 'retrieve' ? 'retrieval' : 'return'} of this physical folder? The action is audited.`)) return
  retrievalBusy.value = true
  detailError.value = ''
  try {
    await api.updateRetrievalTask(activeTask.value.id, action)
    message.value = action === 'retrieve' ? 'Physical folder marked retrieved.' : 'Physical folder marked returned.'
    await loadDetail(detailPage.value)
    emit('retrieval-change')
  } catch (error) {
    detailError.value = requestError(error, 'Physical folder status could not be updated.')
  } finally {
    retrievalBusy.value = false
  }
}

function changePage(nextPage) { page.value = nextPage; loadStudents() }
function clearFilters() { clearTimeout(searchTimer); search.value = ''; status.value = ''; loadStudents(true) }
async function refresh() { await Promise.all([loadStudents(), selectedStudentId.value ? loadDetail(detailPage.value) : Promise.resolve()]) }

watch(search, () => { clearTimeout(searchTimer); searchTimer = setTimeout(() => loadStudents(true), 350) })
onMounted(async () => {
  await loadStudents()
  const studentId = positiveId(route.query.student_id)
  if (studentId) await openStudent(studentId)
})
onBeforeUnmount(() => { clearTimeout(searchTimer); listSequence++; detailSequence++; locationSequence++ })
defineExpose({ refresh, contextQuery: () => ({ studentSearch: search.value, studentStatus: status.value, studentPage: page.value }) })
</script>

<template>
  <section class="dr-unified-workspace" aria-labelledby="student-requests-heading">
    <div class="dr-unified-toolbar">
      <div><h2 id="student-requests-heading" ref="listHeading" tabindex="-1">Active requests</h2><p>One student at a time. Counts include all pending and approved documents.</p></div>
      <div class="dr-unified-filters" role="search" aria-label="Filter student requests">
        <label>Find student or request<input v-model="search" type="search" placeholder="Name, student number, or REQ reference" /></label>
        <label>Status<select v-model="status" @change="loadStudents(true)"><option value="">Pending and approved</option><option value="pending">Has pending</option><option value="approved">Has approved</option></select></label>
        <CdmButton type="button" variant="outline" :disabled="!search && !status" @click="clearFilters">Clear filters</CdmButton>
      </div>
    </div>
    <p v-if="message" class="notice success" role="status">{{ message }}</p>
    <div v-if="listError" class="notice error" role="alert">{{ listError }} <CdmButton type="button" variant="outline" @click="loadStudents()">Retry</CdmButton></div>
    <p v-if="loading && !students.length" role="status" class="dr-unified-state">Loading student requests…</p>
    <div v-else-if="!students.length && !listError" class="dr-unified-state"><strong>{{ search || status ? 'No matching active requests' : 'No active document requests' }}</strong><p>{{ search || status ? 'Try another search or clear the filter.' : 'New student requests will appear here.' }}</p></div>
    <div v-else class="dr-unified-list" :aria-busy="loading">
      <article v-for="student in students" :key="student.id" class="dr-unified-student" :class="{ 'is-selected': selectedStudentId === student.id }">
        <div><strong>{{ studentName(student) }}</strong><small>{{ student.student_number || 'Student number unavailable' }}</small></div>
        <p>{{ activeCount(student) }} active {{ activeCount(student) === 1 ? 'document' : 'documents' }} <span v-if="student.pending_requests_count">· {{ student.pending_requests_count }} pending</span><span v-if="student.approved_requests_count"> · {{ student.approved_requests_count }} approved</span></p>
        <CdmButton :id="`student-request-${student.id}`" type="button" @click="openStudent(student.id)">{{ activeCount(student) === 1 ? 'View request' : 'View requests' }}</CdmButton>
      </article>
    </div>
    <PaginationControls v-if="total" :current-page="page" :last-page="lastPage" :total="total" total-label="students" aria-label="Student request pages" @page-change="changePage" />

    <section v-if="selectedStudentId" class="dr-unified-detail" aria-labelledby="student-request-detail-heading" @keydown.esc.stop="closeStudent">
      <header class="dr-unified-detail-header"><div><span class="dr-unified-eyebrow">Student requests</span><h3 id="student-request-detail-heading" ref="detailHeading" tabindex="-1">{{ detail ? studentName(detail.student) : 'Loading student…' }}</h3><p v-if="detail">{{ detail.student.student_number }} · {{ detail.requests.total }} active {{ detail.requests.total === 1 ? 'document' : 'documents' }}</p></div><div class="dr-unified-detail-actions"><CdmButton v-if="backStack.length" type="button" variant="outline" @click="goBack">Previous student</CdmButton><CdmButton type="button" variant="outline" @click="closeStudent">Close details</CdmButton></div></header>
      <p v-if="detailLoading && !detail" role="status" class="dr-unified-state">Loading request details…</p>
      <div v-if="detailError" class="notice error" role="alert">{{ detailError }} <CdmButton type="button" variant="outline" @click="loadDetail(detailPage)">Retry</CdmButton></div>
      <template v-if="detail">
        <section class="dr-unified-submissions" aria-label="Student submissions">
          <div v-for="submission in submissions" :key="submission.key" class="dr-unified-submission"><h4>{{ requestDate(submission.date) }} <small>{{ submission.reference }}</small></h4>
            <div v-for="item in submission.requests" :key="item.id" class="dr-unified-document">
              <label v-if="item.status === 'pending'" class="dr-unified-check"><input v-model="selectedIds" type="checkbox" :value="item.id" :disabled="selectedIds.length >= 20 && !selectedIds.includes(item.id)" :aria-label="`Select ${item.document_type?.document_name} request ${item.request_reference}`" /></label><span v-else class="dr-unified-check" aria-hidden="true"></span>
              <div class="dr-unified-document-name"><strong>{{ item.document_type?.document_name || 'Document request' }}</strong><small>{{ item.request_reference }} · {{ item.quantity }} {{ item.quantity === 1 ? 'copy' : 'copies' }}<span v-if="retrievalRequestIds.has(item.id)"> · Folder retrieval required</span></small></div>
              <DocumentRequestStatusBadge :status="item.status" />
              <CdmButton type="button" :aria-label="`Process ${item.document_type?.document_name} request ${item.request_reference}`" @click="emit('process', item)">Process</CdmButton>
            </div>
          </div>
          <p v-if="!detail.requests.data.length" class="dr-unified-state">No active documents remain for this student.</p>
          <PaginationControls v-if="detail.requests.total" :current-page="detail.requests.current_page" :last-page="detail.requests.last_page" :total="detail.requests.total" total-label="documents" aria-label="Student document pages" @page-change="loadDetail" />
        </section>
        <form v-if="selectedIds.length" class="dr-unified-bulk" @submit.prevent="rejectSelected"><strong>{{ selectedIds.length }} pending selected</strong><label>Rejection reason<input v-model="rejectionReason" required maxlength="2000" placeholder="Required for the audit" /></label><CdmButton type="submit" variant="danger" :disabled="rejecting || !rejectionReason.trim()">{{ rejecting ? 'Rejecting…' : 'Reject selected' }}</CdmButton></form>
        <section class="dr-unified-physical" aria-label="Physical Record"><div><h4>Physical Record</h4><p v-if="location?.cabinet">Cabinet {{ location.cabinet.cabinet_code }} · Drawer / Slot {{ location.slot_code }}</p><p v-else>No physical location is assigned.</p><p v-if="activeTask">Folder task: {{ activeTask.status === 'awaiting' ? 'Awaiting retrieval' : 'Retrieved' }}</p><p v-else>No active folder retrieval task. Not every document requires one.</p></div><div class="dr-unified-physical-actions"><CdmButton v-if="activeTask?.status === 'awaiting'" type="button" :disabled="retrievalBusy || !location?.cabinet || !location?.slot_code" @click="transitionFolder('retrieve')">{{ retrievalBusy ? 'Saving…' : 'Mark retrieved' }}</CdmButton><CdmButton v-if="activeTask?.status === 'retrieved'" type="button" variant="outline" :disabled="retrievalBusy" @click="transitionFolder('return')">{{ retrievalBusy ? 'Saving…' : 'Mark returned' }}</CdmButton><CdmButton type="button" variant="outline" :disabled="!location?.cabinet || !location?.slot_code || locationLoading" @click="findSameLocation(1)">Find requests in the same location</CdmButton></div></section>
        <div v-if="locationError" class="notice error" role="alert">{{ locationError }} <CdmButton type="button" variant="outline" @click="findSameLocation(locationPage)">Retry</CdmButton></div>
        <p v-if="locationLoading" role="status" class="dr-unified-state">Checking this drawer…</p>
        <section v-if="sameLocation && !locationLoading" class="dr-unified-neighbors" aria-label="Other active requests in this location"><h4>Other active requests · Cabinet {{ sameLocation.location.cabinet }}, Drawer / Slot {{ sameLocation.location.drawer }}</h4><p v-if="!sameLocation.students.data.length">No other active requests in this drawer.</p><div v-for="student in sameLocation.students.data" :key="student.id" class="dr-unified-neighbor"><div><strong>{{ studentName(student) }}</strong><small>{{ student.student_number }} · {{ student.active_requests_count }} active {{ student.active_requests_count === 1 ? 'document' : 'documents' }} · {{ student.pending_requests_count }} pending · {{ student.approved_requests_count }} approved<span v-if="student.retrieval_required_count"> · {{ student.retrieval_required_count }} requiring folder retrieval</span><span v-else> · No folder retrieval task required for these requests</span></small></div><CdmButton type="button" @click="openStudent(student.id)">{{ student.active_requests_count === 1 ? 'View request' : 'View requests' }}</CdmButton></div><PaginationControls v-if="sameLocation.students.total" :current-page="sameLocation.students.current_page" :last-page="sameLocation.students.last_page" :total="sameLocation.students.total" total-label="students" aria-label="Same-location request pages" @page-change="findSameLocation" /></section>
      </template>
    </section>
  </section>
</template>

<style scoped>
.dr-unified-workspace{min-width:0;border:1px solid var(--border-color);border-radius:10px;background:var(--bg-surface);color:var(--text-primary)}.dr-unified-toolbar{display:grid;gap:12px;padding:16px;border-bottom:1px solid var(--border-color)}.dr-unified-toolbar h2{margin:0;font-size:1.1rem}.dr-unified-toolbar p{margin:3px 0 0;color:var(--text-secondary);font-size:.82rem}.dr-unified-filters{display:flex;align-items:end;flex-wrap:wrap;gap:10px}.dr-unified-filters label{display:grid;flex:1 1 180px;gap:5px;min-width:0;color:var(--text-secondary);font-size:.78rem}.dr-unified-filters label:first-child{flex:2 1 250px}.dr-unified-filters :is(input,select),.dr-unified-bulk input{width:100%;height:40px;padding:0 10px;border:1px solid var(--border-strong);border-radius:7px;background:var(--bg-input);color:var(--text-primary);font:inherit}.dr-unified-filters button{min-height:40px}.dr-unified-list{display:grid}.dr-unified-student,.dr-unified-neighbor{display:flex;align-items:center;flex-wrap:wrap;gap:8px 16px;padding:13px 16px;border-bottom:1px solid var(--border-color)}.dr-unified-student.is-selected{background:var(--bg-surface-alt)}.dr-unified-student>div,.dr-unified-neighbor>div{display:grid;gap:2px;flex:1 1 190px;min-width:0}.dr-unified-student strong,.dr-unified-neighbor strong{font-size:.88rem}.dr-unified-student small,.dr-unified-neighbor small{color:var(--text-secondary);font-size:.76rem;overflow-wrap:anywhere}.dr-unified-student p{flex:1 1 180px;margin:0;color:var(--text-secondary);font-size:.8rem}.dr-unified-state{padding:22px 16px;color:var(--text-secondary);font-size:.84rem}.dr-unified-state p{margin:5px 0 0}.dr-unified-detail{margin:14px;border:1px solid var(--border-strong);border-radius:9px;overflow:hidden}.dr-unified-detail-header,.dr-unified-physical{display:flex;align-items:start;justify-content:space-between;flex-wrap:wrap;gap:12px;padding:16px;background:var(--bg-surface-alt)}.dr-unified-detail-header h3{margin:3px 0;font-size:1rem}.dr-unified-detail-header p{margin:0;color:var(--text-secondary);font-size:.8rem}.dr-unified-eyebrow{color:var(--accent);font-size:.72rem;font-weight:700}.dr-unified-detail-actions,.dr-unified-physical-actions{display:flex;flex-wrap:wrap;gap:8px}.dr-unified-submissions{padding:8px 16px}.dr-unified-submission{padding:12px 0;border-bottom:1px solid var(--border-color)}.dr-unified-submission h4{display:flex;align-items:baseline;flex-wrap:wrap;gap:8px;margin:0 0 8px;font-size:.84rem}.dr-unified-submission h4 small{color:var(--text-secondary);font-size:.75rem;font-weight:400}.dr-unified-document{display:flex;align-items:center;flex-wrap:wrap;gap:9px 12px;padding:9px 0}.dr-unified-check{width:20px;flex:none}.dr-unified-check input{width:17px;height:17px;accent-color:var(--accent)}.dr-unified-document-name{display:grid;gap:2px;flex:1 1 180px;min-width:0;overflow-wrap:anywhere}.dr-unified-document-name strong{font-size:.83rem}.dr-unified-document-name small{color:var(--text-secondary);font-size:.75rem}.dr-unified-bulk{display:flex;align-items:end;flex-wrap:wrap;gap:10px;padding:12px 16px;background:var(--bg-surface-alt)}.dr-unified-bulk strong{font-size:.82rem}.dr-unified-bulk label{display:grid;flex:1 1 200px;gap:5px;font-size:.76rem}.dr-unified-physical{border-top:1px solid var(--border-color)}.dr-unified-physical h4,.dr-unified-neighbors h4{margin:0 0 6px;font-size:.9rem}.dr-unified-physical p{margin:3px 0;color:var(--text-secondary);font-size:.78rem}.dr-unified-neighbors{padding:15px 16px;border-top:1px solid var(--border-color)}.dr-unified-neighbors>p{color:var(--text-secondary);font-size:.83rem}.dr-unified-neighbor{padding-inline:0}.dr-unified-workspace :is(button,input,select,h2,h3):focus-visible{outline:2px solid var(--accent);outline-offset:2px}@media(max-width:700px){.dr-unified-student .cdm-button,.dr-unified-document .cdm-button{margin-left:auto}.dr-unified-detail{margin:10px}.dr-unified-physical-actions{width:100%}}@media(prefers-reduced-motion:reduce){.dr-unified-workspace *{scroll-behavior:auto}}
</style>
