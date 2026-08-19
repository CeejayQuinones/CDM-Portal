<script setup>
import { reactive, ref } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import { useAuthStore } from '../stores/authStore'
import logoUrl from '../assets/styles/images/cdm_logo.png'

const router = useRouter()
const authStore = useAuthStore()
const form = reactive({
  username: '', email: '', password: '', password_confirmation: '', first_name: '', last_name: '', gender: '',
})
const errors = ref({})
const formError = ref('')
const isLoading = ref(false)
const showPassword = ref(false)

const submit = async () => {
  if (isLoading.value) return
  errors.value = {}
  formError.value = ''

  for (const field of ['username', 'email', 'password', 'password_confirmation', 'first_name', 'last_name', 'gender']) {
    if (!form[field]) errors.value[field] = 'This field is required.'
  }
  if (form.password && form.password.length < 8) errors.value.password = 'Password must be at least 8 characters.'
  if (form.password && form.password_confirmation && form.password !== form.password_confirmation) errors.value.password_confirmation = 'Passwords do not match.'
  if (Object.keys(errors.value).length) return

  isLoading.value = true
  try {
    await authStore.register(form)
    await router.replace({ name: 'login', query: { registered: '1' } })
  } catch (error) {
    const backendErrors = error.response?.data?.errors || {}
    errors.value = Object.fromEntries(Object.entries(backendErrors).map(([field, messages]) => [field, messages[0]]))
    console.log(error.response)
console.log(error.response?.data)

formError.value =
  JSON.stringify(error.response?.data) || error.message
  } finally {
    isLoading.value = false
  }
}
</script>

<template>
  <section class="register-card" aria-labelledby="register-title">
    <div class="brand"><img :src="logoUrl" alt="CDM logo"><span>CDM Portal<small>OneServe</small></span></div>
    <h1 id="register-title">Create a Guest account</h1>
    <p class="intro">Register for the public Registrar Portal.</p>
    <form novalidate @submit.prevent="submit">
      <p v-if="formError" class="form-error" role="alert">{{ formError }}</p>
      <div class="form-grid">
        <label>First name<input v-model="form.first_name" autocomplete="given-name" /><small v-if="errors.first_name">{{ errors.first_name }}</small></label>
        <label>Last name<input v-model="form.last_name" autocomplete="family-name" /><small v-if="errors.last_name">{{ errors.last_name }}</small></label>
      </div>
      <label>Username<input v-model="form.username" autocomplete="username" /><small v-if="errors.username">{{ errors.username }}</small></label>
      <label>Email address<input v-model="form.email" type="email" autocomplete="email" /><small v-if="errors.email">{{ errors.email }}</small></label>
      <label>Gender<select v-model="form.gender"><option disabled value="">Select an option</option><option>Male</option><option>Female</option><option>Prefer not to say</option></select><small v-if="errors.gender">{{ errors.gender }}</small></label>
      <label>Password<div class="password-field"><input v-model="form.password" :type="showPassword ? 'text' : 'password'" autocomplete="new-password" /><button type="button" @click="showPassword = !showPassword">{{ showPassword ? 'Hide' : 'Show' }}</button></div><small v-if="errors.password">{{ errors.password }}</small></label>
      <label>Confirm password<input v-model="form.password_confirmation" :type="showPassword ? 'text' : 'password'" autocomplete="new-password" /><small v-if="errors.password_confirmation">{{ errors.password_confirmation }}</small></label>
      <button class="submit" type="submit" :disabled="isLoading">{{ isLoading ? 'Creating account…' : 'Create account' }}</button>
      <p class="sign-in">Already have an account? <RouterLink :to="{ name: 'login' }">Sign in</RouterLink></p>
    </form>
  </section>
</template>

<style scoped>
.register-card { width: min(100%, 620px); border-radius: 20px; background: var(--color-surface); padding: 36px; box-shadow: 0 24px 60px rgba(0,0,0,.2); }.brand { align-items:center; display:flex; gap:11px; margin:0 0 18px; color:var(--color-dartmouth-green); font-weight:800; letter-spacing:.02em; }.brand img { background:#fff; border-radius:50%; height:48px; object-fit:contain; padding:2px; width:48px; }.brand small { color:var(--color-dark-spring-green); display:block; font-size:.75rem; letter-spacing:.08em; text-transform:uppercase; } h1 { margin: 0; font-size: 1.8rem; }.intro { margin: 8px 0 22px; color: var(--color-muted); } label { display: block; margin: 14px 0 0; font-weight: 700; } input, select { width: 100%; min-height: 44px; margin-top: 6px; border: 1px solid var(--color-border); border-radius: 10px; padding: 0 12px; background: #fff; } .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }.form-grid label { margin-top: 0; } small, .form-error { display: block; margin-top: 5px; color: #b42318; font-size: .84rem; }.form-error { border-radius: 8px; background: #fef3f2; padding: 10px; }.password-field { position: relative; }.password-field input { padding-right: 66px; }.password-field button { position: absolute; top: 10px; right: 5px; border: 0; background: transparent; color: var(--color-dark-spring-green); font-weight: 700; cursor: pointer; }.submit { width: 100%; min-height: 46px; margin-top: 24px; border: 0; border-radius: 10px; background: var(--color-dartmouth-green); color: #fff; font-weight: 800; cursor: pointer; }.submit:disabled { opacity: .7; cursor: wait; }.sign-in { margin: 16px 0 0; text-align: center; color: var(--color-muted); }.sign-in a { color: var(--color-dark-spring-green); font-weight: 700; } @media (max-width: 520px) { .register-card { padding: 24px; }.form-grid { grid-template-columns: 1fr; gap: 0; }.form-grid label + label { margin-top: 14px; } }
</style>
