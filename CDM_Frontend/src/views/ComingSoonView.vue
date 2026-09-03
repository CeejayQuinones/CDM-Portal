<script setup>
import EmptyPage from '../components/EmptyPage.vue'

defineProps({
  title: { type: String, required: true },
  description: { type: String, default: 'This service is being prepared.' },
})
</script>

<template>
  <EmptyPage />
</template>
