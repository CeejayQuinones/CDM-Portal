import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { performance } from 'node:perf_hooks'
import test from 'node:test'
import {
  createGradeStudentSearchIndex,
  findGradeStudents,
  findIndexedGradeStudents,
  gradeStudentDisplayName,
  resolveGradeJumpTarget,
} from '../src/modules/grading/gradeFindNavigation.js'

const read = path => readFile(new URL(`../${path}`, import.meta.url), 'utf8')
const roster = [
  { enrollment_subject_id: 11, student_number: '26-0003', first_name: 'Carla', middle_name: 'M', last_name: 'Reyes' },
  { enrollment_subject_id: 12, student_number: '26-0001', first_name: 'Ana', middle_name: '', last_name: 'Cruz' },
  { enrollment_subject_id: 13, student_number: '26-0002', first_name: 'Ben', middle_name: '', last_name: 'Santos' },
]
const columns = [
  { assessment: { id: 81, label: 'Quiz 1' } },
  { assessment: { id: 82, label: 'Activity 1' } },
]

test('Student find matches names and Student numbers without changing source order', () => {
  assert.deepEqual(findGradeStudents(roster, 'reyes').map(item => item.enrollment_subject_id), [11])
  assert.deepEqual(findGradeStudents(roster, '26-0001').map(item => item.enrollment_subject_id), [12])
  assert.deepEqual(findGradeStudents(roster, 'an').map(item => item.enrollment_subject_id), [12, 13])
  assert.equal(gradeStudentDisplayName(roster[0]), 'Reyes, Carla M')
})

test('jump resolution uses stable IDs in filtered or sorted visible order', () => {
  const visible = [roster[2], roster[0]]
  const target = resolveGradeJumpTarget({ enrollmentSubjectId: 11, assessmentId: 82, roster: visible, columns })
  assert.equal(target.row, 1)
  assert.equal(target.column, 1)
  assert.equal(target.key, '11:82')
  assert.equal(resolveGradeJumpTarget({ enrollmentSubjectId: 11, assessmentId: 81, roster: visible, columns }).column, 0)
  assert.equal(resolveGradeJumpTarget({ enrollmentSubjectId: 12, assessmentId: 82, roster: visible, columns }), null)
})

test('indexed search stays bounded and responsive for a 300-Student roster', () => {
  const largeRoster = Array.from({ length: 300 }, (_, index) => ({
    enrollment_subject_id: 1000 + index,
    student_number: `26-${String(index + 1).padStart(4, '0')}`,
    first_name: 'Generated',
    middle_name: '',
    last_name: `Student ${index + 1}`,
  }))
  const index = createGradeStudentSearchIndex(largeRoster)
  const started = performance.now()
  let matches
  for (let run = 0; run < 1000; run++) matches = findIndexedGradeStudents(index, 'student 29', 8)
  const elapsed = performance.now() - started
  assert.equal(matches.length, 8)
  assert.equal(matches[0].enrollment_subject_id, 1028)
  assert.ok(elapsed < 500, `Expected 1,000 indexed searches to finish within 500 ms; observed ${elapsed.toFixed(2)} ms`)
})

test('Find control has a permanent combobox and instant navigation after deliberate selection', async () => {
  const [control, view, styles] = await Promise.all([
    read('src/modules/grading/GradeFindControl.vue'),
    read('src/modules/grading/GradingView.vue'),
    read('src/modules/grading/grading.css'),
  ])
  assert.match(control, /placeholder="Search student name or ID\.\.\."/)
  assert.match(control, /role="combobox"/)
  assert.match(control, /role="listbox"/)
  assert.match(control, /role="option"/)
  assert.match(control, /:aria-activedescendant=/)
  assert.match(control, /v-model="query"/)
  assert.match(control, /@focus="open = Boolean\(query\.trim\(\) && !selectedStudent\)"/)
  assert.match(control, /const selectedStudentId = ref\(''\)/)
  assert.match(control, /const activeResultIndex = ref\(-1\)/)
  assert.match(control, /watch\(\(\) => props\.assessments/)
  assert.match(control, /assessmentId\.value = items\[0\]\?\.id \?\? ''/)
  assert.match(control, /function selectStudent\(student\)/)
  assert.match(control, /function requestNavigation\(\)/)
  const selectStudentBlock = control.match(/function selectStudent\(student\) \{[^]*?\n\}/)?.[0] || ''
  assert.match(selectStudentBlock, /selectedStudentId\.value = String\(student\.enrollment_subject_id\)/)
  assert.match(selectStudentBlock, /requestNavigation\(\)/)
  const queryInputBlock = control.match(/function handleQueryInput\(\) \{[^]*?\n\}/)?.[0] || ''
  assert.doesNotMatch(queryInputBlock, /requestNavigation|emit/)
  assert.match(control, /function handleAssessmentChange\(\) \{\s*requestNavigation\(\)/)
  assert.match(control, /emit\('navigate', \{\s*enrollmentSubjectId: selectedStudent\.value\.enrollment_subject_id,\s*assessmentId: assessmentId\.value/)
  assert.match(control, /@change="handleAssessmentChange"/)
  assert.match(control, /@keydown="handleInputKeydown"/)
  assert.match(control, /ArrowDown/)
  assert.match(control, /ArrowUp/)
  assert.match(control, /event\.key === 'Enter'/)
  assert.match(control, /event\.key === 'Escape'/)
  assert.match(control, /document\.addEventListener\('pointerdown', handleOutsidePointer\)/)
  assert.match(control, /No Students match this search\./)
  assert.match(control, /Selected:/)
  assert.doesNotMatch(control, /Jump to score/)
  assert.doesNotMatch(control, /grade-find-jump/)
  assert.doesNotMatch(control, /emit\('filter'/)
  assert.doesNotMatch(view, /@filter="applyStudentFilter"/)
  assert.match(view, /@navigate="navigateToGradeCell"/)
  assert.match(view, /if\(!editable\.value\).*Scores are read-only for the current grading period/)
  assert.match(view, /resolveGradeJumpTarget/)
  assert.match(view, /scoreCellElement\(target\)/)
  assert.match(view, /temporarily unavailable/)
  assert.match(view, /scrollIntoView\(\{behavior:'auto',block:'nearest',inline:'nearest'\}\)/)
  assert.doesNotMatch(view.match(/function navigateToGradeCell[^]*?\nfunction scoreCellElement/)?.[0] || '', /gradingService|scoreDrafts\.value\s*=/)
  assert.match(view, /modifier&&keyName==='f'/)
  assert.match(view, /gradeFindControl\.value\?\.focusSearch\(\)/)
  assert.match(view, /is-jump-target/)
  assert.match(styles, /\.website-professor-grading \.grade-find-input input \{[^]*display: block;[^]*min-width: 0;[^]*width: 100%;/)
  assert.match(styles, /\.grade-find-results \[role='option'\]/)
  assert.match(styles, /@media \(max-width: 390px\) \{[^]*\.grade-find-control/)
  assert.match(styles, /\.grade-score-cell\.is-jump-target/)
  assert.doesNotMatch(styles, /\.grade-find-jump(?:-wrap)?\s*\{/)
  assert.match(view, /<template v-if="activePeriod!=='final'"><template v-if="isWebsitePresentation">/)
})
