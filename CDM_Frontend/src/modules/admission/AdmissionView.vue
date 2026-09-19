<script setup>
import AdmissionPlaceholder from './components/AdmissionPlaceholder.vue'

defineProps({ title: { type: String, required: true } })
</script>

<template>
  <AdmissionPlaceholder :title="title" />
</template>
