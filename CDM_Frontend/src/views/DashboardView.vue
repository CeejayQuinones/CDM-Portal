<script setup>
import { computed } from 'vue'
import { useNavigationStore } from '../stores/navigation'
import { useAuthStore } from '../stores/authStore'

const navigationStore = useNavigationStore()
const authStore = useAuthStore()

const moduleCards = computed(() =>
  navigationStore.menuItemsForRole(authStore.currentRole).filter((item) => item.name !== 'dashboard'),
)
</script>

<template>
  <section class="page-header">
    <p class="page-kicker">Welcome</p>
    <h1 class="page-title">CDM Portal Dashboard</h1>
    <p class="page-description">
      Welcome to OneServe, your centralized campus information workspace.
    </p>
  </section>

  <section class="dashboard-grid" aria-label="Campus management modules">
    <RouterLink v-for="module in moduleCards" :key="module.name" :to="module.path" class="module-card">
      <span class="module-icon" aria-hidden="true">{{ module.icon }}</span>
      <h2>{{ module.label }}</h2>
      <p>{{ module.description }}</p>
    </RouterLink>
  </section>
</template>

<style scoped>
.dashboard-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
  gap: 20px;
}

.module-card {
  display: grid;
  min-height: 190px;
  align-content: start;
  gap: 12px;
  border: 1px solid var(--color-border);
  border-radius: 16px;
  background: var(--color-surface);
  padding: 24px;
  box-shadow: var(--shadow-soft);
  transition:
    border-color 160ms ease,
    transform 160ms ease,
    box-shadow 160ms ease;
}

.module-card:hover {
  border-color: var(--color-dark-spring-green);
  transform: translateY(-4px);
  box-shadow: 0 20px 44px rgba(13, 120, 86, 0.16);
}

.module-icon {
  display: grid;
  width: 46px;
  height: 46px;
  place-items: center;
  border-radius: 12px;
  background: var(--color-green-tint);
  color: var(--color-dartmouth-green);
  font-size: 0;
}
.module-icon::after { content: '✦'; font-size: 1.25rem; }

.module-card h2 {
  margin: 0;
  color: var(--color-eerie-black);
  font-size: 1.05rem;
  line-height: 1.35;
}

.module-card p {
  margin: 0;
  color: var(--color-muted);
  line-height: 1.55;
}
</style>
