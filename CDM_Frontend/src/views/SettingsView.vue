<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { apiClient } from '../services/apiClient'
import { clientPlatform } from '../config/clientPlatform'
import { ROLES } from '../config/accessControl'
import { applyPortalAppearance, previewPortalAppearance, storedPortalAppearance } from '../composables/useStudentTheme'
import { useStudentProfileStore } from '../stores/studentProfile'
import { useAuthStore } from '../stores/authStore'
import { getProfessorDashboard } from '../modules/dashboard/dashboardService'
import AcademicPageHeader from '../components/AcademicPageHeader.vue'
import WebsiteModuleHeader from '../components/website/WebsiteModuleHeader.vue'
const isWebsitePresentation=clientPlatform==='web'

const props = defineProps({ staffMode: { type: Boolean, default: false } })
const authStore = useAuthStore()
const studentProfile = useStudentProfileStore()
const studentUserId = computed(() => authStore.currentUser?.id)
const isProfessor = computed(() => !props.staffMode && authStore.currentRole === ROLES.PROFESSOR)
const isStudent = computed(() => !props.staffMode && authStore.currentRole === ROLES.STUDENT)
const accountId = computed(() => authStore.currentUser?.id || authStore.currentUser?.username)

const sections = computed(() => {
  if (props.staffMode) return ['Profile', 'Account', 'Contact Information', 'Appearance', 'Security']
  if (isProfessor.value) return ['Profile', 'Account', 'Appearance', 'Security']
  return ['Profile', 'Account', 'Student Status', 'Record Requirements', 'Document Status', 'Contact Information', 'Notifications', 'Appearance', 'Security']
})
const active = ref('Profile'), open = ref(false), data = ref(null), error = ref(''), success = ref(''), loading = ref(true), saving = ref(false), saved = ref('')
const form = ref({ first_name: '', middle_name: '', last_name: '', suffix: '', preferred_display_name: '', bio: '', email: '', contact_number: '', address: '', notification_preferences: {}, appearance: 'system', current_password: '', password: '', password_confirmation: '' })
const editableSections = computed(() => isProfessor.value ? ['Appearance'] : ['Profile', 'Contact Information', 'Appearance', ...(!props.staffMode ? ['Notifications'] : [])])
const dirty = computed(() => editableSections.value.includes(active.value) && saved.value !== JSON.stringify(form.value))
const status = computed(() => data.value?.student_status || {})
const avatarInput = ref(null), avatarFailed = ref(false)
const requirementSaving = ref(null)
const avatarUrl = computed(() => isStudent.value || isProfessor.value ? studentProfile.websiteAvatarUrl : data.value?.profile?.avatar_url)
watch(() => data.value?.profile, profile => { if (isStudent.value) studentProfile.setProfile(profile, studentUserId.value) }, { deep: true, flush: 'sync' })
const initials = computed(() => {
  const preferredName = form.value.preferred_display_name
  const staffName = [form.value.first_name, form.value.last_name].filter(Boolean).join(' ')
  const name = ((props.staffMode || isProfessor.value) ? staffName : (typeof preferredName === 'string' ? preferredName.trim() : '')) || data.value?.official?.legal_name || 'User'
  const parts = name.trim().split(/\s+/)
  return [parts[0], ...(parts.length > 1 ? [parts.at(-1)] : [])].map(part => Array.from(part)[0]).join('').toUpperCase()
})
const displayName = computed(() => {
  const preferred = typeof form.value.preferred_display_name === 'string' ? form.value.preferred_display_name.trim() : ''
  const profileName = [form.value.first_name, form.value.middle_name, form.value.last_name, form.value.suffix].filter(Boolean).join(' ')
  return preferred || profileName || data.value?.official?.legal_name || data.value?.official?.full_name || authStore.currentUser?.username || 'Portal user'
})
const settingsRole = computed(() => isProfessor.value ? 'Professor' : props.staffMode ? authStore.currentRole : 'Student')
const sectionMeta = Object.freeze({
  Profile: { group: 'Personal', description: 'Photo, display name, biography, and official identity.', icon: 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm-7 8a7 7 0 0 1 14 0' },
  Account: { group: 'Personal', description: 'Username, role, status, and account activity.', icon: 'M5 4h14v16H5zM8 8h8M8 12h5' },
  'Student Status': { group: 'Academic', description: 'Current official academic standing and program details.', icon: 'm4 7 8-4 8 4-8 4-8-4Zm3 3v6c3 2 7 2 10 0v-6' },
  'Record Requirements': { group: 'Academic', description: 'Digital and physical Student Record requirements.', icon: 'M6 3h9l3 3v15H6zM9 11h6M9 15h6' },
  'Document Status': { group: 'Academic', description: 'Verification progress for submitted documents.', icon: 'M5 4h14v16H5zM8 9h8M8 13h5m-5 4h3' },
  'Contact Information': { group: 'Personal', description: 'Email, mobile number, and current address.', icon: 'M4 5h16v14H4zM4 7l8 6 8-6' },
  Notifications: { group: 'Preferences', description: 'Choose the updates you want CDM Portal to send.', icon: 'M18 9a6 6 0 0 0-12 0c0 6-3 6-3 8h18c0-2-3-2-3-8M10 21h4' },
  Appearance: { group: 'Preferences', description: 'Choose Light, Dark, or your device appearance.', icon: 'M12 3a9 9 0 1 0 9 9 7 7 0 0 1-9-9Z' },
  Security: { group: 'Account', description: 'Update your password using the existing secure workflow.', icon: 'M6 10h12v11H6zM9 10V7a3 3 0 0 1 6 0v3' },
})
const sectionOptions = computed(() => sections.value.map(label => ({ label, ...sectionMeta[label] })))
const activeMeta = computed(() => sectionMeta[active.value] || { group: 'Settings', description: 'Manage this account setting.' })
const meaningfulEntries = source => Object.entries(source || {}).filter(([, value]) => value !== null && value !== undefined && value !== '')
const officialEntries = computed(() => meaningfulEntries(data.value?.official))
const accountEntries = computed(() => meaningfulEntries(data.value?.account))
const statusEntries = computed(() => meaningfulEntries(status.value))
const contactEntries = computed(() => meaningfulEntries(data.value?.contact))
const preferenceDescriptions = Object.freeze({
  document_updates: 'Changes to document-request progress and verification.', appointment_reminders: 'Reminders for scheduled Registrar appointments.', email: 'Deliver supported notices to your account email.', in_app: 'Show supported notices inside CDM Portal.',
})
const appearances = [
  { value: 'light', label: 'Light', description: 'A bright appearance' },
  { value: 'dark', label: 'Dark', description: 'A darker appearance' },
  { value: 'system', label: 'System', description: 'Match your device' },
]
const avatarMaxBytes = 2 * 1024 * 1024
const optimizeAvatar = async (file) => {
  if (file.size <= avatarMaxBytes) return file
  const bitmap = await createImageBitmap(file)
  const scale = Math.min(1, 1600 / Math.max(bitmap.width, bitmap.height))
  const canvas = document.createElement('canvas')
  canvas.width = Math.max(1, Math.round(bitmap.width * scale))
  canvas.height = Math.max(1, Math.round(bitmap.height * scale))
  const context = canvas.getContext('2d')
  context.fillStyle = '#fff'
  context.fillRect(0, 0, canvas.width, canvas.height)
  context.drawImage(bitmap, 0, 0, canvas.width, canvas.height)
  bitmap.close()
  const type = file.type === 'image/webp' ? 'image/webp' : 'image/jpeg'
  const extension = type === 'image/webp' ? 'webp' : 'jpg'
  for (const quality of [0.86, 0.74, 0.62, 0.5]) {
    const blob = await new Promise(resolve => canvas.toBlob(resolve, type, quality))
    if (blob?.size && blob.size <= avatarMaxBytes) {
      return new File([blob], `${file.name.replace(/\.[^.]+$/, '')}.${extension}`, { type, lastModified: file.lastModified })
    }
  }
  throw new Error('AVATAR_TOO_LARGE')
}
watch(avatarUrl, () => { avatarFailed.value = false })
const messageFor = (err, fallback) => {
  const response = err?.response?.data
  const fields = Object.values(response?.errors || {}).flat().filter(message => typeof message === 'string').join(' ')
  return fields || response?.message || fallback
}
const applyAppearance = () => previewPortalAppearance(form.value.appearance)
const load = async () => {
  loading.value = true
  error.value = ''
  try {
    let settings
    if (isProfessor.value) {
      const [user, dashboard] = await Promise.all([authStore.fetchCurrentUser(), getProfessorDashboard()])
      const profile = user?.profile || {}
      settings = {
        profile: { ...profile, avatar_url: profile.profile_photo_url },
        official: {
          faculty_id: dashboard?.identity?.faculty_id,
          department: dashboard?.identity?.department,
          full_name: dashboard?.identity?.name || [profile.first_name, profile.middle_name, profile.last_name, profile.suffix].filter(Boolean).join(' '),
        },
        account: { username: user?.username, role: ROLES.PROFESSOR, account_status: user?.status, last_login: user?.last_login },
        contact: { personal_email: profile.email, mobile: profile.contact_number, address: profile.address },
      }
    } else {
      settings = (await apiClient.get(props.staffMode ? '/registrar/settings' : '/student/settings')).data.data
    }
    data.value = settings
    const name = settings?.profile?.preferred_display_name
    Object.assign(form.value, {
      preferred_display_name: typeof name === 'string' ? name : '',
      bio: settings?.profile?.bio || '',
      first_name: settings?.profile?.first_name || '',
      middle_name: settings?.profile?.middle_name || '',
      last_name: settings?.profile?.last_name || '',
      suffix: settings?.profile?.suffix || '',
      email: settings?.contact?.personal_email || '',
      contact_number: settings?.contact?.mobile || '',
      address: settings?.contact?.address || '',
      notification_preferences: settings?.preferences?.notification_preferences || {},
      appearance: (props.staffMode || isProfessor.value) ? storedPortalAppearance(authStore.currentRole, accountId.value) : (settings?.appearance || 'system'),
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
const saveProfile = () => save(props.staffMode ? '/registrar/settings/profile' : '/student/settings/profile', props.staffMode
  ? { first_name: form.value.first_name, middle_name: form.value.middle_name, last_name: form.value.last_name, suffix: form.value.suffix }
  : { preferred_display_name: form.value.preferred_display_name, bio: form.value.bio }, 'Profile saved.')
const saveContact = () => save(props.staffMode ? '/registrar/settings/contact' : '/student/settings/contact', { email: form.value.email, contact_number: form.value.contact_number, address: form.value.address }, 'Contact information saved.')
const savePreferences = async () => {
  if (props.staffMode || isProfessor.value) {
    applyPortalAppearance(form.value.appearance, authStore.currentRole, accountId.value)
    saved.value = JSON.stringify(form.value)
    error.value = ''
    success.value = 'Appearance saved.'
    return
  }
  saving.value = true
  error.value = success.value = ''
  try {
    data.value = (await apiClient.patch('/student/settings/preferences', { notification_preferences: form.value.notification_preferences, appearance: form.value.appearance })).data.data
    applyPortalAppearance(form.value.appearance, authStore.currentRole, accountId.value)
    saved.value = JSON.stringify(form.value)
    success.value = 'Preferences saved.'
  } catch (err) {
    error.value = messageFor(err, 'Unable to save settings.')
  } finally {
    saving.value = false
  }
}
const saveActive = () => ({ Profile: saveProfile, 'Contact Information': saveContact, Notifications: savePreferences, Appearance: savePreferences }[active.value]?.())
const changePassword = async () => { saving.value = true; error.value = success.value = ''; try { await apiClient.post('/change-password', { current_password: form.value.current_password, password: form.value.password, password_confirmation: form.value.password_confirmation }); success.value = 'Password changed.'; form.value.current_password = form.value.password = form.value.password_confirmation = '' } catch (err) { error.value = messageFor(err, 'Unable to change password.') } finally { saving.value = false } }
const upload = async (event) => {
  const file = event.target.files?.[0]
  if (!file) return
  if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) {
    error.value = 'Choose a JPG, PNG, or WebP image.'
    event.target.value = ''
    return
  }
  saving.value = true
  error.value = success.value = ''
  try {
    const preparedFile = await optimizeAvatar(file)
    const payload = new FormData()
    payload.append('avatar', preparedFile)
    // Override the API client's JSON default; Axios then lets WebView add the multipart boundary.
    data.value = (await apiClient.post(props.staffMode ? '/registrar/settings/avatar' : '/student/settings/avatar', payload, { headers: { 'Content-Type': 'multipart/form-data' } })).data.data
    avatarFailed.value = false
    success.value = 'Profile picture updated.'
  } catch (err) {
    error.value = err?.message === 'AVATAR_TOO_LARGE'
      ? 'This photo could not be reduced below the 2 MB profile-picture limit. Choose a smaller image.'
      : messageFor(err, 'Avatar upload failed.')
  } finally {
    event.target.value = ''
    saving.value = false
  }
}
const removeAvatar = async () => {
  saving.value = true
  error.value = success.value = ''
  try {
    await apiClient.delete(props.staffMode ? '/registrar/settings/avatar' : '/student/settings/avatar')
    data.value.profile.avatar_url = null
    success.value = 'Profile picture removed.'
  } catch (err) {
    error.value = messageFor(err, 'Unable to remove profile picture.')
  } finally {
    saving.value = false
  }
}
const requirementLabel = (value) => ({
  missing: 'Missing', submitted: 'Submitted', under_review: 'Under Review', verified: 'Verified',
  needs_resubmission: 'Needs Resubmission', not_required: 'Not Required', required: 'Required',
  submitted_physical: 'Received',
}[value] || String(value || '').replaceAll('_', ' '))
const uploadRequirement = async (requirement, event) => {
  const file = event.target.files?.[0]
  if (!file) return
  const payload = new FormData()
  payload.append('file', file)
  requirementSaving.value = requirement.document_type_id
  error.value = success.value = ''
  try {
    const response = await apiClient.post(`/student/settings/record-requirements/${requirement.document_type_id}/upload`, payload, { headers: { 'Content-Type': 'multipart/form-data' } })
    const items = data.value.record_requirements.items
    const index = items.findIndex(item => item.document_type_id === requirement.document_type_id)
    items[index] = response.data.data
    data.value.record_requirements.unresolved_count = items.filter(item => item.unresolved).length
    data.value.record_requirements.complete = data.value.record_requirements.unresolved_count === 0
    success.value = response.data.message
  } catch (err) {
    error.value = messageFor(err, 'Unable to submit this document.')
  } finally {
    event.target.value = ''
    requirementSaving.value = null
  }
}
onMounted(load)
</script>

<template>
  <main class="settings" :class="{ 'website-settings-workspace': isWebsitePresentation }">
    <WebsiteModuleHeader v-if="isWebsitePresentation" :eyebrow="isProfessor ? 'Professor Settings' : 'Student Settings'" title="Settings workspace" :description="isProfessor ? 'Review your faculty identity, secure your account, and choose your website appearance.' : 'Manage your profile, academic preferences, records, notifications, and account security.'" compact />
    <AcademicPageHeader v-else :eyebrow="staffMode ? 'Registrar Settings' : 'Student Settings'" title="Your account and preferences" variant="settings"><template #leading><button class="menu" type="button" @click="open = true">Settings menu</button></template></AcademicPageHeader>

    <section v-if="isWebsitePresentation" class="settings-profile-banner" aria-label="Current account">
      <div class="avatar">
        <img v-if="avatarUrl && !avatarFailed" :key="avatarUrl" :src="avatarUrl" alt="Profile picture" @error="avatarFailed = true" />
        <span v-else class="avatar-initials" role="img" aria-label="Profile picture initials">{{ initials }}</span>
      </div>
      <div class="settings-banner-copy">
        <span>{{ settingsRole }} account</span>
        <h2>{{ displayName }}</h2>
        <p>Keep your CDM Portal identity, preferences, records, and security details organized in one place.</p>
      </div>
      <div v-if="active === 'Profile'" class="settings-banner-photo-actions">
        <template v-if="!isProfessor">
          <p id="website-avatar-help">JPG, PNG or WebP · Maximum 2 MB</p>
          <input ref="avatarInput" type="file" hidden aria-label="Choose profile picture" aria-describedby="website-avatar-help" accept="image/png,image/jpeg,image/webp" :disabled="saving" @change="upload" />
          <div class="photo-actions">
            <button class="photo-change" type="button" :disabled="saving" @click="avatarInput?.click()">Change Photo</button>
            <button v-if="avatarUrl" class="photo-remove" type="button" :disabled="saving" @click="removeAvatar">Remove</button>
          </div>
        </template>
        <p v-else>Faculty profile photos are managed by an authorized administrator.</p>
      </div>
    </section>

    <div class="layout">
      <aside :class="{ open }" :role="isWebsitePresentation ? 'navigation' : undefined" aria-label="Settings categories">
        <div v-if="isWebsitePresentation" class="settings-category-intro"><strong>Categories</strong><span>Choose an area to review or update.</span></div>
        <strong v-else>Settings</strong>
        <button
          v-for="option in sectionOptions"
          :key="option.label"
          type="button"
          :class="{ active: active === option.label }"
          :aria-current="isWebsitePresentation && active === option.label ? 'page' : undefined"
          @click="active = option.label; open = false"
        >
          <svg v-if="isWebsitePresentation" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path :d="option.icon" /></svg>
          <span><strong>{{ option.label }}</strong><small v-if="isWebsitePresentation">{{ option.group }}</small></span>
        </button>
      </aside>
      <div v-if="open && !isWebsitePresentation" class="backdrop" @click="open = false" />

      <section class="content" :aria-busy="loading">
        <div v-if="loading" class="settings-loading" role="status"><span aria-hidden="true"></span><p>Loading settings...</p></div>
        <p v-if="error" class="error" role="alert">{{ error }}</p>
        <article v-if="!loading && data" id="settings-category-panel">
          <p v-if="success" class="toast" role="status">{{ success }}</p>
          <div v-if="dirty" class="save-bar" role="status"><span>You have unsaved changes</span><div><button type="button" :disabled="saving" @click="reset">Reset</button><button type="button" :disabled="saving" @click="saveActive">{{ saving ? 'Saving...' : 'Save Changes' }}</button></div></div>
          <header class="settings-content-heading">
            <div><span>{{ activeMeta.group }}</span><h2>{{ active }}</h2><p>{{ activeMeta.description }}</p></div>
            <span v-if="['Account', 'Student Status'].includes(active) || (isProfessor && active === 'Profile')" class="readonly-badge">Official · Read only</span>
          </header>

        <template v-if="active === 'Profile'">
          <div v-if="!isWebsitePresentation" class="profile-photo">
            <div class="avatar">
              <img v-if="avatarUrl && !avatarFailed" :key="avatarUrl" :src="avatarUrl" alt="Profile picture" @error="avatarFailed = true" />
              <span v-else class="avatar-initials" role="img" aria-label="Profile picture initials">{{ initials }}</span>
            </div>
            <div class="photo-controls">
              <strong>Profile picture</strong>
              <p id="avatar-help" class="muted">{{ isProfessor ? 'Faculty profile photos are managed by an authorized administrator.' : 'JPG, PNG or WebP, up to 2 MB.' }}</p>
              <input v-if="!isProfessor" ref="avatarInput" type="file" hidden aria-label="Choose profile picture" aria-describedby="avatar-help" accept="image/png,image/jpeg,image/webp" :disabled="saving" @change="upload" />
              <div v-if="!isProfessor" class="photo-actions">
                <button class="photo-change" type="button" :disabled="saving" @click="avatarInput?.click()">Change Photo</button>
                <button v-if="avatarUrl" class="photo-remove" type="button" :disabled="saving" @click="removeAvatar">Remove</button>
              </div>
            </div>
          </div>
          <div class="profile-fields">
            <template v-if="staffMode">
              <label>First name<input v-model="form.first_name" maxlength="100" autocomplete="given-name" required /></label>
              <label>Middle name<input v-model="form.middle_name" maxlength="100" autocomplete="additional-name" /></label>
              <label>Last name<input v-model="form.last_name" maxlength="100" autocomplete="family-name" required /></label>
              <label>Suffix<input v-model="form.suffix" maxlength="20" /></label>
            </template>
            <template v-else-if="isProfessor">
              <dl v-if="contactEntries.length" class="official-info professor-profile-summary"><div v-for="([key, value]) in contactEntries" :key="key"><dt>{{ key.replaceAll('_', ' ') }}</dt><dd>{{ value }}</dd></div></dl>
              <p class="muted professor-readonly-note">Professor profile details are read-only here because no self-service faculty profile endpoint is available.</p>
            </template>
            <template v-else>
              <label><span class="field-label">Preferred display name <small>Optional</small></span><input v-model="form.preferred_display_name" maxlength="120" autocomplete="nickname" placeholder="How your name appears around CDM Portal" /></label>
              <label><span class="field-label">Bio <small>Optional</small></span><textarea v-model="form.bio" maxlength="1000" placeholder="Add a short introduction for your profile" /></label>
            </template>
          </div>
          <div class="official-profile">
            <div class="subsection-heading"><div><h3>Official information</h3><p>Verified institutional details cannot be edited here.</p></div><span aria-hidden="true">Read only</span></div>
            <dl class="official-info"><div v-for="([key, value]) in isWebsitePresentation ? officialEntries : Object.entries(data.official || {})" :key="key"><dt>{{ key.replaceAll('_', ' ') }}</dt><dd>{{ value || 'Not available' }}</dd></div></dl>
          </div>
        </template>
        <template v-else-if="active === 'Contact Information'">
          <div class="settings-form-grid"><label><span class="field-label">Personal email <small>Optional</small></span><input v-model="form.email" type="email" autocomplete="email" placeholder="name@example.com" /></label><label><span class="field-label">Mobile <small>Optional</small></span><input v-model="form.contact_number" inputmode="tel" autocomplete="tel" placeholder="Your current mobile number" /></label><label class="full-field"><span class="field-label">Address <small>Optional</small></span><textarea v-model="form.address" autocomplete="street-address" placeholder="Your current mailing address" /></label></div>
        </template>
        <template v-else-if="active === 'Notifications'">
          <div class="preference-grid"><label v-for="key in ['document_updates','appointment_reminders','email','in_app']" :key="key" class="toggle"><span><strong>{{ key.replaceAll('_', ' ') }}</strong><small>{{ preferenceDescriptions[key] }}</small></span><input v-model="form.notification_preferences[key]" type="checkbox" /></label></div>
        </template>
        <template v-else-if="active === 'Appearance'">
          <fieldset class="appearance-options">
            <legend>Choose a theme</legend>
            <div class="appearance-grid">
              <label v-for="option in appearances" :key="option.value" class="appearance-card" :class="{ selected: form.appearance === option.value }">
                <span class="theme-preview" :data-preview="option.value" aria-hidden="true"><span class="preview-sidebar" /><span class="preview-content"><span /><span /></span></span>
                <span class="appearance-choice"><input v-model="form.appearance" name="portal-appearance" type="radio" :value="option.value" :aria-label="option.label" :aria-describedby="`theme-${option.value}-description`" @change="applyAppearance" /> {{ option.label }}</span>
                <span :id="`theme-${option.value}-description`" class="muted appearance-description">{{ option.description }}</span>
              </label>
            </div>
          </fieldset>
        </template>
        <template v-else-if="active === 'Record Requirements'">
          <div class="requirements-heading"><p class="muted">Follow up the digital and physical copies required for your official Student Record.</p><strong>{{ data.record_requirements.unresolved_count }} unresolved</strong></div>
          <p v-if="data.record_requirements.complete" class="requirements-complete" role="status">Your Student Record requirements are complete.</p>
          <div v-else-if="!data.record_requirements.items.length" class="requirements-empty">No Student Record requirements are configured.</div>
          <div v-else class="requirement-list">
            <section v-for="item in data.record_requirements.items" :key="item.document_type_id" class="requirement-card">
              <h3>{{ item.name }}</h3>
              <div class="requirement-statuses">
                <div><span>Digital Copy</span><strong class="status-badge" :data-status="item.digital_status">{{ requirementLabel(item.digital_status) }}</strong></div>
                <div><span>Physical Copy</span><strong class="status-badge" :data-status="item.physical_status">{{ requirementLabel(item.physical_status === 'submitted' ? 'submitted_physical' : item.physical_status) }}</strong></div>
              </div>
              <div v-if="item.digital_status === 'needs_resubmission' && item.remark" class="requirement-note"><strong>Registrar remark</strong><p>{{ item.remark }}</p></div>
              <div v-if="item.physical_status === 'required'" class="requirement-note physical-notice"><strong>Physical Copy Required</strong><p>{{ item.physical_copy_instruction || 'Please submit the physical/original copy to the Registrar Office.' }}</p><small>Do not upload another file for this hard-copy requirement.</small></div>
              <p v-if="item.physical_status === 'submitted'" class="received-copy">Received {{ item.physical_copy_received_at ? new Date(item.physical_copy_received_at).toLocaleDateString('en-PH', { dateStyle: 'long' }) : '' }}</p>
              <label v-if="item.can_upload" class="requirement-upload"><span>{{ item.digital_status === 'needs_resubmission' ? 'Upload Replacement' : 'Upload Document' }}</span><input type="file" accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf" :disabled="requirementSaving === item.document_type_id" @change="uploadRequirement(item, $event)" /></label>
              <p v-else-if="['submitted', 'under_review'].includes(item.digital_status)" class="muted">Your digital copy is waiting for Registrar review.</p>
            </section>
          </div>
        </template>
        <template v-else-if="active === 'Document Status'"><progress :value="data.document_status.complete" :max="data.document_status.total || 1" /><p>{{ data.document_status.complete }} of {{ data.document_status.total }} documents verified</p><ul><li v-for="item in data.document_status.items" :key="item.name">{{ item.name }}: {{ item.status }}</li></ul><RouterLink to="/student-management">View documents</RouterLink></template>
        <template v-else-if="active === 'Security'">
          <div class="security-panel"><div class="security-guidance"><strong>Choose a strong password</strong><p>Use a password unique to CDM Portal. Existing authentication policies and validation still apply.</p></div><div class="security-fields"><label>Current password<input v-model="form.current_password" type="password" autocomplete="current-password" /></label><label>New password<input v-model="form.password" type="password" autocomplete="new-password" /></label><label>Confirm new password<input v-model="form.password_confirmation" type="password" autocomplete="new-password" /></label></div><button type="button" :disabled="saving" @click="changePassword">{{ saving ? 'Saving...' : 'Change password' }}</button></div>
        </template>
        <dl v-else class="summary-info"><div v-for="([key, value]) in active === 'Account' ? accountEntries : statusEntries" :key="key"><dt>{{ key.replaceAll('_', ' ') }}</dt><dd>{{ value }}</dd></div></dl>
        </article>
      </section>
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
  box-sizing: border-box;
  min-width: 0;
  width: 100%;
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
.content, .layout, .content article { max-width: 100%; min-width: 0; }
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
.requirements-heading { display: flex; justify-content: space-between; align-items: start; gap: 12px; }
.requirements-heading p { margin: 0; }
.requirements-heading > strong { flex: 0 0 auto; color: var(--settings-accent); }
.requirement-list { display: grid; gap: 14px; }
.requirement-card { display: grid; gap: 12px; padding: 16px; border: 1px solid var(--settings-border); border-radius: 12px; background: var(--settings-field); }
.requirement-statuses { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
.requirement-statuses > div { display: grid; gap: 6px; }
.requirement-statuses span { color: var(--settings-muted); font-size: .8rem; font-weight: 700; }
.status-badge { width: fit-content; padding: 4px 9px; border: 1px solid var(--settings-border); border-radius: 999px; font-size: .8rem; }
.status-badge[data-status="verified"], .status-badge[data-status="submitted"] { color: var(--settings-accent); background: var(--settings-success); }
.status-badge[data-status="required"], .status-badge[data-status="needs_resubmission"], .status-badge[data-status="missing"] { color: var(--settings-danger); background: var(--settings-danger-bg); border-color: var(--settings-danger-border); }
.requirement-note { padding: 11px; border-left: 3px solid var(--settings-danger); border-radius: 6px; background: var(--settings-danger-bg); }
.requirement-note p, .requirement-note small, .received-copy { margin: 4px 0 0; }
.requirement-upload { position: relative; display: inline-flex; width: fit-content; cursor: pointer; padding: 9px 13px; border-radius: 8px; background: var(--settings-primary); color: var(--settings-on-primary); }
.requirement-upload input { position: absolute; width: 1px !important; height: 1px; opacity: 0; }
.requirements-complete { padding: 14px; border: 1px solid var(--settings-border); border-radius: 10px; background: var(--settings-success); }
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

/* Website-only premium Settings workspace. The base rules above remain the
 * Android/Electron presentation and are overridden only by this web class. */
.settings.website-settings-workspace {
  max-width: 1280px;
  margin: 0 auto;
  padding: clamp(20px, 3vw, 38px);
  border-radius: 0;
  background: transparent;
}
.website-settings-workspace :deep(.website-module-header) { margin-bottom: 18px; }
.settings-profile-banner {
  position: relative;
  isolation: isolate;
  display: grid;
  grid-template-columns: auto minmax(0, 1fr) auto;
  align-items: center;
  gap: clamp(18px, 3vw, 30px);
  min-height: 154px;
  overflow: hidden;
  padding: clamp(22px, 3vw, 32px);
  border: 1px solid color-mix(in srgb, var(--website-emerald-bright) 24%, transparent);
  border-radius: 22px;
  background: linear-gradient(135deg, #064e3b 0%, #043728 54%, #042017 100%);
  color: #fff;
  box-shadow: 0 20px 45px rgb(4 32 23 / 16%);
}
.settings-profile-banner::after {
  content: '';
  position: absolute;
  z-index: -1;
  width: 260px;
  height: 260px;
  right: -70px;
  top: -125px;
  border-radius: 50%;
  background: radial-gradient(circle, rgb(16 185 129 / 26%), transparent 68%);
  pointer-events: none;
}
.website-settings-workspace .settings-profile-banner .avatar {
  flex-basis: 96px;
  width: 96px;
  height: 96px;
  border: 3px solid rgb(255 255 255 / 84%);
  background: #0f7650;
  color: #fff;
  box-shadow: 0 0 0 5px rgb(52 211 153 / 20%), 0 12px 28px rgb(0 0 0 / 24%);
}
.settings-banner-copy { min-width: 0; }
.settings-banner-copy > span { color: #a7f3d0; font-size: .76rem; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; }
.settings-banner-copy h2 { margin: 5px 0 7px; overflow-wrap: anywhere; color: #fff; font-size: clamp(1.4rem, 3vw, 2rem); letter-spacing: -.025em; }
.settings-banner-copy p { max-width: 620px; margin: 0; color: rgb(236 253 245 / 76%); font-size: .9rem; line-height: 1.55; }
.settings-banner-photo-actions { display: grid; max-width: 270px; justify-items: end; gap: 10px; text-align: right; }
.settings-banner-photo-actions > p { margin: 0; color: rgb(236 253 245 / 72%); font-size: .76rem; line-height: 1.45; }
.settings-banner-photo-actions .photo-actions { justify-content: flex-end; }
.settings-banner-photo-actions .photo-change { border-color: rgb(255 255 255 / 28%); background: rgb(255 255 255 / 10%); color: #fff; }
.settings-banner-photo-actions .photo-remove { border-color: rgb(254 202 202 / 38%); background: rgb(127 29 29 / 24%); color: #fecaca; }
.website-settings-workspace .layout { display: block; margin-top: 18px; }
.website-settings-workspace aside {
  position: static;
  display: grid;
  grid-template-columns: repeat(5, minmax(0, 1fr));
  gap: 8px;
  padding: 12px;
  border-radius: 18px;
  background: var(--website-surface-elevated);
  box-shadow: 0 10px 30px rgb(15 23 42 / 6%);
}
.settings-category-intro { grid-column: 1 / -1; display: flex; align-items: baseline; justify-content: space-between; gap: 18px; padding: 4px 5px 7px; }
.settings-category-intro strong { color: var(--website-ink); font-size: .9rem; }
.settings-category-intro span { color: var(--website-muted); font-size: .78rem; }
.website-settings-workspace aside button {
  display: grid;
  grid-template-columns: 34px minmax(0, 1fr);
  align-items: center;
  gap: 10px;
  min-height: 60px;
  padding: 9px 10px;
  border: 1px solid transparent;
  border-radius: 12px;
  background: transparent;
  color: var(--website-muted);
  transition: color 160ms ease, background 180ms ease, border-color 160ms ease, box-shadow 160ms ease;
}
.website-settings-workspace aside button svg { width: 20px; height: 20px; justify-self: center; }
.website-settings-workspace aside button > span { display: grid; min-width: 0; gap: 2px; }
.website-settings-workspace aside button strong { overflow: hidden; color: inherit; font-size: .78rem; text-overflow: ellipsis; white-space: nowrap; }
.website-settings-workspace aside button small { color: var(--website-muted); font-size: .66rem; font-weight: 650; }
.website-settings-workspace aside button:hover { border-color: color-mix(in srgb, var(--website-emerald) 24%, var(--website-border)); background: var(--website-hover); color: var(--website-deep-green); }
.website-settings-workspace aside button.active {
  border-color: color-mix(in srgb, var(--website-emerald) 38%, var(--website-border));
  background: color-mix(in srgb, var(--website-hover) 82%, var(--website-surface-elevated));
  color: var(--website-deep-green);
  box-shadow: inset 0 -3px var(--website-gold);
}
.website-settings-workspace .content { margin-top: 18px; }
.website-settings-workspace article {
  min-height: 410px;
  gap: 22px;
  padding: clamp(22px, 3.2vw, 38px);
  border-radius: 20px;
  background: var(--website-surface-elevated);
  box-shadow: 0 16px 42px rgb(15 23 42 / 7%);
}
.settings-content-heading { display: flex; align-items: flex-start; justify-content: space-between; gap: 24px; padding-bottom: 20px; border-bottom: 1px solid var(--settings-border); }
.settings-content-heading > div { min-width: 0; }
.settings-content-heading > div > span { color: var(--website-emerald); font-size: .72rem; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; }
.settings-content-heading h2 { margin: 4px 0 7px; color: var(--website-ink); font-size: clamp(1.45rem, 2.5vw, 1.9rem); letter-spacing: -.025em; }
.settings-content-heading p { max-width: 680px; margin: 0; color: var(--website-muted); font-size: .88rem; line-height: 1.55; }
.readonly-badge { flex: 0 0 auto; padding: 6px 9px; border: 1px solid var(--website-border); border-radius: 999px; background: var(--website-surface-soft); color: var(--website-muted); font-size: .68rem; font-weight: 760; }
.website-settings-workspace .profile-fields,.settings-form-grid,.security-fields { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 18px; }
.website-settings-workspace .profile-fields > label:last-child,.settings-form-grid .full-field { grid-column: 1 / -1; }
.field-label { display: flex; align-items: baseline; justify-content: space-between; gap: 12px; }
.field-label small { color: var(--website-muted); font-size: .7rem; font-weight: 550; }
.website-settings-workspace .content label { color: var(--website-ink); font-size: .82rem; }
.website-settings-workspace .content input:not([type='checkbox']):not([type='radio']):not([type='file']),.website-settings-workspace .content textarea {
  min-height: 46px;
  border-radius: 11px;
  padding: 11px 13px;
  line-height: 1.45;
}
.website-settings-workspace .content textarea { min-height: 116px; }
.subsection-heading { display: flex; align-items: flex-start; justify-content: space-between; gap: 18px; }
.subsection-heading h3 { color: var(--website-ink); }
.subsection-heading p { margin: 4px 0 0; color: var(--website-muted); font-size: .8rem; }
.subsection-heading > span { color: var(--website-muted); font-size: .72rem; }
.website-settings-workspace .official-info,.summary-info { grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; }
.website-settings-workspace .official-info > div,.summary-info > div {
  min-width: 0;
  padding: 15px 16px;
  border: 1px solid var(--website-border);
  border-radius: 13px;
  background: var(--website-surface-soft);
}
.website-settings-workspace .official-info dt,.summary-info dt { margin: 0 0 6px; color: var(--website-muted); font-size: .7rem; font-weight: 760; letter-spacing: .04em; text-transform: uppercase; }
.website-settings-workspace .official-info dd,.summary-info dd { margin: 0; color: var(--website-ink); font-size: .9rem; line-height: 1.45; overflow-wrap: break-word; }
.preference-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
.website-settings-workspace .toggle { min-height: 76px; padding: 14px; border-radius: 13px; transition: border-color 160ms ease, background 160ms ease; }
.website-settings-workspace .toggle:hover { border-color: color-mix(in srgb, var(--website-emerald) 35%, var(--website-border)); background: var(--website-hover); }
.website-settings-workspace .toggle > span { display: grid; gap: 4px; font-weight: 400; text-transform: none; }
.website-settings-workspace .toggle > span strong { color: var(--website-ink); font-size: .82rem; text-transform: capitalize; }
.website-settings-workspace .toggle > span small { color: var(--website-muted); font-size: .72rem; line-height: 1.4; }
.website-settings-workspace .appearance-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; }
.website-settings-workspace .appearance-card { padding: 16px; border-radius: 14px; transition: border-color 160ms ease, background 160ms ease, box-shadow 160ms ease; }
.website-settings-workspace .theme-preview { height: 92px; border-radius: 9px; }
.security-panel { display: grid; gap: 20px; }
.security-guidance { padding: 16px; border: 1px solid color-mix(in srgb, var(--website-gold) 42%, var(--website-border)); border-radius: 13px; background: color-mix(in srgb, var(--website-warning-bg) 72%, var(--website-surface-elevated)); }
.security-guidance strong { color: var(--website-ink); }
.security-guidance p { margin: 5px 0 0; color: var(--website-muted); font-size: .8rem; line-height: 1.5; }
.security-panel > button { justify-self: start; min-height: 44px; padding: 10px 16px; border: 0; border-radius: 10px; background: var(--settings-primary); color: var(--settings-on-primary); font-weight: 800; }
.settings-loading { display: grid; min-height: 310px; place-items: center; align-content: center; gap: 14px; border: 1px solid var(--website-border); border-radius: 20px; background: var(--website-surface-elevated); color: var(--website-muted); }
.settings-loading span { width: 38px; height: 38px; border: 3px solid var(--website-border); border-top-color: var(--website-emerald); border-radius: 50%; animation: settings-loading-spin .8s linear infinite; }
.settings-loading p { margin: 0; }
.website-settings-workspace .toast,.website-settings-workspace .error { animation: settings-feedback-in 180ms ease both; }
.website-settings-workspace progress { width: 100%; height: 10px; accent-color: var(--website-emerald); }
.website-settings-workspace article > ul { display: grid; gap: 8px; margin: 0; padding: 0; list-style: none; }
.website-settings-workspace article > ul li { padding: 11px 13px; border: 1px solid var(--website-border); border-radius: 10px; background: var(--website-surface-soft); }
.website-settings-workspace article > a { width: fit-content; color: var(--website-deep-green); font-weight: 780; }
@keyframes settings-loading-spin { to { transform: rotate(360deg); } }
@keyframes settings-feedback-in { from { opacity: 0; } to { opacity: 1; } }

@media (max-width: 720px) {
  .settings:not(.website-settings-workspace) { padding: 0; border-radius: 0; }
  .settings:not(.website-settings-workspace) .layout { display: block; }
  .settings:not(.website-settings-workspace) .menu { display: block; min-height: 44px; }
  .settings:not(.website-settings-workspace) article { gap: 13px; padding: 15px; }
  .settings:not(.website-settings-workspace) > header { padding-left: max(0px,env(safe-area-inset-left)); padding-right: max(0px,env(safe-area-inset-right)); }
  .settings:not(.website-settings-workspace) aside { position: fixed; inset: 0 auto 0 0; width: min(290px, 84vw); padding-top:calc(12px + env(safe-area-inset-top)); padding-bottom:calc(12px + env(safe-area-inset-bottom)); z-index: 50; transform: translateX(-105%); transition: transform 180ms ease; }
  .settings:not(.website-settings-workspace) aside.open { transform: translateX(0); }
  .settings:not(.website-settings-workspace) .backdrop { position: fixed; inset: 0; background: #10261a66; z-index: 40; }
  .settings:not(.website-settings-workspace) .save-bar { align-items: stretch; bottom:calc(8px + env(safe-area-inset-bottom)); flex-direction: column; padding:10px; }
  .settings:not(.website-settings-workspace) .save-bar div { display: grid; grid-template-columns: 1fr 1fr; }
  .settings:not(.website-settings-workspace) .requirement-statuses { grid-template-columns: 1fr; }
}

@media (max-width: 480px) {
  .settings:not(.website-settings-workspace) > header h1 { font-size:1.5rem; }
  .settings:not(.website-settings-workspace) .profile-photo { gap:12px; }
  .settings:not(.website-settings-workspace) .avatar { flex-basis:72px; width:72px; height:72px; }
  .settings:not(.website-settings-workspace) .appearance-grid { grid-template-columns:repeat(2,minmax(0,1fr)); }
  .settings:not(.website-settings-workspace) .save-bar div { grid-template-columns:minmax(0,1fr); }
}
@media (max-width: 1100px) {
  .website-settings-workspace aside { grid-template-columns: repeat(3, minmax(0, 1fr)); }
  .settings-profile-banner { grid-template-columns: auto minmax(0,1fr); }
  .settings-banner-photo-actions { grid-column: 2; max-width: none; justify-items: start; text-align: left; }
  .settings-banner-photo-actions .photo-actions { justify-content: flex-start; }
}
@media (max-width: 768px) {
  .website-settings-workspace { padding: 18px max(14px, env(safe-area-inset-right)) 34px max(14px, env(safe-area-inset-left)); }
  .settings-profile-banner { min-height: 0; padding: 20px; border-radius: 18px; }
  .website-settings-workspace .settings-profile-banner .avatar { width: 82px; height: 82px; flex-basis: 82px; }
  .website-settings-workspace aside { display: flex; gap: 8px; overflow-x: auto; overscroll-behavior-inline: contain; scroll-snap-type: inline proximity; padding: 10px; scrollbar-width: thin; }
  .settings-category-intro { display: none; }
  .website-settings-workspace aside button { flex: 0 0 min(205px, 68vw); scroll-snap-align: start; }
  .website-settings-workspace article { min-height: 360px; padding: 22px; }
  .settings-content-heading { flex-direction: column; gap: 12px; }
  .website-settings-workspace .profile-fields,.settings-form-grid,.security-fields,.preference-grid { grid-template-columns: minmax(0, 1fr); }
  .website-settings-workspace .profile-fields > label:last-child,.settings-form-grid .full-field { grid-column: auto; }
  .website-settings-workspace .save-bar { align-items: stretch; bottom: calc(8px + env(safe-area-inset-bottom)); flex-direction: column; }
  .website-settings-workspace .save-bar div { display: grid; grid-template-columns: 1fr 1fr; }
}
@media (max-width: 480px) {
  .settings-profile-banner { grid-template-columns: auto minmax(0, 1fr); gap: 14px; }
  .website-settings-workspace .settings-profile-banner .avatar { width: 68px; height: 68px; flex-basis: 68px; }
  .settings-banner-copy p { display: none; }
  .settings-banner-copy h2 { font-size: 1.2rem; }
  .settings-banner-photo-actions { grid-column: 1 / -1; }
  .website-settings-workspace .content { margin-top: 12px; }
  .website-settings-workspace article { gap: 18px; padding: 17px; border-radius: 16px; }
  .website-settings-workspace .official-info,.summary-info,.website-settings-workspace .appearance-grid { grid-template-columns: minmax(0, 1fr); }
  .subsection-heading { flex-direction: column; gap: 5px; }
  .website-settings-workspace .save-bar div { grid-template-columns: minmax(0, 1fr); }
}
@media (max-width: 340px) {
  .website-settings-workspace { padding-inline: 10px; }
  .settings-profile-banner { padding: 16px; }
  .website-settings-workspace aside button { flex-basis: min(190px, 78vw); }
  .settings-banner-photo-actions .photo-actions { display: grid; width: 100%; grid-template-columns: 1fr; }
}
@media (prefers-reduced-motion: reduce) {
  *, *::before, *::after { transition: none !important; }
}
</style>
