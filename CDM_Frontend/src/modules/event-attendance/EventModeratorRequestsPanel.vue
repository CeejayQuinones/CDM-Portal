<script setup>
import { computed, onMounted, ref } from 'vue'
import { eventErrorMessage, eventService } from './eventService'
import { useEventModalScrollLock } from './useEventModalScrollLock'

const props = defineProps({ event: { type: Object, required: true }, canView: { type: Boolean, default: false }, canReview: { type: Boolean, default: false } })
const emit = defineEmits(['changed'])
const requests = ref([])
const loading = ref(false)
const busy = ref(false)
const error = ref('')
const notice = ref('')
const selectedRequest = ref(null)
useEventModalScrollLock(() => Boolean(selectedRequest.value))
const current = computed(() => props.event.my_moderator_request)
const mayRequest = computed(() => props.event.capabilities?.can_request_moderator && !['pending', 'approved'].includes(current.value?.status))
const formatDate = (value) => value ? new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value)) : '—'

async function load() {
  if (!props.canView) return
  loading.value = true; error.value = ''
  try { requests.value = await eventService.moderatorRequests(props.event.id) }
  catch (cause) { error.value = eventErrorMessage(cause) }
  finally { loading.value = false }
}
async function submitRequest() {
  if (busy.value) return
  busy.value = true; error.value = ''; notice.value = ''
  try { await eventService.requestModerator(props.event.id); notice.value = 'Moderator request submitted for Coordinator review.'; emit('changed') }
  catch (cause) { error.value = eventErrorMessage(cause) }
  finally { busy.value = false }
}
async function review(item, action) {
  if (busy.value) return
  const reason = ['reject', 'revoke'].includes(action) ? window.prompt(`Reason to ${action} this request:`) : null
  if (['reject', 'revoke'].includes(action) && (!reason || reason.trim().length < 5)) return
  busy.value = true; error.value = ''; notice.value = ''
  try { await eventService.reviewModeratorRequest(props.event.id, item.id, action, reason?.trim() || null); notice.value = `Moderator request ${action === 'approve' ? 'approved' : `${action}d`}.`; selectedRequest.value = null; await load(); emit('changed') }
  catch (cause) { error.value = eventErrorMessage(cause) }
  finally { busy.value = false }
}
onMounted(load)
</script>

<template>
  <section class="moderator-requests" aria-labelledby="moderator-requests-title">
    <header><div><p class="event-eyebrow">Event-scoped approval</p><h3 id="moderator-requests-title">Moderator Requests</h3></div><span v-if="canView">{{ requests.filter((item) => item.status === 'pending').length }} pending</span></header>
    <p v-if="notice" class="event-alert success" role="status">{{ notice }}</p>
    <p v-if="error" class="event-alert error" role="alert">{{ error }}</p>
    <div v-if="!canView" class="request-access-card">
      <template v-if="current"><span class="event-badge" :data-status="current.status">{{ current.status }}</span><p>Your moderator request was submitted {{ formatDate(current.requested_at) }}.</p><small v-if="current.review_reason">Coordinator note: {{ current.review_reason }}</small></template>
      <template v-else><p>Request event-scoped moderator access. No QR or attendance privilege is granted until a Coordinator approves it.</p></template>
      <button v-if="mayRequest" class="event-button secondary" type="button" :disabled="busy" @click="submitRequest">Request Moderator Access</button>
    </div>
    <p v-else-if="loading" class="personnel-state">Loading moderator requests…</p>
    <div v-else-if="requests.length" class="personnel-table"><table><thead><tr><th>Participant</th><th>Email</th><th>Portal role</th><th>Requested</th><th>Status</th><th>Actions</th></tr></thead><tbody><tr v-for="item in requests" :key="item.id"><td><strong>{{ item.name }}</strong></td><td>{{ item.email || '—' }}</td><td>{{ item.portal_role }}</td><td>{{ formatDate(item.requested_at) }}</td><td><span class="event-badge" :data-status="item.status">{{ item.status }}</span></td><td class="row-actions"><button type="button" @click="selectedRequest = item">View</button><template v-if="canReview && item.status === 'pending'"><button type="button" :disabled="busy" @click="review(item, 'approve')">Approve</button><button class="danger-link" type="button" :disabled="busy" @click="review(item, 'reject')">Reject</button></template><button v-else-if="canReview && item.status === 'approved'" class="danger-link" type="button" :disabled="busy" @click="review(item, 'revoke')">Revoke</button></td></tr></tbody></table></div>
    <p v-else-if="canView" class="personnel-state">No moderator requests for this Event.</p>

    <Teleport to="body">
      <div v-if="selectedRequest" class="event-modal-backdrop" @click.self="selectedRequest = null">
        <section class="event-modal moderator-request-modal" role="dialog" aria-modal="true" aria-labelledby="moderator-request-detail-title" tabindex="-1" @keydown.esc="selectedRequest = null">
          <header><div><p class="event-eyebrow">Moderator request</p><h2 id="moderator-request-detail-title">{{ selectedRequest.name }}</h2></div><button type="button" aria-label="Close moderator request" @click="selectedRequest = null">×</button></header>
          <div class="event-modal-body request-detail-grid">
            <dl><div><dt>Event</dt><dd>{{ event.title }}</dd></div><div><dt>Portal role</dt><dd>{{ selectedRequest.portal_role }}</dd></div><div><dt>Email</dt><dd>{{ selectedRequest.email || '—' }}</dd></div><div><dt>Submitted</dt><dd>{{ formatDate(selectedRequest.requested_at) }}</dd></div><div><dt>Status</dt><dd><span class="event-badge" :data-status="selectedRequest.status">{{ selectedRequest.status }}</span></dd></div><div><dt>Reason</dt><dd>{{ selectedRequest.request_reason || 'No reason provided.' }}</dd></div></dl>
            <footer v-if="canReview"><button v-if="selectedRequest.status === 'pending'" class="event-button primary" type="button" :disabled="busy" @click="review(selectedRequest, 'approve')">Approve</button><button v-if="selectedRequest.status === 'pending'" class="event-button danger" type="button" :disabled="busy" @click="review(selectedRequest, 'reject')">Reject</button><button v-if="selectedRequest.status === 'approved'" class="event-button danger" type="button" :disabled="busy" @click="review(selectedRequest, 'revoke')">Revoke</button></footer>
          </div>
        </section>
      </div>
    </Teleport>
  </section>
</template>
