<script setup>
import { onMounted, ref } from 'vue'
import { apiClient } from '../../services/apiClient'
import { isStepUpCancelled, useStepUpAuth } from '../../composables/useStepUpAuth'

const { runWithStepUp } = useStepUpAuth()
const visits = ref([])
const loading = ref(false)
const error = ref('')
const message = ref('')
const saving = ref(null)

async function load() {
  loading.value = true
  error.value = ''
  try {
    const { data } = await apiClient.get('/registrar/student-account/visit-requests')
    visits.value = data.data.map((visit) => ({
      ...visit, next_status: visit.status,
      scheduled_date: visit.scheduled_date?.slice(0, 10) || '',
      scheduled_time: visit.scheduled_time?.slice(0, 5) || '',
      physical_school_id_checked: false,
    }))
  } catch (requestError) {
    error.value = requestError.response?.data?.message || 'Unable to load account visits.'
  } finally {
    loading.value = false
  }
}

async function save(visit) {
  saving.value = visit.id
  error.value = ''
  message.value = ''
  try {
    await runWithStepUp(() => apiClient.patch(`/registrar/student-account/visit-requests/${visit.id}`, {
      status: visit.next_status,
      scheduled_date: visit.next_status === 'scheduled' ? visit.scheduled_date : null,
      scheduled_time: visit.next_status === 'scheduled' ? visit.scheduled_time : null,
      physical_school_id_checked: visit.physical_school_id_checked,
    }))
    message.value = 'Visit updated. The student can check the status using their reference.'
    await load()
  } catch (requestError) {
    if (!isStepUpCancelled(requestError)) error.value = Object.values(requestError.response?.data?.errors || {}).flat()[0]
      || requestError.response?.data?.message || 'Unable to update the visit.'
  } finally {
    saving.value = null
  }
}

onMounted(load)
</script>

<template>
  <details class="account-visit-queue">
    <summary>Account recovery visits <span>{{ visits.length }} open</span></summary>
    <p>These are visit requests, not proof of identity. Confirm the physical school ID, name, and student number in person before correcting an email in the student profile.</p>
    <p v-if="error" role="alert" class="queue-error">{{ error }}</p>
    <p v-if="message" role="status" class="queue-success">{{ message }}</p>
    <p v-if="loading">Loading visits…</p>
    <p v-else-if="!visits.length">No open account recovery visits.</p>
    <div v-else class="visit-list">
      <article v-for="visit in visits" :key="visit.id">
        <div><strong>{{ visit.full_name }}</strong><small>{{ visit.student_number }} · {{ visit.contact_email }}</small><small>Preferred: {{ visit.preferred_date?.slice(0, 10) }} · {{ visit.preferred_time }}</small><small>Reference: {{ visit.reference }}</small></div>
        <div class="visit-controls">
          <label>Status<select v-model="visit.next_status"><option value="requested">Requested</option><option value="scheduled">Schedule visit</option><option value="resolved">ID checked / resolved</option><option value="declined">Decline</option></select></label>
          <template v-if="visit.next_status === 'scheduled'"><label>Date<input v-model="visit.scheduled_date" type="date" /></label><label>Time<input v-model="visit.scheduled_time" type="time" /></label></template>
          <label v-if="visit.next_status === 'resolved'" class="visit-check"><input v-model="visit.physical_school_id_checked" type="checkbox" /> Physical school ID, name, and student number checked</label>
          <button type="button" :disabled="saving === visit.id || visit.next_status === 'requested' || (visit.next_status === 'resolved' && !visit.physical_school_id_checked)" @click="save(visit)">Save</button>
        </div>
      </article>
    </div>
  </details>
</template>

<style scoped>
.account-visit-queue{border:1px solid var(--color-border);border-radius:10px;background:var(--color-surface);padding:14px}.account-visit-queue summary{cursor:pointer;font-weight:800;color:var(--color-eerie-black)}.account-visit-queue summary span{margin-left:8px;color:var(--color-muted);font-size:.78rem}.account-visit-queue>p{font-size:.8rem;color:var(--color-muted)}.visit-list{display:grid;gap:8px}.visit-list article{display:grid;grid-template-columns:minmax(180px,1fr) minmax(0,2fr);gap:12px;padding:12px;border:1px solid var(--color-border);border-radius:8px}.visit-list article>div:first-child{display:grid;gap:3px}.visit-list small{overflow-wrap:anywhere;color:var(--color-muted)}.visit-controls{display:flex;align-items:end;flex-wrap:wrap;gap:8px}.visit-controls label{display:grid;gap:4px;font-size:.75rem;font-weight:700}.visit-controls .visit-check{display:flex;align-items:center}.visit-controls select,.visit-controls input:not([type=checkbox]){min-height:36px;border:1px solid var(--color-border);border-radius:6px;background:var(--color-surface);padding:5px 8px}.visit-controls button{min-height:36px;padding:6px 12px;border:1px solid var(--color-border);border-radius:6px;background:var(--color-dartmouth-green);color:#fff;font-weight:700}.visit-controls button:disabled{opacity:.5}.queue-error{color:#a02d1d!important}.queue-success{color:#087654!important}@media(max-width:700px){.visit-list article{grid-template-columns:1fr}}
</style>
