<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useAuthStore } from '../../stores/authStore'
import { apiClient } from '../../services/apiClient'

const auth = useAuthStore()
const isAdmin = computed(() => auth.currentRole === 'Admin')
const title = computed(() => isAdmin.value ? 'Registrar Management' : 'Professor Management')
const endpoint = computed(() => isAdmin.value ? '/admin/registrars' : '/registrar/professors')
const roleLabel = computed(() => isAdmin.value ? 'Registrar Staff' : 'Professor')
const accounts = ref({ data: [], current_page: 1, last_page: 1 })
const departments = ref([])
const loading = ref(false)
const saving = ref(false)
const error = ref('')
const notice = ref('')
const form = ref(null)
const formErrors = ref({})
const showInitialPassword = ref(false)
const showConfirmationPassword = ref(false)
const statusTarget = ref(null)
const filters = reactive({ search: '', status: '', department_id: '', page: 1 })

const emptyForm = () => ({
  id: null, username: '', email: '', password: '', password_confirmation: '', first_name: '', middle_name: '',
  last_name: '', suffix: '', gender: 'Prefer not to say', birth_date: '', contact_number: '', address: '',
  nationality: 'Filipino', employee_number: '', position: isAdmin.value ? 'Registrar Staff' : '',
  employment_status: isAdmin.value ? 'regular' : 'full_time', status: 'active', department_id: '', specialization: '',
})

const safeError = err => err.response?.data?.message === 'Validation failed.'
  ? Object.values(err.response?.data?.errors || {}).flat()[0] || 'Check the account details and try again.'
  : err.response?.data?.message || 'Account management is temporarily unavailable.'

async function load() {
  loading.value = true
  error.value = ''
  try {
    const params = { ...filters }
    if (isAdmin.value) delete params.department_id
    const response = await apiClient.get(endpoint.value, { params })
    accounts.value = response.data.data.accounts
    departments.value = response.data.data.departments || []
  } catch (err) {
    error.value = safeError(err)
  } finally {
    loading.value = false
  }
}

function resetPasswordControls() {
  formErrors.value = {}
  showInitialPassword.value = false
  showConfirmationPassword.value = false
}

function closeForm() {
  form.value = null
  error.value = ''
  resetPasswordControls()
}

function openCreate() { form.value = emptyForm(); error.value = ''; notice.value = ''; resetPasswordControls() }
function openEdit(account) {
  form.value = { ...emptyForm(), ...account, password: '', password_confirmation: '' }
  error.value = ''
  notice.value = ''
  resetPasswordControls()
}

async function save() {
  if (saving.value) return
  formErrors.value = {}
  if (form.value.password !== form.value.password_confirmation) {
    formErrors.value = { password_confirmation: ['The password confirmation does not match.'] }
    return
  }
  saving.value = true
  error.value = ''
  try {
    const payload = { ...form.value }
    delete payload.id
    delete payload.user_id
    delete payload.account_status
    if (form.value.id) {
      delete payload.status
      if (!payload.password) {
        delete payload.password
        delete payload.password_confirmation
      }
      await apiClient.put(`${endpoint.value}/${form.value.id}`, payload)
    } else {
      if (!isAdmin.value) payload.department_id = Number(payload.department_id)
      await apiClient.post(endpoint.value, payload)
    }
    notice.value = `${roleLabel.value} account ${form.value.id ? 'updated' : 'created'} successfully.`
    closeForm()
    await load()
  } catch (err) {
    formErrors.value = err.response?.data?.errors || {}
    error.value = safeError(err)
  } finally {
    saving.value = false
  }
}

async function changeStatus() {
  if (!statusTarget.value || saving.value) return
  saving.value = true
  error.value = ''
  const status = statusTarget.value.status === 'active' ? 'inactive' : 'active'
  try {
    await apiClient.patch(`${endpoint.value}/${statusTarget.value.id}/status`, { status })
    notice.value = `${roleLabel.value} account ${status === 'active' ? 'activated' : 'deactivated'}.`
    statusTarget.value = null
    await load()
  } catch (err) {
    error.value = safeError(err)
  } finally {
    saving.value = false
  }
}

function applyFilters() { filters.page = 1; load() }
function go(page) { filters.page = page; load() }

onMounted(load)
</script>

<template>
  <section class="account-management-page">
    <header class="account-page-header">
      <div><p class="account-kicker">Account provisioning</p><h1>{{ title }}</h1><p>Create and maintain {{ roleLabel }} identities using the portal's existing account records.</p></div>
      <button type="button" class="account-primary" :disabled="loading" @click="openCreate">Add {{ roleLabel }}</button>
    </header>

    <p v-if="error && !form" class="account-alert error" role="alert">{{ error }} <button type="button" @click="load">Retry</button></p>
    <p v-if="notice" class="account-alert success" role="status">{{ notice }}</p>

    <form class="account-filter" @submit.prevent="applyFilters">
      <label><span>Search</span><input v-model.trim="filters.search" type="search" maxlength="100" placeholder="Name, username, email, or employee number"></label>
      <label><span>Status</span><select v-model="filters.status"><option value="">All statuses</option><option value="active">Active</option><option value="inactive">Inactive</option></select></label>
      <label v-if="!isAdmin"><span>Department</span><select v-model="filters.department_id"><option value="">All departments</option><option v-for="department in departments" :key="department.id" :value="department.id">{{ department.department_code }} — {{ department.department_name }}</option></select></label>
      <button type="submit" :disabled="loading">Apply filters</button>
    </form>

    <p v-if="loading" class="account-state" role="status">Loading accounts…</p>
    <section v-else-if="!accounts.data.length" class="account-state"><h2>No {{ roleLabel }} accounts found</h2><p>Create the first account or adjust the current filters.</p></section>
    <div v-else class="account-table-wrap">
      <table><thead><tr><th>Identity</th><th>Employee</th><th v-if="!isAdmin">Department</th><th>Employment</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody><tr v-for="account in accounts.data" :key="account.id">
          <td data-label="Identity"><strong>{{ account.first_name }} {{ account.last_name }}</strong><small>{{ account.username }} · {{ account.email }}</small></td>
          <td data-label="Employee"><strong>{{ account.employee_number }}</strong><small>{{ account.position || 'Position not set' }}</small></td>
          <td v-if="!isAdmin" data-label="Department">{{ departments.find(item => item.id === account.department_id)?.department_code || '—' }}</td>
          <td data-label="Employment">{{ account.employment_status.replaceAll('_', ' ') }}</td>
          <td data-label="Status"><span class="account-badge" :data-status="account.status">{{ account.status }}</span></td>
          <td data-label="Actions"><div class="account-actions"><button type="button" @click="openEdit(account)">Edit</button><button type="button" class="secondary" @click="statusTarget=account">{{ account.status === 'active' ? 'Deactivate' : 'Activate' }}</button></div></td>
        </tr></tbody>
      </table>
      <footer class="account-pagination"><button :disabled="loading || accounts.current_page <= 1" @click="go(accounts.current_page - 1)">Previous</button><span>Page {{ accounts.current_page }} of {{ accounts.last_page }}</span><button :disabled="loading || accounts.current_page >= accounts.last_page" @click="go(accounts.current_page + 1)">Next</button></footer>
    </div>

    <div v-if="form" class="account-dialog-backdrop" @click.self="closeForm">
      <section class="account-dialog" role="dialog" aria-modal="true" aria-labelledby="account-form-title">
        <header><div><p class="account-kicker">{{ form.id ? 'Update identity' : 'New account' }}</p><h2 id="account-form-title">{{ form.id ? 'Edit' : 'Add' }} {{ roleLabel }}</h2></div><button type="button" aria-label="Close" @click="closeForm">×</button></header>
        <p v-if="error" class="account-alert error account-dialog-error" role="alert">{{ error }}</p>
        <form @submit.prevent="save">
          <div class="account-form-grid">
            <label><span>Username</span><input v-model.trim="form.username" required maxlength="50" autocomplete="off"></label>
            <label><span>Email</span><input v-model.trim="form.email" type="email" required maxlength="255"></label>
            <label><span>First name</span><input v-model.trim="form.first_name" required maxlength="100"></label>
            <label><span>Middle name</span><input v-model.trim="form.middle_name" maxlength="100"></label>
            <label><span>Last name</span><input v-model.trim="form.last_name" required maxlength="100"></label>
            <label><span>Suffix</span><input v-model.trim="form.suffix" maxlength="20"></label>
            <label><span>Gender</span><select v-model="form.gender" required><option>Male</option><option>Female</option><option>Prefer not to say</option></select></label>
            <label><span>Birth date</span><input v-model="form.birth_date" type="date"></label>
            <label><span>Contact number</span><input v-model.trim="form.contact_number" maxlength="20"></label>
            <label><span>Nationality</span><input v-model.trim="form.nationality" maxlength="50"></label>
            <label class="wide"><span>Address</span><textarea v-model.trim="form.address" maxlength="1000" rows="2"></textarea></label>
            <label><span>Employee number</span><input v-model.trim="form.employee_number" required maxlength="20"></label>
            <label><span>Position</span><input v-model.trim="form.position" :required="isAdmin" maxlength="100"></label>
            <label v-if="!isAdmin"><span>Department</span><select v-model="form.department_id" required><option value="">Choose department</option><option v-for="department in departments" :key="department.id" :value="department.id">{{ department.department_code }} — {{ department.department_name }}</option></select></label>
            <label v-if="!isAdmin"><span>Specialization</span><input v-model.trim="form.specialization" maxlength="255"></label>
            <label><span>Employment status</span><select v-model="form.employment_status" required><template v-if="isAdmin"><option value="regular">Regular</option><option value="contractual">Contractual</option><option value="part_time">Part time</option></template><template v-else><option value="full_time">Full time</option><option value="part_time">Part time</option><option value="contractual">Contractual</option></template></select></label>
            <label v-if="!form.id"><span>Account status</span><select v-model="form.status" required><option value="active">Active</option><option value="inactive">Inactive</option></select></label>
            <label>
              <span>{{ form.id ? 'New password (optional)' : 'Initial password' }}</span>
              <span class="account-password-control">
                <input id="account-initial-password" v-model="form.password" :type="showInitialPassword ? 'text' : 'password'" :required="!form.id" minlength="8" autocomplete="new-password" :aria-invalid="Boolean(formErrors.password)" :aria-describedby="formErrors.password ? 'account-initial-password-error' : undefined">
                <button type="button" :aria-label="`${showInitialPassword ? 'Hide' : 'Show'} initial password`" :aria-pressed="showInitialPassword" @click="showInitialPassword = !showInitialPassword">{{ showInitialPassword ? 'Hide' : 'Show' }}</button>
              </span>
              <small v-if="formErrors.password" id="account-initial-password-error" class="account-field-error" role="alert">{{ formErrors.password[0] }}</small>
            </label>
            <label>
              <span>Confirm password</span>
              <span class="account-password-control">
                <input id="account-confirm-password" v-model="form.password_confirmation" :type="showConfirmationPassword ? 'text' : 'password'" :required="Boolean(form.password)" minlength="8" autocomplete="new-password" :aria-invalid="Boolean(formErrors.password_confirmation)" :aria-describedby="formErrors.password_confirmation ? 'account-confirm-password-error' : undefined">
                <button type="button" :aria-label="`${showConfirmationPassword ? 'Hide' : 'Show'} password confirmation`" :aria-pressed="showConfirmationPassword" @click="showConfirmationPassword = !showConfirmationPassword">{{ showConfirmationPassword ? 'Hide' : 'Show' }}</button>
              </span>
              <small v-if="formErrors.password_confirmation" id="account-confirm-password-error" class="account-field-error" role="alert">{{ formErrors.password_confirmation[0] }}</small>
            </label>
          </div>
          <p class="account-form-note">The role is assigned by the server. The initial password must be shared through an approved private channel.</p>
          <footer><button type="button" class="secondary" :disabled="saving" @click="closeForm">Cancel</button><button type="submit" class="account-primary" :disabled="saving">{{ saving ? 'Saving…' : `Save ${roleLabel}` }}</button></footer>
        </form>
      </section>
    </div>

    <div v-if="statusTarget" class="account-dialog-backdrop" @click.self="statusTarget=null">
      <section class="account-dialog compact" role="dialog" aria-modal="true" aria-labelledby="account-status-title"><h2 id="account-status-title">{{ statusTarget.status === 'active' ? 'Deactivate' : 'Activate' }} this account?</h2><p>{{ statusTarget.first_name }} {{ statusTarget.last_name }} will {{ statusTarget.status === 'active' ? 'lose' : 'regain' }} sign-in access. Existing records remain intact.</p><footer><button class="secondary" @click="statusTarget=null">Cancel</button><button class="account-primary" :disabled="saving" @click="changeStatus">Confirm</button></footer></section>
    </div>
  </section>
</template>

<style scoped src="./account-management.css"></style>
