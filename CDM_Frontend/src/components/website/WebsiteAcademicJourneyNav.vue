<script setup>
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { useAuthStore } from '../../stores/authStore'
import { ROLES } from '../../config/accessControl'
import { clientPlatform } from '../../config/clientPlatform'

const route = useRoute()
const auth = useAuthStore()
const visible = computed(() => clientPlatform === 'web' && auth.isAuthenticated && auth.currentRole === ROLES.STUDENT)
const links = [
  { label: 'Enrollment', name: 'enrollment-status', routes: ['enrollment', 'enrollment-status'] },
  { label: 'Programs', name: 'enrollment-courses', routes: ['enrollment-courses', 'enrollment-curriculum'] },
  { label: 'Guide', name: 'enrollment-steps', routes: ['enrollment-steps'] },
  { label: 'Subjects', name: 'enrollment-subjects', routes: ['enrollment-subjects'] },
  { label: 'Schedule', name: 'enrollment-schedule', routes: ['enrollment-schedule'] },
  { label: 'COR', name: 'enrollment-cor', routes: ['enrollment-cor'] },
  { label: 'Grades', name: 'grading', routes: ['grading'] },
]
</script>

<template>
  <nav v-if="visible" class="ui4-academic-nav" aria-label="Student academic journey">
    <RouterLink v-for="link in links" :key="link.name" :to="{ name: link.name }" :aria-current="link.routes.includes(route.name) ? 'page' : undefined">
      {{ link.label }}
    </RouterLink>
  </nav>
</template>

<style scoped>
.ui4-academic-nav { display: flex; gap: 4px; max-width: 100%; margin: 0 0 22px; padding: 5px; overflow-x: auto; overscroll-behavior-inline: contain; border: 1px solid var(--website-border); border-radius: 11px; background: var(--website-card); scrollbar-width: thin; }
.ui4-academic-nav a { flex: 0 0 auto; display: inline-flex; align-items: center; justify-content: center; min-height: 40px; padding: 0 14px; border-radius: 7px; color: var(--website-muted); font-size: 13px; font-weight: 700; text-decoration: none; white-space: nowrap; }
.ui4-academic-nav a:hover { background: var(--website-hover); color: var(--website-ink); }
.ui4-academic-nav a[aria-current='page'] { background: var(--website-success-bg); color: var(--website-success); box-shadow: inset 0 0 0 1px var(--website-success-border); }
.ui4-academic-nav a:focus-visible { outline: 2px solid var(--website-focus); outline-offset: -2px; }
@media (max-width: 640px) { .ui4-academic-nav { margin-bottom: 16px; } .ui4-academic-nav a { min-height: 44px; padding-inline: 12px; } }
</style>
