import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const read = path => readFile(new URL(`../${path}`, import.meta.url), 'utf8')

test('Step 2 supplies reusable, accessible website academic primitives', async () => {
  const [header, panel, status, stepper, empty, loading, toolbar, actionBar, table] = await Promise.all([
    read('src/components/website/WebsiteModuleHeader.vue'),
    read('src/components/website/WebsiteSectionPanel.vue'),
    read('src/components/website/WebsiteStatusBadge.vue'),
    read('src/components/website/WebsiteStepper.vue'),
    read('src/components/website/WebsiteEmptyState.vue'),
    read('src/components/website/WebsiteLoadingState.vue'),
    read('src/components/website/WebsiteFilterToolbar.vue'),
    read('src/components/website/WebsiteActionBar.vue'),
    read('src/components/website/WebsiteDataTable.vue'),
  ])

  assert.match(header, /<h1 :id="titleId \|\| undefined">/)
  assert.match(panel, /aria-labelledby/)
  assert.match(status, /role="status"/)
  assert.match(stepper, /aria-label="Workflow progress"/)
  assert.match(stepper, /:aria-current="index===currentIndex\?'step':undefined"/)
  assert.match(empty, /class="website-empty-state"/)
  assert.match(loading, /role="status"/)
  assert.match(toolbar, /@submit\.prevent="\$emit\('submit',\$event\)"/)
  assert.match(actionBar, /class="website-action-bar"/)
  assert.match(table, /role="region"/)
  assert.match(table, /tabindex="0"/)
})

test('Step 2 presentation is activated only by the persistent web shell', async () => {
  const [dashboardLayout, shell, styles] = await Promise.all([
    read('src/layouts/DashboardLayout.vue'),
    read('src/layouts/WebsiteAppShell.vue'),
    read('src/assets/styles/website-academic-modules.css'),
  ])

  assert.match(dashboardLayout, /<WebsiteAppShell v-if="isWebsitePresentation"/)
  assert.match(dashboardLayout, /<div v-else class="app-shell"/)
  assert.match(shell, /website-academic-modules\.css/)
  assert.match(shell, /<CDMBuddy \/>/)
  assert.match(styles, /\.website-ui-2-shell/)
  assert.match(styles, /prefers-reduced-motion: reduce/)
  assert.doesNotMatch(styles, /^\s*:root\s*\{/m)
  assert.doesNotMatch(styles, /^\s*body\s*\{/m)
})

test('Student academic workspaces adopt the components without changing their data paths', async () => {
  const [status, records, grades, documents] = await Promise.all([
    read('src/modules/enrollment/EnrollmentStudent.vue'),
    read('src/modules/enrollment/EnrollmentAcademicStudent.vue'),
    read('src/modules/grading/StudentGradesView.vue'),
    read('src/modules/document-request/StudentDocumentRequestView.vue'),
  ])

  assert.match(status, /WebsiteStepper/)
  assert.match(status, /\/enrollment\/applications\/mine/)
  assert.match(records, /WebsiteSectionPanel/)
  assert.match(records, /\/enrollment\/academic\/records/)
  assert.match(grades, /WebsiteDataTable/)
  assert.match(grades, /gradingService\.studentGrades/)
  assert.match(documents, /DocumentRequestPageHeader/)
  assert.match(documents, /releasedRequests/)
  assert.match(documents, /DocumentRequestStatusBadge/)
})

test('Guest Admission keeps the existing guarded workflow and examination protections', async () => {
  const [admission, exam] = await Promise.all([
    read('src/modules/admission/AdmissionView.vue'),
    read('src/modules/admission/AdmissionExamView.vue'),
  ])

  assert.match(admission, /WebsiteModuleHeader/)
  assert.match(admission, /WebsiteStepper/)
  assert.match(admission, /auth\.currentRole !== ROLES\.GUEST/)
  assert.match(admission, /Start Admission Application/)
  assert.match(exam, /save\(submit = false\)/)
  assert.match(exam, /You cannot change answers afterward/)
  assert.match(exam, /awaiting_publication/)
})

test('Professor workspaces retain assigned-class, bulk grading, and submission flows', async () => {
  const [enrollment, grading] = await Promise.all([
    read('src/modules/enrollment/EnrollmentProfessor.vue'),
    read('src/modules/grading/GradingView.vue'),
  ])

  assert.match(enrollment, /CdmPageHeader/)
  assert.match(enrollment, /CdmTable/)
  assert.match(enrollment, /\/enrollment\/academic\/professor/)
  assert.match(grading, /WebsiteModuleHeader/)
  assert.match(grading, /Bulk Assessment Entry/)
  assert.match(grading, /saveBulkAssessments/)
  assert.match(grading, /submitSheet/)
  assert.match(grading, /Submission readiness/)
})

test('Settings, navigation stability, Buddy, and Event separation remain intact', async () => {
  const [settings, shell, buddy, routes, access] = await Promise.all([
    read('src/views/SettingsView.vue'),
    read('src/layouts/WebsiteAppShell.vue'),
    read('src/components/CDMBuddy.vue'),
    read('src/router/index.js'),
    read('src/config/accessControl.js'),
  ])

  assert.match(settings, /WebsiteModuleHeader/)
  assert.match(settings, /clientPlatform==='web'/)
  assert.match(shell, /:key="currentRoute\.path"/)
  assert.doesNotMatch(shell, /mode="out-in"/)
  assert.match(buddy, /buddyMuteStorageKey/)
  assert.match(buddy, /position\.onPointerDown/)
  assert.doesNotMatch(`${routes}\n${access}`, /event-attendance|event-promotions|Event Reports|Upcoming Events/)
})
