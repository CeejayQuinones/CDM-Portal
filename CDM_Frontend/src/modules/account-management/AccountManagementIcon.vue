<script setup>
defineProps({ name: { type: String, required: true }, size: { type: Number, default: 18 } })
</script>

<template>
  <svg :width="size" :height="size" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    <template v-if="name === 'professor'"><path d="m2 9 10-5 10 5-10 5Z"/><path d="M6 11.5V16c3 2 9 2 12 0v-4.5M22 9v6"/></template>
    <template v-else-if="name === 'registrar'"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6M16 11h6"/></template>
    <template v-else-if="name === 'search'"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></template>
    <template v-else-if="name === 'filter'"><path d="M4 5h16M7 12h10M10 19h4"/></template>
    <template v-else-if="name === 'building'"><path d="M3 21h18M5 21V8l7-4 7 4v13M9 10v1M12 10v1M15 10v1M9 15v1M12 15v1M15 15v1"/></template>
    <template v-else-if="name === 'check'"><path d="m5 12 4 4L19 6"/></template>
    <template v-else-if="name === 'pause'"><path d="M8 5v14M16 5v14"/></template>
    <template v-else-if="name === 'users'"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8"/></template>
    <template v-else-if="name === 'edit'"><path d="m4 20 4.2-1 10.6-10.6a2.1 2.1 0 0 0-3-3L5.2 16Z"/><path d="m14.5 6.5 3 3"/></template>
    <template v-else-if="name === 'power'"><path d="M12 2v10"/><path d="M18.4 6.6a9 9 0 1 1-12.8 0"/></template>
    <template v-else-if="name === 'plus'"><path d="M12 5v14M5 12h14"/></template>
    <template v-else-if="name === 'arrow-left'"><path d="m15 18-6-6 6-6"/></template>
    <template v-else-if="name === 'arrow-right'"><path d="m9 18 6-6-6-6"/></template>
    <template v-else-if="name === 'identity'"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></template>
    <template v-else-if="name === 'briefcase'"><rect x="3" y="7" width="18" height="13" rx="2"/><path d="M8 7V4h8v3M3 12h18"/></template>
    <template v-else-if="name === 'lock'"><rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></template>
    <template v-else-if="name === 'book'"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2Z"/></template>
    <template v-else><circle cx="12" cy="12" r="9"/><path d="M12 8v4M12 16h.01"/></template>
  </svg>
</template>
