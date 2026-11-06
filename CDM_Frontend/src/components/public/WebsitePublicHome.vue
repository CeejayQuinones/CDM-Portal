<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import WebsiteLoginLoading from '../website/WebsiteLoginLoading.vue'
import buildingUrl from '../../assets/styles/images/CDM-bdng.png'
import {
  academicInstitutes,
  homepageRoles,
  institutionHistory,
  institutionalDirection,
  institutionProfile,
  portalServices,
} from '../../config/publicHomepage.js'

defineProps({
  logoUrl: { type: String, required: true },
  authenticating: { type: Boolean, default: false },
})
const emit = defineEmits(['start-admission'])

const root = ref(null)
const mobileMenuOpen = ref(false)
let revealObserver

const iconPaths = Object.freeze({
  admission: ['M6 3h12v18H6z', 'M9 7h6M9 11h6M9 15h4'],
  enrollment: ['M4 5h16v15H4z', 'M8 3v4M16 3v4M4 9h16', 'm8 14 2 2 4-4'],
  subjects: ['M4 5.5A2.5 2.5 0 0 1 6.5 3H11v17H6.5A2.5 2.5 0 0 0 4 22z', 'M20 5.5A2.5 2.5 0 0 0 17.5 3H13v17h4.5A2.5 2.5 0 0 1 20 22z'],
  schedule: ['M5 4h14v16H5z', 'M8 2v4M16 2v4M5 9h14M9 13h2M13 13h2M9 17h2'],
  grades: ['M5 20V10M10 20V4M15 20v-7M20 20V7', 'M3 20h19'],
  documents: ['M6 2h8l4 4v16H6z', 'M14 2v5h5M9 12h6M9 16h6'],
})

const prefersReducedMotion = () => window.matchMedia?.('(prefers-reduced-motion: reduce)').matches === true

const scrollToSection = (id) => {
  mobileMenuOpen.value = false
  document.getElementById(id)?.scrollIntoView({ behavior: prefersReducedMotion() ? 'auto' : 'smooth', block: 'start' })
}

const startAdmission = () => {
  mobileMenuOpen.value = false
  emit('start-admission')
}

const handleRoleAction = (role) => {
  if (role.key === 'applicant') startAdmission()
  else scrollToSection('login')
}

onMounted(() => {
  const sections = [...(root.value?.querySelectorAll('[data-reveal]') || [])]
  if (prefersReducedMotion() || typeof window.IntersectionObserver !== 'function') {
    sections.forEach(section => section.classList.add('is-visible'))
    return
  }
  root.value?.classList.add('reveal-ready')
  revealObserver = new window.IntersectionObserver((entries) => {
    entries.forEach((entry) => {
      if (!entry.isIntersecting) return
      entry.target.classList.add('is-visible')
      revealObserver.unobserve(entry.target)
    })
  }, { threshold: 0.12, rootMargin: '0px 0px -48px' })
  sections.forEach(section => revealObserver.observe(section))
})

onBeforeUnmount(() => revealObserver?.disconnect())
</script>

<template>
  <div ref="root" class="website-home" :style="{ '--campus-image': `url(${buildingUrl})` }">
    <header class="website-home-header" @keydown.esc="mobileMenuOpen = false">
      <div class="website-home-nav-shell">
        <button class="website-home-brand" type="button" aria-label="Colegio de Montalban homepage" @click="scrollToSection('home')">
          <img :src="logoUrl" alt="" width="1254" height="1254" />
          <span><strong>Colegio de Montalban</strong><small>CDM Portal</small></span>
        </button>
        <button class="website-home-menu" type="button" :aria-expanded="mobileMenuOpen" aria-controls="website-home-navigation" :aria-label="mobileMenuOpen ? 'Close public navigation' : 'Open public navigation'" @click="mobileMenuOpen = !mobileMenuOpen">
          <span></span><span></span><span></span>
        </button>
        <nav id="website-home-navigation" :class="{ open: mobileMenuOpen }" aria-label="Public navigation">
          <button type="button" @click="scrollToSection('home')">Home</button>
          <button type="button" @click="scrollToSection('about')">About</button>
          <button type="button" @click="scrollToSection('programs')">Academic Programs</button>
          <button type="button" @click="scrollToSection('portal-services')">Portal Services</button>
          <button class="website-home-login-link" type="button" @click="scrollToSection('login')">Log In</button>
          <button class="website-home-admission-link" type="button" @click="startAdmission">Admission / Get Started</button>
        </nav>
      </div>
    </header>

    <main>
      <section id="home" class="website-home-hero" aria-labelledby="website-home-title">
        <div class="website-home-hero-shell">
          <div class="website-home-hero-copy" data-reveal>
            <p class="website-home-kicker"><span></span>Colegio de Montalban</p>
            <h1 id="website-home-title">Your Campus.<br />Your Journey.<br /><em>One Portal.</em></h1>
            <p class="website-home-hero-description">Welcome to CDM Portal, your gateway to academic services and student information at Colegio de Montalban.</p>
            <div class="website-home-hero-actions">
              <button class="website-home-button primary" type="button" @click="scrollToSection('login')">Log In <span aria-hidden="true">→</span></button>
              <button class="website-home-button secondary" type="button" @click="startAdmission">Apply / Get Started <span aria-hidden="true">↗</span></button>
            </div>
            <div class="website-home-hero-meta" aria-label="Institution summary">
              <span>Public higher education</span>
              <span>Rodriguez, Rizal</span>
              <span>Established 2003</span>
            </div>
          </div>
          <div class="website-home-login-wrap" data-reveal>
            <slot name="login"></slot>
          </div>
        </div>
        <p class="website-home-photo-label">Ynares School Building · Colegio de Montalban</p>
      </section>

      <section id="about" class="website-home-section website-home-about" aria-labelledby="about-cdm-title">
        <div class="website-home-shell website-home-about-grid" data-reveal>
          <div class="website-home-about-copy">
            <p class="website-home-section-label">About Colegio de Montalban</p>
            <h2 id="about-cdm-title">Local access to higher education, rooted in community.</h2>
            <p class="website-home-lead">{{ institutionProfile.purpose }}</p>
            <p>Known today as Colegio de Montalban, the institution was formerly Pamantasan ng Montalban and serves the community from Kasiglahan Village in Barangay San Jose.</p>
          </div>
          <aside class="website-home-fact-panel" aria-label="Institution profile">
            <div class="website-home-seal-lockup">
              <img :src="logoUrl" alt="Colegio de Montalban seal" width="1254" height="1254" />
              <div><small>Institution profile</small><strong>{{ institutionProfile.abbreviation }}</strong></div>
            </div>
            <dl>
              <div><dt>Formerly</dt><dd>{{ institutionProfile.formerName }}</dd></div>
              <div><dt>Established</dt><dd>{{ institutionProfile.established }}</dd></div>
              <div><dt>Institution type</dt><dd>{{ institutionProfile.type }}</dd></div>
              <div class="wide"><dt>Location</dt><dd>{{ institutionProfile.location }}</dd></div>
            </dl>
          </aside>
        </div>
      </section>

      <section id="history" class="website-home-section website-home-history" aria-labelledby="history-title">
        <div class="website-home-shell">
          <div class="website-home-section-heading" data-reveal>
            <div><p class="website-home-section-label">Institutional history</p><h2 id="history-title">From Pamantasan ng Montalban to CdM.</h2></div>
            <p>Four milestones supplied for this public portal presentation.</p>
          </div>
          <ol class="website-home-timeline" data-reveal>
            <li v-for="entry in institutionHistory" :key="entry.year">
              <span class="website-home-timeline-year">{{ entry.year }}</span>
              <span class="website-home-timeline-marker" aria-hidden="true"></span>
              <div><h3>{{ entry.title }}</h3><p>{{ entry.description }}</p></div>
            </li>
          </ol>
        </div>
      </section>

      <section id="programs" class="website-home-section website-home-programs" aria-labelledby="programs-title">
        <div class="website-home-shell">
          <div class="website-home-section-heading" data-reveal>
            <div><p class="website-home-section-label">Academic institutes</p><h2 id="programs-title">Programs shaped for professional paths.</h2></div>
            <p>Informational listings supplied for the homepage. Current application availability is confirmed only through the Admission workflow.</p>
          </div>
          <div class="website-home-program-grid" data-reveal>
            <article v-for="institute in academicInstitutes" :key="institute.code" class="website-home-program-card">
              <header><span>{{ institute.code }}</span><small>{{ String(institute.programs.length).padStart(2, '0') }} programs</small></header>
              <h3>{{ institute.name }}</h3>
              <ul><li v-for="program in institute.programs" :key="program">{{ program }}</li></ul>
            </article>
          </div>
        </div>
      </section>

      <section class="website-home-section website-home-direction" aria-labelledby="direction-title">
        <div class="website-home-shell" data-reveal>
          <div class="website-home-direction-intro">
            <p class="website-home-section-label">Institutional direction</p>
            <h2 id="direction-title">Education with purpose, service, and responsibility.</h2>
            <p>Presented using the institutional wording supplied for this portal homepage.</p>
          </div>
          <div class="website-home-direction-cards">
            <article><span>01 · Mission</span><blockquote>“{{ institutionalDirection.mission }}”</blockquote></article>
            <article><span>02 · Vision</span><blockquote>“{{ institutionalDirection.vision }}”</blockquote></article>
          </div>
        </div>
      </section>

      <section id="portal-services" class="website-home-section website-home-services" aria-labelledby="services-title">
        <div class="website-home-shell">
          <div class="website-home-section-heading" data-reveal>
            <div><p class="website-home-section-label">CDM Portal services</p><h2 id="services-title">Academic workspaces in one secure place.</h2></div>
            <p>Sign in to see only the services and information authorized for your role and account.</p>
          </div>
          <div class="website-home-service-grid" data-reveal>
            <article v-for="(service, index) in portalServices" :key="service.title" class="website-home-service-card" :class="{ featured: index === 0 || index === 5 }">
              <div class="website-home-service-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path v-for="path in iconPaths[service.icon]" :key="path" :d="path" /></svg>
              </div>
              <div><small>{{ service.access }}</small><h3>{{ service.title }}</h3><p>{{ service.description }}</p></div>
            </article>
          </div>
          <div class="website-home-service-action" data-reveal><p>Private academic records never appear on this public page.</p><button type="button" @click="scrollToSection('login')">Sign in to access services <span aria-hidden="true">→</span></button></div>
        </div>
      </section>

      <section id="getting-started" class="website-home-section website-home-start" aria-labelledby="getting-started-title">
        <div class="website-home-shell">
          <div class="website-home-start-heading" data-reveal>
            <p class="website-home-section-label">Getting started</p>
            <h2 id="getting-started-title">Choose the entry point for your role.</h2>
            <p>CDM Portal keeps application, student, and teaching access within the existing authentication and authorization flow.</p>
          </div>
          <div class="website-home-role-grid" data-reveal>
            <article v-for="role in homepageRoles" :key="role.key">
              <span>{{ role.number }}</span><h3>{{ role.title }}</h3><p>{{ role.description }}</p>
              <button type="button" @click="handleRoleAction(role)">{{ role.action }} <span aria-hidden="true">→</span></button>
            </article>
          </div>
        </div>
      </section>
    </main>

    <footer class="website-home-footer">
      <div class="website-home-shell website-home-footer-grid">
        <div class="website-home-footer-brand"><img :src="logoUrl" alt="" width="1254" height="1254" /><div><strong>Colegio de Montalban</strong><span>CDM Portal</span></div></div>
        <p>Kasiglahan Village, Barangay San Jose<br />Rodriguez, Rizal, Philippines</p>
        <nav aria-label="Footer navigation"><button type="button" @click="scrollToSection('about')">About</button><button type="button" @click="scrollToSection('programs')">Programs</button><button type="button" @click="scrollToSection('portal-services')">Services</button></nav>
        <nav aria-label="Legal links"><RouterLink to="/terms">Terms & Conditions</RouterLink><RouterLink to="/privacy">Privacy Policy</RouterLink></nav>
      </div>
      <div class="website-home-footer-bottom"><span>© 2026 Colegio de Montalban. Project portal.</span><span>Institutional content supplied for the CDM Portal homepage.</span></div>
    </footer>

    <Transition name="website-login-loading">
      <WebsiteLoginLoading v-if="authenticating" />
    </Transition>
  </div>
</template>

<style src="../../assets/styles/website-public-home.css"></style>
