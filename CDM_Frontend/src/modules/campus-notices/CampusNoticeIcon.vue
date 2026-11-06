<script setup>
defineProps({ name: { type: String, required: true }, size: { type: Number, default: 18 } })
</script>

<template>
  <svg :width="size" :height="size" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    <template v-if="name === 'bell'"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 7h18s-3 0-3-7"/><path d="M10 19h4"/></template>
    <template v-else-if="name === 'edit'"><path d="m4 20 4.2-1 10.6-10.6a2.1 2.1 0 0 0-3-3L5.2 16Z"/><path d="m14.5 6.5 3 3"/></template>
    <template v-else-if="name === 'tag'"><path d="M20 13 11 22l-9-9V4h9Z"/><circle cx="7" cy="9" r="1"/></template>
    <template v-else-if="name === 'flag'"><path d="M5 21V4"/><path d="M5 5h11l-2 4 2 4H5"/></template>
    <template v-else-if="name === 'users'"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8"/></template>
    <template v-else-if="name === 'student'"><path d="m2 9 10-5 10 5-10 5Z"/><path d="M6 11.5V16c3 2 9 2 12 0v-4.5M22 9v6"/></template>
    <template v-else-if="name === 'calendar'"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/></template>
    <template v-else-if="name === 'repeat'"><path d="m17 2 4 4-4 4"/><path d="M3 11V9a3 3 0 0 1 3-3h15M7 22l-4-4 4-4"/><path d="M21 13v2a3 3 0 0 1-3 3H3"/></template>
    <template v-else-if="name === 'link'"><path d="M10 13a5 5 0 0 0 7.1.1l2-2a5 5 0 0 0-7.1-7.1l-1.1 1.1"/><path d="M14 11a5 5 0 0 0-7.1-.1l-2 2A5 5 0 0 0 12 20l1.1-1.1"/></template>
    <template v-else-if="name === 'eye'"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12"/><circle cx="12" cy="12" r="3"/></template>
    <template v-else-if="name === 'save'"><path d="M4 4h14l2 2v14H4Z"/><path d="M8 4v6h8V4M8 20v-6h8v6"/></template>
    <template v-else-if="name === 'send'"><path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/></template>
    <template v-else-if="name === 'trash'"><path d="M3 6h18M8 6V4h8v2M19 6l-1 15H6L5 6M10 11v5M14 11v5"/></template>
    <template v-else-if="name === 'refresh'"><path d="M20 6v5h-5"/><path d="M4 18v-5h5"/><path d="M6.1 9a7 7 0 0 1 11.5-2.6L20 11M4 13l2.4 4.6A7 7 0 0 0 17.9 15"/></template>
    <template v-else><circle cx="12" cy="12" r="9"/><path d="M12 8v4M12 16h.01"/></template>
  </svg>
</template>
