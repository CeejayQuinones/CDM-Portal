<script setup>
import { computed, ref, watch } from 'vue'
import { scheduleTimeFromParts, scheduleTimeParts, spinScheduleHour, spinScheduleMinute } from './scheduleTime'

const props = defineProps({ modelValue: { type: String, default: '' }, label: { type: String, required: true }, inputId: { type: String, required: true }, disabled: Boolean })
const emit = defineEmits(['update:modelValue'])
const initial = scheduleTimeParts(props.modelValue)
const hour = ref(initial.hour), minute = ref(initial.minute), period = ref(initial.period)
let lastEmitted

const value = computed(() => scheduleTimeFromParts(hour.value, minute.value, period.value))
const invalid = computed(() => !value.value)
function commit() {
  lastEmitted = value.value
  emit('update:modelValue', value.value)
}
function adjustHour(direction) { hour.value = spinScheduleHour(hour.value, direction); commit() }
function adjustMinute(direction) { minute.value = spinScheduleMinute(minute.value, direction); commit() }
function togglePeriod() { period.value = period.value === 'AM' ? 'PM' : 'AM'; commit() }
function handleKey(event, adjust) {
  if (!['ArrowUp', 'ArrowDown'].includes(event.key)) return
  event.preventDefault()
  adjust(event.key === 'ArrowUp' ? 1 : -1)
}
function handleWheel(event, adjust) {
  if (document.activeElement !== event.currentTarget) return
  event.preventDefault()
  adjust(event.deltaY < 0 ? 1 : -1)
}
function normalizeDrafts() {
  const parsedHour = Number(hour.value), parsedMinute = Number(minute.value)
  if (Number.isInteger(parsedHour) && parsedHour >= 1 && parsedHour <= 12) hour.value = String(parsedHour)
  if (Number.isInteger(parsedMinute) && parsedMinute >= 0 && parsedMinute <= 59) minute.value = String(parsedMinute).padStart(2, '0')
  commit()
}
watch(() => props.modelValue, next => {
  if (next === lastEmitted) { lastEmitted = undefined; return }
  const parts = scheduleTimeParts(next)
  hour.value = parts.hour; minute.value = parts.minute; period.value = parts.period
})
</script>

<template>
  <div class="en-time-spinner" role="group" :aria-label="label" :aria-invalid="invalid">
    <span class="en-time-spinner-label">{{ label }}</span>
    <div class="en-time-spinner-control">
      <div class="en-time-spinner-part">
        <button type="button" :disabled="disabled" :aria-label="`Increase ${label} hour`" @click="adjustHour(1)">⌃</button>
        <input :id="`${inputId}-hour`" v-model="hour" :disabled="disabled" inputmode="numeric" maxlength="2" placeholder="hh" :aria-label="`${label} hour`" @input="commit" @blur="normalizeDrafts" @keydown="handleKey($event, adjustHour)" @wheel="handleWheel($event, adjustHour)">
        <button type="button" :disabled="disabled" :aria-label="`Decrease ${label} hour`" @click="adjustHour(-1)">⌄</button>
      </div>
      <span class="en-time-colon" aria-hidden="true">:</span>
      <div class="en-time-spinner-part">
        <button type="button" :disabled="disabled" :aria-label="`Increase ${label} minute`" @click="adjustMinute(1)">⌃</button>
        <input :id="`${inputId}-minute`" v-model="minute" :disabled="disabled" inputmode="numeric" maxlength="2" placeholder="mm" :aria-label="`${label} minute`" @input="commit" @blur="normalizeDrafts" @keydown="handleKey($event, adjustMinute)" @wheel="handleWheel($event, adjustMinute)">
        <button type="button" :disabled="disabled" :aria-label="`Decrease ${label} minute`" @click="adjustMinute(-1)">⌄</button>
      </div>
      <div class="en-time-spinner-part en-time-period">
        <button type="button" :disabled="disabled" :aria-label="`Switch ${label} period`" @click="togglePeriod">⌃</button>
        <button type="button" class="en-time-period-value" :disabled="disabled" :aria-label="`${label} ${period}. Toggle AM or PM`" @click="togglePeriod" @keydown="handleKey($event, togglePeriod)">{{ period }}</button>
        <button type="button" :disabled="disabled" :aria-label="`Switch ${label} period`" @click="togglePeriod">⌄</button>
      </div>
    </div>
  </div>
</template>
