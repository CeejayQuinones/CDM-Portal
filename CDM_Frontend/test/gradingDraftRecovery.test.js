import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'
import {
  GRADE_RECOVERY_TTL_MS,
  analyzeGradeRecovery,
  clearGradeRecoveryForAccount,
  createGradeRecoveryRecord,
  gradeRecoveryScope,
  gradeRecoveryStorageKey,
  readGradeRecovery,
  readGradeRecoveryPreference,
  removeGradeRecovery,
  writeGradeRecovery,
  writeGradeRecoveryPreference,
} from '../src/modules/grading/gradeDraftRecovery.js'

const read = path => readFile(new URL(`../${path}`, import.meta.url), 'utf8')
class MemoryStorage {
  values = new Map()
  get length() { return this.values.size }
  key(index) { return [...this.values.keys()][index] ?? null }
  getItem(key) { return this.values.get(key) ?? null }
  setItem(key, value) { this.values.set(key, String(value)) }
  removeItem(key) { this.values.delete(key) }
}

const classContext = { id: 41, subject_id: 15, section_id: 7, academic_year_id: 3, semester_id: 1, subject_code: 'IT101', section: 'BSIT-1A', academic_year: '2026-2027', semester: 'First' }
const sheet = { id: 71, version: 3 }
const roster = [
  { enrollment_subject_id: 101, student_number: '26-0001', first_name: 'Ada', last_name: 'Luna' },
  { enrollment_subject_id: 102, student_number: '26-0002', first_name: 'Ben', last_name: 'Santos' },
]
const assessments = [
  { id: 201, version: 2, period: 'midterm', status: 'active', label: 'Quiz 1', max_score: 20 },
  { id: 202, version: 1, period: 'midterm', status: 'active', label: 'Activity 1', max_score: 30 },
]
const scores = new Map([
  ['101:201', { score: 10, version: 4 }],
  ['102:202', { score: 18, version: 2 }],
])
const persistedScore = (student, assessment) => scores.get(`${student.enrollment_subject_id}:${assessment.id}`)
const scope = gradeRecoveryScope({ accountId: 9, classContext, sheet, period: 'midterm' })
const makeRecord = (now = 1_000) => createGradeRecoveryRecord({
  scope,
  drafts: { '101:201': '15', '101:202': '0' },
  dirtyKeys: new Set(['101:201', '101:202']),
  roster,
  assessments,
  persistedScore,
  now,
})

test('temporary recovery is opt-in and scoped by account, class, sheet, term, and period', () => {
  const storage = new MemoryStorage()
  assert.equal(readGradeRecoveryPreference(storage, 9), false)
  assert.equal(writeGradeRecoveryPreference(storage, 9, true), true)
  assert.equal(readGradeRecoveryPreference(storage, 9), true)
  assert.equal(writeGradeRecoveryPreference(storage, 9, false), false)
  assert.equal(readGradeRecoveryPreference(storage, 9), false)
  writeGradeRecoveryPreference(storage, 9, true)

  const record = makeRecord()
  assert.equal(writeGradeRecovery(storage, record), true)
  assert.deepEqual(readGradeRecovery(storage, scope, 2_000), record)

  const otherAccount = { ...scope, accountId: '10' }
  const otherClass = { ...scope, classId: '42' }
  const otherSubject = { ...scope, subjectId: '16' }
  const otherSection = { ...scope, sectionId: '8' }
  const otherTerm = { ...scope, academicYearId: '4' }
  const otherPeriod = { ...scope, period: 'finals' }
  assert.equal(readGradeRecovery(storage, otherAccount, 2_000), null)
  assert.equal(readGradeRecovery(storage, otherClass, 2_000), null)
  assert.equal(readGradeRecovery(storage, otherSubject, 2_000), null)
  assert.equal(readGradeRecovery(storage, otherSection, 2_000), null)
  assert.equal(readGradeRecovery(storage, otherTerm, 2_000), null)
  assert.equal(readGradeRecovery(storage, otherPeriod, 2_000), null)
  assert.notEqual(gradeRecoveryStorageKey(scope), gradeRecoveryStorageKey(otherAccount))
})

test('recovery stores only scoped identifiers, versions, timestamps, and draft values', () => {
  const serialized = JSON.stringify(makeRecord())
  assert.doesNotMatch(serialized, /Ada|Luna|Ben|Santos|26-0001|26-0002|token/i)
  assert.match(serialized, /enrollmentSubjectId/)
  assert.match(serialized, /assessmentVersion/)
  assert.match(serialized, /baseVersion/)
  assert.match(serialized, /expiresAt/)
})

test('expired recovery is rejected and removed', () => {
  const storage = new MemoryStorage()
  const record = makeRecord(5_000)
  writeGradeRecovery(storage, record)
  assert.equal(readGradeRecovery(storage, scope, 5_000 + GRADE_RECOVERY_TTL_MS + 1), null)
  assert.equal(storage.getItem(gradeRecoveryStorageKey(scope)), null)
})

test('malformed recovery is ignored and removed', () => {
  const storage = new MemoryStorage()
  storage.setItem(gradeRecoveryStorageKey(scope), '{not-json')
  assert.equal(readGradeRecovery(storage, scope, 2_000), null)
  assert.equal(storage.getItem(gradeRecoveryStorageKey(scope)), null)
})

test('recovery analysis restores only unchanged authoritative cells', () => {
  const record = makeRecord()
  const review = analyzeGradeRecovery({ record, scope, roster, assessments, persistedScore, editable: true })
  assert.equal(review.eligible.length, 2)
  assert.equal(review.conflicts.length, 0)

  scores.set('101:201', { score: 12, version: 5 })
  const changed = analyzeGradeRecovery({ record, scope, roster, assessments, persistedScore, editable: true })
  assert.equal(changed.eligible.length, 1)
  assert.match(changed.conflicts[0].reason, /newer authoritative score/)
  scores.set('101:201', { score: 10, version: 4 })
})

test('changed assessments, sheet versions, rosters, and locked periods become conflicts', () => {
  const record = makeRecord()
  const changedAssessment = assessments.map(item => item.id === 201 ? { ...item, version: 3 } : item)
  assert.match(analyzeGradeRecovery({ record, scope, roster, assessments: changedAssessment, persistedScore, editable: true }).conflicts[0].reason, /assessment definition changed/)
  assert.equal(analyzeGradeRecovery({ record, scope: { ...scope, sheetVersion: '4' }, roster, assessments, persistedScore, editable: true }).conflicts.length, 2)
  assert.equal(analyzeGradeRecovery({ record, scope, roster: roster.slice(1), assessments, persistedScore, editable: true }).conflicts.length, 2)
  assert.equal(analyzeGradeRecovery({ record, scope, roster, assessments, persistedScore, editable: false }).conflicts.length, 2)
})

test('discard and logout cleanup remove recovery without affecting another account', () => {
  const storage = new MemoryStorage()
  writeGradeRecoveryPreference(storage, 9, true)
  writeGradeRecovery(storage, makeRecord())
  const otherScope = { ...scope, accountId: '10' }
  writeGradeRecovery(storage, { ...makeRecord(), scope: otherScope })
  assert.equal(removeGradeRecovery(storage, scope), true)
  assert.equal(readGradeRecovery(storage, scope), null)
  writeGradeRecovery(storage, makeRecord())
  assert.equal(clearGradeRecoveryForAccount(storage, 9), 2)
  assert.ok(readGradeRecovery(storage, otherScope, 2_000))
})

test('Grade Entry recovery lifecycle preserves Phase 4A and Phase 3 safety boundaries', async () => {
  const [view, authStore] = await Promise.all([
    read('src/modules/grading/GradingView.vue'),
    read('src/stores/authStore.js'),
  ])
  assert.match(view, /Temporary draft recovery/)
  assert.match(view, /Unsaved grading work found/)
  assert.match(view, /Restore Draft/)
  assert.match(view, /Discard Recovery/)
  assert.match(view, /createGradeRecoveryRecord/)
  assert.match(view, /analyzeGradeRecovery/)
  assert.match(view, /scheduleRecoveryWrite\(\)/)
  assert.match(view, /createDraftOperation\('paste'[^]*scheduleRecoveryWrite\(\)/)
  assert.match(view, /createDraftOperation\('recovery'[^]*pushScoreOperation\(operation\)[^]*scheduleRecoveryWrite\(\)/)
  assert.match(view, /if\(recoveryEnabled\.value\)persistRecoveryNow\(\)/)
  assert.match(view, /removeGradeRecovery\(sessionStorage,recoveryScope\)/)
  assert.match(view, /scoreDrafts\.value=clearSavedScoreBatch[^]*persistRecoveryNow\(\)/)
  assert.match(view, /retained in the temporary recovery copy/)
  assert.match(view, /Restore or discard the temporary grading recovery before opening Edit in Excel/)
  assert.match(view, /Restore or discard the temporary grading recovery before opening Grade Submission/)
  assert.match(view, /<span>Edit in Excel<\/span>/)
  assert.match(authStore, /clearGradeRecoveryForAccount\(sessionStorage, accountId\)/)
})
