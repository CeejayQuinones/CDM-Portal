<script setup>
import { ADMISSION_EXAM_RULES } from '../../../config/admissionExamRules'

defineProps({
  acknowledged: { type: Boolean, default: false },
  disabled: { type: Boolean, default: false },
})
defineEmits(['update:acknowledged'])
</script>

<template>
  <section class="exam-rules-panel" aria-labelledby="exam-rules-title">
    <header>
      <span aria-hidden="true">!</span>
      <div><p class="page-kicker">Required before starting</p><h2 id="exam-rules-title">Strict examination rules</h2></div>
    </header>
    <ol>
      <li v-for="(rule, index) in ADMISSION_EXAM_RULES" :key="index"><span>{{ index + 1 }}</span><p>{{ rule }}</p></li>
    </ol>
    <label class="exam-rules-acknowledgment">
      <input
        type="checkbox"
        :checked="acknowledged"
        :disabled="disabled"
        @change="$emit('update:acknowledged', $event.target.checked)"
      />
      <span>I have read and agree to follow all six examination rules.</span>
    </label>
    <p class="exam-monitoring-limit">The portal can observe browser visibility, focus, route, reload, and connection events. It cannot reliably detect every other application, website, device, conversation, or AI tool.</p>
  </section>
</template>

