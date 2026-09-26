<script setup>
import { computed } from 'vue'
import { useAuthStore } from '../../../stores/authStore'
import { useNavigationStore } from '../../../stores/navigation'
defineProps({ title: String, description: String, embedded: Boolean })
const auth = useAuthStore()
const navigation = useNavigationStore()
const pages = computed(() => navigation.menuItemsForRole(auth.currentRole).find(item => item.name === 'admission-menu')?.children || [])
</script>
<template>
  <section :class="{ 'admission-workflow': !embedded }">
    <template v-if="!embedded">
      <header class="page-header"><p class="page-kicker">Admission workspace</p><h1 class="page-title">{{ title }}</h1><p v-if="description" class="page-description">{{ description }}</p></header>
      <nav class="admission-context-nav" aria-label="Admission workspace">
        <router-link v-for="page in pages" :key="page.name" :to="page.path" class="admission-context-link" active-class="" exact-active-class="is-current">{{ page.label }}</router-link>
      </nav>
    </template>
    <slot />
  </section>
</template>
