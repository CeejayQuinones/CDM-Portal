<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import PaginationControls from '../../components/PaginationControls.vue'
import DocumentRequestStatusBadge from './DocumentRequestStatusBadge.vue'
import { documentRequestService as api } from './documentRequestService'
import { groupRequestStatus } from './documentRequestWorkspace'

defineProps({ documentTypes: { type: Array, default: () => [] } })
const emit = defineEmits(['select'])
const groups = ref([])
const search = ref('')
const documentTypeId = ref('')
const status = ref('')
const dateFrom = ref('')
const dateTo = ref('')
const page = ref(1)
const lastPage = ref(1)
const total = ref(0)
const loading = ref(false)
const error = ref('')
const message = ref('')
const selectedIds = ref([])
const rejectionReason = ref('')
const rejecting = ref(false)
const expandedIds = ref([])
const filtersOpen = ref(false)
const filtersActive = computed(() => Boolean(search.value || documentTypeId.value || status.value || dateFrom.value || dateTo.value))
let searchTimer
let requestSequence = 0
let suppressNextSearch = false

async function refresh(resetPage = false) {
  clearTimeout(searchTimer)
  if (resetPage) page.value = 1
  selectedIds.value = []
  const sequence = ++requestSequence
  loading.value = true
  error.value = ''
  try {
    const result = await api.registrarBatches({
      search: search.value || undefined,
      document_type_id: documentTypeId.value || undefined,
      status: status.value || undefined,
      date_from: dateFrom.value || undefined,
      date_to: dateTo.value || undefined,
      page: page.value,
    })
    if (sequence !== requestSequence) return
    groups.value = result.data
    page.value = result.current_page
    lastPage.value = result.last_page
    total.value = result.total
  } catch (err) {
    if (sequence !== requestSequence) return
    error.value = err.response?.data?.message || 'Grouped requests could not be loaded.'
  } finally {
    if (sequence === requestSequence) loading.value = false
  }
}

async function rejectSelected() {
  if (rejecting.value || !selectedIds.value.length || !rejectionReason.value.trim()) return
  if (!window.confirm(`Reject ${selectedIds.value.length} selected pending requests? This cannot be undone.`)) return
  rejecting.value = true
  error.value = ''
  message.value = ''
  try {
    await api.bulkRejectRequests({ request_ids: selectedIds.value, reason: rejectionReason.value.trim() })
    message.value = `${selectedIds.value.length} pending requests rejected.`
    rejectionReason.value = ''
    await refresh()
  } catch (err) {
    error.value = err.response?.data?.message || 'Selected requests could not be rejected.'
  } finally {
    rejecting.value = false
  }
}

function reset() {
  clearTimeout(searchTimer)
  if (search.value) suppressNextSearch = true
  search.value = ''
  documentTypeId.value = ''
  status.value = ''
  dateFrom.value = ''
  dateTo.value = ''
  refresh(true)
}

function changePage(nextPage) {
  page.value = nextPage
  refresh()
}

function setExpanded(id, open) {
  expandedIds.value = open
    ? [...new Set([...expandedIds.value, id])]
    : expandedIds.value.filter((value) => value !== id)
}

watch(search, () => {
  if (suppressNextSearch) { suppressNextSearch = false; return }
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => refresh(true), 350)
})
onMounted(refresh)
onBeforeUnmount(() => { clearTimeout(searchTimer); requestSequence++ })
defineExpose({ refresh })
</script>

<template>
  <section class="dr-table-panel grouped-workspace" aria-labelledby="grouped-requests-heading">
    <header class="grouped-header"><div><h2 id="grouped-requests-heading">Grouped submissions</h2><p>Review related requests together; process each document independently.</p></div><span v-if="total" class="grouped-count">{{ total }} {{ total === 1 ? 'group' : 'groups' }}</span></header>
    <form class="grouped-filters" @submit.prevent="refresh(true)">
      <div class="grouped-filter-main">
        <label class="grouped-search">Search submissions<input v-model="search" type="search" placeholder="Student, number, or group reference" /></label>
        <label>Document type<select v-model="documentTypeId"><option value="">All documents</option><option v-for="type in documentTypes" :key="type.id" :value="type.id">{{ type.document_name }}</option></select></label>
        <label>Status<select v-model="status"><option value="">All statuses</option><option value="pending">Pending</option><option value="approved">Approved</option><option value="completed">Completed</option><option value="rejected">Rejected</option><option value="cancelled">Cancelled</option></select></label>
      </div>
      <div class="grouped-filter-more">
        <button type="button" class="dr-button dr-button--ghost grouped-more-toggle" :aria-expanded="filtersOpen" aria-controls="grouped-date-filters" @click="filtersOpen = !filtersOpen">{{ filtersOpen ? 'Hide dates' : 'Date filters' }}{{ dateFrom || dateTo ? ' · active' : '' }}</button>
        <div v-show="filtersOpen" id="grouped-date-filters" class="grouped-date-fields"><label>From<input v-model="dateFrom" type="date" /></label><label>To<input v-model="dateTo" type="date" :min="dateFrom || undefined" /></label></div>
        <div class="grouped-filter-actions"><button type="submit" class="dr-button dr-button--primary" :disabled="loading">Apply filters</button><button type="button" class="dr-button dr-button--secondary" :disabled="!filtersActive" @click="reset">Clear</button></div>
      </div>
    </form>
    <div v-if="error" role="alert" class="grouped-feedback notice error">{{ error }} <button type="button" class="dr-button dr-button--secondary" @click="refresh()">Retry</button></div>
    <p v-if="message" role="status" class="notice success">{{ message }}</p>
    <form v-if="selectedIds.length" class="grouped-bulk" @submit.prevent="rejectSelected">
      <div class="grouped-bulk-title"><strong>{{ selectedIds.length }} selected</strong><span>Pending requests only</span></div>
      <label>Rejection reason<input v-model="rejectionReason" required maxlength="2000" placeholder="Required for the audit" /></label>
      <button type="submit" class="dr-button dr-button--danger" :disabled="rejecting || !rejectionReason.trim()">{{ rejecting ? 'Rejecting…' : 'Reject selected pending' }}</button>
    </form>
    <div v-if="loading" class="grouped-loading" role="status" aria-label="Loading grouped requests"><span></span><span></span><span></span></div>
    <div v-else-if="!groups.length && !error" class="grouped-empty"><strong>{{ filtersActive ? 'No matching submissions' : 'No grouped submissions yet' }}</strong><p>{{ filtersActive ? 'Try a different search or clear the filters.' : 'New multi-document requests will appear here. Older single requests remain in Individual requests.' }}</p></div>
    <div v-else-if="!loading" class="grouped-list">
      <details v-for="group in groups" :key="group.id" class="grouped-request" :open="expandedIds.includes(group.id)" @toggle="setExpanded(group.id, $event.target.open)">
        <summary :aria-expanded="expandedIds.includes(group.id)"><span class="grouped-student"><strong>{{ [group.student?.user?.profile?.first_name, group.student?.user?.profile?.last_name].filter(Boolean).join(' ') || 'Student record' }}</strong><small>{{ group.student?.student_number || 'Student number unavailable' }}</small></span><span class="grouped-reference">{{ group.group_reference }}</span><span class="grouped-meta">{{ group.document_requests_count }} {{ group.document_requests_count === 1 ? 'document' : 'documents' }} · {{ groupRequestStatus(group.document_requests) }}</span><span class="grouped-chevron" aria-hidden="true">⌄</span></summary>
        <ul>
          <li v-for="item in group.document_requests" :key="item.id">
            <label v-if="item.status === 'pending'" class="grouped-select"><input v-model="selectedIds" type="checkbox" :value="item.id" :aria-label="`Select ${item.document_type?.document_name} request ${item.id}`" /></label><span v-else class="grouped-select-spacer" aria-hidden="true"></span>
            <span class="grouped-document"><strong>{{ item.document_type?.document_name }}</strong><small>REQ-{{ String(item.id).padStart(6, '0') }} · {{ item.quantity }} {{ item.quantity === 1 ? 'copy' : 'copies' }}</small></span>
            <DocumentRequestStatusBadge :status="item.status" />
            <button type="button" class="dr-button dr-button--secondary" :aria-label="`Open ${item.document_type?.document_name} request ${item.id}`" @click="emit('select', item)">Open request</button>
          </li>
        </ul>
      </details>
    </div>
    <PaginationControls v-if="total" :current-page="page" :last-page="lastPage" :total="total" total-label="groups" aria-label="Grouped request pages" @page-change="changePage" />
  </section>
</template>

<style scoped>
.grouped-workspace{min-width:0}.grouped-header{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:16px 18px;border-bottom:1px solid var(--border-color);color:var(--text-primary)}.grouped-header h2{margin:0;font-size:1.05rem}.grouped-header p{margin:3px 0 0;color:var(--text-secondary);font-size:.82rem;line-height:1.4}.grouped-count{flex:none;font-size:.78rem;color:var(--text-secondary)}
.grouped-filters{display:grid;gap:10px;padding:14px 18px;border-bottom:1px solid var(--border-color)}.grouped-filter-main{display:grid;grid-template-columns:minmax(220px,2fr) repeat(2,minmax(140px,1fr));gap:10px}.grouped-filters label{display:grid;gap:5px;min-width:0;font-size:.78rem;font-weight:600;color:var(--text-secondary)}.grouped-filters :is(input,select){width:100%;min-width:0;height:40px;padding:0 10px;border:1px solid var(--border-strong);border-radius:7px;background:var(--bg-input);color:var(--text-primary);font:inherit;font-weight:400}.grouped-filter-more,.grouped-date-fields,.grouped-filter-actions{display:flex;align-items:end;flex-wrap:wrap;gap:10px}.grouped-filter-more{justify-content:space-between}.grouped-date-fields label{min-width:150px}.grouped-filter-actions{margin-left:auto}.grouped-filters button{min-height:40px}.grouped-more-toggle{align-self:end}.grouped-feedback{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap}
.grouped-list{padding:8px 14px 14px}.grouped-request{margin-top:8px;border:1px solid var(--border-color);border-radius:9px;background:var(--bg-surface)}.grouped-request[open]{border-color:var(--border-strong)}.grouped-request summary{display:flex;align-items:center;gap:14px;padding:13px 15px;cursor:pointer;color:var(--text-primary);list-style:none}.grouped-request summary::-webkit-details-marker{display:none}.grouped-student{display:grid;gap:2px;flex:1 1 210px;min-width:0}.grouped-student strong{font-size:.9rem}.grouped-request small{color:var(--text-secondary);font-size:.76rem}.grouped-reference{flex:0 1 150px;font-size:.79rem;color:var(--text-secondary)}.grouped-meta{flex:0 1 190px;font-size:.78rem;color:var(--text-secondary)}.grouped-chevron{flex:none;font-size:1.15rem;transition:transform 180ms ease}.grouped-request[open] .grouped-chevron{transform:rotate(180deg)}.grouped-request ul{margin:0;padding:0 15px 6px;list-style:none;border-top:1px solid var(--border-color)}.grouped-request li{display:flex;align-items:center;gap:12px;min-height:58px;padding:8px 0;border-bottom:1px solid var(--border-color)}.grouped-request li:last-child{border-bottom:0}.grouped-document{display:grid;gap:2px;flex:1;min-width:0;overflow-wrap:anywhere;color:var(--text-primary)}.grouped-document strong{font-size:.84rem}.grouped-document small{display:block}.grouped-request li .dr-button{flex:none}.grouped-select,.grouped-select-spacer{display:flex;align-items:center;justify-content:center;flex:none;width:22px}.grouped-select input{width:17px;height:17px;accent-color:var(--accent)}
.grouped-bulk{display:flex;align-items:end;flex-wrap:wrap;gap:10px;padding:12px 18px;border-bottom:1px solid var(--border-color);background:var(--bg-surface-alt)}.grouped-bulk-title{display:grid;gap:2px;color:var(--text-primary);font-size:.85rem}.grouped-bulk-title span{color:var(--text-secondary);font-size:.76rem}.grouped-bulk label{display:grid;flex:1 1 230px;gap:5px;color:var(--text-primary);font-size:.78rem}.grouped-bulk input{height:40px;padding:0 9px;border:1px solid var(--border-strong);border-radius:7px;background:var(--bg-input);color:var(--text-primary)}.grouped-empty{padding:34px 18px;text-align:center;color:var(--text-primary)}.grouped-empty p{margin:5px 0 0;color:var(--text-secondary);font-size:.84rem}.grouped-loading{display:grid;gap:10px;padding:18px}.grouped-loading span{height:58px;border-radius:7px;background:var(--bg-surface-alt)}
.grouped-workspace :is(summary,input,select,button):focus-visible{outline:2px solid var(--accent);outline-offset:2px}@media(max-width:1120px){.grouped-filter-main{grid-template-columns:minmax(0,1fr) minmax(0,1fr)}.grouped-search{grid-column:1/-1}}@media(max-width:700px){.grouped-filter-main{grid-template-columns:minmax(0,1fr)}.grouped-search{grid-column:auto}.grouped-request summary{flex-wrap:wrap;gap:6px 12px}.grouped-student{flex-basis:100%}.grouped-meta{flex:1}.grouped-request li{flex-wrap:wrap}.grouped-document{flex-basis:calc(100% - 40px)}.grouped-request li .dr-button{margin-left:34px}}@media(prefers-reduced-motion:reduce){.grouped-chevron{transition:none}}
</style>
