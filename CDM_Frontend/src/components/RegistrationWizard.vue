<script setup>
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue'
import { apiClient } from '../services/apiClient'
import { useAuthStore } from '../stores/authStore'

const emit = defineEmits(['close', 'registered'])
const authStore = useAuthStore()

const steps = [
  { number: 1, label: 'Personal' },
  { number: 2, label: 'Contact' },
  { number: 3, label: 'Email' },
  { number: 4, label: 'Account' },
  { number: 5, label: 'Review' },
]

const form = reactive({
  first_name: '',
  middle_name: '',
  last_name: '',
  birth_date: '',
  gender: '',
  address_line: '',
  barangay: '',
  city_municipality: '',
  province: '',
  contact_number: '',
  email: '',
  verification_code: '',
  email_verification_token: '',
  username: '',
  password: '',
  password_confirmation: '',
  terms_accepted: false,
})

const currentStep = ref(1)
const touched = reactive({})
const serverErrors = ref({})
const formError = ref('')
const emailMessage = ref('')
const emailError = ref('')
const isSendingCode = ref(false)
const isVerifyingCode = ref(false)
const isSubmitting = ref(false)
const showPassword = ref(false)
const cooldown = ref(0)
let cooldownTimer

const fieldRules = {
  first_name: () => (!form.first_name.trim() ? 'First name is required.' : ''),
  last_name: () => (!form.last_name.trim() ? 'Last name is required.' : ''),
  birth_date: () => {
    if (!form.birth_date) return 'Birth date is required.'
    return new Date(`${form.birth_date}T00:00:00`) >= new Date() ? 'Birth date must be in the past.' : ''
  },
  gender: () => (!form.gender ? 'Please select an option.' : ''),
  address_line: () => (!form.address_line.trim() ? 'Street address is required.' : ''),
  barangay: () => (!form.barangay.trim() ? 'Barangay is required.' : ''),
  city_municipality: () => (!form.city_municipality.trim() ? 'City or municipality is required.' : ''),
  province: () => (!form.province.trim() ? 'Province is required.' : ''),
  contact_number: () => {
    if (!form.contact_number.trim()) return 'Contact number is required.'
    return !/^[+\d][\d\s()-]{6,19}$/.test(form.contact_number) ? 'Enter a valid contact number.' : ''
  },
  email: () => {
    if (!form.email.trim()) return 'Email address is required.'
    return !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.email) ? 'Enter a valid email address.' : ''
  },
  username: () => {
    if (!form.username.trim()) return 'Username is required.'
    return !/^[A-Za-z0-9_-]+$/.test(form.username) ? 'Use only letters, numbers, dashes, and underscores.' : ''
  },
  password: () => (!form.password ? 'Password is required.' : form.password.length < 8 ? 'Use at least 8 characters.' : ''),
  password_confirmation: () => {
    if (!form.password_confirmation) return 'Please confirm your password.'
    return form.password_confirmation !== form.password ? 'Passwords do not match.' : ''
  },
  terms_accepted: () => (!form.terms_accepted ? 'You must accept the Terms and Privacy Policy.' : ''),
}

const stepFields = {
  1: ['first_name', 'last_name', 'birth_date', 'gender'],
  2: ['address_line', 'barangay', 'city_municipality', 'province', 'contact_number'],
  3: ['email'],
  4: ['username', 'password', 'password_confirmation', 'terms_accepted'],
}

const fieldError = (field) => serverErrors.value[field]?.[0] || fieldRules[field]?.() || ''
const showFieldError = (field) => Boolean((touched[field] || serverErrors.value[field]) && fieldError(field))
const currentFieldsAreValid = computed(() => {
  const fields = stepFields[currentStep.value] || []
  const baseValid = fields.every((field) => !fieldRules[field]?.())
  return currentStep.value === 3 ? baseValid && Boolean(form.email_verification_token) : baseValid
})
const age = computed(() => {
  if (!form.birth_date) return null
  const born = new Date(`${form.birth_date}T00:00:00`)
  const today = new Date()
  let result = today.getFullYear() - born.getFullYear()
  if (today < new Date(today.getFullYear(), born.getMonth(), born.getDate())) result -= 1
  return result >= 0 ? result : null
})
const fullAddress = computed(() =>
  [form.address_line, `Barangay ${form.barangay}`, form.city_municipality, form.province]
    .filter((part) => part && part !== 'Barangay ')
    .join(', '),
)
const hasSubstantialData = computed(() =>
  Object.entries(form).some(([key, value]) => !['terms_accepted'].includes(key) && String(value).trim()),
)

const touch = (field) => {
  touched[field] = true
  if (serverErrors.value[field]) delete serverErrors.value[field]
}

const startCooldown = () => {
  cooldown.value = 60
  clearInterval(cooldownTimer)
  cooldownTimer = setInterval(() => {
    cooldown.value -= 1
    if (cooldown.value <= 0) clearInterval(cooldownTimer)
  }, 1000)
}

const sendCode = async () => {
  touch('email')
  if (fieldRules.email() || isSendingCode.value || cooldown.value) return
  emailError.value = ''
  emailMessage.value = ''
  isSendingCode.value = true
  try {
    const { data } = await apiClient.post('/registration/email-verification/send', { email: form.email.trim() })
    emailMessage.value = data.message
    startCooldown()
  } catch (error) {
    emailError.value = error.response?.data?.errors?.email?.[0] || error.response?.data?.message || 'Unable to send the code.'
  } finally {
    isSendingCode.value = false
  }
}

const verifyCode = async () => {
  if (!/^\d{6}$/.test(form.verification_code) || isVerifyingCode.value) return
  emailError.value = ''
  isVerifyingCode.value = true
  try {
    const { data } = await apiClient.post('/registration/email-verification/verify', {
      email: form.email.trim(),
      code: form.verification_code,
    })
    form.email_verification_token = data.data.verification_token
    emailMessage.value = data.message
  } catch (error) {
    emailError.value = error.response?.data?.errors?.code?.[0] || error.response?.data?.message || 'Unable to verify the code.'
  } finally {
    isVerifyingCode.value = false
  }
}

const next = () => {
  for (const field of stepFields[currentStep.value] || []) touched[field] = true
  if (!currentFieldsAreValid.value) return
  serverErrors.value = {}
  formError.value = ''
  currentStep.value = Math.min(5, currentStep.value + 1)
}

const back = () => {
  serverErrors.value = {}
  formError.value = ''
  currentStep.value = Math.max(1, currentStep.value - 1)
}

const requestClose = () => {
  if (hasSubstantialData.value && !window.confirm('Discard the information entered for this registration?')) return
  emit('close')
}

const submit = async () => {
  if (isSubmitting.value || !form.email_verification_token || !form.terms_accepted) return
  isSubmitting.value = true
  serverErrors.value = {}
  formError.value = ''
  try {
    await authStore.register({
      username: form.username.trim(),
      email: form.email.trim(),
      password: form.password,
      password_confirmation: form.password_confirmation,
      first_name: form.first_name.trim(),
      middle_name: form.middle_name.trim() || null,
      last_name: form.last_name.trim(),
      gender: form.gender,
      birth_date: form.birth_date,
      contact_number: form.contact_number.trim(),
      address: fullAddress.value,
      terms_accepted: form.terms_accepted,
      email_verification_token: form.email_verification_token,
    })
    emit('registered')
  } catch (error) {
    serverErrors.value = error.response?.data?.errors || {}
    formError.value = error.response?.data?.message || 'The account could not be created. Review your information and try again.'
    const accountFields = ['username', 'password', 'password_confirmation', 'terms_accepted']
    if (serverErrors.value.email || serverErrors.value.email_verification_token) {
      form.email_verification_token = ''
      form.verification_code = ''
      emailError.value = serverErrors.value.email?.[0] || serverErrors.value.email_verification_token?.[0] || ''
      currentStep.value = 3
    } else if (accountFields.some((field) => serverErrors.value[field])) currentStep.value = 4
  } finally {
    isSubmitting.value = false
  }
}

watch(
  () => form.email,
  () => {
    form.email_verification_token = ''
    form.verification_code = ''
    emailMessage.value = ''
    emailError.value = ''
  },
)

onBeforeUnmount(() => clearInterval(cooldownTimer))
</script>

<template>
  <div class="modal-backdrop" role="presentation" @mousedown.self="requestClose">
    <section class="wizard" role="dialog" aria-modal="true" aria-labelledby="registration-title">
      <header class="wizard-header">
        <div>
          <p class="eyebrow">Guest registration</p>
          <h2 id="registration-title">Create your CDM Portal account</h2>
          <p>Step {{ currentStep }} of 5 · {{ steps[currentStep - 1].label }}</p>
        </div>
        <button class="close-button" type="button" aria-label="Close registration" @click="requestClose">×</button>
      </header>

      <ol class="progress" aria-label="Registration progress">
        <li v-for="item in steps" :key="item.number" :class="{ active: item.number === currentStep, complete: item.number < currentStep }">
          <span>{{ item.number < currentStep ? '✓' : item.number }}</span>
          <small>{{ item.label }}</small>
        </li>
      </ol>

      <form novalidate @submit.prevent="currentStep === 5 ? submit() : next()">
        <div class="wizard-body">
          <p v-if="formError" class="alert error" role="alert">{{ formError }}</p>

          <Transition name="step" mode="out-in">
            <div :key="currentStep">
              <section v-if="currentStep === 1" class="step-panel">
                <div class="step-heading">
                  <span>01</span>
                  <div><h3>Personal information</h3><p>Tell us who will own this account.</p></div>
                </div>
                <div class="field-grid two">
                  <label>First name <input v-model="form.first_name" autocomplete="given-name" @blur="touch('first_name')" /><small v-if="showFieldError('first_name')">{{ fieldError('first_name') }}</small></label>
                  <label>Middle name <em>Optional</em><input v-model="form.middle_name" autocomplete="additional-name" /></label>
                  <label>Last name <input v-model="form.last_name" autocomplete="family-name" @blur="touch('last_name')" /><small v-if="showFieldError('last_name')">{{ fieldError('last_name') }}</small></label>
                  <label>Birth date <input v-model="form.birth_date" type="date" autocomplete="bday" @blur="touch('birth_date')" /><small v-if="age !== null && !fieldError('birth_date')" class="hint">Age: {{ age }}</small><small v-if="showFieldError('birth_date')">{{ fieldError('birth_date') }}</small></label>
                  <label class="wide">Gender <select v-model="form.gender" @blur="touch('gender')"><option disabled value="">Select an option</option><option>Male</option><option>Female</option><option>Prefer not to say</option></select><small v-if="showFieldError('gender')">{{ fieldError('gender') }}</small></label>
                </div>
              </section>

              <section v-else-if="currentStep === 2" class="step-panel">
                <div class="step-heading"><span>02</span><div><h3>Address & contact</h3><p>Provide a current way for the school to reach you.</p></div></div>
                <div class="field-grid two">
                  <label class="wide">House no. and street <input v-model="form.address_line" autocomplete="address-line1" @blur="touch('address_line')" /><small v-if="showFieldError('address_line')">{{ fieldError('address_line') }}</small></label>
                  <label>Barangay <input v-model="form.barangay" autocomplete="address-line2" @blur="touch('barangay')" /><small v-if="showFieldError('barangay')">{{ fieldError('barangay') }}</small></label>
                  <label>City / municipality <input v-model="form.city_municipality" autocomplete="address-level2" @blur="touch('city_municipality')" /><small v-if="showFieldError('city_municipality')">{{ fieldError('city_municipality') }}</small></label>
                  <label>Province <input v-model="form.province" autocomplete="address-level1" @blur="touch('province')" /><small v-if="showFieldError('province')">{{ fieldError('province') }}</small></label>
                  <label>Contact number <input v-model="form.contact_number" type="tel" autocomplete="tel" placeholder="e.g. 0917 123 4567" @blur="touch('contact_number')" /><small v-if="showFieldError('contact_number')">{{ fieldError('contact_number') }}</small></label>
                </div>
                <p class="schema-note">These address parts are saved together in the portal’s existing address field.</p>
              </section>

              <section v-else-if="currentStep === 3" class="step-panel">
                <div class="step-heading"><span>03</span><div><h3>Verify your email</h3><p>We’ll send a six-digit, one-time code to your inbox.</p></div></div>
                <div class="email-box">
                  <label>Email address <input v-model="form.email" type="email" autocomplete="email" :disabled="Boolean(form.email_verification_token)" @blur="touch('email')" /><small v-if="showFieldError('email')">{{ fieldError('email') }}</small></label>
                  <button v-if="!form.email_verification_token" class="secondary-button" type="button" :disabled="Boolean(fieldRules.email()) || isSendingCode || cooldown > 0" @click="sendCode">
                    {{ isSendingCode ? 'Sending…' : cooldown > 0 ? `Resend in ${cooldown}s` : 'Send verification code' }}
                  </button>
                  <div v-if="!form.email_verification_token" class="code-row">
                    <label>Verification code <input v-model="form.verification_code" inputmode="numeric" maxlength="6" placeholder="000000" /></label>
                    <button class="primary-button" type="button" :disabled="!/^\d{6}$/.test(form.verification_code) || isVerifyingCode" @click="verifyCode">{{ isVerifyingCode ? 'Verifying…' : 'Verify' }}</button>
                  </div>
                  <p v-if="emailMessage" class="alert success" role="status">{{ emailMessage }}</p>
                  <p v-if="emailError" class="alert error" role="alert">{{ emailError }}</p>
                </div>
              </section>

              <section v-else-if="currentStep === 4" class="step-panel">
                <div class="step-heading"><span>04</span><div><h3>Account setup</h3><p>Choose the credentials you will use to sign in.</p></div></div>
                <div class="field-grid">
                  <label>Username <input v-model="form.username" autocomplete="username" @blur="touch('username')" /><small v-if="showFieldError('username')">{{ fieldError('username') }}</small></label>
                  <label>Password <div class="password-wrap"><input v-model="form.password" :type="showPassword ? 'text' : 'password'" autocomplete="new-password" @blur="touch('password')" /><button type="button" @click="showPassword = !showPassword">{{ showPassword ? 'Hide' : 'Show' }}</button></div><small v-if="showFieldError('password')">{{ fieldError('password') }}</small></label>
                  <label>Confirm password <input v-model="form.password_confirmation" :type="showPassword ? 'text' : 'password'" autocomplete="new-password" @blur="touch('password_confirmation')" /><small v-if="showFieldError('password_confirmation')">{{ fieldError('password_confirmation') }}</small></label>
                  <label class="consent"><input v-model="form.terms_accepted" type="checkbox" @change="touch('terms_accepted')" /><span>I agree to the <a href="#/terms" target="_blank" rel="noopener">Terms & Conditions</a> and <a href="#/privacy" target="_blank" rel="noopener">Privacy Policy</a>.</span></label>
                  <small v-if="showFieldError('terms_accepted')" class="standalone-error">{{ fieldError('terms_accepted') }}</small>
                </div>
              </section>

              <section v-else class="step-panel review-panel">
                <div class="step-heading"><span>05</span><div><h3>Review your information</h3><p>Confirm these details before creating your Guest account.</p></div></div>
                <div class="review-grid">
                  <article><h4>Personal information</h4><dl><div><dt>Name</dt><dd>{{ [form.first_name, form.middle_name, form.last_name].filter(Boolean).join(' ') }}</dd></div><div><dt>Birth date</dt><dd>{{ form.birth_date }}<span v-if="age !== null"> (Age {{ age }})</span></dd></div><div><dt>Gender</dt><dd>{{ form.gender }}</dd></div></dl><button type="button" @click="currentStep = 1">Edit</button></article>
                  <article><h4>Contact information</h4><dl><div><dt>Address</dt><dd>{{ fullAddress }}</dd></div><div><dt>Contact</dt><dd>{{ form.contact_number }}</dd></div></dl><button type="button" @click="currentStep = 2">Edit</button></article>
                  <article><h4>Email & account</h4><dl><div><dt>Email</dt><dd>{{ form.email }} · Verified</dd></div><div><dt>Username</dt><dd>{{ form.username }}</dd></div><div><dt>Account type</dt><dd>Guest</dd></div></dl><button type="button" @click="currentStep = 4">Edit</button></article>
                </div>
                <p class="security-note">Your password is securely submitted and is never shown in this review.</p>
              </section>
            </div>
          </Transition>
        </div>

        <footer class="wizard-actions">
          <button class="cancel-button" type="button" @click="requestClose">Cancel</button>
          <div>
            <button v-if="currentStep > 1" class="back-button" type="button" @click="back">Back</button>
            <button v-if="currentStep < 5" class="primary-button" type="submit" :disabled="!currentFieldsAreValid">Next</button>
            <button v-else class="primary-button create-button" type="submit" :disabled="isSubmitting">{{ isSubmitting ? 'Creating account…' : 'Create account' }}</button>
          </div>
        </footer>
      </form>
    </section>
  </div>
</template>

<style scoped>
.modal-backdrop{position:fixed;inset:0;z-index:100;background:rgba(5,34,20,.72);backdrop-filter:blur(5px);display:grid;place-items:center;padding:18px;overflow-y:auto}.wizard{width:min(820px,100%);max-height:calc(100vh - 36px);overflow:hidden;background:#fff;border-radius:22px;box-shadow:0 30px 80px rgba(0,0,0,.32);display:flex;flex-direction:column}.wizard-header{display:flex;justify-content:space-between;gap:24px;padding:24px 28px 16px}.eyebrow{margin:0 0 5px;color:#197548;font-size:.74rem;font-weight:800;letter-spacing:.12em;text-transform:uppercase}.wizard-header h2{margin:0;color:#17251d;font-size:1.45rem}.wizard-header p:last-child{margin:6px 0 0;color:#6b756e;font-size:.88rem}.close-button{border:0;background:#edf3ef;border-radius:50%;width:38px;height:38px;color:#385146;font-size:1.6rem;line-height:1;cursor:pointer}.progress{display:grid;grid-template-columns:repeat(5,1fr);list-style:none;margin:0;padding:0 28px 20px}.progress li{position:relative;display:grid;justify-items:center;gap:5px;color:#8b948f;font-size:.7rem}.progress li:not(:last-child)::after{content:'';position:absolute;top:14px;left:calc(50% + 18px);right:calc(-50% + 18px);height:2px;background:#dfe6e1}.progress li.complete:not(:last-child)::after{background:#2a8356}.progress span{position:relative;z-index:1;display:grid;place-items:center;width:30px;height:30px;border:2px solid #dfe6e1;border-radius:50%;background:#fff;font-size:.75rem;font-weight:800}.progress .active span,.progress .complete span{border-color:#197548;background:#197548;color:#fff}.progress .active small{color:#155f3d;font-weight:800}.wizard form{min-height:0;display:flex;flex:1;flex-direction:column}.wizard-body{min-height:0;overflow-y:auto;border-top:1px solid #edf0ee;padding:25px 28px}.step-heading{display:flex;align-items:flex-start;gap:13px;margin-bottom:22px}.step-heading>span{display:grid;place-items:center;flex:0 0 42px;height:42px;border-radius:12px;background:#edf7f0;color:#197548;font-size:.78rem;font-weight:900}.step-heading h3{margin:0;font-size:1.14rem}.step-heading p{margin:5px 0 0;color:#6b756e;font-size:.9rem}.field-grid{display:grid;gap:17px}.field-grid.two{grid-template-columns:1fr 1fr}.field-grid .wide{grid-column:1/-1}label{display:block;color:#34463c;font-size:.84rem;font-weight:750}label em{float:right;color:#89948e;font-size:.76rem;font-style:normal;font-weight:500}input,select{width:100%;margin-top:7px;border:1px solid #d9e1dc;border-radius:9px;min-height:45px;padding:9px 12px;background:#fff;color:#1c2a22}input:disabled{background:#f3f7f4;color:#5c6961}label small,.standalone-error{display:block;margin-top:5px;color:#b42318;font-size:.76rem;font-weight:600}.hint{color:#66746c}.schema-note,.security-note{margin:18px 0 0;border-radius:9px;background:#f4f7f5;padding:11px 13px;color:#617067;font-size:.8rem;line-height:1.45}.email-box{border:1px solid #dfe8e2;border-radius:14px;background:#f9fbfa;padding:20px}.secondary-button,.primary-button,.back-button,.cancel-button{border:0;border-radius:9px;min-height:42px;padding:0 18px;font-weight:800;cursor:pointer}.secondary-button{margin-top:14px;border:1px solid #197548;background:#fff;color:#197548}.primary-button{background:#176c42;color:#fff}.primary-button:disabled,.secondary-button:disabled{cursor:not-allowed;opacity:.45;transform:none}.code-row{display:grid;grid-template-columns:1fr auto;align-items:end;gap:12px;margin-top:18px}.code-row input{font-size:1.2rem;letter-spacing:.28em}.alert{border-radius:9px;margin:14px 0 0;padding:11px 13px;font-size:.84rem;line-height:1.45}.alert.success{background:#eaf7ee;color:#17683e}.alert.error{background:#fff0ef;color:#a5261e}.password-wrap{position:relative}.password-wrap input{padding-right:66px}.password-wrap button{position:absolute;right:7px;top:13px;border:0;background:transparent;color:#176c42;font-size:.8rem;font-weight:800;cursor:pointer}.consent{display:flex;align-items:flex-start;gap:10px;line-height:1.5;font-weight:500}.consent input{flex:0 0 auto;width:17px;min-height:17px;margin-top:3px}.consent a{color:#176c42;font-weight:800;text-decoration:underline}.review-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}.review-grid article{position:relative;border:1px solid #dfe6e1;border-radius:13px;padding:17px}.review-grid article:last-child{grid-column:1/-1}.review-grid h4{margin:0 0 12px;color:#176c42;font-size:.94rem}.review-grid dl,.review-grid dd{margin:0}.review-grid dl div{margin-top:10px}.review-grid dt{color:#7a857f;font-size:.7rem;font-weight:800;text-transform:uppercase}.review-grid dd{margin-top:2px;color:#25372d;font-size:.86rem;line-height:1.45}.review-grid article>button{position:absolute;right:13px;top:13px;border:0;background:transparent;color:#176c42;font-size:.75rem;font-weight:800;cursor:pointer}.wizard-actions{display:flex;justify-content:space-between;align-items:center;gap:12px;border-top:1px solid #edf0ee;padding:16px 28px;background:#fbfcfb}.wizard-actions>div{display:flex;gap:9px}.cancel-button,.back-button{background:transparent;color:#5b6861}.back-button{border:1px solid #d7dfda}.create-button{min-width:155px}.step-enter-active,.step-leave-active{transition:opacity .16s ease,transform .16s ease}.step-enter-from{opacity:0;transform:translateX(8px)}.step-leave-to{opacity:0;transform:translateX(-8px)}
@media(max-width:640px){.modal-backdrop{padding:0;place-items:stretch}.wizard{max-height:100vh;height:100vh;border-radius:0}.wizard-header{padding:20px 18px 14px}.wizard-header h2{font-size:1.2rem}.progress{padding:0 12px 16px}.progress small{font-size:.58rem}.wizard-body{padding:22px 18px}.field-grid.two,.review-grid{grid-template-columns:1fr}.field-grid .wide,.review-grid article:last-child{grid-column:auto}.wizard-actions{padding:13px 16px}.cancel-button,.back-button,.primary-button{padding:0 13px}.code-row{grid-template-columns:1fr}.code-row .primary-button{width:100%}}
</style>
