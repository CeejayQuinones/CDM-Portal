<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { useAuthStore } from '../../stores/authStore'
import { fetchAdminUsers } from './adminDashboardService'

const authStore = useAuthStore()

const loading = ref(true)
const error = ref('')
const search = ref('')
const roleFilter = ref('')
const statusFilter = ref('')
const summary = ref({ total: 0, by_role: {}, active: 0 })
const users = ref([])

const ROLE_FILTERS = [
  { value: '', label: 'All roles' },
  { value: 'Admin', label: 'Admin' },
  { value: 'Registrar Staff', label: 'Registrar' },
  { value: 'Professor', label: 'Professor' },
  { value: 'Student', label: 'Student' },
  { value: 'Guest', label: 'Guest' },
]

const STATUS_FILTERS = [
  { value: '', label: 'All statuses' },
  { value: 'active', label: 'Active' },
  { value: 'inactive', label: 'Inactive' },
  { value: 'suspended', label: 'Suspended' },
]

const displayName = computed(() => {
  const profile = authStore.currentUser?.profile
  const fullName = [profile?.first_name, profile?.last_name].filter(Boolean).join(' ')
  return fullName || authStore.currentUser?.username || 'Admin'
})

const summaryCards = computed(() => {
  const byRole = summary.value.by_role || {}
  return [
    {
      key: 'total',
      label: 'All users',
      value: summary.value.total ?? 0,
      tone: 'green',
    },
    {
      key: 'students',
      label: 'Students',
      value: byRole.Student ?? 0,
      tone: 'teal',
    },
    {
      key: 'professors',
      label: 'Professors',
      value: byRole.Professor ?? 0,
      tone: 'blue',
    },
    {
      key: 'registrars',
      label: 'Registrar staff',
      value: byRole['Registrar Staff'] ?? 0,
      tone: 'amber',
    },
    {
      key: 'admins',
      label: 'Admins',
      value: byRole.Admin ?? 0,
      tone: 'slate',
    },
  ]
})

let searchTimer = null

async function loadUsers() {
  loading.value = true
  error.value = ''

  try {
    const params = {}
    if (roleFilter.value) params.role = roleFilter.value
    if (statusFilter.value) params.status = statusFilter.value
    if (search.value.trim()) params.search = search.value.trim()

    const data = await fetchAdminUsers(params)
    summary.value = data.summary || { total: 0, by_role: {}, active: 0 }
    users.value = data.users || []
  } catch (err) {
    const status = err.response?.status
    if (status === 404) {
      error.value =
        'User directory API is not on the live backend yet. Redeploy the Railway API with the latest code, then refresh.'
    } else {
      error.value = err.response?.data?.message || 'Could not load the user directory. Please try again.'
    }
    summary.value = { total: 0, by_role: {}, active: 0 }
    users.value = []
  } finally {
    loading.value = false
  }
}

function onSearchInput() {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(loadUsers, 300)
}

function roleTone(roleName) {
  const map = {
    Admin: 'slate',
    'Registrar Staff': 'amber',
    Professor: 'blue',
    Student: 'teal',
    Guest: 'muted',
  }
  return map[roleName] || 'muted'
}

function formatLastLogin(value) {
  if (!value) return 'Never'
  return new Intl.DateTimeFormat('en-PH', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
  }).format(new Date(value))
}

function identityLabel(user) {
  if (user.student_number) return user.student_number
  if (user.employee_number) return user.employee_number
  return '—'
}

function detailLabel(user) {
  if (user.course_code) {
    return user.year_level ? `${user.course_code} · Y${user.year_level}` : user.course_code
  }
  return user.department || '—'
}

watch([roleFilter, statusFilter], loadUsers)
onMounted(loadUsers)
</script>

<template>
  <section class="admin-dashboard">
    <header class="admin-hero">
      <div>
        <p class="admin-kicker">Administration</p>
        <h1 class="admin-title">Admin Dashboard</h1>
        <p class="admin-description">
          View every portal account — professors, registrar staff, students, admins, and guests.
        </p>
        <div class="admin-meta">
          <span class="admin-pill">
            <strong>{{ displayName }}</strong>
            <small>Admin</small>
          </span>
        </div>
      </div>
      <div class="admin-hero-actions">
        <button type="button" class="refresh-btn" :disabled="loading" @click="loadUsers">
          {{ loading ? 'Loading…' : 'Refresh' }}
        </button>
        <RouterLink class="link-btn" to="/student-management">Student records</RouterLink>
        <RouterLink class="link-btn secondary" to="/monitoring">Monitoring</RouterLink>
      </div>
    </header>

    <p v-if="error" class="admin-error" role="alert">{{ error }}</p>

    <section class="summary-grid" aria-label="User totals by role">
      <article v-for="card in summaryCards" :key="card.key" class="summary-card" :class="`tone-${card.tone}`">
        <strong>{{ loading ? '—' : card.value.toLocaleString() }}</strong>
        <span>{{ card.label }}</span>
      </article>
    </section>

    <section class="directory-panel" aria-label="All users directory">
      <div class="directory-heading">
        <div>
          <p class="section-kicker">Directory</p>
          <h2>All users</h2>
        </div>
        <p class="directory-note">
          Showing {{ loading ? '…' : users.length.toLocaleString() }} account{{ users.length === 1 ? '' : 's' }}
        </p>
      </div>

      <div class="filters">
        <label class="filter-field search-field">
          <span>Search</span>
          <input
            v-model="search"
            type="search"
            placeholder="Name, username, email, student/employee no."
            @input="onSearchInput"
          />
        </label>
        <label class="filter-field">
          <span>Role</span>
          <select v-model="roleFilter">
            <option v-for="option in ROLE_FILTERS" :key="option.label" :value="option.value">
              {{ option.label }}
            </option>
          </select>
        </label>
        <label class="filter-field">
          <span>Status</span>
          <select v-model="statusFilter">
            <option v-for="option in STATUS_FILTERS" :key="option.label" :value="option.value">
              {{ option.label }}
            </option>
          </select>
        </label>
      </div>

      <div class="table-wrap">
        <p v-if="loading" class="panel-state">Loading users…</p>
        <p v-else-if="!users.length" class="panel-state">No users match the current filters.</p>
        <table v-else class="users-table">
          <thead>
            <tr>
              <th>Name</th>
              <th>Role</th>
              <th>Username</th>
              <th>ID / No.</th>
              <th>Course / Dept</th>
              <th>Email</th>
              <th>Status</th>
              <th>Last login</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="user in users" :key="user.id">
              <td>
                <strong>{{ user.name }}</strong>
              </td>
              <td>
                <span class="role-badge" :class="`tone-${roleTone(user.role?.role_name)}`">
                  {{ user.role?.role_name || 'Unknown' }}
                </span>
              </td>
              <td>{{ user.username }}</td>
              <td>{{ identityLabel(user) }}</td>
              <td>{{ detailLabel(user) }}</td>
              <td>{{ user.email || '—' }}</td>
              <td>
                <span class="status-badge" :class="`status-${user.status || 'unknown'}`">
                  {{ user.status || '—' }}
                </span>
              </td>
              <td>{{ formatLastLogin(user.last_login) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </section>
</template>

<style scoped>
.admin-dashboard {
  --ink: var(--color-eerie-black, #1a1f1c);
  --muted: var(--color-muted, #5c6b63);
  --surface: var(--color-surface, #fff);
  --border: #c5d5ca;
  --accent: var(--color-dark-spring-green, #0a6938);
  animation: page-enter 260ms ease both;
  display: grid;
  gap: 20px;
  min-width: 0;
}

@keyframes page-enter {
  from {
    opacity: 0;
    transform: translateY(8px);
  }
  to {
    opacity: 1;
    transform: none;
  }
}

.admin-hero {
  align-items: start;
  background:
    radial-gradient(circle at 88% 18%, rgba(244, 211, 94, 0.18), transparent 28%),
    linear-gradient(135deg, #063c26 0%, #0a6938 58%, #147a45 100%);
  border: 1px solid #075631;
  border-radius: 18px;
  box-shadow: 0 18px 40px rgba(6, 60, 38, 0.18);
  color: #fff;
  display: grid;
  gap: 20px;
  grid-template-columns: minmax(0, 1fr) auto;
  padding: 28px 30px;
}

.admin-kicker {
  color: rgba(244, 211, 94, 0.95);
  font-size: 0.78rem;
  font-weight: 700;
  letter-spacing: 0.08em;
  margin: 0 0 8px;
  text-transform: uppercase;
}

.admin-title {
  font-size: clamp(1.7rem, 3vw, 2.35rem);
  line-height: 1.1;
  margin: 0;
}

.admin-description {
  color: rgba(255, 255, 255, 0.92);
  line-height: 1.6;
  margin: 10px 0 0;
  max-width: 52ch;
}

.admin-meta {
  margin-top: 16px;
}

.admin-pill {
  align-items: baseline;
  background: rgba(255, 255, 255, 0.12);
  border: 1px solid rgba(255, 255, 255, 0.22);
  border-radius: 999px;
  display: inline-flex;
  gap: 10px;
  padding: 8px 14px;
}

.admin-pill small {
  color: rgba(255, 255, 255, 0.78);
  font-size: 0.78rem;
  letter-spacing: 0.04em;
  text-transform: uppercase;
}

.admin-hero-actions {
  align-items: stretch;
  display: grid;
  gap: 8px;
}

.refresh-btn,
.link-btn {
  border-radius: 10px;
  font-size: 0.9rem;
  font-weight: 600;
  padding: 10px 14px;
  text-align: center;
  text-decoration: none;
}

.refresh-btn {
  background: rgba(255, 255, 255, 0.14);
  border: 1px solid rgba(255, 255, 255, 0.28);
  color: #fff;
  cursor: pointer;
}

.refresh-btn:disabled {
  opacity: 0.65;
}

.link-btn {
  background: #f4d35e;
  color: #063c26;
}

.link-btn.secondary {
  background: rgba(255, 255, 255, 0.14);
  border: 1px solid rgba(255, 255, 255, 0.28);
  color: #fff;
}

.admin-error {
  background: #fff4f0;
  border: 1px solid #f0b9a8;
  border-radius: 12px;
  color: #8a3b28;
  margin: 0;
  padding: 12px 14px;
}

.summary-grid {
  display: grid;
  gap: 12px;
  grid-template-columns: repeat(5, minmax(0, 1fr));
}

.summary-card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 14px;
  box-shadow: 0 8px 20px rgba(15, 75, 41, 0.04);
  display: grid;
  gap: 4px;
  padding: 16px 18px;
}

.summary-card strong {
  color: var(--ink);
  font-size: 1.55rem;
  line-height: 1;
}

.summary-card span {
  color: var(--muted);
  font-size: 0.88rem;
}

.summary-card.tone-green {
  border-color: #9fcbb0;
}
.summary-card.tone-teal {
  border-color: #9ecfc4;
}
.summary-card.tone-blue {
  border-color: #a9c4e8;
}
.summary-card.tone-amber {
  border-color: #e6c98a;
}
.summary-card.tone-slate {
  border-color: #c3c9d4;
}

.directory-panel {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 16px;
  box-shadow: 0 10px 24px rgba(15, 75, 41, 0.05);
  display: grid;
  gap: 16px;
  padding: 20px;
}

.directory-heading {
  align-items: end;
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  justify-content: space-between;
}

.section-kicker {
  color: var(--accent);
  font-size: 0.75rem;
  font-weight: 700;
  letter-spacing: 0.08em;
  margin: 0 0 4px;
  text-transform: uppercase;
}

.directory-heading h2 {
  color: var(--ink);
  font-size: 1.25rem;
  margin: 0;
}

.directory-note {
  color: var(--muted);
  font-size: 0.9rem;
  margin: 0;
}

.filters {
  display: grid;
  gap: 12px;
  grid-template-columns: minmax(0, 1.6fr) minmax(140px, 0.7fr) minmax(140px, 0.7fr);
}

.filter-field {
  display: grid;
  gap: 6px;
}

.filter-field span {
  color: var(--muted);
  font-size: 0.78rem;
  font-weight: 600;
  letter-spacing: 0.04em;
  text-transform: uppercase;
}

.filter-field input,
.filter-field select {
  background: #f7fbf8;
  border: 1px solid var(--border);
  border-radius: 10px;
  color: var(--ink);
  font: inherit;
  padding: 10px 12px;
}

.table-wrap {
  overflow-x: auto;
}

.panel-state {
  color: var(--muted);
  margin: 8px 0;
  padding: 18px 4px;
}

.users-table {
  border-collapse: collapse;
  min-width: 920px;
  width: 100%;
}

.users-table th,
.users-table td {
  border-bottom: 1px solid #e2ebe5;
  padding: 12px 10px;
  text-align: left;
  vertical-align: top;
}

.users-table th {
  color: var(--muted);
  font-size: 0.75rem;
  font-weight: 700;
  letter-spacing: 0.05em;
  text-transform: uppercase;
}

.users-table td {
  color: var(--ink);
  font-size: 0.92rem;
}

.role-badge,
.status-badge {
  border-radius: 999px;
  display: inline-flex;
  font-size: 0.78rem;
  font-weight: 700;
  padding: 4px 10px;
  white-space: nowrap;
}

.role-badge.tone-teal {
  background: #e7f7f2;
  color: #0d6b58;
}
.role-badge.tone-blue {
  background: #eaf1fb;
  color: #2a5d9f;
}
.role-badge.tone-amber {
  background: #fff6e5;
  color: #8a6200;
}
.role-badge.tone-slate {
  background: #eef0f4;
  color: #3d4656;
}
.role-badge.tone-muted {
  background: #f2f4f3;
  color: #5c6b63;
}

.status-badge.status-active {
  background: #e8f7ec;
  color: #1b6b3a;
}
.status-badge.status-inactive {
  background: #f2f4f3;
  color: #5c6b63;
}
.status-badge.status-suspended {
  background: #fff0ee;
  color: #a13b2c;
}

@media (max-width: 1100px) {
  .summary-grid {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }
}

@media (max-width: 860px) {
  .admin-hero {
    grid-template-columns: 1fr;
  }

  .admin-hero-actions {
    grid-template-columns: 1fr;
  }

  .summary-grid,
  .filters {
    grid-template-columns: 1fr;
  }
}
</style>
