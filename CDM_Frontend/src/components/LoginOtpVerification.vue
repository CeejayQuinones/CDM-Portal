<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'

const props = defineProps({
  challenge: { type: Object, required: true },
  busy: { type: Boolean, default: false },
  error: { type: String, default: '' },
})
const emit = defineEmits(['verify', 'resend', 'back'])
const code = ref('')
const now = ref(Date.now())
const input = ref(null)
let timer

const resendSeconds = computed(() => Math.max(0, Math.ceil((new Date(props.challenge.resend_available_at).getTime() - now.value) / 1000)))
const expiresSeconds = computed(() => Math.max(0, Math.ceil((new Date(props.challenge.expires_at).getTime() - now.value) / 1000)))
const canSubmit = computed(() => /^\d{6}$/.test(code.value) && !props.busy && expiresSeconds.value > 0)

const normalizeCode = () => { code.value = code.value.replace(/\D/g, '').slice(0, 6) }
const submit = () => { if (canSubmit.value) emit('verify', code.value) }
const resend = () => { if (!props.busy && resendSeconds.value === 0) emit('resend') }

watch(() => props.challenge.challenge_id, async () => {
  code.value = ''
  now.value = Date.now()
  await nextTick()
  input.value?.focus()
})
onMounted(() => {
  timer = window.setInterval(() => { now.value = Date.now() }, 1000)
  input.value?.focus()
})
onBeforeUnmount(() => window.clearInterval(timer))
</script>

<template>
  <section class="login-otp" aria-labelledby="login-otp-title">
    <div class="login-otp-icon" aria-hidden="true">
      <svg viewBox="0 0 24 24"><path d="M7 10V8a5 5 0 0 1 10 0v2"/><rect x="4" y="10" width="16" height="11" rx="2"/><path d="M12 14v3"/></svg>
    </div>
    <p class="login-otp-kicker">Secure sign-in</p>
    <h2 id="login-otp-title">Verify your login</h2>
    <p>Enter the 6-digit code sent to <strong>{{ challenge.masked_email }}</strong>.</p>
    <form @submit.prevent="submit">
      <label for="login-otp-code">Verification code</label>
      <input
        id="login-otp-code"
        ref="input"
        v-model="code"
        class="login-otp-code"
        type="text"
        inputmode="numeric"
        autocomplete="one-time-code"
        pattern="[0-9]*"
        maxlength="6"
        :disabled="busy || expiresSeconds === 0"
        :aria-invalid="Boolean(error)"
        aria-describedby="login-otp-help login-otp-error"
        @input="normalizeCode"
      />
      <p id="login-otp-help" class="login-otp-help">
        {{ expiresSeconds > 0 ? `Code expires in ${Math.ceil(expiresSeconds / 60)} minute${Math.ceil(expiresSeconds / 60) === 1 ? '' : 's'}.` : 'This code has expired. Return to sign in.' }}
      </p>
      <p v-if="error" id="login-otp-error" class="login-otp-error" role="alert">{{ error }}</p>
      <button class="login-otp-submit" type="submit" :disabled="!canSubmit">
        {{ busy ? 'Verifying…' : 'Verify and sign in' }}
      </button>
    </form>
    <div class="login-otp-actions">
      <button type="button" :disabled="busy || resendSeconds > 0" @click="resend">
        {{ resendSeconds > 0 ? `Resend in ${resendSeconds}s` : 'Resend code' }}
      </button>
      <button type="button" :disabled="busy" @click="emit('back')">Back to sign in</button>
    </div>
    <p class="login-otp-security">Never share this code. CDM Portal staff will not ask for it.</p>
  </section>
</template>

<style scoped>
.login-otp{display:grid;gap:12px;text-align:left;color:inherit}.login-otp-icon{display:grid;place-items:center;width:42px;height:42px;border-radius:12px;background:#e8f6ed;color:#116a38}.login-otp-icon svg{width:23px;fill:none;stroke:currentColor;stroke-width:1.8}.login-otp-kicker{margin:0;color:#116a38;font-size:12px;font-weight:800;letter-spacing:.08em;text-transform:uppercase}.login-otp h2,.login-otp p{margin:0}.login-otp>p:not(.login-otp-kicker):not(.login-otp-security){font-size:14px;line-height:1.5}.login-otp form{display:grid;gap:8px}.login-otp label{font-size:13px;font-weight:700}.login-otp-code{box-sizing:border-box;width:100%;height:52px;border:1px solid #aebdb4;border-radius:10px;background:#fff;color:#142b20;text-align:center;font-size:25px;font-weight:800;letter-spacing:.45em;padding-left:.45em}.login-otp-code:focus{outline:3px solid rgba(22,125,66,.2);border-color:#167d42}.login-otp-code[aria-invalid=true]{border-color:#b42318}.login-otp-help,.login-otp-error,.login-otp-security{font-size:12px;line-height:1.4}.login-otp-help,.login-otp-security{color:#607068}.login-otp-error{color:#b42318}.login-otp-submit{height:44px;border:0;border-radius:10px;background:#116a38;color:#fff;font-weight:750;cursor:pointer}.login-otp-submit:disabled{opacity:.55;cursor:not-allowed}.login-otp-actions{display:flex;justify-content:space-between;gap:10px}.login-otp-actions button{border:0;background:transparent;color:#116a38;font:inherit;font-size:13px;font-weight:700;padding:5px 0;cursor:pointer}.login-otp-actions button:disabled{color:#89958f;cursor:not-allowed}.login-otp button:focus-visible{outline:3px solid rgba(22,125,66,.25);outline-offset:2px}@media(max-width:480px){.login-otp-actions{align-items:flex-start;flex-direction:column}}@media(prefers-color-scheme:dark){.login-otp-icon{background:#173c29;color:#68d391}.login-otp-code{background:#13251c;border-color:#4d6659;color:#fff}.login-otp-kicker,.login-otp-actions button{color:#68d391}.login-otp-help,.login-otp-security{color:#b7c5bd}}
</style>
