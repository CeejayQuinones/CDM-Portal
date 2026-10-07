<script setup>
import { computed, onMounted, ref } from 'vue'
import { eventErrorMessage, eventService } from './eventService'

const props = defineProps({
  event: { type: Object, required: true },
  canManage: { type: Boolean, default: false },
})
const assignments = ref([])
const candidates = ref([])
const responsibilities = ref([])
const search = ref('')
const selectedUserId = ref('')
const responsibility = ref('event_staff')
const startsAt = ref('')
const endsAt = ref('')
const loading = ref(true)
const busy = ref(false)
const error = ref('')
const notice = ref('')

const selectedCandidate = computed(() => candidates.value.find((candidate) => candidate.id === Number(selectedUserId.value)))
const eligibleResponsibilities = computed(() => responsibilities.value.filter((item) => item.eligible_roles.includes(selectedCandidate.value?.portal_role)))
const activeAssignments = computed(() => assignments.value.filter((item) => item.status === 'active'))
const historicalAssignments = computed(() => assignments.value.filter((item) => item.status !== 'active'))
const localValue = (value) => value ? new Date(new Date(value).getTime() - new Date(value).getTimezoneOffset() * 60000).toISOString().slice(0, 16) : null
const formatDate = (value) => value ? new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value)) : '—'

const load = async (includeSearch = false) => {
  loading.value = true
  error.value = ''
  try {
    const data = await eventService.personnel(props.event.id, includeSearch ? { search: search.value.trim() } : {})
    assignments.value = data.assignments || []
    candidates.value = data.candidates || []
    responsibilities.value = data.responsibilities || []
  } catch (requestError) {
    error.value = eventErrorMessage(requestError)
  } finally {
    loading.value = false
  }
}

const searchUsers = () => load(true)
const chooseCandidate = (candidate) => {
  selectedUserId.value = candidate.id
  responsibility.value = ['Admin', 'Registrar Staff'].includes(candidate.portal_role)
    ? 'semi_coordinator'
    : candidate.portal_role === 'Student' ? 'class_mayor' : 'moderator'
}

const assign = async () => {
  if (busy.value || !selectedUserId.value) return
  busy.value = true
  error.value = ''
  notice.value = ''
  try {
    await eventService.assignPersonnel(props.event.id, {
      user_id: Number(selectedUserId.value),
      responsibility: responsibility.value,
      starts_at: startsAt.value || null,
      ends_at: endsAt.value || null,
    })
    notice.value = 'Event personnel assigned.'
    selectedUserId.value = ''
    search.value = ''
    startsAt.value = ''
    endsAt.value = ''
    await load()
  } catch (requestError) {
    error.value = eventErrorMessage(requestError)
  } finally {
    busy.value = false
  }
}

const changeResponsibility = async (assignment) => {
  if (busy.value) return
  busy.value = true
  error.value = ''
  notice.value = ''
  try {
    await eventService.updatePersonnel(props.event.id, assignment.id, {
      responsibility: assignment.responsibility,
      starts_at: localValue(assignment.starts_at),
      ends_at: localValue(assignment.ends_at),
    })
    notice.value = 'Event responsibility updated.'
    await load()
  } catch (requestError) {
    error.value = eventErrorMessage(requestError)
  } finally {
    busy.value = false
  }
}

const revoke = async (assignment) => {
  if (busy.value) return
  busy.value = true
  error.value = ''
  notice.value = ''
  try {
    await eventService.revokePersonnel(props.event.id, assignment.id)
    notice.value = 'Event responsibility revoked.'
    await load()
  } catch (requestError) {
    error.value = eventErrorMessage(requestError)
  } finally {
    busy.value = false
  }
}

onMounted(load)
</script>

<template>
  <section class="personnel-panel" aria-labelledby="personnel-title">
    <header>
      <div><p class="event-eyebrow">Event-scoped access</p><h3 id="personnel-title">Authorized Personnel</h3></div>
      <span>{{ activeAssignments.length }} active</span>
    </header>
    <p>Assignments grant capabilities for this Event only. Portal roles and platform restrictions remain unchanged.</p>
    <p v-if="notice" class="event-alert success" role="status">{{ notice }}</p>
    <p v-if="error" class="event-alert error" role="alert">{{ error }}</p>

    <form v-if="canManage" class="personnel-search" @submit.prevent="searchUsers">
      <label><span>Find eligible portal user</span><input v-model.trim="search" type="search" minlength="2" maxlength="100" placeholder="Search by name or username" /></label>
      <button class="event-button secondary" type="submit" :disabled="loading || search.length < 2">Search</button>
    </form>

    <div v-if="canManage && candidates.length" class="candidate-list" aria-label="Eligible portal users">
      <button v-for="candidate in candidates" :key="candidate.id" type="button" :class="{ selected: selectedUserId === candidate.id }" @click="chooseCandidate(candidate)">
        <span><strong>{{ candidate.name }}</strong><small>Portal Role: {{ candidate.portal_role }}</small></span><span>Select</span>
      </button>
    </div>

    <form v-if="canManage && selectedCandidate" class="assignment-form" @submit.prevent="assign">
      <div class="selected-person"><strong>{{ selectedCandidate.name }}</strong><span>Portal Role: {{ selectedCandidate.portal_role }}</span></div>
      <label><span>Event Responsibility</span><select v-model="responsibility" required><option v-for="item in eligibleResponsibilities" :key="item.value" :value="item.value">{{ item.label }}</option></select></label>
      <label><span>Starts (optional)</span><input v-model="startsAt" type="datetime-local" /></label>
      <label><span>Ends (optional)</span><input v-model="endsAt" type="datetime-local" /></label>
      <button class="event-button primary" type="submit" :disabled="busy">Assign Personnel</button>
    </form>

    <p v-if="loading" class="personnel-state" role="status">Loading Event personnel…</p>
    <div v-else-if="activeAssignments.length" class="personnel-table">
      <table>
        <thead><tr><th>Name</th><th>Portal Role</th><th>Event Responsibility</th><th>Assigned By</th><th>Assigned At</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody><tr v-for="assignment in activeAssignments" :key="assignment.id"><td><strong>{{ assignment.name }}</strong></td><td>{{ assignment.portal_role }}</td><td><select v-model="assignment.responsibility" :disabled="busy || !canManage" @change="changeResponsibility(assignment)"><option v-for="item in responsibilities.filter((role) => role.eligible_roles.includes(assignment.portal_role))" :key="item.value" :value="item.value">{{ item.label }}</option></select></td><td>{{ assignment.assigned_by }}</td><td>{{ formatDate(assignment.assigned_at) }}</td><td><span class="personnel-status active">Active</span></td><td><button v-if="canManage" type="button" :disabled="busy" @click="revoke(assignment)">Revoke</button><span v-else>View only</span></td></tr></tbody>
      </table>
    </div>
    <p v-else class="personnel-state">No active Event personnel assignments.</p>

    <details v-if="historicalAssignments.length" class="personnel-history">
      <summary>Revoked or inactive assignments ({{ historicalAssignments.length }})</summary>
      <ul><li v-for="assignment in historicalAssignments" :key="assignment.id"><span><strong>{{ assignment.name }}</strong> · {{ assignment.responsibility_label }}</span><span>{{ assignment.status }} · {{ formatDate(assignment.revoked_at || assignment.ends_at) }}</span></li></ul>
    </details>
  </section>
</template>

<style scoped>
.personnel-panel { display: grid; gap: 14px; border: 1px solid var(--border-color); border-radius: 14px; padding: 16px; background: var(--bg-surface-alt); color: var(--text-primary); }
.personnel-panel > header { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.personnel-panel h3, .personnel-panel p { margin: 0; }
.personnel-panel > p:not(.event-alert) { color: var(--text-secondary); }
.personnel-search { display: flex; align-items: end; gap: 9px; }
.personnel-search label, .assignment-form label { display: grid; flex: 1; gap: 5px; }
.personnel-search label span, .assignment-form label span { color: var(--text-secondary); font-size: .75rem; font-weight: 800; text-transform: uppercase; }
.personnel-search input, .assignment-form input, .assignment-form select, .personnel-table select { width: 100%; border: 1px solid var(--border-color); border-radius: 8px; padding: 9px 10px; background: var(--bg-surface); color: var(--text-primary); font: inherit; }
.candidate-list { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 8px; }
.candidate-list button { display: flex; align-items: center; justify-content: space-between; gap: 10px; border: 1px solid var(--border-color); border-radius: 9px; padding: 11px; background: var(--bg-surface); color: var(--text-primary); text-align: left; cursor: pointer; }
.candidate-list button.selected { border-color: var(--accent); box-shadow: inset 3px 0 var(--accent); }
.candidate-list button span:first-child { display: grid; gap: 3px; }
.candidate-list small { color: var(--text-secondary); }
.assignment-form { display: grid; grid-template-columns: minmax(180px, 1.2fr) repeat(3, minmax(150px, 1fr)) auto; align-items: end; gap: 9px; border: 1px solid var(--border-color); border-radius: 10px; padding: 12px; background: var(--bg-surface); }
.selected-person { display: grid; gap: 4px; }
.selected-person span { color: var(--text-secondary); font-size: .8rem; }
.personnel-table { overflow-x: auto; border: 1px solid var(--border-color); border-radius: 10px; }
.personnel-table table { width: 100%; min-width: 930px; border-collapse: collapse; }
.personnel-table th, .personnel-table td { border-bottom: 1px solid var(--border-color); padding: 10px; text-align: left; }
.personnel-table th { background: var(--bg-surface); color: var(--text-secondary); font-size: .72rem; text-transform: uppercase; }
.personnel-table button { border: 0; background: transparent; color: var(--danger); font-weight: 800; cursor: pointer; }
.personnel-status { border-radius: 999px; padding: 4px 8px; font-size: .72rem; font-weight: 800; text-transform: capitalize; }
.personnel-status.active { background: var(--success-bg); color: var(--success); }
.personnel-state { border: 1px dashed var(--border-color); border-radius: 10px; padding: 18px; background: var(--bg-surface); text-align: center; }
.personnel-history summary { color: var(--accent); font-weight: 800; cursor: pointer; }
.personnel-history ul { display: grid; gap: 7px; list-style: none; padding: 0; }
.personnel-history li { display: flex; justify-content: space-between; gap: 12px; border-bottom: 1px solid var(--border-color); padding: 8px 0; color: var(--text-secondary); }
@media (max-width: 900px) { .assignment-form { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 700px) { .personnel-search, .personnel-panel > header, .personnel-history li { align-items: stretch; flex-direction: column; } .assignment-form { grid-template-columns: 1fr; } .personnel-search button, .assignment-form button { width: 100%; } }
</style>
