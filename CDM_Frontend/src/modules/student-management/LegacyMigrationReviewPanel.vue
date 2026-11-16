<script setup>
import { onMounted, ref } from 'vue'
import { apiClient } from '../../services/apiClient'
import { isStepUpCancelled, useStepUpAuth } from '../../composables/useStepUpAuth'

const { runWithStepUp } = useStepUpAuth()

const batches = ref([])
const selected = ref(null)
const busy = ref(false)
const error = ref('')
const approvalReference = ref('')
const backupReference = ref('')
const notice = ref('')

async function refresh() {
  busy.value = true
  error.value = ''
  try {
    const response = await apiClient.get('/registrar/legacy-migration/batches')
    batches.value = response.data.data || []
  } catch (exception) {
    error.value = exception.response?.data?.message || 'Migration batches could not be loaded.'
  } finally {
    busy.value = false
  }
}

async function selectBatch(batch) {
  if (selected.value?.batch_id === batch.batch_id) { selected.value = null; return }
  busy.value = true
  error.value = ''
  try {
    const response = await apiClient.get(`/registrar/legacy-migration/batches/${batch.batch_id}`)
    selected.value = response.data.data
  } catch (exception) {
    error.value = exception.response?.data?.message || 'Batch details could not be loaded.'
  } finally {
    busy.value = false
  }
}

async function approve() {
  const batch = selected.value
  if (!batch || batch.status !== 'preflighted' || !approvalReference.value.trim() || !backupReference.value.trim()) return
  if (!window.confirm(`Approve batch #${batch.batch_id} after checking all ${batch.row_count} source rows and the verified backup? This does not import records yet.`)) return
  busy.value = true
  error.value = ''
  try {
    const response = await runWithStepUp(() => apiClient.post(`/registrar/legacy-migration/batches/${batch.batch_id}/approve`, {
      source_sha256: batch.source_sha256,
      reconciled_row_count: batch.row_count,
      approval_reference: approvalReference.value.trim(),
      backup_reference: backupReference.value.trim(),
    }))
    selected.value = response.data.data
    notice.value = `Batch #${batch.batch_id} approved. An authorized operator must run the controlled commit on the server.`
    approvalReference.value = ''
    backupReference.value = ''
    await refresh()
  } catch (exception) {
    if (!isStepUpCancelled(exception)) error.value = Object.values(exception.response?.data?.errors || {}).flat().join(' ') || exception.response?.data?.message || 'Batch approval failed.'
  } finally { busy.value = false }
}

onMounted(refresh)
</script>

<template>
  <section class="legacy-review" aria-labelledby="legacy-review-title">
    <header class="legacy-review-heading">
      <div><p class="legacy-review-kicker">Access migration · Registrar review</p><h2 id="legacy-review-title">Staged batches</h2><p>Developer-loaded exports appear here for reconciliation. Only an approved, separately committed batch creates official records.</p></div>
      <button type="button" :disabled="busy" @click="refresh">Refresh</button>
    </header>
    <p v-if="error" role="alert" class="legacy-review-error">{{ error }}</p>
    <p v-if="notice" role="status">{{ notice }}</p>
    <p v-if="!busy && !batches.length" class="legacy-review-empty">No Access exports have been staged. The existing small-file importer below remains available.</p>
    <div v-else class="legacy-review-list">
      <button v-for="batch in batches" :key="batch.batch_id" type="button" class="legacy-review-batch" :aria-expanded="selected?.batch_id === batch.batch_id" :disabled="busy" @click="selectBatch(batch)">
        <span><strong>{{ batch.entity === 'students' ? 'Student identities' : 'Previous grades' }}</strong><small>Batch #{{ batch.batch_id }} · {{ batch.status }}</small></span>
        <span>{{ batch.row_count }} rows</span>
        <span>{{ batch.by_status?.ready || 0 }} ready · {{ batch.by_status?.imported || 0 }} imported · {{ batch.by_status?.needs_review || 0 }} need review</span>
      </button>
    </div>
    <div v-if="selected" class="legacy-review-detail">
      <h3>Batch #{{ selected.batch_id }} exceptions</h3>
      <p>{{ selected.note }}</p>
      <ul v-if="Object.keys(selected.by_reason || {}).length"><li v-for="(count, reason) in selected.by_reason" :key="reason"><span>{{ reason.replaceAll('_', ' ') }}</span><strong>{{ count }}</strong></li></ul>
      <p v-else>{{ selected.status === 'preflighted' ? 'No validation exceptions. Reconcile the source and verify a backup before approval.' : selected.status === 'imported' ? 'All rows were committed.' : 'No exceptions recorded for this batch.' }}</p>
      <p v-if="selected.exceptions?.length" class="legacy-review-rows">Affected export rows: {{ selected.exceptions.map(item => item.source_row).join(', ') }}{{ selected.exceptions_truncated ? '…' : '' }}</p>
      <form v-if="selected.status === 'preflighted' && selected.by_status?.ready === selected.row_count && selected.row_count > 0" class="legacy-approval" @submit.prevent="approve">
        <strong>Approve reconciled batch</strong>
        <p>Confirm the row count against the Access export and record the signed review and verified backup references. Approval alone does not import records; the server operator commits the batch separately.</p>
        <label>Signed reconciliation reference<input v-model.trim="approvalReference" required minlength="10" maxlength="255" placeholder="Approved report or register reference" /></label>
        <label>Verified backup reference<input v-model.trim="backupReference" required minlength="10" maxlength="255" placeholder="Backup ID and recovery-check reference" /></label>
        <button type="submit" :disabled="busy">Approve batch</button>
      </form>
    </div>
  </section>
</template>

<style scoped>
.legacy-review{border:1px solid var(--color-border,#d9e5de);border-radius:14px;background:var(--color-surface,#fff);padding:20px;display:grid;gap:14px}.legacy-review-heading{display:flex;justify-content:space-between;align-items:start;gap:16px}.legacy-review-heading h2{font-size:1.1rem;margin:2px 0}.legacy-review-heading p:not(.legacy-review-kicker),.legacy-review-detail p,.legacy-review-empty{color:var(--color-muted,#60726b);line-height:1.5;margin:4px 0}.legacy-review-kicker{font-size:.72rem;color:#087443;font-weight:800;text-transform:uppercase;letter-spacing:.08em;margin:0}.legacy-review button{font:inherit;cursor:pointer}.legacy-review-heading button{background:transparent;border:1px solid var(--color-border,#d9e5de);border-radius:8px;padding:8px 12px;color:inherit}.legacy-review-list{display:grid;gap:6px}.legacy-review-batch{display:grid;grid-template-columns:minmax(140px,1fr) auto auto;gap:18px;align-items:center;text-align:left;background:var(--color-surface,#fff);border:1px solid var(--color-border,#d9e5de);border-radius:9px;padding:12px;color:inherit}.legacy-review-batch[aria-expanded=true]{border-color:#087443;background:#f2faf5}.legacy-review-batch span:first-child{display:grid;gap:3px}.legacy-review-batch small{color:var(--color-muted,#60726b)}.legacy-review-detail{border-top:1px solid var(--color-border,#d9e5de);padding-top:12px}.legacy-review-detail h3{font-size:1rem;margin:0}.legacy-review-detail ul{list-style:none;margin:12px 0;padding:0;max-width:520px}.legacy-review-detail li{display:flex;justify-content:space-between;gap:15px;border-bottom:1px solid var(--color-border,#d9e5de);padding:8px 0;text-transform:capitalize}.legacy-review-rows{font-size:.82rem;overflow-wrap:anywhere}.legacy-review-error{color:#a32626;margin:0}@media(max-width:650px){.legacy-review-heading{flex-direction:column}.legacy-review-batch{grid-template-columns:1fr;gap:4px}}
.legacy-approval{display:grid;gap:10px;max-width:580px;padding:14px;border:1px solid var(--color-border,#d9e5de);border-radius:10px}.legacy-approval p{margin:0}.legacy-approval label{display:grid;gap:5px;font-weight:700}.legacy-approval input{font:inherit;padding:9px;border:1px solid var(--color-border,#d9e5de);border-radius:7px}.legacy-approval button{justify-self:start;padding:9px 13px;color:white;background:#087443;border:0;border-radius:7px}
</style>
