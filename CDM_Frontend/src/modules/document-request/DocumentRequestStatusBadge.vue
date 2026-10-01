<script setup>
import { computed } from 'vue'
import { documentHistoryStatus } from './documentRequestPresentation'

const props = defineProps({ status: { type: String, default: '' } })
const presentation = computed(() => documentHistoryStatus(props.status))
</script>

<template>
  <span class="dr-status-badge" :class="presentation.className">{{ presentation.label }}</span>
</template>

<style scoped src="./documentRequestPrimitives.css"></style>
