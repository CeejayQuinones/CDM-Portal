import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const source = path => readFile(new URL(`../src/${path}`, import.meta.url), 'utf8')

test('Registrar Enrollment recreates the approved four-screen Electron architecture without changing API contracts', async () => {
  const [academic, subjects, schedule, staff, css] = await Promise.all([
    source('modules/enrollment/EnrollmentAcademicStaff.vue'),
    source('modules/enrollment/EnrollmentSubjectManagement.vue'),
    source('modules/enrollment/EnrollmentSchedule.vue'),
    source('modules/enrollment/EnrollmentStaff.vue'),
    source('modules/enrollment/registrarEnrollmentComplete.css'),
  ])

  assert.match(academic, /en-section-card-grid/)
  assert.match(academic, /Enrollment Records/)
  assert.match(academic, /en-scheduling-layout/)
  assert.match(academic, /en-section-browser/)
  assert.match(academic, /en-timetable-workspace/)
  assert.match(academic, /en-scheduled-subjects/)
  assert.match(academic, /More filters/)
  assert.match(academic, /en-records-workspace/)
  assert.match(academic, /en-record-filters/)
  assert.match(academic, /en-record-master-row/)
  assert.match(academic, /en-record-inspector/)
  assert.match(academic, /\/enrollment\/academic\/sections/)
  assert.match(academic, /\/enrollment\/academic\/schedules/)
  assert.match(academic, /\/enrollment\/academic\/records\//)
  assert.match(academic, /Create section/)
  assert.match(academic, /Add schedule/)
  assert.match(academic, /View COR/)

  assert.match(subjects, /curriculumGroups/)
  assert.match(subjects, /en-catalog-period/)
  assert.match(subjects, /Assign to Curriculum/)
  assert.match(subjects, /en-subject-actions/)
  assert.match(subjects, /\/enrollment\/academic\/subjects/)
  assert.match(subjects, /archive/)
  assert.match(subjects, /reactivate/)

  assert.match(schedule, /isDesktopPresentation/)
  assert.match(schedule, /ref\(isDesktopPresentation \? 'timetable' : 'cards'\)/)
  assert.match(schedule, /en-weekly-timetable/)
  assert.match(schedule, /class="\{ active: view===option \}"/)
  assert.match(schedule, /Weekly overview/)
  assert.match(schedule, /blockStyle/)
  assert.match(schedule, /overlapKeys/)
  assert.match(schedule, /WebsiteActionBar/)
  assert.match(schedule, /ui4-schedule-card/)

  // Applications and periods were explicitly reviewed and retained because
  // their existing UI4 records workspace already satisfies this pass.
  assert.match(staff, /Applications/)
  assert.match(staff, /Enrollment Periods/)
  assert.match(staff, /\/enrollment\/applications/)
  assert.match(staff, /\/enrollment\/periods/)

  assert.ok((css.match(/html\.cdm-desktop-client/g) || []).length > 30)
  assert.equal(css.split('\n').filter(line => /^\s*\.en-/.test(line)).length, 0)
  assert.match(css, /@media \(max-width: 900px\)/)
  assert.match(css, /@media \(prefers-reduced-motion: reduce\)/)
  assert.match(css, /\.en-modern-search \{ flex: 1 1 340px;/)
  assert.match(css, /\.en-scheduling-layout \{[^}]*display: grid;/)
  assert.match(css, /\.en-section-card-grid \{ display: grid;/)
  assert.match(css, /\.en-records-workspace \{[^}]*display: grid;/)
  assert.match(css, /height: 40px; min-height: 40px;/)
  assert.match(css, /\.en-timetable-block \.en-schedule-row-actions \{ display: none;/)
})

test('desktop scheduling is data-derived and contains no drag-and-drop or synthetic meetings', async () => {
  const schedule = await source('modules/enrollment/EnrollmentSchedule.vue')

  assert.match(schedule, /props\.schedules\.filter/)
  assert.match(schedule, /schedule\.start_time/)
  assert.match(schedule, /schedule\.end_time/)
  assert.match(schedule, /schedule\.room/)
  assert.match(schedule, /schedule\.professor/)
  assert.doesNotMatch(schedule, /draggable|ondrop|dragstart/i)
  assert.doesNotMatch(schedule, /mock|sample schedule|demo meeting/i)
})

