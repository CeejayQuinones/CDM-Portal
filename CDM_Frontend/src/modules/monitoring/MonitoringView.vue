<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useAuthStore } from '../../stores/authStore'
import { ROLES } from '../../config/accessControl'
import { fetchMonitoringOverview, sendRiskNotification } from './services/monitoringApi'
import AdviserAlertsPanel from './components/AdviserAlertsPanel.vue'
import AiHelpPanel from './components/AiHelpPanel.vue'
import MyNoticesPanel from './components/MyNoticesPanel.vue'
import ProfessorGradebook from './components/ProfessorGradebook.vue'
import ProfessorRecordPanel from './components/ProfessorRecordPanel.vue'
import RegistrarGradePanel from './components/RegistrarGradePanel.vue'
import StudentStudyStudio from './components/StudentStudyStudio.vue'
import StudyPlansPanel from './components/StudyPlansPanel.vue'

const auth = useAuthStore()
const overview = ref(null)
const selectedId = ref(null)
const loading = ref(true)
const error = ref('')
const search = ref('')
const risk = ref('')
const termKey = ref('')
const activeTab = ref('warnings')
const noticeOpen = ref(false)
const noticeMessage = ref('')
const noticeSending = ref(false)
const noticeFeedback = ref('')
const noticeError = ref('')

const isStudent = computed(() => auth.currentRole === ROLES.STUDENT)
const isProfessor = computed(() => auth.currentRole === ROLES.PROFESSOR)
const isRegistrar = computed(() => auth.currentRole === ROLES.REGISTRAR_STAFF)
const isAdmin = computed(() => auth.currentRole === ROLES.ADMIN)
const students = computed(() => overview.value?.students || [])
const selected = computed(
  () => students.value.find((student) => student.student_id === selectedId.value) || students.value[0] || null,
)
const summary = computed(() => overview.value?.summary || {})
const recordSubjects = computed(() =>
  (selected.value?.subjects || []).map((subject) => ({
    ...subject,
    periods: {
      Midterm: subject.periods?.Midterm ?? subject.midterm_grade ?? null,
      Final: subject.periods?.Final ?? subject.finals_grade ?? subject.final_grade ?? null,
    },
  })),
)
const roleDescription = computed(() => {
  if (isStudent.value) return 'Your published official results for the selected academic term.'
  if (isProfessor.value) return 'Published results for students in your current assigned classes only.'
  if (isAdmin.value) return 'Institution-wide published grade signals. Review many students in one roster.'
  return 'Institution-wide published grade signals for the selected academic term.'
})
const tabs = computed(() => {
  if (isStudent.value) {
    return [
      { id: 'warnings', label: 'Early Warnings' },
      { id: 'studio', label: 'AI Study Studio' },
      { id: 'plans', label: 'Study Plan' },
      { id: 'notices', label: 'My Notices' },
      { id: 'ai-help', label: 'AI Help' },
    ]
  }
  if (isProfessor.value) {
    return [
      { id: 'gradebook', label: 'Gradebook (Spreadsheet)' },
      { id: 'warnings', label: 'Early Warnings' },
      { id: 'records', label: 'Student Records & Plans' },
      { id: 'plans', label: 'Study Plans' },
      { id: 'alerts', label: 'Adviser Alerts' },
      { id: 'ai-help', label: 'AI Help' },
    ]
  }
  if (isRegistrar.value) {
    return [
      { id: 'warnings', label: 'Early Warnings' },
      { id: 'review', label: 'Grade Review' },
      { id: 'alerts', label: 'Adviser Alerts' },
      { id: 'ai-help', label: 'AI Help' },
    ]
  }
  return [
    { id: 'warnings', label: 'Early Warnings' },
    { id: 'review', label: 'Grade Review' },
    { id: 'alerts', label: 'Adviser Alerts' },
    { id: 'ai-help', label: 'AI Help' },
  ]
})

const load = async ({ preserveSelection = false } = {}) => {
  loading.value = true
  error.value = ''
  const previous = preserveSelection ? selectedId.value : null
  const [academicYearId, semesterId] = termKey.value ? termKey.value.split(':').map(Number) : []

  try {
    overview.value = await fetchMonitoringOverview({
      academic_year_id: academicYearId || undefined,
      semester_id: semesterId || undefined,
      search: search.value.trim() || undefined,
      risk: risk.value || undefined,
    })
    if (!termKey.value && overview.value?.selected_term) {
      termKey.value = String(overview.value.selected_term.academic_year_id) + ':' + String(overview.value.selected_term.semester_id)
    }
    const stillVisible = students.value.some((student) => student.student_id === previous)
    selectedId.value = stillVisible
      ? previous
      : isAdmin.value
        ? null
        : students.value[0]?.student_id || null
  } catch (requestError) {
    error.value = requestError.response?.data?.message || 'Academic Monitoring is temporarily unavailable.'
  } finally {
    loading.value = false
  }
}

const resetFilters = () => {
  search.value = ''
  risk.value = ''
  load()
}

const filterByRisk = (level) => {
  risk.value = risk.value === level ? '' : level
  load()
}

const toggleStudent = (studentId) => {
  selectedId.value = selectedId.value === studentId ? null : studentId
}

const focusSubjects = (student) =>
  (student.subjects || [])
    .filter((subject) => subject.risk_level === 'high' || subject.risk_level === 'moderate')
    .map((subject) => subject.subject_code)
    .join(', ') || '—'

const openStudent = (studentId) => {
  selectedId.value = studentId
  activeTab.value = 'warnings'
}

const selectedHasLowGrade = computed(() => {
  const student = selected.value
  if (!student) return false
  if (student.risk_level === 'high' || student.risk_level === 'moderate' || student.trend === 'declining') return true
  return (student.subjects || []).some((subject) =>
    [subject.midterm_grade, subject.finals_grade, subject.final_grade, subject.average_grade].some(
      (grade) => grade != null && Number(grade) < 75,
    ),
  )
})

const defaultLowGradeNotice = 'A grade in this class is below 75. Please review that subject, complete missed work, and see me during consultation hours.'

const toggleNotice = () => {
  noticeOpen.value = !noticeOpen.value
  noticeFeedback.value = ''
  noticeError.value = ''
  if (noticeOpen.value && !noticeMessage.value.trim() && selectedHasLowGrade.value) {
    noticeMessage.value = defaultLowGradeNotice
  }
}

const sendStudentNotice = async () => {
  if (!selected.value?.student_id || noticeSending.value) return
  noticeSending.value = true
  noticeFeedback.value = ''
  noticeError.value = ''
  try {
    const notice = await sendRiskNotification(
      selected.value.student_id,
      noticeMessage.value,
      selectedHasLowGrade.value ? 'Low grade notice' : 'Academic support notice',
    )
    noticeFeedback.value = notice.duplicate
      ? 'An identical recent notice already exists.'
      : 'Notice sent. The student will see it under My Notices.'
    noticeMessage.value = ''
  } catch (requestError) {
    noticeError.value = requestError.response?.data?.message || 'Unable to send this notice.'
  } finally {
    noticeSending.value = false
  }
}

const formatGrade = (value) => (value === null || value === undefined ? 'Not published' : Number(value).toFixed(2))
const formatChange = (value) => {
  if (value === null || value === undefined) return 'Insufficient data'
  const number = Number(value)
  return (number > 0 ? '+' : '') + number.toFixed(2)
}
const termLabel = (term) => term.academic_year + ' · ' + term.semester

watch(
  () => selected.value?.student_id,
  () => {
    noticeOpen.value = false
    noticeMessage.value = ''
    noticeFeedback.value = ''
    noticeError.value = ''
  },
)
watch(
  () => auth.currentUser?.id,
  () => load(),
)
watch(
  tabs,
  (items) => {
    if (!items.some((tab) => tab.id === activeTab.value)) {
      activeTab.value = items[0]?.id || 'warnings'
    }
  },
  { immediate: true },
)
onMounted(load)
</script>

<template>
  <main class="monitoring-page" :class="{ bulk: isAdmin }">
    <header class="monitoring-header">
      <div>
        <p class="eyebrow">Academic Monitoring</p>
        <h1>Academic risk overview</h1>
        <p>{{ roleDescription }}</p>
      </div>
      <button class="button secondary" type="button" :disabled="loading" @click="load({ preserveSelection: true })">
        {{ loading ? 'Refreshing…' : 'Refresh' }}
      </button>
    </header>

    <nav class="monitoring-tabs" aria-label="Academic Monitoring sections">
      <button
        v-for="tab in tabs"
        :key="tab.id"
        type="button"
        :class="{ active: activeTab === tab.id }"
        :aria-current="activeTab === tab.id ? 'page' : undefined"
        @click="activeTab = tab.id"
      >
        {{ tab.label }}
      </button>
    </nav>

    <section v-show="activeTab === 'warnings'" class="monitoring-tab-panel">

    <p v-if="error" class="state-message error" role="alert">
      <span>{{ error }}</span>
      <button type="button" @click="load()">Try again</button>
    </p>

    <form v-if="!isStudent" class="filters" aria-label="Monitoring filters" @submit.prevent="load()">
      <label>
        Search students
        <input v-model="search" type="search" placeholder="Name or student number" />
      </label>
      <label>
        Academic term
        <select v-model="termKey" @change="load()">
          <option v-if="!overview?.terms?.length" value="">No configured term</option>
          <option
            v-for="term in overview?.terms || []"
            :key="term.academic_year_id + ':' + term.semester_id"
            :value="term.academic_year_id + ':' + term.semester_id"
          >
            {{ termLabel(term) }}
          </option>
        </select>
      </label>
      <label>
        Risk level
        <select v-model="risk">
          <option value="">All levels</option>
          <option value="high">High</option>
          <option value="moderate">Moderate</option>
          <option value="stable">Stable</option>
          <option value="insufficient">Insufficient data</option>
        </select>
      </label>
      <div class="filter-actions">
        <button class="button primary" type="submit" :disabled="loading">Apply</button>
        <button class="button secondary" type="button" :disabled="loading" @click="resetFilters">Clear</button>
      </div>
    </form>

    <label v-else-if="overview?.terms?.length > 1" class="student-term">
      Academic term
      <select v-model="termKey" @change="load()">
        <option
          v-for="term in overview.terms"
          :key="term.academic_year_id + ':' + term.semester_id"
          :value="term.academic_year_id + ':' + term.semester_id"
        >
          {{ termLabel(term) }}
        </option>
      </select>
    </label>

    <section class="summary-grid" aria-label="Academic risk summary">
      <button
        v-for="card in [
          { level: 'high', label: 'High', hint: 'Severe or repeated signals', count: summary.high || 0 },
          { level: 'moderate', label: 'Moderate', hint: 'One signal merits review', count: summary.moderate || 0 },
          { level: 'stable', label: 'Stable', hint: 'No material signal found', count: summary.stable || 0 },
          { level: 'insufficient', label: 'Insufficient data', hint: 'No published classification yet', count: summary.insufficient || 0 },
        ]"
        :key="card.level"
        type="button"
        :data-level="card.level"
        :class="{ active: risk === card.level }"
        :aria-pressed="risk === card.level"
        @click="filterByRisk(card.level)"
      >
        <span>{{ card.label }}</span>
        <strong>{{ card.count }}</strong>
        <small>{{ card.hint }}</small>
      </button>
    </section>

    <p v-if="loading" class="state-message" role="status">Loading published academic signals…</p>

    <div v-else-if="students.length && isAdmin" class="bulk-roster">
      <div class="panel-heading">
        <div>
          <p class="eyebrow">Selected term</p>
          <h2>Student risk roster</h2>
          <p>Scan every published monitoring record, then open a row for subject-level signals.</p>
        </div>
        <span>{{ students.length }}</span>
      </div>
      <div class="table-scroll bulk-scroll">
        <table>
          <thead>
            <tr>
              <th>Student</th>
              <th>Program</th>
              <th>Risk</th>
              <th>Trend</th>
              <th>Completeness</th>
            </tr>
          </thead>
          <tbody>
            <template v-for="student in students" :key="student.student_id">
              <tr
                :class="{ active: selectedId === student.student_id }"
                role="button"
                tabindex="0"
                @click="toggleStudent(student.student_id)"
                @keydown.enter.prevent="toggleStudent(student.student_id)"
              >
                <td>
                  <strong>{{ student.student_name }}</strong>
                  <small>{{ student.student_number }}</small>
                </td>
                <td>
                  {{ student.course_code || 'Course unavailable' }}
                  <small>Year {{ student.year_level || '—' }} · {{ student.section_name || student.section || 'Section unavailable' }}</small>
                </td>
                <td><span class="risk-badge" :data-level="student.risk_level">{{ student.risk_label }}</span></td>
                <td>{{ student.trend_label }}</td>
                <td>
                  {{ student.data_completeness?.label || '—' }}
                  <small>
                    {{ student.data_completeness?.published_subjects || 0 }} /
                    {{ student.data_completeness?.expected_subjects || 0 }} published
                  </small>
                </td>
              </tr>
              <tr v-if="selectedId === student.student_id" class="bulk-detail">
                <td colspan="5">
                  <p>{{ student.headline }}</p>
                  <p v-if="focusSubjects(student) !== '—'"><strong>Focus subjects:</strong> {{ focusSubjects(student) }}</p>
                  <ul>
                    <li v-for="reason in student.reasons" :key="reason">{{ reason }}</li>
                  </ul>
                  <div v-if="student.subjects?.length" class="table-scroll nested">
                    <table>
                      <thead>
                        <tr>
                          <th>Subject</th>
                          <th>Midterm</th>
                          <th>Finals</th>
                          <th>Official final</th>
                          <th>Change</th>
                          <th>Trend</th>
                          <th>Signal</th>
                        </tr>
                      </thead>
                      <tbody>
                        <tr v-for="subject in student.subjects" :key="subject.enrollment_subject_id || subject.subject_code">
                          <td>
                            <strong>{{ subject.subject_code }}</strong>
                            <small>{{ subject.subject_name }}</small>
                          </td>
                          <td>{{ formatGrade(subject.midterm_grade) }}</td>
                          <td>{{ formatGrade(subject.finals_grade) }}</td>
                          <td>{{ formatGrade(subject.final_grade) }}</td>
                          <td>{{ formatChange(subject.checkpoint_change) }}</td>
                          <td>{{ subject.trend === 'insufficient' ? 'Insufficient data' : subject.trend }}</td>
                          <td><span class="risk-badge" :data-level="subject.risk_level">{{ subject.risk_label }}</span></td>
                        </tr>
                      </tbody>
                    </table>
                  </div>
                  <p v-else class="empty-state">No published official grade snapshots are available for this student.</p>
                </td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>
    </div>

    <div v-else-if="students.length" class="monitoring-workspace">
      <aside v-if="!isStudent" class="student-panel" aria-label="Monitored students">
        <div class="panel-heading">
          <div>
            <p class="eyebrow">Selected term</p>
            <h2>Students</h2>
          </div>
          <span>{{ students.length }}</span>
        </div>
        <div class="student-list">
          <button
            v-for="student in students"
            :key="student.student_id"
            type="button"
            :class="{ active: selected?.student_id === student.student_id }"
            @click="selectedId = student.student_id"
          >
            <span>
              <strong>{{ student.student_name }}</strong>
              <small>{{ student.student_number }} · {{ student.course_code || 'Course unavailable' }}</small>
            </span>
            <em class="risk-badge" :data-level="student.risk_level">{{ student.risk_label }}</em>
          </button>
        </div>
      </aside>

      <section v-if="selected" class="assessment-panel" aria-live="polite">
        <header class="assessment-heading">
          <div>
            <p class="eyebrow">{{ isStudent ? 'My official data' : selected.student_number }}</p>
            <h2>{{ isStudent ? 'My academic monitoring' : selected.student_name }}</h2>
            <p>{{ selected.headline }}</p>
          </div>
          <div class="assessment-actions">
            <span class="risk-badge large" :data-level="selected.risk_level">{{ selected.risk_label }}</span>
            <button
              v-if="isProfessor"
              class="button primary"
              type="button"
              @click="toggleNotice"
            >
              {{ noticeOpen ? 'Close notice' : selectedHasLowGrade ? 'Notify student — low grade' : 'Notify student' }}
            </button>
          </div>
        </header>

        <form v-if="isProfessor && noticeOpen" class="notice-composer" @submit.prevent="sendStudentNotice">
          <label>
            Message to {{ selected.student_name }}
            <textarea
              v-model="noticeMessage"
              rows="4"
              maxlength="1000"
              placeholder="Tell the student which subject is low and what to do next."
            />
          </label>
          <div class="notice-composer-footer">
            <p v-if="noticeFeedback" class="notice-ok" role="status">{{ noticeFeedback }}</p>
            <p v-else-if="noticeError" class="notice-err" role="alert">{{ noticeError }}</p>
            <button class="button primary" type="submit" :disabled="noticeSending">
              {{ noticeSending ? 'Sending…' : 'Send notice' }}
            </button>
          </div>
        </form>

        <div class="assessment-metrics">
          <article>
            <span>Academic trend</span>
            <strong>{{ selected.trend_label }}</strong>
          </article>
          <article>
            <span>Subjects analyzed</span>
            <strong>{{ selected.subjects_analyzed }}</strong>
          </article>
          <article>
            <span>Data completeness</span>
            <strong>{{ selected.data_completeness.label }}</strong>
            <small>
              {{ selected.data_completeness.published_subjects }} of
              {{ selected.data_completeness.expected_subjects }} expected subjects published
            </small>
          </article>
        </div>

        <section class="reason-card">
          <h3>Why this level was assigned</h3>
          <ul>
            <li v-for="reason in selected.reasons" :key="reason">{{ reason }}</li>
          </ul>
        </section>

        <section>
          <div class="section-heading">
            <div>
              <h3>Published subject signals</h3>
              <p>Each trend compares checkpoints inside the same subject.</p>
            </div>
          </div>
          <div v-if="selected.subjects.length" class="table-scroll">
            <table>
              <thead>
                <tr>
                  <th>Subject</th>
                  <th>Midterm</th>
                  <th>Finals</th>
                  <th>Official final</th>
                  <th>Change</th>
                  <th>Trend</th>
                  <th>Signal</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="subject in selected.subjects" :key="subject.enrollment_subject_id">
                  <td>
                    <strong>{{ subject.subject_code }}</strong>
                    <small>{{ subject.subject_name }}</small>
                  </td>
                  <td>{{ formatGrade(subject.midterm_grade) }}</td>
                  <td>{{ formatGrade(subject.finals_grade) }}</td>
                  <td>{{ formatGrade(subject.final_grade) }}</td>
                  <td>{{ formatChange(subject.checkpoint_change) }}</td>
                  <td>{{ subject.trend === 'insufficient' ? 'Insufficient data' : subject.trend }}</td>
                  <td><span class="risk-badge" :data-level="subject.risk_level">{{ subject.risk_label }}</span></td>
                </tr>
              </tbody>
            </table>
          </div>
          <p v-else class="empty-state">No published official grade snapshots are available for this term.</p>
        </section>

        <details class="method-card">
          <summary>How Academic Monitoring works</summary>
          <p>{{ overview.methodology?.source }}</p>
          <p>{{ overview.methodology?.risk_rule }}</p>
          <p>{{ overview.methodology?.trend_rule }}</p>
          <p><strong>Excluded:</strong> {{ (overview.methodology?.excluded || []).join(', ') }}.</p>
        </details>
      </section>
    </div>

    <section v-else-if="!error" class="empty-state large">
      <h2>No monitoring records for this view</h2>
      <p v-if="isStudent">
        Academic Monitoring will show an explicit assessment after official grades are published for a finalized enrollment.
      </p>
      <p v-else>Try another term or clear the search and risk filters.</p>
    </section>
    </section>

    <section v-if="isStudent && activeTab === 'studio'" class="monitoring-tab-panel">
      <StudentStudyStudio :key="`studio-${auth.currentUser?.id}`" />
    </section>

    <section v-if="isProfessor && activeTab === 'gradebook'" class="monitoring-tab-panel">
      <ProfessorGradebook @changed="() => load({ preserveSelection: true })" />
    </section>

    <section v-if="isProfessor && activeTab === 'records'" class="monitoring-tab-panel">
      <label v-if="students.length" class="student-term">
        Student
        <select v-model="selectedId">
          <option v-for="student in students" :key="student.student_id" :value="student.student_id">
            {{ student.student_name }} · {{ student.student_number }}
          </option>
        </select>
      </label>
      <ProfessorRecordPanel
        v-if="selected"
        :key="`records-${selected.student_id}`"
        :student-id="selected.student_id"
        :subjects="recordSubjects"
        viewer-role="professor"
      />
      <p v-else class="empty-state">Select a student to view approved grades and send a study plan.</p>
    </section>

    <section v-if="(isRegistrar || isAdmin) && activeTab === 'review'" class="monitoring-tab-panel">
      <RegistrarGradePanel :key="`review-${auth.currentUser?.id}`" />
    </section>

    <section v-if="(isStudent || isProfessor) && activeTab === 'plans'" class="monitoring-tab-panel">
      <StudyPlansPanel :student-id="selectedId" />
    </section>
    <section v-if="!isStudent && activeTab === 'alerts'" class="monitoring-tab-panel">
      <AdviserAlertsPanel @select-student="openStudent" />
    </section>
    <section v-if="isStudent && activeTab === 'notices'" class="monitoring-tab-panel">
      <MyNoticesPanel @open-monitoring="activeTab = 'warnings'" />
    </section>
    <section v-if="activeTab === 'ai-help'" class="monitoring-tab-panel">
      <AiHelpPanel :student-id="selectedId" :assessment="selected" />
    </section>

    <button type="button" class="ai-help-fab" @click="activeTab = 'ai-help'">AI Help</button>
  </main>
</template>

<style scoped>
.monitoring-page {
  width: 100%;
  max-width: 1420px;
  min-width: 0;
  margin: 0 auto;
  padding: clamp(16px, 2.5vw, 32px);
  color: var(--text-primary);
}

.monitoring-page.bulk {
  max-width: 1680px;
}

.monitoring-tab-panel,
.monitoring-workspace,
.assessment-panel,
.assessment-panel > section,
.assessment-metrics,
.method-card,
.assessment-heading > div,
.section-heading > div,
.student-panel,
.student-list button > span {
  min-width: 0;
}

.monitoring-header,
.assessment-heading,
.panel-heading,
.section-heading {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
}

.monitoring-header h1,
.assessment-heading h2,
.panel-heading h2,
.section-heading h3 {
  margin: 4px 0 6px;
}

.monitoring-header p,
.assessment-heading p,
.section-heading p {
  margin: 0;
  color: var(--tab-inactive, var(--text-secondary));
  overflow-wrap: anywhere;
}

.assessment-actions {
  align-items: flex-end;
  display: grid;
  gap: 10px;
  justify-items: end;
}

.notice-composer {
  display: grid;
  gap: 10px;
  margin: 0 0 8px;
  padding: 14px;
  border: 1px solid var(--border-color);
  border-radius: 12px;
  background: var(--bg-surface-alt, #f4f8f5);
}

.notice-composer label {
  display: grid;
  gap: 6px;
  color: var(--text-secondary);
  font-size: 0.84rem;
  font-weight: 700;
}

.notice-composer textarea {
  width: 100%;
  min-height: 96px;
  resize: vertical;
  border: 1px solid var(--border-color);
  border-radius: 8px;
  padding: 10px;
  background: var(--bg-input, #fff);
  color: var(--text-primary);
  font: inherit;
}

.notice-composer-footer {
  align-items: center;
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  justify-content: space-between;
}

.notice-ok { margin: 0; color: var(--success, #166534); }
.notice-err { margin: 0; color: var(--danger, #9f2d25); }

.eyebrow {
  margin: 0;
  color: var(--accent);
  font-size: 0.78rem;
  font-weight: 800;
  letter-spacing: 0.08em;
  text-transform: uppercase;
}

.monitoring-tabs {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
  margin-top: 24px;
  overflow: visible;
  border-bottom: 1px solid var(--border-color);
}

.monitoring-tabs button {
  flex: 0 1 auto;
  min-height: 44px;
  border: 0;
  border-bottom: 3px solid transparent;
  padding: 9px 14px;
  background: transparent;
  color: var(--text-secondary);
  font: inherit;
  font-weight: 750;
  white-space: nowrap;
  cursor: pointer;
}

.monitoring-tabs button:hover {
  background: var(--tab-hover, var(--bg-surface-alt));
  color: var(--text-primary);
}

.monitoring-tabs button.active {
  border-bottom-color: var(--accent);
  color: var(--tab-active, var(--accent));
}

.ai-help-fab {
  position: fixed;
  right: 24px;
  bottom: 24px;
  z-index: 30;
  min-height: 44px;
  border: 0;
  border-radius: 999px;
  padding: 12px 18px;
  background: var(--accent);
  color: var(--text-on-accent, #fff);
  font: inherit;
  font-weight: 800;
  box-shadow: var(--shadow-soft);
  cursor: pointer;
}

.monitoring-tab-panel {
  margin-top: 20px;
}

.button,
.state-message button {
  min-height: 40px;
  border: 1px solid var(--border-strong);
  border-radius: 8px;
  padding: 8px 14px;
  font: inherit;
  font-weight: 700;
  cursor: pointer;
}

.button.primary {
  border-color: var(--accent);
  background: var(--accent);
  color: var(--text-on-accent);
}

.button.secondary,
.state-message button {
  background: var(--bg-surface);
  color: var(--accent);
}

.button:disabled {
  cursor: wait;
  opacity: 0.62;
}

.filters {
  display: grid;
  grid-template-columns: minmax(220px, 1.4fr) repeat(2, minmax(180px, 1fr)) auto;
  align-items: end;
  gap: 12px;
  margin-top: 24px;
  padding: 16px;
  border: 1px solid var(--border-color);
  border-radius: 12px;
  background: var(--bg-surface);
  box-shadow: var(--shadow-soft);
}

.filters label,
.student-term {
  display: grid;
  gap: 7px;
  color: var(--text-secondary);
  font-size: 0.82rem;
  font-weight: 700;
}

.filters input,
.filters select,
.student-term select {
  width: 100%;
  min-height: 42px;
  border: 1px solid var(--border-color);
  border-radius: 8px;
  padding: 8px 10px;
  background: var(--bg-input);
  color: var(--text-primary);
  font: inherit;
}

.filter-actions {
  display: flex;
  gap: 8px;
}

.student-term {
  max-width: 360px;
  margin-top: 22px;
}

.summary-grid {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 12px;
  margin: 20px 0;
}

.summary-grid button,
.assessment-panel,
.student-panel,
.bulk-roster {
  border: 1px solid var(--border-default);
  background: var(--bg-surface);
  box-shadow: var(--shadow-soft);
}

.summary-grid button {
  position: relative;
  overflow: hidden;
  min-width: 0;
  min-height: 116px;
  border-radius: 12px;
  padding: 16px 18px;
  text-align: left;
  font: inherit;
  color: inherit;
  cursor: pointer;
}

.summary-grid button::before {
  position: absolute;
  inset: 0 auto 0 0;
  width: 4px;
  background: var(--signal-color);
  content: '';
}

.summary-grid button.active {
  border-color: var(--signal-color);
  box-shadow: inset 0 0 0 1px var(--signal-color);
}

.summary-grid button[data-level='high'] { --signal-color: var(--danger); }
.summary-grid button[data-level='moderate'] { --signal-color: var(--warning); }
.summary-grid button[data-level='stable'] { --signal-color: var(--success); }
.summary-grid button[data-level='insufficient'] { --signal-color: var(--text-muted); }

.summary-grid span,
.assessment-metrics span {
  color: var(--text-secondary);
  font-size: 0.84rem;
  font-weight: 700;
}

.summary-grid strong {
  display: block;
  margin: 3px 0;
  font-size: 2rem;
}

.summary-grid small,
.assessment-metrics small {
  color: var(--text-muted);
}

.monitoring-workspace {
  display: grid;
  grid-template-columns: minmax(220px, 280px) minmax(0, 1fr);
  align-items: start;
  gap: 16px;
}

.monitoring-workspace > .assessment-panel:only-child {
  grid-column: 1 / -1;
}

.student-panel,
.assessment-panel,
.bulk-roster {
  border-radius: 14px;
}

.bulk-roster {
  overflow: hidden;
}

.bulk-roster .panel-heading {
  align-items: flex-start;
}

.bulk-roster .panel-heading p {
  margin: 0;
  color: var(--text-secondary);
}

.bulk-scroll {
  max-height: min(70vh, 820px);
  padding-bottom: 8px;
}

.bulk-roster table {
  min-width: 0;
}

.bulk-roster tbody tr[role='button'] {
  cursor: pointer;
}

.bulk-roster tbody tr[role='button']:hover,
.bulk-roster tbody tr.active {
  background: var(--bg-surface-alt);
}

.bulk-detail td {
  background: var(--bg-surface-alt);
  padding: 16px 18px 20px;
}

.bulk-detail p,
.bulk-detail ul {
  margin: 0 0 12px;
  color: var(--text-secondary);
}

.bulk-detail .table-scroll.nested {
  margin-top: 0;
}

.student-panel {
  position: sticky;
  top: 16px;
  max-height: calc(100vh - 32px);
  overflow: hidden;
}

.panel-heading {
  padding: 16px;
  border-bottom: 1px solid var(--border-color);
}

.panel-heading > span {
  border-radius: 999px;
  padding: 4px 9px;
  background: var(--bg-surface-alt);
  color: var(--text-secondary);
  font-weight: 800;
}

.student-list {
  display: grid;
  max-height: calc(100vh - 150px);
  overflow: auto;
}

.student-list button {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  border: 0;
  border-bottom: 1px solid var(--border-color);
  padding: 13px 15px;
  background: transparent;
  color: var(--text-primary);
  text-align: left;
  cursor: pointer;
}

.student-list button :is(strong, small),
.assessment-metrics :is(strong, small),
.reason-card li,
.method-card p {
  overflow-wrap: anywhere;
}

.student-list button:hover,
.student-list button.active {
  background: var(--bg-surface-alt);
}

.student-list button.active {
  box-shadow: inset 3px 0 var(--accent);
}

.student-list button span,
table td:first-child {
  display: grid;
  gap: 3px;
}

.student-list small,
table td small {
  color: var(--text-muted);
}

.assessment-panel {
  display: grid;
  gap: 22px;
  padding: clamp(18px, 2.4vw, 28px);
}

.risk-badge {
  display: inline-flex;
  align-items: center;
  width: max-content;
  border-radius: 999px;
  padding: 4px 9px;
  background: var(--bg-surface-alt);
  color: var(--text-secondary);
  font-size: 0.76rem;
  font-style: normal;
  font-weight: 800;
  text-transform: capitalize;
  flex-shrink: 0;
}

.risk-badge.large {
  padding: 7px 12px;
  font-size: 0.86rem;
}

.risk-badge[data-level='high'] { background: var(--danger-bg); color: var(--danger); }
.risk-badge[data-level='moderate'] { background: var(--warning-bg); color: var(--warning); }
.risk-badge[data-level='stable'] { background: var(--success-bg); color: var(--success); }

.assessment-metrics {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 10px;
}

.assessment-metrics article {
  min-width: 0;
  min-height: 92px;
  border: 1px solid var(--border-color);
  border-radius: 10px;
  padding: 13px;
  background: var(--bg-surface-alt);
}

.assessment-metrics strong {
  display: block;
  margin-top: 5px;
  font-size: 1.06rem;
}

.reason-card,
.method-card,
.empty-state {
  border: 1px solid var(--border-color);
  border-radius: 10px;
  padding: 16px;
  background: var(--bg-surface-alt);
}

.reason-card h3 {
  margin: 0 0 8px;
}

.reason-card ul {
  margin: 0;
  padding-left: 20px;
}

.reason-card li + li {
  margin-top: 6px;
}

.table-scroll {
  width: 100%;
  max-width: 100%;
  min-width: 0;
  overflow-x: auto;
  overscroll-behavior-inline: contain;
  margin-top: 12px;
  border: 1px solid var(--border-color);
  border-radius: 10px;
}

table {
  width: 100%;
  min-width: 760px;
  border-collapse: collapse;
}

th,
td {
  border-bottom: 1px solid var(--border-color);
  padding: 11px 12px;
  text-align: left;
  vertical-align: middle;
}

th {
  background: var(--bg-surface-alt);
  color: var(--text-secondary);
  font-size: 0.77rem;
  letter-spacing: 0.04em;
  text-transform: uppercase;
}

tbody tr:last-child td {
  border-bottom: 0;
}

.method-card summary {
  color: var(--accent);
  font-weight: 800;
  cursor: pointer;
}

.method-card p {
  color: var(--text-secondary);
}

.state-message {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin: 20px 0;
  border: 1px solid var(--border-color);
  border-radius: 10px;
  padding: 14px 16px;
  background: var(--bg-surface);
}

.state-message.error {
  border-color: var(--danger);
  background: var(--danger-bg);
  color: var(--danger);
}

.empty-state {
  color: var(--text-secondary);
  text-align: center;
}

.empty-state.large {
  margin-top: 20px;
  padding: 40px 20px;
  background: var(--bg-surface);
}

.empty-state h2 {
  margin-top: 0;
  color: var(--text-primary);
}

@media (max-width: 1400px) {
  .filters {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .summary-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .monitoring-workspace {
    grid-template-columns: minmax(200px, 240px) minmax(0, 1fr);
  }

  .assessment-metrics {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (max-width: 1200px) {

  .monitoring-workspace {
    grid-template-columns: minmax(0, 1fr);
  }

  .student-panel {
    position: static;
    max-height: none;
  }

  .student-list {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    max-height: 330px;
  }
}

@media (max-width: 680px) {
  .monitoring-page {
    padding: 0;
  }

  .monitoring-header,
  .assessment-heading,
  .assessment-actions,
  .notice-composer-footer {
    display: grid;
  }

  .assessment-actions,
  .notice-composer-footer .button {
    width: 100%;
    justify-items: stretch;
  }

  .filters,
  .student-list {
    grid-template-columns: minmax(0, 1fr);
  }

  .summary-grid,
  .assessment-metrics {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 10px;
  }

  .summary-grid button,
  .assessment-metrics article {
    min-height: 78px;
    padding: 12px;
  }

  .filter-actions,
  .filter-actions .button,
  .monitoring-header .button {
    width: 100%;
  }

  .assessment-panel {
    padding: 14px;
  }

  .monitoring-tabs {
    margin-right: -12px;
    margin-left: -12px;
    padding: 0 12px 4px;
  }
}

@media (max-width: 380px) {
  .summary-grid,
  .assessment-metrics { grid-template-columns: minmax(0, 1fr); }
}
</style>
