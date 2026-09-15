<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { apiClient } from '../services/apiClient'
import { applyStudentAppearance } from '../composables/useStudentTheme'
import { useStudentProfileStore } from '../stores/studentProfile'
import { useAuthStore } from '../stores/authStore'

const studentProfile = useStudentProfileStore()
const studentUserId = useAuthStore().currentUser?.id

const sections = ['Profile', 'Account', 'Student Status', 'Document Status', 'Contact Information', 'Notifications', 'Academic Preferences', 'Appearance', 'Security']
const active = ref('Profile'), open = ref(false), data = ref(null), error = ref(''), success = ref(''), loading = ref(true), saving = ref(false), saved = ref('')
const form = ref({ preferred_display_name: '', bio: '', email: '', contact_number: '', address: '', notification_preferences: {}, academic_preferences: {}, appearance: 'system', current_password: '', password: '', password_confirmation: '' })
const editable = ['Profile', 'Contact Information', 'Notifications', 'Academic Preferences', 'Appearance']
const dirty = computed(() => editable.includes(active.value) && saved.value !== JSON.stringify(form.value))
const status = computed(() => data.value?.student_status || {})
const avatarInput = ref(null), avatarFailed = ref(false)
const avatarUrl = computed(() => studentProfile.avatarUrl)
watch(() => data.value?.profile, profile => studentProfile.setProfile(profile, studentUserId), { deep: true, flush: 'sync' })
const initials = computed(() => {
  const preferredName = form.value.preferred_display_name
  const name = (typeof preferredName === 'string' ? preferredName.trim() : '') || data.value?.official?.legal_name || 'Student'
  const parts = name.trim().split(/\s+/)
  return [parts[0], ...(parts.length > 1 ? [parts.at(-1)] : [])].map(part => Array.from(part)[0]).join('').toUpperCase()
})
const appearances = [
  { value: 'light', label: 'Light', description: 'A bright appearance' },
  { value: 'dark', label: 'Dark', description: 'A darker appearance' },
  { value: 'system', label: 'System', description: 'Match your device' },
]
watch(avatarUrl, () => { avatarFailed.value = false })
const messageFor = (err, fallback) => {
  const response = err?.response?.data
  const fields = Object.values(response?.errors || {}).flat().filter(message => typeof message === 'string').join(' ')
  return fields || response?.message || fallback
}
const applyAppearance = () => applyStudentAppearance(form.value.appearance)
const load = async () => {
  loading.value = true
  error.value = ''
  try {
    data.value = (await apiClient.get('/student/settings')).data.data
    const settings = data.value
    const name = settings?.profile?.preferred_display_name
    Object.assign(form.value, {
      preferred_display_name: typeof name === 'string' ? name : '',
      bio: settings?.profile?.bio || '',
      email: settings?.contact?.personal_email || '',
      contact_number: settings?.contact?.mobile || '',
      address: settings?.contact?.address || '',
      notification_preferences: settings?.preferences?.notification_preferences || {},
      academic_preferences: settings?.preferences?.academic_preferences || {},
      appearance: settings?.appearance || 'system',
    })
    saved.value = JSON.stringify(form.value)
    applyAppearance()
  } catch (err) {
    error.value = messageFor(err, 'Unable to load settings.')
  } finally {
    loading.value = false
  }
}
const reset = () => { if (!saved.value) return; Object.assign(form.value, JSON.parse(saved.value)); applyAppearance(); error.value = '' }
const save = async (path, payload, message) => { saving.value = true; error.value = success.value = ''; try { data.value = (await apiClient.patch(path, payload)).data.data; saved.value = JSON.stringify(form.value); success.value = message } catch (err) { error.value = messageFor(err, 'Unable to save settings.') } finally { saving.value = false } }
const saveProfile = () => save('/student/settings/profile', { preferred_display_name: form.value.preferred_display_name, bio: form.value.bio }, 'Profile saved.')
const saveContact = () => save('/student/settings/contact', { email: form.value.email, contact_number: form.value.contact_number, address: form.value.address }, 'Contact information saved.')
const savePreferences = () => { applyAppearance(); return save('/student/settings/preferences', { notification_preferences: form.value.notification_preferences, academic_preferences: form.value.academic_preferences, appearance: form.value.appearance }, 'Preferences saved.') }
const saveActive = () => ({ Profile: saveProfile, 'Contact Information': saveContact, Notifications: savePreferences, 'Academic Preferences': savePreferences, Appearance: savePreferences }[active.value]?.())
const changePassword = async () => { saving.value = true; try { await apiClient.post('/change-password', { current_password: form.value.current_password, password: form.value.password, password_confirmation: form.value.password_confirmation }); success.value = 'Password changed.'; form.value.current_password = form.value.password = form.value.password_confirmation = '' } catch (err) { error.value = messageFor(err, 'Unable to change password.') } finally { saving.value = false } }
const upload = async (event) => {
  const file = event.target.files?.[0]
  if (!file) return
  const payload = new FormData()
  payload.append('avatar', file)
  saving.value = true
  error.value = success.value = ''
  try {
    data.value = (await apiClient.post('/student/settings/avatar', payload, { headers: { 'Content-Type': 'multipart/form-data' } })).data.data
    avatarFailed.value = false
    success.value = 'Profile picture updated.'
  } catch (err) {
    error.value = messageFor(err, 'Avatar upload failed.')
  } finally {
    event.target.value = ''
    saving.value = false
  }
}
const removeAvatar = async () => {
  saving.value = true
  error.value = success.value = ''
  try {
    await apiClient.delete('/student/settings/avatar')
    data.value.profile.avatar_url = null
    success.value = 'Profile picture removed.'
  } catch (err) {
    error.value = messageFor(err, 'Unable to remove profile picture.')
  } finally {
    saving.value = false
  }
}
onMounted(load)
</script>

<template>
  <main class="settings"><header><button class="menu" type="button" @click="open = true">Settings menu</button><div><p>Student Settings</p><h1>Your account and preferences</h1></div></header>
    <div class="layout"><aside :class="{ open }"><strong>Settings</strong><button v-for="section in sections" :key="section" :class="{ active: active === section }" @click="active = section; open = false">{{ section }}</button></aside><div v-if="open" class="backdrop" @click="open = false" />
      <section class="content"><p v-if="loading" class="muted">Loading settings...</p><p v-if="error" class="error" role="alert">{{ error }}</p><article v-if="!loading && data"><p v-if="success" class="toast" role="status">{{ success }}</p><div v-if="dirty" class="save-bar" role="status"><span>You have unsaved changes</span><div><button type="button" :disabled="saving" @click="reset">Reset</button><button type="button" :disabled="saving" @click="saveActive">{{ saving ? 'Saving...' : 'Save Changes' }}</button></div></div><h2>{{ active }}</h2>
        <template v-if="active === 'Profile'">
          <div class="profile-photo">
            <div class="avatar">
              <img v-if="avatarUrl && !avatarFailed" :key="avatarUrl" :src="avatarUrl" alt="Profile picture" @error="avatarFailed = true" />
              <span v-else class="avatar-initials" role="img" aria-label="Profile picture initials">{{ initials }}</span>
            </div>
            <div class="photo-controls">
              <strong>Profile picture</strong>
              <p id="avatar-help" class="muted">JPG, PNG or WebP, up to 2 MB.</p>
              <input ref="avatarInput" type="file" hidden aria-label="Choose profile picture" aria-describedby="avatar-help" accept="image/png,image/jpeg,image/webp" :disabled="saving" @change="upload" />
              <div class="photo-actions">
                <button class="photo-change" type="button" :disabled="saving" @click="avatarInput?.click()">Change Photo</button>
                <button v-if="avatarUrl" class="photo-remove" type="button" :disabled="saving" @click="removeAvatar">Remove</button>
              </div>
            </div>
          </div>
          <div class="profile-fields">
            <label>Preferred display name<input v-model="form.preferred_display_name" maxlength="120" autocomplete="nickname" /></label>
            <label>Bio<textarea v-model="form.bio" maxlength="1000" /></label>
          </div>
          <div class="official-profile">
            <h3>Official information</h3>
            <dl class="official-info"><div v-for="(value, key) in data.official" :key="key"><dt>{{ key.replaceAll('_', ' ') }}</dt><dd>{{ value || 'Not available' }}</dd></div></dl>
          </div>
        </template>
        <template v-else-if="active === 'Contact Information'"><label>Personal email<input v-model="form.email" type="email" /></label><label>Mobile<input v-model="form.contact_number" /></label><label>Address<textarea v-model="form.address" /></label></template>
        <template v-else-if="active === 'Notifications' || active === 'Academic Preferences'"><label v-for="key in active === 'Notifications' ? ['document_updates','appointment_reminders','academic_warnings','adviser_alerts','email','in_app'] : ['warning_notifications','study_plan_reminders','adviser_alerts','ai_suggestions']" :key="key" class="toggle"><input v-model="(active === 'Notifications' ? form.notification_preferences : form.academic_preferences)[key]" type="checkbox" /> {{ key.replaceAll('_', ' ') }}</label></template>
        <template v-else-if="active === 'Appearance'">
          <fieldset class="appearance-options">
            <legend>Choose a theme</legend>
            <div class="appearance-grid">
              <label v-for="option in appearances" :key="option.value" class="appearance-card" :class="{ selected: form.appearance === option.value }">
                <span class="theme-preview" :data-preview="option.value" aria-hidden="true"><span class="preview-sidebar" /><span class="preview-content"><span /><span /></span></span>
                <span class="appearance-choice"><input v-model="form.appearance" name="student-appearance" type="radio" :value="option.value" :aria-label="option.label" :aria-describedby="`theme-${option.value}-description`" @change="applyAppearance" /> {{ option.label }}</span>
                <span :id="`theme-${option.value}-description`" class="muted appearance-description">{{ option.description }}</span>
              </label>
            </div>
          </fieldset>
        </template>
        <template v-else-if="active === 'Document Status'"><progress :value="data.document_status.complete" :max="data.document_status.total || 1" /><p>{{ data.document_status.complete }} of {{ data.document_status.total }} documents verified</p><ul><li v-for="item in data.document_status.items" :key="item.name">{{ item.name }}: {{ item.status }}</li></ul><RouterLink to="/student-management">View documents</RouterLink></template>
        <template v-else-if="active === 'Security'"><label>Current password<input v-model="form.current_password" type="password" /></label><label>New password<input v-model="form.password" type="password" /></label><label>Confirm password<input v-model="form.password_confirmation" type="password" /></label><button :disabled="saving" @click="changePassword">{{ saving ? 'Saving...' : 'Change password' }}</button></template>
        <dl v-else><template v-for="(value, key) in active === 'Account' ? data.account : status" :key="key"><dt>{{ key.replaceAll('_', ' ') }}</dt><dd>{{ value || 'Not available' }}</dd></template></dl>
      </article></section>
    </div>
  </main>
</template>

<style scoped>
.settings {
  --settings-page: var(--student-page, #f7faf7);
  --settings-surface: var(--student-surface, #fffdf8);
  --settings-field: var(--student-field, #fff);
  --settings-text: var(--student-text, #213329);
  --settings-muted: var(--student-muted, #5e7064);
  --settings-border: var(--student-border, #cddbd0);
  --settings-accent: var(--student-accent, #155f39);
  --settings-selected: var(--student-selected, #e6f3e9);
  --settings-primary: var(--student-primary, #176c42);
  --settings-on-primary: var(--student-on-primary, #fff);
  --settings-focus: var(--student-focus, #71ac83);
  --settings-toggle: var(--student-toggle, #bdc9c0);
  --settings-save: var(--student-save, #f3faf4);
  --settings-success: var(--student-success, #edf7ef);
  --settings-danger: var(--student-danger, #a8291f);
  --settings-danger-bg: var(--student-danger-bg, #fff0ed);
  --settings-danger-border: var(--student-danger-border, #edc3bd);
  --settings-shadow: var(--student-shadow, 0 5px 18px #123a1a0d);
  --settings-color-scheme: var(--student-color-scheme, light);
  max-width: 850px;
  margin: auto;
  padding: 24px;
  border-radius: 16px;
  background: var(--settings-page);
  color: var(--settings-text);
  color-scheme: var(--settings-color-scheme);
}
.settings > header { display: flex; gap: 12px; align-items: center; }
.settings header p { margin: 0; color: var(--settings-accent); font-weight: 700; }
.settings h1 { margin: 4px 0; }
.layout { display: grid; grid-template-columns: 220px minmax(0, 1fr); gap: 18px; margin-top: 18px; }
.content { min-width: 0; }
aside, article { background: var(--settings-surface); border: 1px solid var(--settings-border); border-radius: 14px; box-shadow: var(--settings-shadow); }
aside { display: grid; align-content: start; padding: 12px; }
aside button { border: 0; background: transparent; color: var(--settings-text); text-align: left; padding: 10px; border-radius: 8px; transition: background 180ms ease; }
aside button.active, aside button:hover { background: var(--settings-selected); color: var(--settings-accent); font-weight: 800; }
article { display: grid; gap: 16px; padding: 22px; }
article h2, article h3 { margin: 0; }
article h3 { font-size: 1rem; }
label { display: grid; gap: 6px; min-width: 0; font-weight: 700; }
/* Keep Settings control sizing while sharing the Student palette. */
.settings .content input, .settings .content textarea {
  min-width: 0;
  width: 100%;
  border: 1px solid var(--settings-border);
  border-radius: 8px;
  padding: 10px;
  background: var(--settings-field);
  color: var(--settings-text);
}
.settings .content input:focus, .settings .content textarea:focus, button:focus-visible, a:focus-visible {
  border-color: var(--settings-accent);
  outline: 3px solid var(--settings-focus);
  outline-offset: 2px;
  box-shadow: none;
}
.settings textarea { min-height: 100px; resize: vertical; }
.settings input::placeholder, .settings textarea::placeholder, .muted, dt { color: var(--settings-muted); }
button:disabled { opacity: .6; }
.profile-photo { display: flex; align-items: center; gap: 18px; flex-wrap: wrap; }
.avatar { display: grid; place-items: center; flex: 0 0 88px; width: 88px; height: 88px; overflow: hidden; border: 1px solid var(--settings-border); border-radius: 50%; background: var(--settings-selected); color: var(--settings-accent); }
.avatar img { display: block; width: 100%; height: 100%; object-fit: cover; }
.avatar-initials { font-size: 1.75rem; font-weight: 800; }
.photo-controls { display: grid; gap: 7px; }
.photo-controls p { margin: 0; font-size: .8rem; }
.photo-actions { display: flex; flex-wrap: wrap; gap: 8px; }
.photo-actions button { width: auto; padding: 7px 11px; border: 1px solid var(--settings-border); border-radius: 8px; font-size: .85rem; font-weight: 700; }
.photo-change { background: var(--settings-field); color: var(--settings-accent); }
.photo-actions .photo-remove { border-color: var(--settings-danger-border); background: var(--settings-danger-bg); color: var(--settings-danger); }
.profile-fields { display: grid; gap: 16px; }
.official-profile { padding-top: 16px; border-top: 1px solid var(--settings-border); }
.official-info { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 12px 18px; margin: 12px 0 0; }
.official-info dt { margin: 0; font-size: .8rem; }
dt { text-transform: capitalize; margin-top: 10px; }
dd { margin: 3px 0; font-weight: 700; overflow-wrap: anywhere; }
.toggle { display: flex; align-items: center; justify-content: space-between; gap: 12px; min-height: 44px; padding: 10px; border: 1px solid var(--settings-border); border-radius: 9px; background: var(--settings-field); text-transform: capitalize; }
.settings .toggle input { appearance: none; flex: 0 0 44px; width: 44px; min-height: 24px; height: 24px; margin: 0; padding: 0; border-radius: 999px; background: var(--settings-toggle); border: 0; cursor: pointer; position: relative; transition: background 180ms ease; }
.toggle input::after { content: ''; position: absolute; top: 3px; left: 3px; width: 18px; height: 18px; border-radius: 50%; background: var(--settings-on-primary); box-shadow: 0 1px 3px #0004; transition: transform 180ms ease; }
.settings .toggle input:checked { background: var(--settings-primary); }
.toggle input:checked::after { transform: translateX(20px); }
.appearance-options { min-width: 0; margin: 0; padding: 0; border: 0; }
.appearance-options legend { padding: 0; margin-bottom: 12px; color: var(--settings-muted); }
.appearance-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(110px, 1fr)); gap: 10px; }
.appearance-card { align-content: start; gap: 10px; padding: 12px; border: 1px solid var(--settings-border); border-radius: 10px; background: var(--settings-field); cursor: pointer; transition: border-color 180ms ease, background 180ms ease; }
.appearance-card:hover { border-color: var(--settings-accent); }
.appearance-card.selected { border-color: var(--settings-accent); background: var(--settings-selected); box-shadow: inset 0 0 0 1px var(--settings-accent); }
.appearance-card:focus-within { outline: 3px solid var(--settings-focus); outline-offset: 2px; }
.appearance-choice { display: flex; align-items: center; gap: 7px; font-size: .9rem; }
.settings .appearance-choice input { flex: 0 0 16px; width: 16px; height: 16px; min-height: 16px; margin: 0; padding: 0; accent-color: var(--settings-accent); }
.appearance-description { font-size: .75rem; line-height: 1.4; font-weight: 400; }
/* Theme previews intentionally show the option's palette in either active theme. */
.theme-preview { display: flex; height: 60px; overflow: hidden; border: 1px solid var(--settings-border); border-radius: 6px; background: #f7faf7; }
.preview-sidebar { width: 26%; border-right: 1px solid #cddbd0; background: #e6f3e9; }
.preview-content { display: grid; align-content: center; flex: 1; gap: 7px; padding: 9px; }
.preview-content > span { height: 7px; border-radius: 3px; background: #cddbd0; }
.preview-content > span:last-child { width: 65%; }
[data-preview='dark'] { background: #17221b; }
[data-preview='dark'] .preview-sidebar { background: #294434; border-color: #587061; }
[data-preview='dark'] .preview-content > span { background: #587061; }
[data-preview='system'] { background: linear-gradient(90deg, #f7faf7 50%, #17221b 50%); }
[data-preview='system'] .preview-content > span { background: #90a797; }
.toast, .error { margin: 0; padding: 12px; border-radius: 8px; border: 1px solid var(--settings-border); }
.toast { background: var(--settings-success); color: var(--settings-accent); }
.error { margin-bottom: 12px; border-color: var(--settings-danger-border); background: var(--settings-danger-bg); color: var(--settings-danger); }
.save-bar { position: sticky; bottom: 12px; z-index: 2; display: flex; flex-wrap: wrap; justify-content: space-between; gap: 12px; align-items: center; padding: 12px 14px; border-radius: 12px; color: var(--settings-text); background: var(--settings-save); border: 1px solid var(--settings-border); box-shadow: var(--settings-shadow); }
.save-bar div { display: flex; flex-wrap: wrap; gap: 8px; }
.save-bar button, article > button, .menu { border: 1px solid transparent; border-radius: 7px; background: var(--settings-primary); color: var(--settings-on-primary); padding: 10px 14px; font-weight: 800; }
.save-bar button:first-child { background: var(--settings-field); color: var(--settings-accent); border-color: var(--settings-border); }
article > button { justify-self: start; padding: 7px 10px; font-size: .85rem; }
.menu { display: none; }
@media (max-width: 720px) {
  .settings { padding: 16px; }
  .layout { display: block; }
  .menu { display: block; }
  article { padding: 16px; }
  aside { position: fixed; inset: 0 auto 0 0; width: min(290px, 82vw); z-index: 50; transform: translateX(-105%); transition: transform 180ms ease; }
  aside.open { transform: translateX(0); }
  .backdrop { position: fixed; inset: 0; background: #10261a66; z-index: 40; }
  .save-bar { align-items: stretch; flex-direction: column; }
  .save-bar div { display: grid; grid-template-columns: 1fr 1fr; }
}
@media (prefers-reduced-motion: reduce) {
  *, *::before, *::after { transition: none !important; }
}
</style>
