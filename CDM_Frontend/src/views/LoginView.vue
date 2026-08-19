<script setup>
import { reactive, ref } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '../stores/authStore'
import { dashboardForRole } from '../utils/roleDashboard'
import logoUrl from '../assets/styles/images/cdm_logo.png'

const router = useRouter()
const route = useRoute()
const authStore = useAuthStore()
const form = reactive({ username: '', password: '', remember: true })
const errors = ref({})
const formError = ref('')
const isLoading = ref(false)
const showPassword = ref(false)

const submit = async () => {
  if (isLoading.value) return

  errors.value = {}
  formError.value = ''
  if (!form.username.trim()) errors.value.username = 'Username is required.'
  if (!form.password) errors.value.password = 'Password is required.'
  if (Object.keys(errors.value).length) return

  isLoading.value = true
  try {
    await authStore.login({ username: form.username.trim(), password: form.password })
    const destination = typeof route.query.redirect === 'string' ? route.query.redirect : dashboardForRole(authStore.currentRole)
    await router.replace(destination)
  } catch (error) {
    const backendErrors = error.response?.data?.errors
    errors.value = Object.fromEntries(
      Object.entries(backendErrors || {}).map(([field, messages]) => [field, messages[0]]),
    )
    formError.value = error.response?.data?.message || 'Invalid username or password.'
  } finally {
    isLoading.value = false
  }
}
</script>

<template>
  <section class="login-card" aria-labelledby="login-title">
    <div class="brand"><img :src="logoUrl" alt="CDM logo"><span>CDM Portal<small>OneServe</small></span></div>
    <h1 id="login-title">Sign in</h1>
    <p class="intro">Use your campus account to continue.</p>
    <p v-if="route.query.registered" class="registration-success" role="status">Account created. You can now sign in.</p>

    <form novalidate @submit.prevent="submit">
      <p v-if="formError" class="form-error" role="alert">{{ formError }}</p>

      <label for="username">Username</label>
      <input id="username" v-model="form.username" autocomplete="username" :aria-invalid="Boolean(errors.username)" />
      <p v-if="errors.username" class="field-error">{{ errors.username }}</p>

      <label for="password">Password</label>
      <div class="password-field">
        <input id="password" v-model="form.password" :type="showPassword ? 'text' : 'password'" autocomplete="current-password" :aria-invalid="Boolean(errors.password)" />
        <button type="button" @click="showPassword = !showPassword">{{ showPassword ? 'Hide' : 'Show' }}</button>
      </div>
      <p v-if="errors.password" class="field-error">{{ errors.password }}</p>

      <label class="remember"><input v-model="form.remember" type="checkbox" /> Remember me on this device</label>
      <button class="submit" type="submit" :disabled="isLoading">{{ isLoading ? 'Signing in…' : 'Sign in' }}</button>
      <p class="register-link">Need an account? <RouterLink :to="{ name: 'register' }">Register as a Guest</RouterLink></p>
    </form>
  </section>
</template>

<style scoped>
.login-card { width: min(100%, 440px); border-radius: 20px; background: var(--color-surface); padding: 38px; box-shadow: 0 24px 60px rgba(0, 0, 0, .2); }
.brand { align-items:center; display:flex; gap:11px; margin:0 0 18px; color:var(--color-dartmouth-green); font-weight:800; letter-spacing:.02em; }.brand img { background:#fff; border-radius:50%; height:48px; object-fit:contain; padding:2px; width:48px; }.brand small { color:var(--color-dark-spring-green); display:block; font-size:.75rem; letter-spacing:.08em; text-transform:uppercase; }
h1 { margin: 0; font-size: 2rem; }.intro { margin: 8px 0 26px; color: var(--color-muted); }
label { display: block; margin: 18px 0 7px; font-weight: 700; } input { width: 100%; min-height: 46px; border: 1px solid var(--color-border); border-radius: 10px; padding: 0 12px; }
.password-field { position: relative; }.password-field input { padding-right: 66px; }.password-field button { position: absolute; top: 5px; right: 5px; min-height: 34px; border: 0; border-radius: 6px; background: transparent; color: var(--color-dark-spring-green); font-weight: 700; cursor: pointer; }
.remember { display: flex; align-items: center; gap: 8px; color: var(--color-muted); font-size: .9rem; font-weight: 500; }.remember input { width: auto; min-height: auto; }
.submit { width: 100%; min-height: 46px; margin-top: 24px; border: 0; border-radius: 8px; background: var(--color-dartmouth-green); color: #fff; font-weight: 800; cursor: pointer; }.submit:disabled { cursor: wait; opacity: .7; }
.field-error, .form-error { margin: 6px 0 0; color: #b42318; font-size: .88rem; }.form-error { border-radius: 8px; background: #fef3f2; padding: 10px; }
.registration-success { border-radius: 8px; background: #edf7ef; color: #176434; padding: 10px; font-size: .9rem; }.register-link { margin: 16px 0 0; text-align: center; color: var(--color-muted); }.register-link a { color: var(--color-dark-spring-green); font-weight: 700; }
</style>
