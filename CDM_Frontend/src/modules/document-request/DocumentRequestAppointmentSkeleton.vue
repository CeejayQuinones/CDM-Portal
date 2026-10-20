<script setup>
import DocumentRequestSkeleton from './DocumentRequestSkeleton.vue'

defineProps({ rows: { type: Number, default: 4 } })
</script>

<template>
  <div class="dr-appointment-skeleton" role="status" aria-label="Loading today's appointments" aria-busy="true">
    <span class="dr-visually-hidden">Loading today's appointments</span>
    <div v-for="index in rows" :key="index" class="dr-appointment-skeleton-row">
      <div><DocumentRequestSkeleton width="72%" /><DocumentRequestSkeleton kind="text" width="48%" /><DocumentRequestSkeleton kind="badge" width="76px" /></div>
      <div><DocumentRequestSkeleton kind="label" width="42%" /><DocumentRequestSkeleton width="78%" /></div>
      <div><DocumentRequestSkeleton kind="label" width="48%" /><DocumentRequestSkeleton width="74%" /><DocumentRequestSkeleton kind="text" width="55%" /></div>
      <DocumentRequestSkeleton kind="button" width="132px" />
    </div>
  </div>
</template>

<style scoped src="./documentRequestPrimitives.css"></style>
