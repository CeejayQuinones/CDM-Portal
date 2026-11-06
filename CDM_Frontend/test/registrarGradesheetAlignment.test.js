import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const read = path => readFile(new URL(`../${path}`, import.meta.url), 'utf8')

test('website Grade Entry renders an institutional gradesheet header from verified data', async () => {
  const [view, header] = await Promise.all([
    read('src/modules/grading/GradingView.vue'),
    read('src/modules/grading/RegistrarGradesheetHeader.vue'),
  ])

  assert.match(view, /<RegistrarGradesheetHeader v-if="isWebsitePresentation"/)
  assert.match(header, /CDMSeal/)
  assert.match(header, /institutionProfile\.location/)
  assert.match(header, /institutionProfile\.name/)
  for (const label of ['Course / Program', 'Year and Section', 'Semester', 'Academic Year', 'Subject Code', 'Subject Description', 'Total Units', 'Institute']) {
    assert.match(header, new RegExp(label.replace('/', '\\/')))
  }
  assert.match(header, /Not an officially released record/)
})

test('Registrar-inspired spreadsheet has multi-level identity, assessment, and result headers', async () => {
  const [view, model] = await Promise.all([
    read('src/modules/grading/GradingView.vue'),
    read('src/modules/grading/gradeTableModel.js'),
  ])

  assert.match(view, /class="professor-grade-sheet grouped-grade-sheet registrar-inspired-grid"/)
  assert.match(view, /scope="colgroup">Student Information/)
  assert.match(view, /scope="colgroup"><span>\{\{group\.label\}\}<\/span>/)
  assert.match(view, /scope="colgroup">Calculated Results · Read Only/)
  assert.match(view, /gradeTableModel\.studentInformationColumns/)
  assert.match(model, /label: 'Student No\.'/)
  assert.match(model, /label: 'Student Name'/)
  for (const field of ['Midterm', 'Finals', 'Final Grade', 'Equivalent', 'Remarks']) assert.match(model, new RegExp(`label: '${field}'`))
})

test('assessment groups and score columns remain generated from real workspace records', async () => {
  const [view, model] = await Promise.all([
    read('src/modules/grading/GradingView.vue'),
    read('src/modules/grading/gradeTableModel.js'),
  ])

  assert.match(view, /gradeTableModel=computed\(\(\)=>buildGradeTableModel\(gridAssessments\.value\)\)/)
  assert.match(view, /v-for="group in gradeTableModel\.assessmentGroups"/)
  assert.match(view, /v-for="column in gradeTableModel\.assessmentColumns"/)
  assert.match(view, /v-for="\(column,j\) in gradeTableModel\.assessmentColumns"/)
  assert.match(view, /:max="column\.assessment\.max_score"/)
  assert.match(view, /@input="updateScore/)
  assert.match(model, /group\.columns\.push/)
})

test('calculated result cells use supported readiness fields and remain visibly read-only', async () => {
  const [view, model] = await Promise.all([
    read('src/modules/grading/GradingView.vue'),
    read('src/modules/grading/gradeTableModel.js'),
  ])

  for (const field of ['midterm_grade', 'finals_grade', 'final_grade', 'grade_point', 'remarks']) assert.match(model, new RegExp(`field: '${field}'`))
  assert.match(view, /resultFor\(s\)\[column\.field\]/)
  assert.match(view, /data-readonly="true"/)
  assert.match(view, /Read-only \$\{column\.label\} result/)
  assert.doesNotMatch(view, /preliminary_grade/)
  assert.doesNotMatch(view, /rounded_grade/)
})

test('institutional sheet remains dense, non-overlapping, responsive, and semantically themed', async () => {
  const css = await read('src/modules/grading/grading.css')

  assert.match(css, /\.registrar-sheet-header \{[\s\S]*var\(--bg-surface\)/)
  assert.match(css, /\.registrar-sheet-institution \{[\s\S]*var\(--academic-emerald-900/)
  assert.match(css, /Horizontal and vertical sticky positioning is deliberately disabled/)
  assert.doesNotMatch(css, /--grade-sticky-left/)
  assert.match(css, /\.website-professor-grading \.registrar-inspired-grid th,[\s\S]*position: static;/)
  assert.match(css, /\.registrar-inspired-grid \.grade-sheet-calculated \{[\s\S]*var\(--accent-soft\)/)
  assert.match(css, /@media \(max-width: 900px\)/)
  assert.match(css, /@media \(max-width: 390px\)/)
})

test('Grade Submission remains separate and website Professor authorization is unchanged', async () => {
  const [view, router, access] = await Promise.all([
    read('src/modules/grading/GradingView.vue'),
    read('src/router/index.js'),
    read('src/config/accessControl.js'),
  ])

  assert.doesNotMatch(view, /class="submission-readiness-panel expanded"/)
  assert.match(router, /path: 'grading\/submission'[\s\S]*platforms: \['web'\]/)
  assert.match(access, /'grading-submission': \[ROLES\.PROFESSOR\]/)
  assert.match(view, /onBeforeRouteLeave/)
})
