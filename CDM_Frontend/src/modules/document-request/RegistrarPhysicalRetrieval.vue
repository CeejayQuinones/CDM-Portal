<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import PaginationControls from '../../components/PaginationControls.vue'
import CdmButton from '../../design-system/CdmButton.vue'
import { documentRequestService as api } from './documentRequestService'
import { groupRetrievalTasks } from './documentRequestRetrieval'
import { retrievalPageSummary } from './documentRequestWorkspace'
import { physicalRecordsService } from '../student-management/physicalRecordsService'

const props = defineProps({ returnOnly: { type: Boolean, default: false } })
const emit = defineEmits(['changed'])
const tasks = ref([])
const search = ref('')
const status = ref(props.returnOnly ? 'retrieved' : '')
const cabinetId = ref('')
const cabinets = ref(null)
const cabinetError = ref('')
const page = ref(1)
const lastPage = ref(1)
const total = ref(0)
const loading = ref(false)
const busyId = ref(null)
const error = ref('')
const message = ref('')
const expanded = ref(false)
let searchTimer
let requestSequence = 0
let suppressNextSearch = false

const cabinetGroups = computed(() => groupRetrievalTasks(tasks.value))
const pageSummary = computed(() => retrievalPageSummary(tasks.value))
const filtersActive = computed(() => Boolean(search.value || status.value || cabinetId.value))

async function loadCabinets() {
  if (cabinets.value !== null) return
  cabinetError.value = ''
  try {
    const result = await physicalRecordsService.cabinets()
    cabinets.value = Array.isArray(result) ? result : []
  } catch {
    cabinetError.value = 'Cabinet filter is unavailable. Retrieval work is still shown.'
  }
}

function changeStatus() {
  if (status.value === 'awaiting') loadCabinets()
  else cabinetId.value = ''
  refresh(true)
}

async function refresh(resetPage = false) {
  clearTimeout(searchTimer)
  if (resetPage) page.value = 1
  const sequence = ++requestSequence
  loading.value = true
  error.value = ''
  try {
    const result = await api.retrievalTasks({ search: search.value || undefined, status: status.value || undefined, cabinet_id: status.value === 'awaiting' ? cabinetId.value || undefined : undefined, page: page.value })
    if (sequence !== requestSequence) return
    tasks.value = result.data
    page.value = result.current_page
    lastPage.value = result.last_page
    total.value = result.total
  } catch (err) {
    if (sequence !== requestSequence) return
    error.value = err.response?.data?.message || 'Physical retrieval work could not be loaded.'
  } finally {
    if (sequence === requestSequence) loading.value = false
  }
}

async function transition(task, action) {
  if (busyId.value) return
  const verb = action === 'retrieve' ? 'retrieve' : 'return'
  if (!window.confirm(`Confirm ${verb} for ${task.student?.student_number}? This action is recorded in the retrieval audit.`)) return
  busyId.value = task.id
  error.value = ''
  message.value = ''
  try {
    await api.updateRetrievalTask(task.id, action)
    message.value = action === 'retrieve' ? 'Physical folder marked retrieved.' : 'Physical folder marked returned.'
    await refresh()
    emit('changed')
  } catch (err) {
    error.value = err.response?.data?.message || 'The physical retrieval could not be updated.'
  } finally {
    busyId.value = null
  }
}

function reset() {
  clearTimeout(searchTimer)
  if (search.value) suppressNextSearch = true
  search.value = ''
  status.value = ''
  cabinetId.value = ''
  refresh(true)
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
  <section class="dr-table-panel retrieval-workspace" :class="{ 'retrieval-workspace--return-only': returnOnly }" :aria-labelledby="returnOnly ? undefined : 'retrieval-heading'" :aria-label="returnOnly ? 'Physical folders' : undefined">
    <header v-if="!returnOnly" class="retrieval-header"><div><h2 id="retrieval-heading">Physical Retrieval</h2><p>Locate and track student folders for eligible document requests.</p></div><span v-if="total" class="retrieval-total">{{ total }} {{ total === 1 ? 'folder' : 'folders' }} in results</span></header>
    <div v-else class="retrieval-compact-bar">
      <div><strong>Physical folders</strong><small v-if="loading" role="status">Checking folders out of storage…</small><small v-else-if="error">Folder status unavailable</small><small v-else-if="total === 0" role="status">All folders accounted for</small><small v-else role="status">{{ total }} {{ total === 1 ? 'folder' : 'folders' }} out of storage</small></div>
      <CdmButton v-if="!error && !loading && total > 0" type="button" variant="outline" size="sm" :aria-expanded="expanded" aria-controls="return-folder-list" @click="expanded = !expanded">{{ expanded ? 'Hide folders' : 'View folders' }}</CdmButton>
    </div>
    <div v-if="!returnOnly && !loading && tasks.length" class="retrieval-overview" aria-label="Retrieval counts on this page">
      <span class="retrieval-overview-label">This page</span><span><strong>{{ pageSummary.awaiting }}</strong> awaiting</span><span><strong>{{ pageSummary.retrieved }}</strong> retrieved</span><span><strong>{{ pageSummary.missing }}</strong> unassigned</span>
    </div>
    <div v-if="!returnOnly" class="retrieval-filters" role="search" aria-label="Filter physical retrieval work">
      <label>Find student<input v-model="search" type="search" placeholder="Name or student number" /></label>
      <label>Status<select v-model="status" @change="changeStatus"><option value="">All active work</option><option value="awaiting">Awaiting retrieval</option><option value="retrieved">Retrieved</option></select></label>
      <label v-if="status === 'awaiting' && cabinets !== null">Current cabinet<select v-model="cabinetId" @change="refresh(true)"><option value="">All cabinets</option><option v-for="cabinet in cabinets" :key="cabinet.id" :value="cabinet.id">Cabinet {{ cabinet.cabinet_code }}</option></select></label>
      <button type="button" class="dr-button dr-button--secondary" :disabled="!filtersActive" @click="reset">Clear</button>
    </div>
    <div v-if="cabinetError && status === 'awaiting'" class="retrieval-feedback notice" role="status">{{ cabinetError }} <button type="button" class="dr-button dr-button--secondary" @click="loadCabinets">Retry cabinet list</button></div>
    <div v-if="error" class="retrieval-feedback notice error" role="alert">{{ error }} <CdmButton type="button" variant="outline" @click="refresh()">Retry</CdmButton></div>
    <p v-if="message" class="notice success" role="status">{{ message }}</p>
    <div v-if="!error && (!returnOnly || (expanded && total > 0))" id="return-folder-list">
      <div v-if="loading" role="status" aria-label="Loading physical retrieval work" class="retrieval-loading"><span></span><span></span><span></span></div>
      <div v-else-if="!cabinetGroups.length" class="retrieval-empty"><strong>{{ returnOnly ? 'No folders awaiting return' : filtersActive ? 'No matching folders' : 'No folders in the retrieval queue' }}</strong><p v-if="!returnOnly">{{ filtersActive ? 'Try another student or clear the status filter.' : 'Physical retrieval is enabled per document type and applies only to new eligible requests.' }}</p><router-link v-if="!returnOnly && !filtersActive" :to="{ name: 'registrar-document-types' }" class="dr-button dr-button--secondary">Manage document types</router-link></div>
      <div v-else-if="!loading" class="retrieval-list">
      <section v-for="cabinet in cabinetGroups" :key="cabinet.name" class="retrieval-cabinet">
        <h3>{{ cabinet.name === 'Unassigned location' ? cabinet.name : `Cabinet ${cabinet.name}` }} <small>{{ cabinet.drawers.reduce((count, drawer) => count + drawer.items.length, 0) }} folders</small></h3>
        <div v-for="drawer in cabinet.drawers" :key="drawer.name" class="retrieval-drawer">
          <h4>{{ drawer.name === 'No drawer assigned' ? drawer.name : `Drawer / Slot ${drawer.name}` }} <small>{{ drawer.items.length }} {{ drawer.items.length === 1 ? 'folder' : 'folders' }}</small></h4>
          <article v-for="task in drawer.items" :key="task.id" class="retrieval-task">
            <div class="retrieval-person"><strong>{{ [task.student?.user?.profile?.first_name, task.student?.user?.profile?.last_name].filter(Boolean).join(' ') || 'Student record' }}</strong><small>{{ task.student?.student_number || 'Student number unavailable' }}</small></div>
            <div v-if="!returnOnly" class="retrieval-requests"><span v-for="item in task.document_requests" :key="item.id">{{ item.document_type?.document_name || 'Document request' }}</span></div>
            <span class="retrieval-status" :class="`is-${task.status}`">{{ task.status === 'awaiting' ? 'Awaiting retrieval' : 'Retrieved' }}</span>
            <CdmButton v-if="task.status === 'awaiting'" type="button" :disabled="busyId === task.id || drawer.name === 'No drawer assigned' || cabinet.name === 'Unassigned location'" :aria-label="`Mark folder for ${task.student?.student_number} retrieved`" @click="transition(task, 'retrieve')">{{ busyId === task.id ? 'Saving…' : 'Mark retrieved' }}</CdmButton>
            <CdmButton v-else type="button" variant="outline" :disabled="busyId === task.id" :aria-label="`Mark folder for ${task.student?.student_number} returned`" @click="transition(task, 'return')">{{ busyId === task.id ? 'Saving…' : 'Mark returned' }}</CdmButton>
            <small v-if="(drawer.name === 'No drawer assigned' || cabinet.name === 'Unassigned location') && task.status === 'awaiting'" class="retrieval-location-warning">Assign a physical location before retrieval.</small>
          </article>
        </div>
      </section>
      </div>
      <PaginationControls v-if="total" :current-page="page" :last-page="lastPage" :total="total" total-label="folders" aria-label="Physical retrieval pages" @page-change="page = $event; refresh()" />
    </div>
  </section>
</template>

<style scoped>
.retrieval-workspace{min-width:0}.retrieval-header{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:16px 18px;border-bottom:1px solid var(--border-color);color:var(--text-primary)}.retrieval-header h2{margin:0;font-size:1.05rem}.retrieval-header p{margin:3px 0 0;color:var(--text-secondary);font-size:.82rem;line-height:1.4}.retrieval-total{flex:none;color:var(--text-secondary);font-size:.78rem}.retrieval-overview{display:flex;align-items:center;flex-wrap:wrap;gap:10px 20px;padding:10px 18px;border-bottom:1px solid var(--border-color);background:var(--bg-surface-alt);color:var(--text-secondary);font-size:.78rem}.retrieval-overview strong{margin-right:4px;color:var(--text-primary);font-size:.9rem}.retrieval-overview-label{font-weight:700;color:var(--text-primary)}
.retrieval-filters{display:flex;align-items:end;flex-wrap:wrap;gap:10px;padding:14px 18px;border-bottom:1px solid var(--border-color)}.retrieval-filters label{display:grid;flex:1 1 145px;min-width:0;gap:5px;font-size:.78rem;font-weight:600;color:var(--text-secondary)}.retrieval-filters label:first-child{flex:2 1 220px}.retrieval-filters :is(input,select){width:100%;min-width:0;height:40px;padding:0 10px;border:1px solid var(--border-strong);border-radius:7px;background:var(--bg-input);color:var(--text-primary);font:inherit;font-weight:400}.retrieval-filters button{min-height:40px}.retrieval-feedback{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap}.retrieval-list{padding:4px 14px 14px}.retrieval-cabinet{padding-top:14px}.retrieval-cabinet h3{display:flex;align-items:baseline;gap:9px;margin:0 0 8px;color:var(--text-primary);font-size:.94rem}.retrieval-cabinet h3 small,.retrieval-drawer h4 small{color:var(--text-secondary);font-size:.75rem;font-weight:400}.retrieval-drawer{margin:7px 0 0;padding:0 12px;border:1px solid var(--border-color);border-radius:8px;background:var(--bg-surface)}.retrieval-drawer h4{display:flex;align-items:baseline;gap:9px;margin:0;padding:10px 0;color:var(--text-primary);font-size:.81rem}.retrieval-task{display:flex;align-items:center;gap:10px 14px;min-width:0;padding:11px 0;border-top:1px solid var(--border-color)}.retrieval-person{display:grid;gap:2px;flex:1 1 180px;min-width:0;color:var(--text-primary);font-size:.85rem}.retrieval-person small{color:var(--text-secondary);font-size:.76rem}.retrieval-requests{display:flex;flex:1 1 160px;flex-wrap:wrap;gap:4px 6px;min-width:0}.retrieval-requests span{max-width:100%;overflow-wrap:anywhere;padding:3px 7px;border:1px solid var(--border-color);border-radius:5px;color:var(--text-secondary);font-size:.73rem}.retrieval-status{flex:none;color:var(--text-secondary);font-size:.76rem}.retrieval-status.is-retrieved{color:var(--accent);font-weight:700}.retrieval-task .dr-button{flex:none}.retrieval-location-warning{flex-basis:100%;color:var(--text-secondary);font-size:.75rem}.retrieval-empty{padding:34px 18px;text-align:center;color:var(--text-primary)}.retrieval-empty p{margin:5px 0 12px;color:var(--text-secondary);font-size:.84rem}.retrieval-loading{display:grid;gap:10px;padding:18px}.retrieval-loading span{height:58px;border-radius:7px;background:var(--bg-surface-alt)}
.retrieval-workspace :is(input,select,button,a):focus-visible{outline:2px solid var(--accent);outline-offset:2px}@media(max-width:700px){.retrieval-filters{grid-template-columns:minmax(0,1fr) auto}.retrieval-filters label:first-child{grid-column:1/-1}.retrieval-task{flex-wrap:wrap}.retrieval-person{flex-basis:100%}.retrieval-requests{flex-basis:100%}.retrieval-task .cdm-button{margin-left:auto}}@media(max-width:480px){.retrieval-filters{grid-template-columns:minmax(0,1fr)}.retrieval-header{flex-wrap:wrap}}
.retrieval-workspace--return-only{margin-top:12px;border:0;background:transparent;box-shadow:none}
.retrieval-compact-bar{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px 12px;padding:9px 12px;border:1px solid var(--border-color);border-radius:8px;background:var(--bg-surface);color:var(--text-primary)}
.retrieval-compact-bar>div{display:flex;align-items:baseline;flex-wrap:wrap;gap:5px 12px;min-width:0}
.retrieval-compact-bar strong{font-size:.82rem}.retrieval-compact-bar small{color:var(--text-secondary);font-size:.78rem}
.retrieval-workspace--return-only .retrieval-list{padding:4px 0}
.retrieval-workspace--return-only .retrieval-drawer{margin-inline:0}
.retrieval-workspace--return-only .retrieval-task{flex-wrap:wrap}
</style>
