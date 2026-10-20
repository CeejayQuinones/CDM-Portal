<script setup>
import { nextTick, reactive, ref } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import RegistrationWizard from '../components/RegistrationWizard.vue'
import { useAuthStore } from '../stores/authStore'
import { dashboardForRole } from '../utils/roleDashboard'
import { runLoginRequest } from '../utils/loginFlow'
import { apiClient } from '../services/apiClient'
import { clientPlatform } from '../config/clientPlatform'
import logoUrl from '../assets/styles/images/cdm_logo.png'

const router = useRouter()
const route = useRoute()
const authStore = useAuthStore()
const form = reactive({ username: '', password: '' })
const errors = ref({})
const formError = ref(route.query.platformDenied === '1'
  ? 'This account cannot use this CDM Portal application. Sign in through an authorized platform.'
  : '')
const isLoading = ref(false)
const showPassword = ref(false)
const showRegistration = ref(route.query.signup === '1')
const registrationSuccess = ref(Boolean(route.query.registered))
const mobileMenuOpen = ref(false)

// Placeholder homepage content: replace these arrays when a CMS or announcements backend is introduced.
const announcements = [
  { tag: 'Enrollment', month: 'AUG', day: '25', poster: 'Enrollment week', date: 'August 25, 2026', title: 'Enrollment Advisory', description: 'Review the enrollment reminders and prepare the required documents before visiting the Registrar’s Office.' },
  { tag: 'Registrar', month: 'AUG', day: '22', poster: 'Service hours', date: 'August 22, 2026', title: 'Registrar Office Schedule', description: 'Updated service hours are available for records, certifications, and other registrar assistance.' },
  { tag: 'Campus', month: 'AUG', day: '18', poster: 'Get involved', date: 'August 18, 2026', title: 'Campus Activity', description: 'Students are invited to join upcoming campus programs focused on community and engagement.' },
  { tag: 'Documents', month: 'AUG', day: '15', poster: 'Before you visit', date: 'August 15, 2026', title: 'Document Request Notice', description: 'Verify your request details and appointment schedule before proceeding to the campus office.' },
]

// Placeholder event content: replace when an events data source becomes available.
const events = [
  { month: 'SEP', day: '02', type: 'Student services', title: 'Student Orientation', description: 'A welcome session introducing campus services, student resources, and portal access.' },
  { month: 'SEP', day: '12', type: 'Campus community', title: 'Campus Foundation Day', description: 'A campus-wide program celebrating the Colegio de Montalban community.' },
  { month: 'SEP', day: '21', type: 'Academic support', title: 'Academic Consultation Week', description: 'Dedicated consultation days for academic guidance and student support.' },
]

const scrollTo = async (id) => {
  mobileMenuOpen.value = false
  await nextTick()
  document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' })
}

const submit = async () => {
  if (isLoading.value) return
  errors.value = {}
  formError.value = ''
  if (!form.username.trim()) errors.value.username = 'Username is required.'
  if (!form.password) errors.value.password = 'Password is required.'
  if (Object.keys(errors.value).length) return

  await runLoginRequest({
    credentials: { username: form.username.trim(), password: form.password },
    login: authStore.login,
    setLoading: (loading) => { isLoading.value = loading },
    redirect: async () => {
      const destination = typeof route.query.redirect === 'string' ? route.query.redirect : dashboardForRole(authStore.currentRole)
      await router.replace(destination)
    },
    onError: (error, message) => {
      const backendErrors = error.response?.data?.errors
      errors.value = Object.fromEntries(Object.entries(backendErrors || {}).map(([field, messages]) => [field, messages[0]]))
      formError.value = message
    },
  })
}

const googleSignIn = () => {
  formError.value = ''
  const base = new URL(apiClient.defaults.baseURL || '/api', window.location.origin)
  const backendOrigin = base.pathname.replace(/\/api\/?$/, '')
  const popup = window.open(`${base.origin}${backendOrigin}/auth/google/redirect?client=${encodeURIComponent(clientPlatform)}`, 'cdm-google-auth', 'popup,width=520,height=700')
  if (!popup) {
    formError.value = 'Allow pop-ups to continue with Google.'
    return
  }
  const receive = async (event) => {
    if (event.origin !== base.origin || event.data?.type !== 'cdm-google-auth') return
    window.removeEventListener('message', receive)
    popup.close()
    if (event.data.error) {
      formError.value = event.data.error
      return
    }
    isLoading.value = true
    try {
      await authStore.exchangeGoogleCode(event.data.code)
      const destination = typeof route.query.redirect === 'string' ? route.query.redirect : dashboardForRole(authStore.currentRole)
      await router.replace(destination)
    } catch (error) {
      formError.value = error.response?.data?.message || 'Google sign-in could not be completed. Please try again.'
    } finally {
      isLoading.value = false
    }
  }
  window.addEventListener('message', receive)
}

const registrationComplete = () => {
  showRegistration.value = false
  registrationSuccess.value = true
  scrollTo('login')
}
</script>

<template>
  <div class="public-page">
    <header class="site-header">
      <div class="nav-shell">
        <button class="brand-link" type="button" aria-label="Colegio de Montalban home" @click="scrollTo('home')">
          <img :src="logoUrl" alt="Colegio de Montalban seal" />
          <span><strong>CDM Portal</strong><small>Colegio de Montalban</small></span>
        </button>
        <button class="menu-button" type="button" :aria-expanded="mobileMenuOpen" aria-label="Toggle navigation" @click="mobileMenuOpen = !mobileMenuOpen"><span></span><span></span><span></span></button>
        <nav :class="{ open: mobileMenuOpen }" aria-label="Main navigation">
          <button type="button" @click="scrollTo('home')">Home</button>
          <button type="button" @click="scrollTo('announcements')">Announcements</button>
          <button type="button" @click="scrollTo('events')">Events</button>
          <button type="button" @click="scrollTo('about')">About</button>
          <button class="nav-login" type="button" @click="scrollTo('login')">Login</button>
        </nav>
      </div>
    </header>

    <main>
      <section id="home" class="hero-section">
        <div class="hero-pattern"></div>
        <div class="hero-shell">
          <div class="hero-copy">
            <p class="hero-kicker"><span></span> Colegio de Montalban</p>
            <h1>Your campus services,<br /><em>one portal away.</em></h1>
            <p class="hero-description">Access student records, enrollment, document requests, announcements, and campus services in one practical place.</p>
            <div class="hero-actions">
              <button type="button" class="yellow-button" @click="scrollTo('login')">Access the portal <span>→</span></button>
              <button type="button" class="text-button" @click="scrollTo('about')">Discover CDM <span>↓</span></button>
            </div>
            <div class="trust-line"><span>Official school portal</span><span>Secure account access</span><span>Built for the CDM community</span></div>
          </div>

          <aside id="login" class="login-card" aria-labelledby="login-title">
            <div class="card-brand"><img :src="logoUrl" alt="" /><span>CDM <strong>PORTAL</strong></span></div>
            <p class="card-kicker">Account access</p>
            <h2 id="login-title">Welcome back</h2>
            <p class="login-intro">Sign in with your campus credentials.</p>
            <p v-if="registrationSuccess" class="registration-success" role="status">Account created successfully. You can now sign in.</p>
            <form novalidate @submit.prevent="submit">
              <p v-if="formError" class="form-error" role="alert">{{ formError }}</p>
              <label for="username">Username</label>
              <div class="input-with-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 21a8 8 0 0 0-16 0M12 13a5 5 0 1 0 0-10 5 5 0 0 0 0 10Z" /></svg><input id="username" v-model="form.username" autocomplete="username" placeholder="Enter your username" :aria-invalid="Boolean(errors.username)" /></div>
              <p v-if="errors.username" class="field-error">{{ errors.username }}</p>
              <label for="password">Password</label>
              <div class="input-with-icon password-field"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="10" width="16" height="11" rx="2" /><path d="M8 10V7a4 4 0 0 1 8 0v3" /></svg><input id="password" v-model="form.password" :type="showPassword ? 'text' : 'password'" autocomplete="current-password" placeholder="Enter your password" :aria-invalid="Boolean(errors.password)" /><button type="button" @click="showPassword = !showPassword">{{ showPassword ? 'Hide' : 'Show' }}</button></div>
              <p v-if="errors.password" class="field-error">{{ errors.password }}</p>
              <button class="sign-in-button" type="submit" :disabled="isLoading">{{ isLoading ? 'Signing in…' : 'Sign in to portal' }} <span>→</span></button>
              <template v-if="clientPlatform === 'web'">
                <div class="google-auth-option">
                  <div class="divider google-divider"><span>Professor or Student?</span></div>
                  <button class="google-sign-in-button" type="button" :disabled="isLoading" @click="googleSignIn">
                    <svg class="google-sign-in-icon" viewBox="0 0 24 24" aria-hidden="true">
                      <path fill="#4285F4" d="M21.6 12.23c0-.71-.06-1.24-.2-1.79H12v3.4h5.52a4.71 4.71 0 0 1-2.05 3.09l-.02.11 2.98 2.31.21.02c1.94-1.79 2.96-4.43 2.96-7.14Z" />
                      <path fill="#34A853" d="M12 22c2.7 0 4.97-.89 6.63-2.43l-3.17-2.45c-.85.58-2 1-3.46 1a6.01 6.01 0 0 1-5.68-4.15l-.11.01-3.1 2.4-.04.11A10 10 0 0 0 12 22Z" />
                      <path fill="#FBBC05" d="M6.32 13.97A6.16 6.16 0 0 1 6 12c0-.69.12-1.36.31-1.97v-.12L3.18 7.47l-.1.05A10 10 0 0 0 2 12c0 1.61.38 3.14 1.07 4.49l3.25-2.52Z" />
                      <path fill="#EA4335" d="M12 5.88c1.88 0 3.15.81 3.88 1.49l2.82-2.76A9.57 9.57 0 0 0 12 2a10 10 0 0 0-8.93 5.52l3.24 2.51A6.03 6.03 0 0 1 12 5.88Z" />
                    </svg>
                    <span>Continue with Google</span>
                  </button>
                  <p class="google-account-note">Professor and Student accounts must already exist in CDM Portal. Google does not create an account.</p>
                </div>
              </template>
              <div class="divider guest-divider"><span>New to CDM Portal?</span></div>
              <button class="sign-up-button" type="button" @click="showRegistration = true">Create a Guest account</button>
            </form>
            <p class="legal-links"><RouterLink to="/terms">Terms & Conditions</RouterLink><span>·</span><RouterLink to="/privacy">Privacy Policy</RouterLink></p>
          </aside>
        </div>
        <div class="hero-wave"></div>
      </section>

      <section id="announcements" class="announcements-section">
        <div class="section-shell">
          <div class="section-heading"><div><p class="section-kicker">Campus bulletin</p><h2>Announcements & updates</h2></div><p>Important notices and updates from offices across the Colegio de Montalban campus.</p></div>
          <div class="announcement-grid">
            <article v-for="(item, index) in announcements" :key="item.title" class="announcement-card" :class="{ featured: index === 0 }">
              <div class="announcement-poster">
                <time><strong>{{ item.day }}</strong><span>{{ item.month }}</span></time>
                <div><span>{{ item.tag }}</span><strong>{{ item.poster }}</strong></div>
                <span class="poster-number">0{{ index + 1 }}</span>
              </div>
              <div class="announcement-body"><time>{{ item.date }}</time><h3>{{ item.title }}</h3><p>{{ item.description }}</p><span class="card-arrow" aria-hidden="true">→</span></div>
            </article>
          </div>
          <p class="demo-note">Homepage announcements shown are presentation placeholders and will be replaced with official content.</p>
        </div>
      </section>

      <section id="events" class="events-section">
        <div class="section-shell events-grid">
          <div class="events-intro"><p class="section-kicker light">What’s next at CDM</p><h2>Upcoming events</h2><p>Take part in activities that bring learning, service, and the CDM community together.</p><div class="gold-rule"></div></div>
          <div class="event-list"><article v-for="event in events" :key="event.title"><time><strong>{{ event.day }}</strong><span>{{ event.month }}</span></time><div><span class="event-type">{{ event.type }}</span><h3>{{ event.title }}</h3><p>{{ event.description }}</p></div><span class="event-arrow">→</span></article></div>
        </div>
      </section>

      <section id="about" class="about-section">
        <div class="section-shell about-grid">
          <div class="about-mark">
            <div class="about-code"><span>CDM</span><strong>One school.<br />One connected portal.</strong></div>
            <div class="seal-lockup"><img :src="logoUrl" alt="Colegio de Montalban seal" /><span>For students, staff,<br />and campus services.</span></div>
          </div>
          <div class="about-copy">
            <p class="section-kicker">About the portal</p>
            <h2>Everyday campus access, designed for the CDM community.</h2>
            <!-- Placeholder school description: replace with approved institutional copy. -->
            <p>CDM Portal gives students and staff one clear place to access academic and registrar services. It keeps essential campus transactions easier to find, follow, and complete.</p>
            <div class="about-features">
              <article><span>01</span><div><h3>Student services</h3><p>Enrollment, records, requests, and appointments in one place.</p></div></article>
              <article><span>02</span><div><h3>Campus updates</h3><p>Announcements and schedules that are easier to keep up with.</p></div></article>
            </div>
          </div>
        </div>
      </section>

      <section class="cta-section"><div class="cta-seal"><img :src="logoUrl" alt="" /></div><div><p class="section-kicker">Ready when you are</p><h2>Your CDM services are one sign-in away.</h2><p>Access your account or register as a Guest to begin using available public portal services.</p></div><button class="yellow-button" type="button" @click="scrollTo('login')">Go to login <span>↑</span></button></section>
    </main>

    <footer class="site-footer"><div class="footer-shell"><div class="footer-brand"><img :src="logoUrl" alt="Colegio de Montalban seal" /><div><strong>Colegio de Montalban</strong><span>CDM Portal</span></div></div><p>Centralized access to academic and registrar services.</p><nav aria-label="Legal links"><RouterLink to="/terms">Terms & Conditions</RouterLink><RouterLink to="/privacy">Privacy Policy</RouterLink></nav></div><div class="footer-bottom"><span>© 2026 Colegio de Montalban. Project portal.</span><span>Designed for the CDM community.</span></div></footer>

    <RegistrationWizard v-if="showRegistration" @close="showRegistration = false" @registered="registrationComplete" />
  </div>
</template>

<style scoped src="../assets/styles/public-home.css"></style>
