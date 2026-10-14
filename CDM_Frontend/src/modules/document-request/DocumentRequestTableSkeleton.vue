<script setup>
import DocumentRequestSkeleton from './DocumentRequestSkeleton.vue'

defineProps({
  columns: { type: Number, default: 6 },
  rows: { type: Number, default: 5 },
  filters: { type: Number, default: 0 },
  label: { type: String, default: 'Loading document requests' },
})
</script>

<template>
  <div class="dr-table-skeleton" role="status" :aria-label="label" aria-busy="true">
    <span class="dr-visually-hidden">{{ label }}</span>
    <div v-if="filters" class="dr-skeleton-filters" :style="{ '--dr-skeleton-columns': filters }">
      <div v-for="index in filters" :key="`filter-${index}`" class="dr-skeleton-filter">
        <DocumentRequestSkeleton kind="label" width="44%" />
        <DocumentRequestSkeleton kind="control" />
      </div>
      <DocumentRequestSkeleton kind="button" width="86px" />
    </div>
    <div class="dr-skeleton-table">
      <div class="dr-skeleton-table-heading">
        <div><DocumentRequestSkeleton width="180px" /><DocumentRequestSkeleton kind="text" width="260px" /></div>
        <DocumentRequestSkeleton kind="badge" width="52px" />
      </div>
      <div class="dr-skeleton-table-header" :style="{ '--dr-skeleton-columns': columns }">
        <DocumentRequestSkeleton v-for="index in columns" :key="`header-${index}`" kind="label" width="72%" />
      </div>
      <div v-for="row in rows" :key="`row-${row}`" class="dr-skeleton-table-row" :style="{ '--dr-skeleton-columns': columns }">
        <DocumentRequestSkeleton
          v-for="column in columns"
          :key="`cell-${row}-${column}`"
          :kind="column === columns ? 'button' : column === columns - 1 ? 'badge' : 'text'"
          :width="column === columns ? '78px' : column === columns - 1 ? '74px' : column % 2 ? '82%' : '68%'"
        />
      </div>
    </div>
  </div>
</template>

<style scoped src="./documentRequestPrimitives.css"></style>
