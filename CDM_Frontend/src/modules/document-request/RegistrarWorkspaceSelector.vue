<script setup>
defineProps({
  items: { type: Array, required: true },
  modelValue: { type: String, required: true },
  ariaLabel: { type: String, default: 'Select workspace section' },
})

defineEmits(['update:modelValue'])
</script>

<template>
  <aside class="registrar-workspace-selector-column" :aria-label="ariaLabel">
    <button
      v-for="item in items"
      :key="item.key"
      type="button"
      class="registrar-workspace-selector-card"
      :class="{ 'is-selected': modelValue === item.key }"
      :aria-pressed="modelValue === item.key"
      @click="$emit('update:modelValue', item.key)"
    >
      <small v-if="item.eyebrow" class="workspace-selector-eyebrow">{{ item.eyebrow }}</small>
      <span>{{ item.title }}</span>
      <strong>{{ item.count }} {{ item.countLabel }}</strong>
      <small>{{ item.description }}</small>
    </button>
  </aside>
</template>

<style scoped src="./documentRequest.css"></style>
