<script setup>
defineProps({ labelledby: { type: String, default: '' } })
</script>
<template><main class="website-workspace" :aria-labelledby="labelledby || undefined"><slot /></main></template>
<style scoped>
.website-workspace { width: min(1380px,100%); min-width: 0; margin: 0 auto; }
</style>
