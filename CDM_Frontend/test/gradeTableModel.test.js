import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'
import {
  buildGradeTableModel,
  compareFinalGradeResults,
  compareGradeStudents,
  GRADE_ASSESSMENT_CATEGORIES,
  GRADE_COLUMN_WIDTHS,
} from '../src/modules/grading/gradeTableModel.js'

const assessment = (id, category, label = `${category} ${id}`, status = 'active') => ({ id, category, label, status, max_score: 100 })
const read = path => readFile(new URL(`../${path}`, import.meta.url), 'utf8')

test('empty period gradesheets retain the requested four category placeholders', () => {
  const model = buildGradeTableModel([])

  assert.deepEqual(model.assessmentGroups.map(group => group.label), GRADE_ASSESSMENT_CATEGORIES)
  assert.equal(model.assessmentColumns.length, 0)
  assert.equal(model.periodColumns.length, 4)
  assert.ok(model.periodColumns.every(column => column.kind === 'assessment-placeholder'))
  assert.equal(model.periodResultColumn.label, 'Midterm')
  assert.equal(model.columnCount, 8)
  assert.equal(model.columns.length, model.columnCount)
})

test('one assessment replaces only its category placeholder', () => {
  const model = buildGradeTableModel([assessment(1, 'Quiz')])

  assert.equal(model.assessmentGroups.length, 4)
  assert.equal(model.assessmentGroups[0].columns.length, 1)
  assert.equal(model.assessmentColumns.length, 1)
  assert.equal(model.periodColumns.length, 4)
  assert.equal(model.columnCount, 8)
})

test('multiple categories normalize into one shared ordered assessment model', () => {
  const model = buildGradeTableModel([
    assessment(1, 'Quiz', 'Quiz 1'),
    assessment(2, 'Activity', 'Activity 1'),
    assessment(3, 'Quiz', 'Quiz 2'),
    assessment(4, 'Recitation'),
    assessment(5, 'Major Exam'),
  ])

  assert.deepEqual(model.assessmentGroups.map(group => [group.label, group.columns.length]), [
    ['Quiz', 2],
    ['Activity', 1],
    ['Recitation', 1],
    ['Major Exam', 1],
  ])
  assert.deepEqual(model.assessmentColumns.map(column => column.assessment.id), [1, 3, 2, 4, 5])
  assert.equal(model.assessmentGroups.reduce((total, group) => total + group.columns.length, 0), model.assessmentColumns.length)
})

test('adding and archiving assessments recalculates spans without phantom columns', () => {
  const before = buildGradeTableModel([assessment(1, 'Quiz')])
  const afterAdd = buildGradeTableModel([assessment(1, 'Quiz'), assessment(2, 'Quiz')])
  const afterArchive = buildGradeTableModel([assessment(1, 'Quiz'), assessment(2, 'Quiz', 'Quiz 2', 'archived')])

  assert.equal(before.assessmentGroups[0].columns.length, 1)
  assert.equal(afterAdd.assessmentGroups[0].columns.length, 2)
  assert.equal(afterArchive.assessmentGroups[0].columns.length, 1)
  assert.equal(afterArchive.columnCount, before.columnCount)
})

test('long assessment labels do not change deterministic column geometry', () => {
  const short = buildGradeTableModel([assessment(1, 'Quiz', 'Q1')])
  const long = buildGradeTableModel([assessment(1, 'Quiz', 'Comprehensive Quiz With A Very Long Registrar Label')])

  assert.equal(short.assessmentColumns[0].width, GRADE_COLUMN_WIDTHS.assessment)
  assert.equal(long.assessmentColumns[0].width, GRADE_COLUMN_WIDTHS.assessment)
  assert.equal(short.totalWidth, long.totalWidth)
  assert.equal(long.assessmentColumns[0].assessment.label, 'Comprehensive Quiz With A Very Long Registrar Label')
})

test('all identity columns share one non-sticky Student Information group', () => {
  const model = buildGradeTableModel([])

  assert.deepEqual(model.studentInformationColumns.map(column => column.key), ['sequence', 'student-number', 'student-name'])
  assert.equal(model.studentInformationGroup.width, 372)
  assert.ok(model.identityColumns.every(column => !Object.hasOwn(column, 'sticky') && !Object.hasOwn(column, 'stickyLeft')))
})

test('period sheets expose only the active period result while Final Grade keeps the full summary', async () => {
  const [view, model] = await Promise.all([
    read('src/modules/grading/GradingView.vue'),
    Promise.resolve(buildGradeTableModel([])),
  ])

  assert.deepEqual(model.resultColumns.map(column => column.field), ['midterm_grade', 'finals_grade', 'final_grade', 'grade_point', 'remarks'])
  assert.equal(buildGradeTableModel([], 'midterm').periodResultColumn.field, 'midterm_grade')
  assert.equal(buildGradeTableModel([], 'finals').periodResultColumn.field, 'finals_grade')
  assert.match(view, /gradeTableModel\.periodResultColumn\.label/)
  assert.match(view, /displayLiveGrade\(periodPreviewFor\(s\)\)/)
  assert.match(view, /<th>No\.<\/th><th>Student No\.<\/th><th>Student Name<\/th><th>Midterm<\/th><th>Finals<\/th><th>Final Grade<\/th><th>Grade Point<\/th><th>Remarks<\/th>/)
})

test('the rendered table uses one colgroup model for header, body, and width styles', async () => {
  const view = await read('src/modules/grading/GradingView.vue')

  assert.match(view, /<colgroup>[\s\S]*v-for="column in gradeTableModel\.columns"/)
  assert.match(view, /:data-column-count="gradeTableModel\.columnCount"/)
  assert.match(view, /gradeColumnStyle\(gradeTableModel\.identityColumns\[0\]\)/)
  assert.match(view, /:colspan="gradeTableModel\.studentInformationColumns\.length"/)
  assert.match(view, /v-for="column in gradeTableModel\.studentInformationColumns"/)
  assert.match(view, /v-for="column in gradeTableModel\.periodColumns"/)
  assert.match(view, /<template v-for="column in gradeTableModel\.periodColumns"/)
  assert.match(view, /column\.scoreIndex/)
  assert.match(view, /--grade-table-width/)
})

test('period changes rebuild the model from the currently filtered assessment records', async () => {
  const view = await read('src/modules/grading/GradingView.vue')

  assert.match(view, /assessments=computed\(\(\)=>workspace\.value\?\.sheet\.assessments\.filter\(a=>a\.period===activePeriod\.value\)/)
  assert.match(view, /gradeTableModel=computed\(\(\)=>buildGradeTableModel\(gridAssessments\.value,activePeriod\.value\)\)/)
  assert.match(view, /activePeriod\.value=period/)
})

test('student rows and final-grade rows sort alphabetically without mutating source arrays', () => {
  const roster = [
    { last_name: 'Zulu', first_name: 'Amy', student_number: '2' },
    { last_name: 'Alonzo', first_name: 'Ben', student_number: '3' },
    { last_name: 'Alonzo', first_name: 'Ana', student_number: '1' },
  ]
  const results = [
    { student_name: 'Zulu, Amy', student_number: '2' },
    { student_name: 'Alonzo, Ben', student_number: '3' },
    { student_name: 'Alonzo, Ana', student_number: '1' },
  ]

  assert.deepEqual([...roster].sort(compareGradeStudents).map(row => row.student_number), ['1', '3', '2'])
  assert.deepEqual([...results].sort(compareFinalGradeResults).map(row => row.student_number), ['1', '3', '2'])
  assert.deepEqual(roster.map(row => row.student_number), ['2', '3', '1'])
  assert.deepEqual(results.map(row => row.student_number), ['2', '3', '1'])
})

test('authoritative table geometry remains website-only', async () => {
  const view = await read('src/modules/grading/GradingView.vue')

  assert.match(view, /<template v-if="isWebsitePresentation">/)
  assert.match(view, /<template v-else><section class="grading-window"/)
})
