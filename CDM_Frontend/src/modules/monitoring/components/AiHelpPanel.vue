<script setup>
import { computed, nextTick, ref } from 'vue'
import { askAiHelp } from '../services/monitoringApi'

const props = defineProps({
  studentId: { type: Number, default: null },
  assessment: { type: Object, default: null },
})

const question = ref('')
const sending = ref(false)
const error = ref('')
const messages = ref([])
const transcript = ref(null)
const quickPrompts = computed(() => props.assessment?.risk_level === 'insufficient'
  ? [
      'How can I organize my study time while official results are incomplete?',
      'Which official results are still unavailable?',
      'Help me prepare general questions for my Professor.',
    ]
  : [
      'Why was this Monitoring risk level assigned?',
      'Which published subject signals should I focus on first?',
      'Help me understand the Suggested Academic Support Plan.',
      'What should I ask my Professor?',
    ])
const canSend = computed(() => Boolean(props.studentId) && question.value.trim().length >= 2 && !sending.value)

const usePrompt = (prompt) => {
  question.value = prompt
}

const send = async () => {
  const prompt = question.value.trim()
  if (!props.studentId || prompt.length < 2 || sending.value) return

  sending.value = true
  error.value = ''
  try {
    const response = await askAiHelp(props.studentId, prompt)
    messages.value.push({ id: `${Date.now()}-question`, role: 'user', text: prompt })
    messages.value.push({
      id: `${Date.now()}-reply`,
      role: 'assistant',
      text: response.reply,
      label: response.label,
      disclaimer: response.disclaimer,
      source: response.source,
    })
    question.value = ''
    await nextTick()
    transcript.value?.lastElementChild?.scrollIntoView({ block: 'nearest', behavior: 'smooth' })
  } catch (requestError) {
    error.value = requestError.response?.data?.message || 'Academic guidance is temporarily unavailable. Please try again.'
  } finally {
    sending.value = false
  }
}
</script>

<template>
  <section class="ai-help-panel">
    <header>
      <div>
        <p class="ai-eyebrow">Advisory academic coach</p>
        <h2>AI Help</h2>
        <p>Ask for study strategies, time-management ideas, consultation preparation, or help understanding the published Monitoring signals.</p>
      </div>
      <span class="advisory-badge">AI-generated academic guidance</span>
    </header>

    <aside class="scope-note">
      <strong>Published evidence only.</strong>
      AI Help cannot change grades, calculate GWA, decide pass or fail, apply policy, or predict an outcome. Messages stay in this page session; only safe request metadata is audited.
    </aside>

    <div class="quick-prompts" aria-label="Suggested questions">
      <button v-for="prompt in quickPrompts" :key="prompt" type="button" :disabled="!studentId || sending" @click="usePrompt(prompt)">
        {{ prompt }}
      </button>
    </div>

    <p v-if="!studentId" class="panel-state">Select a Student with a Monitoring record to use AI Help.</p>

    <div v-if="messages.length" ref="transcript" class="conversation" aria-live="polite">
      <article v-for="message in messages" :key="message.id" :class="['message', message.role]">
        <small>{{ message.role === 'user' ? 'Question' : message.label }}</small>
        <p>{{ message.text }}</p>
        <footer v-if="message.role === 'assistant'">
          <span>{{ message.source === 'fallback' ? 'Deterministic guidance' : 'Configured AI provider' }}</span>
          <span>{{ message.disclaimer }}</span>
        </footer>
      </article>
    </div>

    <form class="composer" @submit.prevent="send">
      <label for="monitoring-ai-question">Ask about academic support</label>
      <textarea
        id="monitoring-ai-question"
        v-model="question"
        rows="4"
        maxlength="1500"
        :disabled="!studentId || sending"
        placeholder="Write in English, Tagalog, or Taglish…"
      />
      <div>
        <small>{{ question.length }} / 1500</small>
        <button type="submit" :disabled="!canSend">{{ sending ? 'Preparing guidance…' : 'Ask AI Help' }}</button>
      </div>
    </form>
    <p v-if="error" class="panel-error" role="alert">{{ error }}</p>
  </section>
</template>

<style scoped>
.ai-help-panel {
  display: grid;
  gap: 18px;
  border: 1px solid var(--border-default);
  border-radius: 14px;
  padding: clamp(18px, 2.4vw, 28px);
  background: var(--bg-surface);
  color: var(--text-primary);
  box-shadow: var(--shadow-soft);
}

header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 18px;
}

h2 { margin: 4px 0 6px; }
header p { max-width: 760px; margin: 0; color: var(--text-secondary); }
.ai-eyebrow { color: var(--accent); font-size: .78rem; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; }
.advisory-badge { flex: 0 0 auto; border-radius: 999px; padding: 7px 11px; background: var(--accent-soft); color: var(--accent); font-size: .78rem; font-weight: 800; }
.scope-note { border-left: 4px solid var(--accent); border-radius: 8px; padding: 13px 15px; background: var(--bg-surface-alt); color: var(--text-secondary); }
.scope-note strong { color: var(--text-primary); }
.quick-prompts { display: flex; flex-wrap: wrap; gap: 8px; }
.quick-prompts button { border: 1px solid var(--border-color); border-radius: 999px; padding: 8px 12px; background: var(--bg-surface-alt); color: var(--text-primary); font: inherit; cursor: pointer; }
.quick-prompts button:hover { border-color: var(--accent); color: var(--accent); }
.quick-prompts button:disabled { cursor: not-allowed; opacity: .55; }
.conversation { display: grid; gap: 12px; max-height: 460px; overflow-y: auto; padding: 4px; }
.message { width: min(760px, 88%); border: 1px solid var(--border-color); border-radius: 12px; padding: 13px 15px; background: var(--bg-surface-alt); white-space: pre-wrap; overflow-wrap: anywhere; }
.message.user { justify-self: end; border-color: var(--accent); background: var(--accent-soft); }
.message small { color: var(--text-muted); font-weight: 800; }
.message p { margin: 6px 0 0; line-height: 1.55; }
.message footer { display: grid; gap: 3px; margin-top: 10px; color: var(--text-muted); font-size: .75rem; }
.composer { display: grid; gap: 8px; }
.composer label { color: var(--text-secondary); font-size: .84rem; font-weight: 800; }
.composer textarea { width: 100%; resize: vertical; border: 1px solid var(--border-color); border-radius: 10px; padding: 12px; background: var(--bg-input); color: var(--text-primary); font: inherit; }
.composer textarea:focus { border-color: var(--accent); outline: 3px solid var(--accent-soft); }
.composer > div { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.composer small { color: var(--text-muted); }
.composer button { min-height: 42px; border: 1px solid var(--accent); border-radius: 8px; padding: 8px 16px; background: var(--accent); color: var(--text-on-accent); font: inherit; font-weight: 800; cursor: pointer; }
.composer button:disabled { cursor: not-allowed; opacity: .6; }
.panel-state, .panel-error { margin: 0; border-radius: 8px; padding: 12px 14px; background: var(--bg-surface-alt); color: var(--text-secondary); }
.panel-error { background: var(--danger-bg); color: var(--danger); }

@media (max-width: 680px) {
  .ai-help-panel { padding: 16px; }
  header { display: grid; }
  .advisory-badge { width: max-content; max-width: 100%; }
  .quick-prompts { display: grid; }
  .quick-prompts button { border-radius: 9px; text-align: left; }
  .message { width: 96%; }
  .composer > div { align-items: stretch; flex-direction: column; }
  .composer button { width: 100%; }
}
</style>
