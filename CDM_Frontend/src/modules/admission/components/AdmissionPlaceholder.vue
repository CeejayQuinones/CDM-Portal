<script setup>
defineProps({ title: { type: String, required: true } })
</script>

<template>
  <section aria-labelledby="admission-page-title">
    <header class="page-header">
      <p class="page-kicker">Admission</p>
      <h1 id="admission-page-title" class="page-title">{{ title }}</h1>
    </header>
    <div class="placeholder-panel">
      <h2>Coming soon</h2>
      <p>This admission service is being prepared. It is not available yet.</p>
    </div>
  </section>
</template>
