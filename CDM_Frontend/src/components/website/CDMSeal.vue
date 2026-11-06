<script setup>
import { portalSealUrl } from '../../config/portalSeal'

defineProps({
  decorative: { type: Boolean, default: false },
})
</script>

<template>
  <img :src="portalSealUrl" :alt="decorative ? '' : 'Colegio de Montalban seal'" width="1254" height="1254" decoding="async" />
</template>

<style scoped>
img { display: block; aspect-ratio: 1; max-width: 100%; object-fit: contain; object-position: center; }
</style>
