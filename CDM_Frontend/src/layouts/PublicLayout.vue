<script setup>
import { RouterLink, RouterView } from 'vue-router'
import { useAuthStore } from '../stores/authStore'
import { dashboardForRole } from '../config/accessControl'
import { schoolInfo } from '../content/schoolInfo'
import logoUrl from '../assets/styles/images/cdm_logo.png'

const authStore = useAuthStore()
</script>

<template>
  <div class="public-shell">
    <header class="public-nav">
      <RouterLink class="nav-brand" :to="{ name: 'landing' }">
        <img :src="logoUrl" alt="" width="40" height="40" />
        <span>
          <strong>{{ schoolInfo.portalName }}</strong>
          <small>OneServe</small>
        </span>
      </RouterLink>

      <nav class="nav-links" aria-label="Public">
        <a href="#overview">Overview</a>
        <a href="#ai-monitoring">AI Monitoring</a>
        <RouterLink
          v-if="authStore.isAuthenticated"
          class="nav-cta"
          :to="dashboardForRole(authStore.currentRole)"
        >
          Open portal
        </RouterLink>
        <template v-else>
          <RouterLink class="nav-text" :to="{ name: 'login' }">Sign in</RouterLink>
          <RouterLink class="nav-cta" :to="{ name: 'get-started' }">Get started</RouterLink>
        </template>
      </nav>
    </header>

    <RouterView />

    <footer class="public-footer">
      <p>{{ schoolInfo.name }} · {{ schoolInfo.location }}</p>
      <p>{{ schoolInfo.portalName }} — campus management & student services</p>
    </footer>
  </div>
</template>

<style scoped>
.public-shell {
  min-height: 100vh;
  color: var(--color-eerie-black);
  background:
    radial-gradient(ellipse 80% 50% at 10% -10%, rgba(244, 211, 94, 0.22), transparent 55%),
    radial-gradient(ellipse 60% 40% at 90% 0%, rgba(16, 106, 46, 0.14), transparent 50%),
    linear-gradient(180deg, #eef6f0 0%, var(--color-anti-flash-white) 42%, #f3f7f4 100%);
}

.public-nav {
  position: sticky;
  top: 0;
  z-index: 20;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  padding: 14px clamp(18px, 4vw, 48px);
  backdrop-filter: blur(12px);
  background: rgba(248, 249, 250, 0.82);
  border-bottom: 1px solid rgba(16, 106, 46, 0.08);
}

.nav-brand {
  display: flex;
  align-items: center;
  gap: 10px;
  color: var(--color-dartmouth-green);
}

.nav-brand img {
  width: 40px;
  height: 40px;
  border-radius: 50%;
  object-fit: contain;
  background: #fff;
  padding: 2px;
}

.nav-brand strong {
  display: block;
  font-family: var(--font-display);
  font-size: 1.05rem;
  line-height: 1.1;
}

.nav-brand small {
  display: block;
  color: var(--color-dark-spring-green);
  font-size: 0.68rem;
  letter-spacing: 0.1em;
  text-transform: uppercase;
}

.nav-links {
  display: flex;
  align-items: center;
  gap: 8px 14px;
  flex-wrap: wrap;
  justify-content: flex-end;
}

.nav-links a {
  color: var(--color-muted);
  font-weight: 600;
  font-size: 0.92rem;
}

.nav-text {
  color: var(--color-dark-spring-green) !important;
}

.nav-cta {
  border-radius: 8px;
  background: var(--color-dartmouth-green);
  color: #fff !important;
  padding: 8px 14px;
  font-weight: 700 !important;
}

.public-footer {
  padding: 36px clamp(18px, 4vw, 48px) 48px;
  color: var(--color-muted);
  font-size: 0.9rem;
  border-top: 1px solid var(--color-border);
}

.public-footer p {
  margin: 0 0 6px;
}

@media (max-width: 720px) {
  .public-nav {
    flex-direction: column;
    align-items: flex-start;
  }

  .nav-links a:not(.nav-cta):not(.nav-text) {
    display: none;
  }
}
</style>
