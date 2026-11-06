<script setup>defineProps({ title: String, description: String, embedded: Boolean })</script>
<template>
  <section :class="{ 'admission-workflow': !embedded, 'admission-staff-workspace': !embedded, 'admission-modern-workspace': !embedded, 'admission-embedded-workspace': embedded }" :aria-label="!embedded ? 'Admission workspace' : undefined">
    <header v-if="!embedded" class="admission-modern-header"><div><p>Admission Management</p><h1>{{ title }}</h1><span v-if="description">{{ description }}</span></div><slot name="header-actions" /></header>
    <slot />
  </section>
</template>
<style src="../admissionStaffModern.css"></style>
