<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'

let nextSelectId = 0

const props = defineProps({
  modelValue: {
    type: [String, Number, Boolean],
    default: '',
  },
  options: {
    type: Array,
    default: () => [],
  },
  ariaLabel: {
    type: String,
    required: true,
  },
  placeholder: {
    type: String,
    default: 'Select an option',
  },
  disabled: {
    type: Boolean,
    default: false,
  },
  error: {
    type: [Boolean, String],
    default: false,
  },
  required: {
    type: Boolean,
    default: false,
  },
})

const emit = defineEmits(['update:modelValue', 'change', 'blur'])
const root = ref(null)
const trigger = ref(null)
const isOpen = ref(false)
const activeIndex = ref(-1)
let outsideListenerAttached = false
const selectId = `portal-select-${++nextSelectId}`
const listboxId = `${selectId}-listbox`

const normalizedOptions = computed(() => props.options.map((option) => {
  if (option && typeof option === 'object') {
    return {
      value: option.value,
      label: String(option.label ?? option.value ?? ''),
      disabled: Boolean(option.disabled),
    }
  }

  return { value: option, label: String(option), disabled: false }
}))

const selectedIndex = computed(() => normalizedOptions.value.findIndex(option => Object.is(option.value, props.modelValue)))
const selectedOption = computed(() => normalizedOptions.value[selectedIndex.value])
const activeOptionId = computed(() => activeIndex.value >= 0 ? `${selectId}-option-${activeIndex.value}` : undefined)

const enabledIndex = (start, direction) => {
  const options = normalizedOptions.value
  if (!options.length) return -1

  let index = start
  for (let count = 0; count < options.length; count += 1) {
    index = (index + direction + options.length) % options.length
    if (!options[index].disabled) return index
  }
  return -1
}

const close = (restoreFocus = false) => {
  if (!isOpen.value) return
  isOpen.value = false
  if (restoreFocus) nextTick(() => trigger.value?.focus())
}

const open = (direction = 1) => {
  if (props.disabled || isOpen.value) return
  const selected = selectedIndex.value
  activeIndex.value = selected >= 0 && !normalizedOptions.value[selected]?.disabled
    ? selected
    : enabledIndex(direction > 0 ? -1 : 0, direction)
  isOpen.value = true
}

const toggle = () => {
  if (isOpen.value) close()
  else open()
}

const move = (direction) => {
  if (!isOpen.value) {
    open(direction)
    return
  }
  activeIndex.value = enabledIndex(activeIndex.value, direction)
}

const selectAt = (index) => {
  const option = normalizedOptions.value[index]
  if (!option || option.disabled) return
  emit('update:modelValue', option.value)
  emit('change', option.value)
  activeIndex.value = index
  close(true)
}

const handleKeydown = (event) => {
  if (props.disabled) return

  if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
    event.preventDefault()
    move(event.key === 'ArrowDown' ? 1 : -1)
    return
  }
  if (event.key === 'Enter' || event.key === ' ') {
    event.preventDefault()
    if (isOpen.value && activeIndex.value >= 0) selectAt(activeIndex.value)
    else open()
    return
  }
  if (event.key === 'Escape') {
    event.preventDefault()
    close(true)
    return
  }
  if (event.key === 'Home' && isOpen.value) {
    event.preventDefault()
    activeIndex.value = enabledIndex(-1, 1)
    return
  }
  if (event.key === 'End' && isOpen.value) {
    event.preventDefault()
    activeIndex.value = enabledIndex(0, -1)
    return
  }
  if (event.key === 'Tab') close()
}

const handleDocumentPointerDown = (event) => {
  if (isOpen.value && !root.value?.contains(event.target)) close()
}

const removeOutsideListener = () => {
  if (!outsideListenerAttached) return
  document.removeEventListener('pointerdown', handleDocumentPointerDown, true)
  outsideListenerAttached = false
}

watch(() => props.disabled, disabled => {
  if (disabled) close()
})
watch(isOpen, openState => {
  if (openState && !outsideListenerAttached) {
    document.addEventListener('pointerdown', handleDocumentPointerDown, true)
    outsideListenerAttached = true
  } else if (!openState) {
    removeOutsideListener()
  }
}, { flush: 'sync' })

onBeforeUnmount(removeOutsideListener)
</script>

<template>
  <div ref="root" class="portal-select" :class="{ 'is-open': isOpen, 'has-error': error, 'is-disabled': disabled }">
    <button
      :id="selectId"
      ref="trigger"
      type="button"
      class="portal-select-trigger"
      role="combobox"
      aria-haspopup="listbox"
      :aria-label="ariaLabel"
      :aria-controls="listboxId"
      :aria-expanded="isOpen"
      :aria-activedescendant="isOpen ? activeOptionId : undefined"
      :aria-invalid="error ? 'true' : undefined"
      :aria-required="required || undefined"
      :disabled="disabled"
      @click="toggle"
      @keydown="handleKeydown"
      @blur="emit('blur')"
    >
      <span :class="{ 'is-placeholder': !selectedOption }">{{ selectedOption?.label || placeholder }}</span>
      <svg viewBox="0 0 20 20" aria-hidden="true"><path d="m5 7.5 5 5 5-5" /></svg>
    </button>

    <ul v-if="isOpen" :id="listboxId" class="portal-select-listbox" role="listbox" :aria-labelledby="selectId">
      <li
        v-for="(option, index) in normalizedOptions"
        :id="`${selectId}-option-${index}`"
        :key="`${String(option.value)}-${index}`"
        class="portal-select-option"
        :class="{ 'is-active': index === activeIndex, 'is-selected': index === selectedIndex, 'is-disabled': option.disabled }"
        role="option"
        :aria-selected="index === selectedIndex"
        :aria-disabled="option.disabled || undefined"
        @pointerenter="!option.disabled && (activeIndex = index)"
        @mousedown.prevent
        @click="selectAt(index)"
      >
        <span>{{ option.label }}</span>
        <svg v-if="index === selectedIndex" viewBox="0 0 20 20" aria-hidden="true"><path d="m4 10 4 4 8-8" /></svg>
      </li>
    </ul>

    <p v-if="typeof error === 'string' && error" class="portal-select-error" role="alert">{{ error }}</p>
  </div>
</template>

<style scoped>
.portal-select {
  min-width: 0;
  position: relative;
  width: 100%;
}

.portal-select-trigger {
  align-items: center;
  background: var(--bg-input, #fff);
  border: 1px solid var(--border-color, var(--color-border));
  border-radius: 8px;
  color: var(--text-primary, var(--color-eerie-black));
  cursor: pointer;
  display: flex;
  font: inherit;
  gap: 10px;
  justify-content: space-between;
  min-height: 42px;
  padding: 9px 11px;
  text-align: left;
  width: 100%;
}

.portal-select-trigger:hover {
  border-color: var(--accent, var(--color-dartmouth-green));
}

.portal-select-trigger:focus-visible {
  border-color: var(--accent, var(--color-dartmouth-green));
  box-shadow: 0 0 0 3px color-mix(in srgb, var(--accent, #0a6332) 20%, transparent);
  outline: 0;
}

.portal-select-trigger span {
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.portal-select-trigger .is-placeholder {
  color: var(--text-muted, #68736c);
}

.portal-select-trigger svg,
.portal-select-option svg {
  fill: none;
  flex: 0 0 auto;
  height: 16px;
  stroke: currentColor;
  stroke-linecap: round;
  stroke-linejoin: round;
  stroke-width: 2;
  width: 16px;
}

.portal-select.is-open .portal-select-trigger svg {
  transform: rotate(180deg);
}

.portal-select-listbox {
  background: var(--bg-surface-raised, var(--bg-surface, #fff));
  border: 1px solid var(--border-color, var(--color-border));
  border-radius: 9px;
  box-shadow: 0 14px 32px rgb(0 0 0 / 20%);
  display: grid;
  gap: 2px;
  left: 0;
  list-style: none;
  margin: 5px 0 0;
  max-height: min(280px, 45vh);
  min-width: 100%;
  overflow-y: auto;
  padding: 5px;
  position: absolute;
  right: 0;
  top: 100%;
  z-index: 80;
}

.portal-select-option {
  align-items: center;
  border-radius: 6px;
  color: var(--text-primary, var(--color-eerie-black));
  cursor: pointer;
  display: flex;
  gap: 12px;
  justify-content: space-between;
  min-height: 38px;
  padding: 8px 10px;
}

.portal-select-option.is-active {
  background: var(--bg-hover, rgb(16 106 46 / 10%));
}

.portal-select-option.is-selected {
  color: var(--accent, var(--color-dartmouth-green));
  font-weight: 700;
}

.portal-select-option.is-disabled,
.portal-select.is-disabled .portal-select-trigger {
  cursor: not-allowed;
  opacity: 0.58;
}

.portal-select.has-error .portal-select-trigger {
  border-color: var(--danger, #b42318);
}

.portal-select-error {
  color: var(--danger, #b42318);
  font-size: 0.75rem;
  margin: 5px 0 0;
}

@media (prefers-reduced-motion: reduce) {
  .portal-select-trigger svg {
    transition-duration: 0.01ms;
  }
}
</style>
