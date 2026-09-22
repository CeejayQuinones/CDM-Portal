<script setup>
import { computed, nextTick, ref, watch } from 'vue'
import { askAiHelp } from '../services/monitoringApi'

const props = defineProps({
  studentId: { type: Number, default: null },
  studentName: { type: String, default: 'Student' },
  riskLabel: { type: String, default: '' },
  liveConfigured: { type: Boolean, default: false },
  provider: { type: String, default: 'cdm-coach' },
})

const emit = defineEmits(['plan-from-chat'])

const open = ref(false)
const messages = ref([])
const draft = ref('')
const sending = ref(false)
const error = ref('')
const lastSource = ref('')
const threadEl = ref(null)
const inputEl = ref(null)

const suggestions = [
  'Paano ko maiiwasan mag-fail this week?',
  'Ano dapat unahin kong aralin?',
  'Gawan mo ako ng 3-day study plan.',
  'Paano ako magprepare sa next quiz?',
]

const badge = computed(() => {
  if (lastSource.value === 'live-ai') return `Live AI · ${props.provider}`
  if (props.liveConfigured) return 'Live AI ready'
  return 'CDM AI Coach'
})

const canChat = computed(() => Boolean(props.studentId))

const historyPayload = computed(() =>
  messages.value
    .filter((msg) => msg.role === 'user' || msg.role === 'assistant')
    .map((msg) => ({ role: msg.role, content: msg.content })),
)

const scrollToBottom = async () => {
  await nextTick()
  if (threadEl.value) threadEl.value.scrollTop = threadEl.value.scrollHeight
}

const seedWelcome = () => {
  messages.value = [{
    id: `sys-${Date.now()}`,
    role: 'assistant',
    content: props.studentId
      ? `Hi! I'm your CDM AI Help coach for ${props.studentName}${props.riskLabel ? ` (${props.riskLabel})` : ''}. Ask me about grades, recovery steps, or what to study first.`
      : 'Select a student first, then I can coach based on their grade risk.',
    pending: false,
  }]
}

const resetChat = () => {
  seedWelcome()
  draft.value = ''
  error.value = ''
  lastSource.value = ''
  scrollToBottom()
}

const openChat = async () => {
  open.value = true
  if (!messages.value.length) seedWelcome()
  await nextTick()
  inputEl.value?.focus()
  scrollToBottom()
}

const closeChat = () => {
  open.value = false
}

const toggleChat = () => {
  if (open.value) closeChat()
  else openChat()
}

const sendMessage = async (text) => {
  const question = (text ?? draft.value).trim()
  if (!question || sending.value || !props.studentId) return

  error.value = ''
  draft.value = ''
  messages.value.push({
    id: `u-${Date.now()}`,
    role: 'user',
    content: question,
    pending: false,
  })

  const pendingId = `a-${Date.now()}`
  messages.value.push({
    id: pendingId,
    role: 'assistant',
    content: 'Thinking…',
    pending: true,
  })
  sending.value = true
  await scrollToBottom()

  try {
    const prior = historyPayload.value.slice(0, -1)
    const reply = await askAiHelp(props.studentId, question, prior)
    lastSource.value = reply.source || ''
    const content = reply.reply || reply.advice || reply.summary || 'Walang sagot mula sa AI Help.'
    const idx = messages.value.findIndex((msg) => msg.id === pendingId)
    if (idx >= 0) {
      messages.value[idx] = {
        id: pendingId,
        role: 'assistant',
        content,
        pending: false,
        actions: reply.actions || [],
      }
    }
    if (reply.actions?.length) {
      emit('plan-from-chat', {
        summary: reply.summary,
        actions: reply.actions,
        prevention_note: reply.prevention_note,
      })
    }
  } catch (err) {
    error.value = err.response?.data?.message || 'Unable to get AI Help right now.'
    messages.value = messages.value.filter((msg) => msg.id !== pendingId)
  } finally {
    sending.value = false
    await scrollToBottom()
  }
}

const onKeydown = (event) => {
  if (event.key === 'Enter' && !event.shiftKey) {
    event.preventDefault()
    sendMessage()
  }
}

watch(
  () => [props.studentId, props.studentName, props.riskLabel],
  () => {
    if (!sending.value) resetChat()
  },
)
</script>

<template>
  <Teleport to="body">
    <button
      type="button"
      class="chat-fab"
      :class="{ open }"
      :aria-expanded="open"
      aria-controls="ai-help-dock"
      @click="toggleChat"
    >
      <span class="fab-icon" aria-hidden="true">{{ open ? '×' : '💬' }}</span>
      <span class="fab-label">{{ open ? 'Close' : 'AI Help' }}</span>
    </button>

    <div
      v-if="open"
      class="chat-backdrop"
      aria-hidden="true"
      @click="closeChat"
    />

    <aside
      id="ai-help-dock"
      class="chat-dock"
      :class="{ open }"
      role="dialog"
      aria-label="AI Help chatbot"
      :aria-hidden="!open"
    >
      <header class="dock-head">
        <div>
          <p class="eyebrow">CDM Portal</p>
          <h3>AI Help</h3>
          <span class="ai-badge" :class="{ live: lastSource === 'live-ai' || liveConfigured }">{{ badge }}</span>
        </div>
        <div class="dock-actions">
          <button type="button" class="ghost-btn" :disabled="sending" @click="resetChat">New</button>
          <button type="button" class="ghost-btn" @click="closeChat" aria-label="Close chat">Close</button>
        </div>
      </header>

      <p class="student-chip">
        Coaching:
        <strong>{{ studentName || 'No student selected' }}</strong>
        <em v-if="riskLabel">{{ riskLabel }}</em>
      </p>

      <div ref="threadEl" class="thread" aria-live="polite">
        <article
          v-for="msg in messages"
          :key="msg.id"
          class="bubble"
          :class="[msg.role, { pending: msg.pending }]"
        >
          <span class="who">{{ msg.role === 'user' ? 'You' : 'AI Help' }}</span>
          <p>{{ msg.content }}</p>
          <ul v-if="msg.actions?.length" class="mini-actions">
            <li v-for="(step, index) in msg.actions" :key="index">{{ step }}</li>
          </ul>
        </article>
      </div>

      <div v-if="canChat" class="suggestions">
        <button
          v-for="tip in suggestions"
          :key="tip"
          type="button"
          class="chip"
          :disabled="sending"
          @click="sendMessage(tip)"
        >
          {{ tip }}
        </button>
      </div>

      <p v-if="error" class="chat-error">{{ error }}</p>
      <p v-if="!canChat" class="chat-hint">Open a student in Early Warnings to start chatting.</p>

      <form class="composer" @submit.prevent="sendMessage()">
        <textarea
          ref="inputEl"
          v-model="draft"
          rows="2"
          :disabled="sending || !canChat"
          placeholder="Type a message…"
          @keydown="onKeydown"
        />
        <button type="submit" class="send-btn" :disabled="sending || !canChat || !draft.trim()">
          {{ sending ? '…' : 'Send' }}
        </button>
      </form>
    </aside>
  </Teleport>
</template>

<style scoped>
.chat-fab {
  position: fixed;
  right: 20px;
  bottom: 24px;
  z-index: 1200;
  display: inline-flex;
  align-items: center;
  gap: 8px;
  border: 0;
  border-radius: 999px;
  background: var(--color-dartmouth-green, #106a2e);
  color: #fff;
  font-weight: 800;
  min-height: 52px;
  padding: 0 18px 0 14px;
  box-shadow: 0 12px 28px rgba(16, 106, 46, 0.35);
  cursor: pointer;
}

.chat-fab.open {
  background: #1f1f1f;
}

.fab-icon {
  display: grid;
  place-items: center;
  width: 28px;
  height: 28px;
  border-radius: 50%;
  background: rgba(255, 255, 255, 0.16);
  font-size: 1rem;
  line-height: 1;
}

.chat-backdrop {
  position: fixed;
  inset: 0;
  z-index: 1190;
  background: rgba(15, 23, 18, 0.28);
}

.chat-dock {
  position: fixed;
  top: 0;
  right: 0;
  z-index: 1210;
  display: flex;
  flex-direction: column;
  gap: 10px;
  width: min(400px, 100vw);
  height: 100vh;
  height: 100dvh;
  padding: 16px;
  background: #fff;
  border-left: 1px solid var(--color-border, #e8e8e8);
  box-shadow: -18px 0 40px rgba(0, 0, 0, 0.14);
  transform: translateX(105%);
  transition: transform 0.22s ease;
}

.chat-dock.open {
  transform: translateX(0);
}

.dock-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 10px;
}

.eyebrow {
  margin: 0 0 2px;
  color: var(--color-dartmouth-green, #106a2e);
  font-size: 0.72rem;
  font-weight: 800;
  letter-spacing: 0.08em;
  text-transform: uppercase;
}

.dock-head h3 {
  margin: 0;
  font-family: var(--font-display, Georgia, serif);
  font-size: 1.35rem;
}

.dock-actions {
  display: flex;
  gap: 6px;
}

.ai-badge {
  display: inline-flex;
  margin-top: 6px;
  border-radius: 999px;
  background: #e8eef0;
  color: var(--color-muted, #5e665f);
  font-size: 0.7rem;
  font-weight: 800;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  padding: 4px 10px;
}

.ai-badge.live {
  background: #dcf5e5;
  color: #176434;
}

.ghost-btn {
  border: 1px solid var(--color-border, #e8e8e8);
  border-radius: 8px;
  background: #fff;
  color: var(--color-dark-spring-green, #0d7856);
  font-weight: 700;
  min-height: 34px;
  padding: 0 10px;
  cursor: pointer;
}

.student-chip {
  margin: 0;
  padding: 10px 12px;
  border-radius: 12px;
  background: rgba(16, 106, 46, 0.07);
  color: var(--color-muted, #5e665f);
  font-size: 0.88rem;
  line-height: 1.4;
}

.student-chip strong {
  color: var(--color-eerie-black, #1f1f1f);
}

.student-chip em {
  margin-left: 6px;
  font-style: normal;
  font-weight: 800;
  color: var(--color-dartmouth-green, #106a2e);
}

.thread {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 10px;
  min-height: 0;
  overflow-y: auto;
  padding: 10px;
  border-radius: 14px;
  background: #f4f7f5;
  border: 1px solid var(--color-border, #e8e8e8);
}

.bubble {
  max-width: 92%;
  padding: 10px 12px;
  border-radius: 14px;
  line-height: 1.5;
  word-break: break-word;
}

.bubble.user {
  align-self: flex-end;
  background: var(--color-dartmouth-green, #106a2e);
  color: #fff;
  border-bottom-right-radius: 4px;
}

.bubble.assistant {
  align-self: flex-start;
  background: #fff;
  border: 1px solid var(--color-border, #e8e8e8);
  border-bottom-left-radius: 4px;
}

.bubble.pending {
  opacity: 0.75;
  font-style: italic;
}

.who {
  display: block;
  margin-bottom: 4px;
  font-size: 0.68rem;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  opacity: 0.75;
}

.bubble p {
  margin: 0;
  white-space: pre-wrap;
}

.mini-actions {
  margin: 8px 0 0;
  padding-left: 18px;
  color: var(--color-muted, #5e665f);
}

.suggestions {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  max-height: 88px;
  overflow-y: auto;
}

.chip {
  border: 1px solid rgba(16, 106, 46, 0.22);
  border-radius: 999px;
  background: #fff;
  color: var(--color-dark-spring-green, #0d7856);
  font-size: 0.74rem;
  font-weight: 700;
  padding: 6px 10px;
  cursor: pointer;
  text-align: left;
}

.chip:disabled,
.send-btn:disabled,
.ghost-btn:disabled {
  opacity: 0.55;
  cursor: not-allowed;
}

.chat-error {
  margin: 0;
  color: #b42318;
  font-size: 0.86rem;
}

.chat-hint {
  margin: 0;
  color: var(--color-muted, #5e665f);
  font-size: 0.86rem;
}

.composer {
  display: grid;
  grid-template-columns: 1fr auto;
  gap: 8px;
  align-items: end;
}

.composer textarea {
  width: 100%;
  resize: none;
  min-height: 56px;
  margin: 0;
  border: 1px solid var(--color-border, #e8e8e8);
  border-radius: 12px;
  padding: 10px 12px;
  font: inherit;
}

.send-btn {
  border: 0;
  border-radius: 12px;
  background: var(--color-dartmouth-green, #106a2e);
  color: #fff;
  font-weight: 800;
  min-height: 48px;
  min-width: 68px;
  padding: 0 14px;
  cursor: pointer;
}

@media (max-width: 640px) {
  .chat-fab {
    right: 14px;
    bottom: 16px;
  }

  .chat-dock {
    width: 100vw;
  }

  .fab-label {
    display: none;
  }

  .chat-fab {
    width: 54px;
    height: 54px;
    padding: 0;
    justify-content: center;
  }
}
</style>
