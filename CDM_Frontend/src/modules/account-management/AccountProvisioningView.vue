<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useAuthStore } from '../../stores/authStore'
import { apiClient } from '../../services/apiClient'
import CdmBadge from '../../design-system/CdmBadge.vue'
import CdmButton from '../../design-system/CdmButton.vue'
import CdmDialog from '../../design-system/CdmDialog.vue'
import CdmPageHeader from '../../design-system/CdmPageHeader.vue'
import AccountManagementIcon from './AccountManagementIcon.vue'

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
const subjectAssignments = ref(null)
const subjectSearch = ref('')
const subjectLoading = ref(false)
const subjectSaving = ref(false)
const filters = reactive({ search: '', status: '', department_id: '', page: 1 })
const accountKind = computed(() => isAdmin.value ? 'Registrar' : 'Professor')
const visibleActive = computed(() => accounts.value.data.filter(account => account.status === 'active').length)
const visibleInactive = computed(() => accounts.value.data.filter(account => account.status === 'inactive').length)
const visibleCoverage = computed(() => new Set(accounts.value.data.map(account => isAdmin.value ? account.position : account.department_id).filter(Boolean)).size)
const totalAccounts = computed(() => accounts.value.total ?? accounts.value.data.length)
const filteredSubjects = computed(() => {
  const query = subjectSearch.value.trim().toLowerCase()
  const subjects = subjectAssignments.value?.subjects || []
  if (!query) return subjects
  return subjects.filter(subject => [
    subject.subject_code, subject.subject_name, subject.program_code, subject.program_name,
    subject.department_code, subject.department_name,
  ].some(value => String(value || '').toLowerCase().includes(query)))
})

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

async function openSubjectAssignments(account) {
  if (isAdmin.value || subjectLoading.value) return
  subjectLoading.value = true
  error.value = ''
  notice.value = ''
  try {
    const response = await apiClient.get(`/registrar/professors/${account.id}/subjects`)
    subjectAssignments.value = {
      professor: response.data.data.professor,
      subjects: response.data.data.subjects,
      selected: [...response.data.data.assigned_subject_ids],
    }
    subjectSearch.value = ''
  } catch (err) {
    error.value = safeError(err)
  } finally {
    subjectLoading.value = false
  }
}

function closeSubjectAssignments() {
  if (subjectSaving.value) return
  subjectAssignments.value = null
  subjectSearch.value = ''
}

function subjectSelected(subjectId) {
  return subjectAssignments.value?.selected.includes(subjectId) || false
}

function toggleSubject(subjectId) {
  if (!subjectAssignments.value) return
  const selected = new Set(subjectAssignments.value.selected)
  selected.has(subjectId) ? selected.delete(subjectId) : selected.add(subjectId)
  subjectAssignments.value.selected = [...selected]
}

async function saveSubjectAssignments() {
  if (!subjectAssignments.value || subjectSaving.value) return
  subjectSaving.value = true
  error.value = ''
  try {
    const professor = subjectAssignments.value.professor
    await apiClient.put(`/registrar/professors/${professor.id}/subjects`, {
      subject_ids: subjectAssignments.value.selected,
      confirmed: true,
    })
    notice.value = `Subject associations for ${displayName(professor)} updated.`
    subjectAssignments.value = null
    subjectSearch.value = ''
  } catch (err) {
    error.value = safeError(err)
  } finally {
    subjectSaving.value = false
  }
}

function applyFilters() { filters.page = 1; load() }
function resetFilters() { Object.assign(filters, { search: '', status: '', department_id: '', page: 1 }); load() }
function go(page) { filters.page = page; load() }
const displayName = account => [account.first_name, account.middle_name, account.last_name, account.suffix].filter(Boolean).join(' ')
const initials = account => [account.first_name, account.last_name].filter(Boolean).map(value => value[0]).join('').slice(0, 2).toUpperCase() || '—'
const formatValue = value => String(value || 'Not set').replaceAll('_', ' ').replace(/\b\w/g, letter => letter.toUpperCase())

onMounted(load)
</script>

<template>
  <section class="account-management-page desktop-account-management">
    <CdmPageHeader eyebrow="Account management" :title="title" :description="isAdmin ? 'Manage Registrar staff identities and authorized portal access.' : 'Manage Professor identities used for grading and academic workflows.'">
      <template #actions><CdmButton class="account-add-button" :disabled="loading" @click="openCreate"><AccountManagementIcon name="plus" :size="16"/>Add {{ accountKind }}</CdmButton></template>
    </CdmPageHeader>

    <p v-if="error && !form" class="account-alert error" role="alert">{{ error }} <button type="button" @click="load">Retry</button></p>
    <p v-if="notice" class="account-alert success" role="status">{{ notice }}</p>

    <section class="account-overview" aria-label="Account overview">
      <article><span class="stat-icon"><AccountManagementIcon name="users"/></span><div><small>Total {{ accountKind }}s</small><strong>{{ totalAccounts }}</strong></div></article>
      <article><span class="stat-icon success"><AccountManagementIcon name="check"/></span><div><small>Active shown</small><strong>{{ visibleActive }}</strong></div></article>
      <article><span class="stat-icon danger"><AccountManagementIcon name="pause"/></span><div><small>Inactive shown</small><strong>{{ visibleInactive }}</strong></div></article>
      <article><span class="stat-icon gold"><AccountManagementIcon :name="isAdmin ? 'briefcase' : 'building'"/></span><div><small>{{ isAdmin ? 'Roles shown' : 'Departments shown' }}</small><strong>{{ visibleCoverage }}</strong></div></article>
    </section>

    <form class="account-filter" :class="{ 'registrar-filter': isAdmin }" @submit.prevent="applyFilters">
      <label class="account-search"><span>Search accounts</span><span class="control-with-icon"><AccountManagementIcon name="search"/><input v-model.trim="filters.search" type="search" maxlength="100" :placeholder="`Search ${accountKind.toLowerCase()}s by name, email, or ID`"></span></label>
      <label><span>Status</span><select v-model="filters.status"><option value="">All statuses</option><option value="active">Active</option><option value="inactive">Inactive</option></select></label>
      <label v-if="!isAdmin"><span>Department</span><select v-model="filters.department_id"><option value="">All departments</option><option v-for="department in departments" :key="department.id" :value="department.id">{{ department.department_code }} — {{ department.department_name }}</option></select></label>
      <div class="filter-actions"><CdmButton type="submit" :disabled="loading"><AccountManagementIcon name="filter" :size="16"/>Apply</CdmButton><CdmButton type="button" variant="ghost" :disabled="loading" @click="resetFilters">Reset</CdmButton></div>
    </form>

    <p v-if="loading" class="account-state" role="status">Loading accounts…</p>
    <section v-else-if="!accounts.data.length" class="account-state"><h2>No {{ roleLabel }} accounts found</h2><p>Create the first account or adjust the current filters.</p></section>
    <div v-else class="account-table-wrap">
      <div class="directory-heading"><div><span class="directory-icon"><AccountManagementIcon :name="isAdmin ? 'registrar' : 'professor'"/></span><div><h2>{{ accountKind }} directory</h2><p>{{ accounts.total ?? accounts.data.length }} account{{ (accounts.total ?? accounts.data.length) === 1 ? '' : 's' }} match the current filters.</p></div></div><CdmBadge status="active">{{ accounts.data.length }} shown</CdmBadge></div>
      <table><thead><tr><th>Identity</th><th>Employee</th><th v-if="!isAdmin">Department</th><th>{{ isAdmin ? 'Office / role' : 'Employment' }}</th><th>Status</th><th><span class="sr-only">Actions</span></th></tr></thead>
        <tbody><tr v-for="account in accounts.data" :key="account.id">
          <td data-label="Identity"><div class="account-identity"><span class="account-avatar">{{ initials(account) }}</span><div><strong>{{ displayName(account) }}</strong><small>{{ account.email || account.username }}</small></div></div></td>
          <td data-label="Employee"><strong>{{ account.employee_number }}</strong><small>@{{ account.username }}</small></td>
          <td v-if="!isAdmin" data-label="Department"><strong>{{ departments.find(item => item.id === account.department_id)?.department_code || '—' }}</strong><small>{{ departments.find(item => item.id === account.department_id)?.department_name || 'Department not available' }}</small></td>
          <td :data-label="isAdmin ? 'Office / role' : 'Employment'"><strong>{{ isAdmin ? (account.position || 'Registrar Staff') : formatValue(account.employment_status) }}</strong><small>{{ isAdmin ? formatValue(account.employment_status) : (account.specialization || account.position || 'No specialization listed') }}</small></td>
          <td data-label="Status"><CdmBadge :status="account.status"/></td>
          <td data-label="Actions"><div class="account-actions"><CdmButton v-if="!isAdmin" type="button" size="icon" variant="ghost" :disabled="subjectLoading" :aria-label="`Manage subject associations for ${displayName(account)}`" title="Subject associations" @click="openSubjectAssignments(account)"><AccountManagementIcon name="book" :size="16"/></CdmButton><CdmButton type="button" size="icon" variant="ghost" :aria-label="`Edit ${displayName(account)}`" title="Edit" @click="openEdit(account)"><AccountManagementIcon name="edit" :size="16"/></CdmButton><CdmButton type="button" size="icon" variant="outline" :aria-label="`${account.status === 'active' ? 'Deactivate' : 'Activate'} ${displayName(account)}`" :title="account.status === 'active' ? 'Deactivate' : 'Activate'" @click="statusTarget=account"><AccountManagementIcon name="power" :size="16"/></CdmButton></div></td>
        </tr></tbody>
      </table>
      <footer class="account-pagination"><span>Page {{ accounts.current_page }} of {{ accounts.last_page }}</span><div><CdmButton size="icon" variant="outline" aria-label="Previous page" :disabled="loading || accounts.current_page <= 1" @click="go(accounts.current_page - 1)"><AccountManagementIcon name="arrow-left" :size="16"/></CdmButton><strong aria-current="page">{{ accounts.current_page }}</strong><CdmButton size="icon" variant="outline" aria-label="Next page" :disabled="loading || accounts.current_page >= accounts.last_page" @click="go(accounts.current_page + 1)"><AccountManagementIcon name="arrow-right" :size="16"/></CdmButton></div></footer>
    </div>

    <CdmDialog v-if="form" id="account-form" :title="`${form.id ? 'Edit' : 'Add'} ${roleLabel}`" :description="form.id ? 'Update the existing academic staff identity.' : 'Create a server-owned academic staff identity.'" :busy="saving" wide @close="closeForm">
        <p v-if="error" class="account-alert error account-dialog-error" role="alert">{{ error }}</p>
        <form class="account-dialog-form" @submit.prevent="save">
          <section class="form-section"><header><span><AccountManagementIcon name="identity"/></span><div><h3>Account identity</h3><p>Personal and sign-in information.</p></div></header><div class="account-form-grid">
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
          </div></section>
          <section class="form-section"><header><span><AccountManagementIcon name="briefcase"/></span><div><h3>Employment &amp; assignment</h3><p>Institutional role and work classification.</p></div></header><div class="account-form-grid">
            <label><span>Employee number</span><input v-model.trim="form.employee_number" required maxlength="20"></label>
            <label><span>Position</span><input v-model.trim="form.position" :required="isAdmin" maxlength="100"></label>
            <label v-if="!isAdmin"><span>Department</span><select v-model="form.department_id" required><option value="">Choose department</option><option v-for="department in departments" :key="department.id" :value="department.id">{{ department.department_code }} — {{ department.department_name }}</option></select></label>
            <label v-if="!isAdmin"><span>Specialization</span><input v-model.trim="form.specialization" maxlength="255"></label>
            <label><span>Employment status</span><select v-model="form.employment_status" required><template v-if="isAdmin"><option value="regular">Regular</option><option value="contractual">Contractual</option><option value="part_time">Part time</option></template><template v-else><option value="full_time">Full time</option><option value="part_time">Part time</option><option value="contractual">Contractual</option></template></select></label>
            <label v-if="!form.id"><span>Account status</span><select v-model="form.status" required><option value="active">Active</option><option value="inactive">Inactive</option></select></label>
          </div></section>
          <section class="form-section"><header><span><AccountManagementIcon name="lock"/></span><div><h3>System access</h3><p>{{ form.id ? 'Leave both fields empty to keep the current password.' : 'Create the initial private sign-in credential.' }}</p></div></header><div class="account-form-grid">
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
          </div></section>
          <p class="account-form-note">The role is assigned by the server. The initial password must be shared through an approved private channel.</p>
          <footer class="account-form-actions"><CdmButton type="button" variant="outline" :disabled="saving" @click="closeForm">Cancel</CdmButton><CdmButton type="submit" :loading="saving"><AccountManagementIcon name="check" :size="16"/>Save {{ accountKind }}</CdmButton></footer>
        </form>
    </CdmDialog>

    <CdmDialog v-if="subjectAssignments" id="professor-subject-assignments" :title="`Subject Assignments — ${displayName(subjectAssignments.professor)}`" description="Maintain scheduling associations without changing class or grading access." :busy="subjectSaving" wide @close="closeSubjectAssignments">
      <form class="subject-assignment-dialog" @submit.prevent="saveSubjectAssignments">
        <p class="subject-assignment-note"><AccountManagementIcon name="book" :size="18"/><span><strong>Scheduling reference only.</strong> A Professor receives teaching and Grade Entry access only after being assigned to a subject offering in a section and academic term.</span></p>
        <label class="subject-assignment-search"><span>Find an available subject</span><span class="control-with-icon"><AccountManagementIcon name="search"/><input v-model="subjectSearch" type="search" maxlength="100" placeholder="Search code, subject, program, or department"></span></label>
        <div class="subject-assignment-summary"><strong>{{ subjectAssignments.selected.length }} selected</strong><span>{{ filteredSubjects.length }} subject{{ filteredSubjects.length === 1 ? '' : 's' }} shown</span></div>
        <div class="subject-assignment-list" role="group" aria-label="Available subject associations">
          <label v-for="subject in filteredSubjects" :key="subject.id" class="subject-assignment-item" :class="{ selected: subjectSelected(subject.id) }">
            <input type="checkbox" :checked="subjectSelected(subject.id)" @change="toggleSubject(subject.id)">
            <span class="subject-code">{{ subject.subject_code }}</span>
            <span class="subject-copy"><strong>{{ subject.subject_name }}</strong><small>{{ subject.program_code || 'No program' }}<template v-if="subject.department_code"> · {{ subject.department_code }}</template><template v-if="subject.program_name"> — {{ subject.program_name }}</template></small></span>
            <CdmBadge v-if="subject.status !== 'active'" status="inactive">Inactive</CdmBadge>
          </label>
          <p v-if="!filteredSubjects.length" class="subject-assignment-empty">No subjects match this search.</p>
        </div>
        <footer class="account-form-actions"><CdmButton type="button" variant="outline" :disabled="subjectSaving" @click="closeSubjectAssignments">Cancel</CdmButton><CdmButton type="submit" :loading="subjectSaving"><AccountManagementIcon name="check" :size="16"/>Save associations</CdmButton></footer>
      </form>
    </CdmDialog>

    <CdmDialog v-if="statusTarget" id="account-status" :title="`${statusTarget.status === 'active' ? 'Deactivate' : 'Activate'} this account?`" :busy="saving" @close="statusTarget=null"><p>{{ statusTarget.first_name }} {{ statusTarget.last_name }} will {{ statusTarget.status === 'active' ? 'lose' : 'regain' }} sign-in access. Existing records remain intact.</p><template #footer><CdmButton variant="outline" :disabled="saving" @click="statusTarget=null">Cancel</CdmButton><CdmButton :variant="statusTarget.status === 'active' ? 'danger' : 'primary'" :loading="saving" @click="changeStatus">Confirm</CdmButton></template></CdmDialog>
  </section>
</template>

<style scoped src="./account-management.css"></style>
