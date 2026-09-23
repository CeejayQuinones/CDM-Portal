<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useAuthStore } from '../../stores/authStore'
import { ROLES } from '../../config/accessControl'
import {
  fetchAiStatus,
  fetchEarlyWarnings,
  fetchMyRisk,
  fetchMySentPlans,
  fetchStudentSentPlans,
  markSentPlanRead,
} from './services/monitoringApi'
import AiHelpChatbot from './components/AiHelpChatbot.vue'
import ProfessorRecordPanel from './components/ProfessorRecordPanel.vue'
import StudentStudyStudio from './components/StudentStudyStudio.vue'

const auth = useAuthStore()
const data = ref(null)
const selectedId = ref(null)
const loading = ref(true)
const error = ref('')
const search = ref('')
const filters = reactive({ department: '', course: '', section: '' })
const filterOptions = reactive({ departments: [], courses: [], sections: [] })
const sentPlans = ref([])
const aiStatus = ref({ live_ai_configured: false, provider: 'gemini' })

const isStudent = computed(() => auth.currentRole === ROLES.STUDENT)
const isProfessor = computed(() => auth.currentRole === ROLES.PROFESSOR)
const isAdmin = computed(() => auth.currentRole === ROLES.ADMIN || auth.currentRole === ROLES.REGISTRAR_STAFF)

const students = computed(() => data.value?.students || [])
const filteredStudents = computed(() => {
  const q = search.value.trim().toLowerCase()
  if (!q) return students.value
  return students.value.filter((s) =>
    [s.student_name, s.student_number, s.course_code, s.section_name, s.department_name]
      .filter(Boolean)
      .some((v) => String(v).toLowerCase().includes(q)),
  )
})
const selected = computed(
  () => filteredStudents.value.find((s) => s.student_id === selectedId.value) || filteredStudents.value[0] || null,
)

const title = computed(() => {
  if (isStudent.value) return 'My Academic Monitoring'
  if (isProfessor.value) return 'Faculty Monitoring'
  return 'Academic Monitoring'
})

watch(selected, (student) => {
  if (student) loadPlans(student.student_id)
  else sentPlans.value = []
})

async function load() {
  loading.value = true
  error.value = ''
  try {
    aiStatus.value = await fetchAiStatus().catch(() => ({ live_ai_configured: false, provider: 'gemini' }))
    if (isStudent.value) {
      data.value = await fetchMyRisk()
    } else {
      const params = {}
      if (isAdmin.value) {
        if (filters.department) params.department = filters.department
        if (filters.course) params.course = filters.course
        if (filters.section) params.section = filters.section
      }
      data.value = await fetchEarlyWarnings(params)
      filterOptions.departments = data.value?.filters?.departments || []
      filterOptions.courses = data.value?.filters?.courses || []
      filterOptions.sections = data.value?.filters?.sections || []
    }
    selectedId.value = students.value[0]?.student_id || null
  } catch (err) {
    error.value = err.response?.data?.message || 'Unable to load monitoring.'
  } finally {
    loading.value = false
  }
}

async function loadPlans(studentId) {
  try {
    const payload = isStudent.value ? await fetchMySentPlans() : await fetchStudentSentPlans(studentId)
    sentPlans.value = payload.plans || []
  } catch {
    sentPlans.value = []
  }
}

async function openPlan(plan) {
  if (isStudent.value && !plan.is_read) {
    try {
      const updated = await markSentPlanRead(plan.id)
      sentPlans.value = sentPlans.value.map((p) => (p.id === plan.id ? updated : p))
    } catch {
      /* ignore */
    }
  }
}

onMounted(load)
</script>

<template>
  <main class="page">
    <header class="head">
      <div>
        <p class="kicker">AI Monitoring</p>
        <h1>{{ title }}</h1>
        <p class="lead">
          {{
            isStudent
              ? 'Study Studio: flashcards, practice quizzes, and AI study plans from your weak topics.'
              : isProfessor
                ? 'Review assigned students, log weak topics, and send AI study plans.'
                : 'Browse students by department, course, and section.'
          }}
        </p>
      </div>
      <button type="button" class="btn" @click="load">Refresh</button>
    </header>

    <p v-if="error" class="alert" role="alert">{{ error }}</p>
    <p v-if="loading" class="muted">Loading…</p>

    <template v-else>
      <section v-if="!isStudent" class="stats">
        <article><span>High</span><strong>{{ data?.summary?.high || 0 }}</strong></article>
        <article><span>Moderate</span><strong>{{ data?.summary?.moderate || 0 }}</strong></article>
        <article><span>Stable</span><strong>{{ data?.summary?.low || 0 }}</strong></article>
        <article><span>Total</span><strong>{{ data?.summary?.total || students.length }}</strong></article>
      </section>

      <div class="layout" :class="{ solo: isStudent }">
        <aside v-if="!isStudent" class="list">
          <input v-model="search" type="search" placeholder="Search student…" />
          <template v-if="isAdmin">
            <select v-model="filters.department" @change="load">
              <option value="">All departments</option>
              <option v-for="d in filterOptions.departments" :key="d" :value="d">{{ d }}</option>
            </select>
            <select v-model="filters.course" @change="load">
              <option value="">All courses</option>
              <option v-for="c in filterOptions.courses" :key="c" :value="c">{{ c }}</option>
            </select>
            <select v-model="filters.section" @change="load">
              <option value="">All sections</option>
              <option v-for="s in filterOptions.sections" :key="s" :value="s">{{ s }}</option>
            </select>
          </template>

          <button
            v-for="student in filteredStudents"
            :key="student.student_id"
            type="button"
            class="row"
            :class="{ active: selected?.student_id === student.student_id }"
            @click="selectedId = student.student_id"
          >
            <strong>{{ student.student_name }}</strong>
            <span>{{ student.student_number }} · {{ student.course_code || '—' }} · {{ student.section_name || '—' }}</span>
            <em :class="student.risk_level">{{ student.risk_label }}</em>
          </button>
          <p v-if="!filteredStudents.length" class="muted">No students found.</p>
        </aside>

        <section v-if="selected" class="detail">
          <div class="card hero">
            <div>
              <h2>{{ isStudent ? 'Your standing' : selected.student_name }}</h2>
              <p>{{ selected.headline }}</p>
              <p class="meta">
                <span v-if="selected.department_name">{{ selected.department_name }}</span>
                <span v-if="selected.course_code">{{ selected.course_code }}</span>
                <span v-if="selected.section_name">Sec {{ selected.section_name }}</span>
              </p>
            </div>
            <div class="right">
              <span class="pill" :class="selected.risk_level">{{ selected.risk_label }}</span>
              <p>Avg <strong>{{ selected.average_grade ?? '—' }}</strong></p>
              <p>Trend <strong>{{ selected.trend_label || selected.trend }}</strong></p>
            </div>
          </div>

          <div class="card">
            <h3>Subjects</h3>
            <div v-if="selected.subjects?.length" class="subjects">
              <article v-for="subject in selected.subjects" :key="subject.subject_code">
                <strong>{{ subject.subject_code }}</strong>
                <span>{{ subject.subject_name }}</span>
                <em :class="subject.risk_level">{{ subject.average_grade }} · {{ subject.risk_level }}</em>
              </article>
            </div>
            <p v-else class="muted">No approved grades yet.</p>
          </div>

          <div class="card">
            <h3>{{ isStudent ? 'Study plans from instructors' : 'Sent plans' }}</h3>
            <article v-for="plan in sentPlans" :key="plan.id" class="plan" @click="openPlan(plan)">
              <strong>{{ plan.title }}</strong>
              <span>{{ plan.subject_code }} · {{ plan.topic }}</span>
              <pre>{{ plan.plan_body }}</pre>
            </article>
            <p v-if="!sentPlans.length" class="muted">No study plans yet.</p>
          </div>

          <StudentStudyStudio v-if="isStudent" />

          <ProfessorRecordPanel
            v-if="selected && (isProfessor || isAdmin || isStudent)"
            :student-id="selected.student_id"
            :subjects="selected.subjects || []"
            :viewer-role="isStudent ? 'student' : isProfessor ? 'professor' : 'staff'"
            @sent="loadPlans(selected.student_id)"
          />
        </section>

        <p v-else class="muted card">No academic record available.</p>
      </div>
    </template>

    <AiHelpChatbot
      :student-id="selected?.student_id || null"
      :student-name="selected?.student_name || (isStudent ? 'You' : 'Student')"
      :risk-label="selected?.risk_label || ''"
      :average-grade="selected?.average_grade ?? null"
      :trend-label="selected?.trend_label || ''"
      :subjects="selected?.subjects || []"
      :live-configured="Boolean(aiStatus.live_ai_configured)"
      :provider="aiStatus.provider || 'gemini'"
    />
  </main>
</template>

<style scoped>
.page {
  --ink: var(--student-heading, #1b2a22);
  --muted: var(--student-muted, #66706a);
  --line: var(--student-border, #d7dfd9);
  --surface: var(--student-surface, #fff);
  --soft: var(--student-surface-soft, #f5f7f6);
  --green: var(--student-accent, #106a2e);
  color: var(--ink);
  display: grid;
  gap: 16px;
}

.head {
  align-items: flex-start;
  display: flex;
  gap: 12px;
  justify-content: space-between;
}

.kicker {
  color: var(--green);
  font-size: 0.72rem;
  font-weight: 700;
  letter-spacing: 0.08em;
  margin: 0 0 4px;
  text-transform: uppercase;
}

h1 {
  font-size: clamp(1.4rem, 2.4vw, 1.85rem);
  margin: 0;
}

.lead,
.muted,
.meta,
.row span,
.subjects span,
.plan span {
  color: var(--muted);
}

.lead {
  margin: 6px 0 0;
  max-width: 52ch;
}

.btn,
.row {
  cursor: pointer;
}

.btn {
  background: var(--surface);
  border: 1px solid var(--line);
  border-radius: 8px;
  color: var(--green);
  font-weight: 700;
  min-height: 38px;
  padding: 0 12px;
}

.alert {
  background: #fff1f0;
  border: 1px solid #ffd1cc;
  border-radius: 8px;
  color: #9f2d25;
  margin: 0;
  padding: 10px 12px;
}

.stats {
  display: grid;
  gap: 10px;
  grid-template-columns: repeat(4, minmax(0, 1fr));
}

.stats article,
.card,
.list {
  background: var(--surface);
  border: 1px solid var(--line);
  border-radius: 10px;
}

.stats article {
  padding: 12px 14px;
}

.stats span {
  color: var(--muted);
  display: block;
  font-size: 0.8rem;
}

.stats strong {
  display: block;
  font-size: 1.4rem;
  margin-top: 2px;
}

.layout {
  display: grid;
  gap: 14px;
  grid-template-columns: 280px minmax(0, 1fr);
}

.layout.solo {
  grid-template-columns: 1fr;
}

.list {
  display: grid;
  gap: 8px;
  max-height: 72vh;
  overflow: auto;
  padding: 12px;
}

.list input,
.list select {
  border: 1px solid var(--line);
  border-radius: 8px;
  padding: 9px 10px;
}

.row {
  background: var(--soft);
  border: 1px solid var(--line);
  border-radius: 8px;
  display: grid;
  gap: 2px;
  padding: 10px;
  text-align: left;
}

.row.active {
  border-color: var(--green);
}

.row em,
.pill,
.subjects em {
  font-style: normal;
  font-size: 0.72rem;
  font-weight: 700;
  justify-self: start;
  margin-top: 4px;
  text-transform: uppercase;
}

.detail {
  display: grid;
  gap: 12px;
  min-width: 0;
}

.card {
  padding: 14px;
}

.hero {
  display: flex;
  gap: 16px;
  justify-content: space-between;
}

.hero h2,
.card h3 {
  margin: 0 0 6px;
}

.meta {
  display: flex;
  flex-wrap: wrap;
  gap: 8px 12px;
  margin: 8px 0 0;
}

.right {
  text-align: right;
}

.right p {
  margin: 6px 0 0;
}

.pill,
.row em,
.subjects em {
  border-radius: 999px;
  padding: 4px 8px;
}

.high,
em.high,
.pill.high {
  background: #fff1e8;
  color: #9a3412;
}

.moderate,
em.moderate,
.pill.moderate {
  background: #fff8db;
  color: #854d0e;
}

.low,
em.low,
.pill.low {
  background: #e8f7ee;
  color: #166534;
}

.subjects {
  display: grid;
  gap: 8px;
  grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
}

.subjects article,
.plan {
  background: var(--soft);
  border: 1px solid var(--line);
  border-radius: 8px;
  padding: 10px;
}

.subjects strong,
.plan strong {
  display: block;
}

.plan {
  cursor: pointer;
  margin-top: 8px;
}

.plan pre {
  font-family: inherit;
  line-height: 1.45;
  margin: 8px 0 0;
  white-space: pre-wrap;
}

@media (max-width: 900px) {
  .layout,
  .stats,
  .hero {
    grid-template-columns: 1fr;
    display: grid;
  }

  .list {
    max-height: none;
  }

  .right {
    text-align: left;
  }
}
</style>
