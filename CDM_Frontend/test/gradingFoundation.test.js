import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { parseGradePeriods } from '../src/modules/grading/gradePeriodPayload.js'

const view = readFileSync(new URL('../src/modules/grading/GradingView.vue', import.meta.url), 'utf8')
const access = readFileSync(new URL('../src/config/accessControl.js', import.meta.url), 'utf8')
const service = readFileSync(new URL('../src/modules/grading/gradingService.js', import.meta.url), 'utf8')

test('grading workspace exposes Phase 1 professor states and actions', () => {
  for (const text of ['My Classes', 'Search Student', 'Midterm', 'Finals', 'Add Assessment', 'Remove', 'Restore', 'Draft scores saved', 'No enrolled Students', 'Loading grading workspace']) assert.match(view, new RegExp(text, 'i'))
  assert.match(view, /const editable=computed/)
  assert.match(view, /type="number"/)
})

test('grading navigation is limited to professor and grading staff', () => {
  assert.match(access, /grading: \[ROLES\.STUDENT, ROLES\.PROFESSOR, ROLES\.REGISTRAR_STAFF, ROLES\.ADMIN\]/)
  assert.match(access, /label: 'My Classes'/)
  assert.match(access, /label: 'Grade Periods'/)
})

test('grading view uses shared semantic tokens and responsive table', () => {
  const css = readFileSync(new URL('../src/modules/grading/grading.css', import.meta.url), 'utf8')
  assert.match(css, /var\(--bg-surface/)
  assert.match(css, /overflow:auto/)
  assert.match(css, /position:sticky/)
  assert.match(css, /@media/)
})

test('Registrar grade-period load is isolated and validation errors are field-level', () => {
  assert.match(view, /else await loadPeriods\(\)/)
  assert.match(view, /openStaffTab\('reviews'\)/)
  assert.match(view, /activeYear=options\.value\.academic_years\?\.find\(y=>y\.status==='active'\)/)
  assert.match(view, /periodErrors\.academic_year_id/)
  assert.match(view, /periodErrors\.midterm_deadline/)
  assert.match(view, /midterm_opens_at:apiDate\(periodForm\.value\.midterm_opens_at\)/)
  assert.match(service, /Object\.values\(gradingValidationErrors\(error\)\)\.flat\(\)\.find\(Boolean\) \|\| error\.response\?\.data\?\.message/)
})

test('Registrar can edit the existing grade period and safely request deletion', () => {
  for (const text of ['Edit Grade Period', 'Delete Grade Period', 'Delete unused period', 'Existing grading history will never be removed']) assert.match(view, new RegExp(text, 'i'))
  assert.match(view, /@click="editPeriod\(item\)"/)
  assert.match(view, /editingPeriod\.value\?await gradingService\.updatePeriod/)
  assert.match(view, /gradingService\.deletePeriod\(periodToDelete\.value\.id,periodToDelete\.value\.version\)/)
  assert.match(service, /deletePeriod: \(id, version\) => apiClient\.delete\(`\/grading\/periods\/\$\{id\}`/)
  assert.match(view, /:disabled="!editable" @click="openAssessment\(\)"/)
})

test('Grade Periods parses and renders the paginated schedule contract', () => {
  const schedule = {
    id: 3,
    academic_year: { school_year: '2026-2027' },
    semester: { semester_name: 'First Semester' },
    midterm_state: { state: 'Open' },
    finals_state: { state: 'Upcoming' },
    status: 'active',
    version: 1,
  }
  const parsed = parseGradePeriods({ periods: { data: [schedule], total: 1 }, academic_years: [], semesters: [] })

  assert.deepEqual(parsed.periods, [schedule])
  assert.equal(parsed.options.periods.total, 1)
  assert.match(view, /parseGradePeriods\(await gradingService\.periods\(\)\)/)
  assert.match(view, /v-for="item in periods"/)
  assert.match(view, /item\.academic_year\.school_year/)
  assert.match(view, /item\.semester\.semester_name/)
  assert.match(view, /item\.midterm_state\.state/)
  assert.match(view, /item\.finals_state\.state/)
})

test('Grade Period staff tabs expose loading, error, empty, and row actions', () => {
  for (const text of [
    'Loading grade periods…',
    'No grade periods have been configured yet.',
    'No submitted grade sheets are awaiting review.',
    'No approved grade sheets are ready for release.',
  ]) assert.match(view, new RegExp(text, 'i'))
  assert.match(view, /role="alert">\{\{error\}\}/)
  assert.match(view, /<template v-if="!loading"><nav v-if="!professor"/)
  assert.match(view, /<template v-if="professor">/)
  for (const action of ['Edit', 'Archive', 'Delete']) assert.match(view, new RegExp(`>${action}<`))
})

test('Grade Period response parser rejects an invalid payload with a visible message', () => {
  assert.throws(() => parseGradePeriods({ periods: [] }), /could not be displayed/)
  assert.match(service, /error\.response\?\.data\?\.message \|\| error\.message/)
})
