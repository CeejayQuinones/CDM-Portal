<script setup>
import EmptyPage from '../components/EmptyPage.vue'

defineProps({
  role: { type: String, required: true },
})
</script>

<template>
  <EmptyPage />
</template>
