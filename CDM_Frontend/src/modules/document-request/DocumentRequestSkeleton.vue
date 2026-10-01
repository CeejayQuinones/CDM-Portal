<script setup>
defineProps({
  kind: { type: String, default: 'line' },
  width: { type: String, default: '100%' },
})
</script>

<template>
  <span class="dr-skeleton" :class="`dr-skeleton--${kind}`" :style="{ '--dr-skeleton-width': width }" aria-hidden="true"></span>
</template>

<style scoped src="./documentRequestPrimitives.css"></style>
