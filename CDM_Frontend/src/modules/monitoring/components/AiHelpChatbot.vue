<script setup>
import { ref } from 'vue'
import { askAiHelp } from '../services/monitoringApi'

const props = defineProps({ studentId: { type: Number, default: null } })
const question = ref('')
const reply = ref('')
const error = ref('')
const sending = ref(false)

const ask = async () => {
  if (!props.studentId || !question.value.trim() || sending.value) return
  sending.value = true; error.value = ''; reply.value = ''
  try { reply.value = (await askAiHelp(props.studentId, question.value.trim())).reply }
  catch (err) { error.value = err.response?.data?.message || 'AI help is temporarily unavailable. Please try again.' }
  finally { sending.value = false }
}
</script>

<template>
  <section class="ai-help" aria-label="AI academic help">
    <h3>AI Help</h3>
    <p>Ask about the selected academic record.</p>
    <form @submit.prevent="ask">
      <textarea v-model="question" maxlength="1500" :disabled="!studentId || sending" placeholder="What should I study first?" />
      <button type="submit" :disabled="!studentId || sending || !question.trim()">{{ sending ? 'Thinking...' : 'Ask AI Help' }}</button>
    </form>
    <p v-if="error" class="error" role="alert">{{ error }}</p>
    <p v-if="reply" class="reply">{{ reply }}</p>
  </section>
</template>

<style scoped>
.ai-help { border: 1px solid #d7dfd6; border-radius: 8px; padding: 16px; background: #fff; }
h3, p { margin: 0 0 8px; } p { color: #52605a; } form { display: grid; gap: 8px; } textarea { min-height: 72px; padding: 10px; resize: vertical; } button { justify-self: start; background: #106a2e; color: #fff; border: 0; border-radius: 5px; padding: 9px 13px; font-weight: 700; } button:disabled { opacity: .55; } .error { color: #a8291f; } .reply { white-space: pre-wrap; color: #1d2c23; }
</style>
