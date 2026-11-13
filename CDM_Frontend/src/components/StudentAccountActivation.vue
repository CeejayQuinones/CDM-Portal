<script setup>
import { reactive, ref, watch } from 'vue'
import { apiClient } from '../services/apiClient'
import TurnstileVerification from './TurnstileVerification.vue'

const emit = defineEmits(['close', 'activated'])
const form = reactive({ student_number: '', email: '', code: '', username: '', password: '', password_confirmation: '' })
const step = ref(1)
const busy = ref(false)
const error = ref('')
const message = ref('')
const token = ref('')
const turnstileToken = ref('')
const widget = ref(null)
const visitMode = ref(false)
const visit = reactive({ full_name: '', contact_email: '', preferred_date: '', preferred_time: 'morning' })
const visitReference = ref('')
const visitStatus = ref(null)

watch(() => [form.student_number, form.email], () => {
  token.value = ''
  if (step.value > 1) step.value = 1
})

const details = () => ({ student_number: form.student_number.trim(), email: form.email.trim().toLowerCase() })
const errorText = (requestError) => Object.values(requestError.response?.data?.errors || {}).flat()[0]
  || requestError.response?.data?.message || 'Please try again.'

async function sendCode() {
  if (busy.value) return
  error.value = ''
  if (!form.student_number.trim() || !form.email.trim()) {
    error.value = 'Enter your student number and the email on your enrollment record.'
    return
  }
  if (!turnstileToken.value) {
    error.value = 'Complete the human verification check.'
    return
  }
  busy.value = true
  try {
    const { data } = await apiClient.post('/student-account/send-code', { ...details(), turnstile_token: turnstileToken.value })
    message.value = data.message
    step.value = 2
  } catch (requestError) {
    error.value = errorText(requestError)
  } finally {
    busy.value = false
    turnstileToken.value = ''
    widget.value?.reset()
  }
}

async function verifyCode() {
  if (busy.value) return
  busy.value = true
  error.value = ''
  try {
    const { data } = await apiClient.post('/student-account/verify-code', { ...details(), code: form.code.trim() })
    token.value = data.data.verification_token
    step.value = 3
    message.value = 'Email verified. Set your account credentials.'
  } catch (requestError) {
    error.value = errorText(requestError)
  } finally {
    busy.value = false
  }
}

async function activate() {
  if (busy.value || !token.value) return
  busy.value = true
  error.value = ''
  try {
    await apiClient.post('/student-account/activate', {
      ...details(), verification_token: token.value,
      username: form.username.trim(), password: form.password,
      password_confirmation: form.password_confirmation,
    })
    emit('activated')
  } catch (requestError) {
    error.value = errorText(requestError)
  } finally {
    busy.value = false
  }
}

async function requestVisit() {
  if (busy.value || !turnstileToken.value) {
    error.value = 'Complete the human verification check.'
    return
  }
  busy.value = true
  error.value = ''
  try {
    const { data } = await apiClient.post('/student-account/visit-requests', {
      student_number: form.student_number.trim(), ...visit, turnstile_token: turnstileToken.value,
    })
    visitReference.value = data.data.reference
    message.value = data.message
  } catch (requestError) {
    error.value = errorText(requestError)
  } finally {
    busy.value = false
    turnstileToken.value = ''
    widget.value?.reset()
  }
}

async function checkVisit() {
  if (!visitReference.value || busy.value) return
  busy.value = true
  error.value = ''
  try {
    const { data } = await apiClient.get(`/student-account/visit-requests/${visitReference.value}`)
    visitStatus.value = data.data
  } catch (requestError) {
    error.value = errorText(requestError)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="activation-backdrop" @click.self="emit('close')">
    <section class="activation-dialog" role="dialog" aria-modal="true" aria-labelledby="activation-title">
      <header><div><small>Student and alumni access</small><h2 id="activation-title">Activate your school record</h2></div><button type="button" aria-label="Close" @click="emit('close')">×</button></header>
      <p class="activation-intro">Use the student number and email recorded during enrollment. Your grades and documents remain private until activation is complete.</p>
      <ol v-if="!visitMode" class="activation-steps"><li :class="{ active: step === 1 }">Record</li><li :class="{ active: step === 2 }">Email code</li><li :class="{ active: step === 3 }">Account</li></ol>
      <p v-if="error" class="activation-error" role="alert">{{ error }}</p>
      <p v-if="message" class="activation-message" role="status">{{ message }}</p>
      <form v-if="visitMode && !visitReference" @submit.prevent="requestVisit">
        <p>Request a Registrar visit if your enrollment email is wrong or no longer accessible. This request does not change your account or confirm a time.</p>
        <label>Student number<input v-model.trim="form.student_number" autocomplete="off" maxlength="20" required /></label>
        <label>Full name on school ID<input v-model.trim="visit.full_name" maxlength="200" required /></label>
        <label>Contact email for the visit<input v-model.trim="visit.contact_email" type="email" required /></label>
        <label>Preferred date<input v-model="visit.preferred_date" type="date" required /></label>
        <label>Preferred time<select v-model="visit.preferred_time"><option value="morning">Morning</option><option value="afternoon">Afternoon</option></select></label>
        <TurnstileVerification ref="widget" action="registration" @verified="turnstileToken = $event" @expired="turnstileToken = ''" @error="error = $event" />
        <button class="activation-primary" type="submit" :disabled="busy">{{ busy ? 'Requesting…' : 'Request Registrar visit' }}</button>
        <button type="button" @click="visitMode = false; error = ''">Back to activation</button>
      </form>
      <div v-else-if="visitMode" class="activation-visit-status">
        <strong>Save your visit reference</strong><p>{{ visitReference }}</p>
        <p>Your preferred date is not confirmed. Check the status here before travelling. Bring your physical school ID for the Registrar to check.</p>
        <p v-if="visitStatus">Status: {{ visitStatus.status }}<template v-if="visitStatus.scheduled_date"> · {{ visitStatus.scheduled_date }} {{ visitStatus.scheduled_time }}</template></p>
        <button type="button" :disabled="busy" @click="checkVisit">Check visit status</button>
        <button type="button" @click="visitMode = false; error = ''">Back to activation</button>
      </div>
      <form v-else-if="step === 1" @submit.prevent="sendCode">
        <label>Student number<input v-model.trim="form.student_number" autocomplete="off" maxlength="20" required placeholder="e.g. 26-00001" /></label>
        <label>Email used at enrollment<input v-model.trim="form.email" type="email" autocomplete="email" required placeholder="name@gmail.com" /></label>
        <TurnstileVerification ref="widget" action="registration" @verified="turnstileToken = $event" @expired="turnstileToken = ''" @error="error = $event" />
        <button class="activation-primary" type="submit" :disabled="busy">{{ busy ? 'Checking…' : 'Send verification code' }}</button>
      </form>
      <form v-else-if="step === 2" @submit.prevent="verifyCode">
        <label>Six-digit email code<input v-model.trim="form.code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required /></label>
        <button class="activation-primary" type="submit" :disabled="busy">{{ busy ? 'Verifying…' : 'Verify email' }}</button>
        <button type="button" @click="step = 1">Change details or resend</button>
      </form>
      <form v-else @submit.prevent="activate">
        <label>Username<input v-model.trim="form.username" autocomplete="username" maxlength="50" required /></label>
        <label>Password<input v-model="form.password" type="password" autocomplete="new-password" minlength="8" required /></label>
        <label>Confirm password<input v-model="form.password_confirmation" type="password" autocomplete="new-password" minlength="8" required /></label>
        <button class="activation-primary" type="submit" :disabled="busy">{{ busy ? 'Activating…' : 'Activate account' }}</button>
      </form>
      <aside class="activation-help"><strong>Lost access to that email?</strong><p>Request a Registrar visit, then bring your physical school ID. Staff will confirm your name and student number before correcting the recorded email. Then return here to verify the new address.</p><button type="button" @click="visitMode = true; error = ''; message = ''">Request a visit</button></aside>
    </section>
  </div>
</template>

<style scoped>
.activation-backdrop{position:fixed;inset:0;z-index:1000;display:grid;place-items:center;padding:16px;background:#071913b8}
.activation-visit-status{display:grid;gap:10px}.activation-visit-status p{margin:0;overflow-wrap:anywhere}.activation-visit-status button,.activation-help button{justify-self:start;min-height:36px;padding:7px 12px;border:1px solid #a9cabb;border-radius:7px;background:#fff;color:#086343;font-weight:700;cursor:pointer}.activation-help button{margin-top:10px}select{width:100%;min-height:42px;border:1px solid #bbcfc2;border-radius:8px;background:#fff;padding:8px 10px}
.activation-dialog{width:min(100%,520px);max-height:calc(100vh - 32px);overflow:auto;padding:24px;border:1px solid #c8ded2;border-radius:16px;background:#fff;color:#142a23;box-shadow:0 20px 55px #0003}
header{display:flex;align-items:start;justify-content:space-between;gap:12px}header small{color:#006f53;font-weight:700;text-transform:uppercase;letter-spacing:.08em}h2{margin:4px 0 0;font-size:1.4rem}header button{border:1px solid #d5e2da;border-radius:8px;background:#fff;font-size:1.4rem;line-height:1;padding:5px 10px}.activation-intro{color:#52655d;line-height:1.5}.activation-steps{display:flex;gap:7px;list-style:none;padding:0;margin:18px 0}.activation-steps li{flex:1;padding:8px;border-radius:8px;background:#eef3ef;text-align:center;font-size:.78rem;font-weight:700;color:#64756c}.activation-steps .active{background:#d9f0e4;color:#006f53}form{display:grid;gap:14px}label{display:grid;gap:6px;font-weight:700;font-size:.86rem}input{width:100%;min-height:42px;border:1px solid #bbcfc2;border-radius:8px;padding:9px 11px;font:inherit;color:#142a23;background:#fff}form button{min-height:40px;border:1px solid #bfd5c6;border-radius:8px;background:#fff;color:#075c43;font-weight:700;cursor:pointer}.activation-primary{background:#087654;color:#fff;border-color:#087654}.activation-help{margin-top:20px;padding:14px;border-radius:10px;background:#f3f7f4;font-size:.82rem;line-height:1.45}.activation-help p{margin:6px 0 0}.activation-error,.activation-message{padding:10px 12px;border-radius:8px;font-size:.84rem}.activation-error{background:#fff0ed;color:#a02d1d}.activation-message{background:#e8f7ee;color:#086343}
</style>
