<script setup>
import { computed } from 'vue'
import { RouterLink } from 'vue-router'
import { useAuthStore } from '../stores/authStore'
import { ROUTE_ROLES, canAccess, dashboardForRole } from '../config/accessControl'
import { schoolInfo } from '../content/schoolInfo'

const authStore = useAuthStore()

const portalLink = computed(() => {
  if (!authStore.isAuthenticated) return { name: 'login' }
  return dashboardForRole(authStore.currentRole)
})

const featureLink = (routeName) => {
  const roles = ROUTE_ROLES[routeName] || []
  if (authStore.isAuthenticated && canAccess(authStore.currentRole, roles)) {
    return { name: routeName }
  }
  if (authStore.isAuthenticated) return dashboardForRole(authStore.currentRole)
  return { name: 'login', query: { redirect: `/portal/${routeName}` } }
}

const featureCtaLabel = (link) => {
  const roles = ROUTE_ROLES[link.routeName] || []
  if (authStore.isAuthenticated && canAccess(authStore.currentRole, roles)) {
    return `Open ${link.title}`
  }
  if (authStore.isAuthenticated) return 'Go to portal'
  return 'Sign in to open'
}
</script>

<template>
  <main>
    <section class="hero" aria-labelledby="hero-brand">
      <div class="hero-copy">
        <p id="hero-brand" class="hero-brand">{{ schoolInfo.portalName }}</p>
        <h1>{{ schoolInfo.name }}</h1>
        <p class="hero-support">{{ schoolInfo.tagline }}</p>
        <div class="hero-actions">
          <RouterLink class="btn-primary" :to="portalLink">
            {{ authStore.isAuthenticated ? 'Go to portal' : 'Sign in to portal' }}
          </RouterLink>
          <RouterLink
            v-if="!authStore.isAuthenticated"
            class="btn-secondary"
            :to="{ name: 'get-started' }"
          >
            Get started
          </RouterLink>
          <a v-else class="btn-secondary" href="#ai-monitoring">See AI Monitoring</a>
        </div>
      </div>
      <div class="hero-visual" aria-hidden="true">
        <div class="hero-panel">
          <span class="pulse" />
          <p>Early Warning</p>
          <strong>Grade risk signals stay ahead of finals</strong>
        </div>
      </div>
    </section>

    <section id="overview" class="section overview">
      <header class="section-head">
        <p class="kicker">Campus overview</p>
        <h2>Know the school behind the portal</h2>
        <p>{{ schoolInfo.mission }}</p>
      </header>

      <div class="overview-grid">
        <article v-for="item in schoolInfo.overview" :key="item.title" class="overview-item">
          <h3>{{ item.title }}</h3>
          <p>{{ item.body }}</p>
        </article>
      </div>

      <dl class="contact-row">
        <div v-for="item in schoolInfo.contacts" :key="item.label">
          <dt>{{ item.label }}</dt>
          <dd>{{ item.value }}</dd>
        </div>
      </dl>
    </section>

    <section id="ai-monitoring" class="section upcoming">
      <header class="section-head">
        <p class="kicker">Available academic care tools</p>
        <h2>AI Monitoring Early Warning System</h2>
        <p>
          Monitor grades in real time, surface risk of failing early, generate professional study plans,
          and review adviser alerts — all inside AI Monitoring.
        </p>
      </header>

      <div class="link-list">
        <article
          v-for="link in schoolInfo.upcomingLinks"
          :key="link.id"
          class="link-item"
          :class="{ featured: link.featured }"
        >
          <div class="link-meta">
            <span class="status">{{ link.status }}</span>
            <h3>{{ link.title }}</h3>
            <p>{{ link.description }}</p>
          </div>
          <RouterLink
            class="link-action"
            :to="featureLink(link.routeName)"
          >
            {{ featureCtaLabel(link) }}
          </RouterLink>
        </article>
      </div>
    </section>
  </main>
</template>

<style scoped>
.hero {
  display: grid;
  grid-template-columns: 1.1fr 0.9fr;
  min-height: calc(100vh - 72px);
  align-items: stretch;
  gap: 0;
}

.hero-copy {
  display: flex;
  flex-direction: column;
  justify-content: center;
  padding: clamp(32px, 7vh, 72px) clamp(18px, 4vw, 48px);
  animation: rise 700ms ease both;
}

.hero-brand {
  margin: 0 0 12px;
  color: var(--color-dartmouth-green);
  font-family: var(--font-display);
  font-size: clamp(2.4rem, 6vw, 4.4rem);
  font-weight: 700;
  line-height: 0.95;
  letter-spacing: -0.03em;
}

.hero-copy h1 {
  margin: 0;
  max-width: 14ch;
  color: var(--color-eerie-black);
  font-family: var(--font-display);
  font-size: clamp(1.35rem, 2.6vw, 1.85rem);
  font-weight: 600;
  line-height: 1.25;
}

.hero-support {
  max-width: 36ch;
  margin: 16px 0 0;
  color: var(--color-muted);
  font-size: 1.05rem;
  line-height: 1.6;
}

.hero-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  margin-top: 28px;
}

.btn-primary,
.btn-secondary {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-height: 46px;
  padding: 0 18px;
  border-radius: 8px;
  font-weight: 700;
}

.btn-primary {
  background: var(--color-dartmouth-green);
  color: #fff;
}

.btn-secondary {
  border: 1px solid rgba(16, 106, 46, 0.28);
  background: rgba(255, 255, 255, 0.7);
  color: var(--color-dark-spring-green);
}

.hero-visual {
  position: relative;
  min-height: 320px;
  background:
    linear-gradient(145deg, rgba(16, 106, 46, 0.92), rgba(13, 120, 86, 0.78)),
    repeating-linear-gradient(
      -18deg,
      rgba(255, 255, 255, 0.05) 0 2px,
      transparent 2px 18px
    );
  animation: wash 1.1s ease both;
}

.hero-panel {
  position: absolute;
  inset: auto 8% 12% 8%;
  border: 1px solid rgba(255, 255, 255, 0.22);
  border-radius: 18px;
  background: rgba(8, 42, 24, 0.35);
  padding: 22px 22px 24px;
  color: #f7fff9;
  backdrop-filter: blur(8px);
  animation: float-in 900ms 180ms ease both;
}

.pulse {
  display: block;
  width: 10px;
  height: 10px;
  margin-bottom: 12px;
  border-radius: 50%;
  background: var(--color-naples-yellow);
  box-shadow: 0 0 0 0 rgba(244, 211, 94, 0.55);
  animation: pulse 2.2s ease infinite;
}

.hero-panel p {
  margin: 0 0 8px;
  font-size: 0.78rem;
  letter-spacing: 0.12em;
  text-transform: uppercase;
  opacity: 0.85;
}

.hero-panel strong {
  display: block;
  font-family: var(--font-display);
  font-size: clamp(1.35rem, 2.4vw, 1.8rem);
  font-weight: 600;
  line-height: 1.25;
}

.section {
  padding: clamp(48px, 9vh, 88px) clamp(18px, 4vw, 48px);
}

.section-head {
  max-width: 680px;
  margin-bottom: 32px;
}

.kicker {
  margin: 0 0 8px;
  color: var(--color-dartmouth-green);
  font-size: 0.8rem;
  font-weight: 700;
  letter-spacing: 0.08em;
  text-transform: uppercase;
}

.section-head h2 {
  margin: 0;
  font-family: var(--font-display);
  font-size: clamp(1.7rem, 3vw, 2.4rem);
  line-height: 1.15;
}

.section-head p {
  margin: 12px 0 0;
  color: var(--color-muted);
  line-height: 1.65;
}

.overview-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 22px 28px;
}

.overview-item h3 {
  margin: 0 0 8px;
  font-family: var(--font-display);
  font-size: 1.2rem;
}

.overview-item p {
  margin: 0;
  color: var(--color-muted);
  line-height: 1.6;
}

.contact-row {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 16px;
  margin: 36px 0 0;
  padding-top: 24px;
  border-top: 1px solid var(--color-border);
}

.contact-row dt {
  color: var(--color-muted);
  font-size: 0.8rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.06em;
}

.contact-row dd {
  margin: 4px 0 0;
  font-weight: 600;
}

.upcoming {
  background: linear-gradient(180deg, transparent, rgba(16, 106, 46, 0.05));
}

.link-list {
  display: grid;
  gap: 16px;
}

.link-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 20px;
  padding: 22px 0;
  border-bottom: 1px solid var(--color-border);
}

.link-item.featured {
  padding: 26px 22px;
  border: 1px solid rgba(16, 106, 46, 0.18);
  border-radius: 16px;
  background: rgba(255, 255, 255, 0.72);
  box-shadow: var(--shadow-soft);
}

.status {
  display: inline-block;
  margin-bottom: 8px;
  color: var(--color-dark-spring-green);
  font-size: 0.78rem;
  font-weight: 800;
  letter-spacing: 0.06em;
  text-transform: uppercase;
}

.link-item h3 {
  margin: 0;
  font-family: var(--font-display);
  font-size: 1.25rem;
}

.link-item p {
  max-width: 58ch;
  margin: 8px 0 0;
  color: var(--color-muted);
  line-height: 1.55;
}

.link-action,
.link-wait {
  flex-shrink: 0;
  font-weight: 700;
}

.link-action {
  color: var(--color-dartmouth-green);
  text-decoration: underline;
  text-underline-offset: 3px;
}

.link-wait {
  color: var(--color-muted);
  font-size: 0.9rem;
}

@keyframes rise {
  from { opacity: 0; transform: translateY(18px); }
  to { opacity: 1; transform: translateY(0); }
}

@keyframes wash {
  from { opacity: 0; transform: scale(1.03); }
  to { opacity: 1; transform: scale(1); }
}

@keyframes float-in {
  from { opacity: 0; transform: translateY(16px); }
  to { opacity: 1; transform: translateY(0); }
}

@keyframes pulse {
  0% { box-shadow: 0 0 0 0 rgba(244, 211, 94, 0.55); }
  70% { box-shadow: 0 0 0 14px rgba(244, 211, 94, 0); }
  100% { box-shadow: 0 0 0 0 rgba(244, 211, 94, 0); }
}

@media (max-width: 900px) {
  .hero {
    grid-template-columns: 1fr;
    min-height: auto;
  }

  .hero-visual {
    min-height: 280px;
  }

  .overview-grid,
  .contact-row {
    grid-template-columns: 1fr;
  }

  .link-item {
    flex-direction: column;
    align-items: flex-start;
  }
}
</style>
