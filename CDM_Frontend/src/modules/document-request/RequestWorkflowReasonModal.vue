<script setup>
import { computed, ref, watch } from 'vue'
import { requestReference, studentName } from './documentRequestPresentation'

const props = defineProps({
  open: { type: Boolean, required: true },
  title: { type: String, required: true },
  description: { type: String, required: true },
  confirmLabel: { type: String, required: true },
  actions: { type: Array, default: () => [] },
  reasonOptions: { type: Array, default: () => [] },
  request: { type: Object, default: null },
  reasonLabel: { type: String, default: 'Reason' },
  busy: { type: Boolean, default: false },
})

const emit = defineEmits(['close', 'confirm'])
const selectedAction = ref('')
const selectedReason = ref('')
const details = ref('')
const requestName = computed(() => studentName(props.request?.student))
const requestDocument = computed(() => props.request?.document_type?.document_name || '')
const requestReferenceLabel = computed(() =>
  props.request ? props.request.request_reference || requestReference(props.request.id) : '',
)

const detailsRequired = computed(() => !props.reasonOptions.length || selectedReason.value === 'Other')
const valid = computed(() => {
  const actionValid = !props.actions.length || selectedAction.value
  const reasonValid = props.reasonOptions.length ? selectedReason.value : details.value.trim()
  const detailsValid = !detailsRequired.value || details.value.trim()

  return Boolean(actionValid && reasonValid && detailsValid)
})

watch(
  () => props.open,
  (open) => {
    if (!open) return
    selectedAction.value = props.actions[0]?.value || ''
    selectedReason.value = ''
    details.value = ''
  },
)

function close() {
  if (!props.busy) emit('close')
}

function submit() {
  if (!valid.value || props.busy) return

  const detailText = details.value.trim()
  const reason = props.reasonOptions.length
    ? `${selectedReason.value}${detailText ? `: ${detailText}` : ''}`
    : detailText

  emit('confirm', {
    action: selectedAction.value || props.actions[0]?.value,
    reason,
  })
}
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="workflow-reason-backdrop" @click.self="close" @keydown.esc="close">
      <section class="workflow-reason-dialog" role="dialog" aria-modal="true" aria-labelledby="workflow-reason-title">
        <header>
          <div>
            <p class="record-eyebrow">Workflow correction</p>
            <h2 id="workflow-reason-title">{{ title }}</h2>
          </div>
          <button type="button" aria-label="Close" :disabled="busy" @click="close">&times;</button>
        </header>
        <form @submit.prevent="submit">
          <p>{{ description }}</p>
          <div v-if="request" class="workflow-request-target">
            <small>Request</small>
            <strong>{{ requestReferenceLabel }}</strong>
            <span>{{ requestName }}</span>
            <span>{{ requestDocument }}</span>
          </div>
          <label v-if="actions.length">
            Outcome
            <select v-model="selectedAction" required>
              <option v-for="option in actions" :key="option.value" :value="option.value">
                {{ option.label }}
              </option>
            </select>
          </label>
          <label v-if="reasonOptions.length">
            Reason
            <select v-model="selectedReason" required>
              <option value="" disabled>Select a reason</option>
              <option v-for="option in reasonOptions" :key="option" :value="option">{{ option }}</option>
            </select>
          </label>
          <label>
            {{ reasonOptions.length ? 'Additional details' : reasonLabel }}
            <textarea
              v-model="details"
              rows="4"
              maxlength="2000"
              :required="detailsRequired"
              :placeholder="detailsRequired ? 'A reason is required.' : 'Optional supporting details'"
            ></textarea>
          </label>
          <footer>
            <button type="button" class="secondary" :disabled="busy" @click="close">Cancel</button>
            <button type="submit" :disabled="!valid || busy">
              {{ busy ? 'Saving…' : confirmLabel }}
            </button>
          </footer>
        </form>
      </section>
    </div>
  </Teleport>
</template>

<style scoped>
.workflow-reason-backdrop {
  align-items: center;
  background: rgba(12, 23, 16, 0.68);
  display: flex;
  inset: 0;
  justify-content: center;
  padding: 20px;
  position: fixed;
  z-index: 1200;
}
.workflow-reason-dialog {
  background: #fff;
  border-radius: 16px;
  box-shadow: 0 24px 70px rgba(5, 24, 12, 0.3);
  max-height: calc(100vh - 40px);
  overflow-y: auto;
  padding: 22px;
  width: min(500px, 100%);
}
header {
  align-items: flex-start;
  display: flex;
  gap: 16px;
  justify-content: space-between;
}
.record-eyebrow {
  color: var(--color-dark-spring-green);
  font-size: 0.68rem;
  font-weight: 800;
  letter-spacing: 0.1em;
  margin: 0;
  text-transform: uppercase;
}
h2 {
  margin: 3px 0 0;
}
header > button {
  align-items: center;
  background: #eef2ef;
  border: 0;
  border-radius: 50%;
  color: #405047;
  display: inline-flex;
  font-size: 1.25rem;
  height: 34px;
  justify-content: center;
  padding: 0;
  width: 34px;
}
form {
  display: grid;
  gap: 14px;
  margin-top: 14px;
}
form > p {
  color: var(--color-muted);
  margin: 0;
}
.workflow-request-target {
  background: #f5faf6;
  border: 1px solid var(--color-border);
  border-radius: 10px;
  display: grid;
  gap: 2px;
  padding: 12px 14px;
}
.workflow-request-target small {
  color: var(--color-muted);
  font-size: 0.68rem;
  font-weight: 800;
  letter-spacing: 0.08em;
  text-transform: uppercase;
}
.workflow-request-target strong {
  color: var(--color-dartmouth-green);
}
.workflow-request-target span {
  color: #405047;
  font-size: 0.82rem;
}
label {
  display: grid;
  font-size: 0.8rem;
  font-weight: 800;
  gap: 6px;
}
select,
textarea {
  border: 1px solid var(--color-border);
  border-radius: 8px;
  font: inherit;
  padding: 10px;
  resize: vertical;
  width: 100%;
}
footer {
  display: flex;
  gap: 8px;
  justify-content: flex-end;
  margin-top: 4px;
}
footer button {
  border: 0;
  border-radius: 8px;
  cursor: pointer;
  min-height: 40px;
  padding: 8px 13px;
}
footer button:not(.secondary) {
  background: var(--color-dartmouth-green);
  color: #fff;
}
footer .secondary {
  background: #eef2ef;
  color: #405047;
}
button:disabled {
  cursor: not-allowed;
  opacity: 0.55;
}
</style>
