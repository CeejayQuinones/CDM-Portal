import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const source = path => readFile(new URL(`../src/${path}`, import.meta.url), 'utf8')

test('Electron Registrar Grading follows the approved four-workspace architecture', async () => {
  const [view, css, access, context, instituteHero, instituteBranding] = await Promise.all([
    source('modules/grading/GradingView.vue'),
    source('modules/grading/grading.css'),
    source('config/accessControl.js'),
    source('components/desktop/DesktopContextPanel.vue'),
    source('components/desktop/InstituteHero.vue'),
    source('config/instituteBranding.js'),
  ])

  assert.match(view, /desktop-registrar-grading/)
  assert.match(view, /registrar-institute-grid/)
  assert.match(view, /registrar-review-table/)
  assert.match(view, /registrar-scale-table/)
  assert.match(view, /grading-professor-repository/)
  assert.match(view, /<AccountProvisioningView\/\>/)
  assert.match(view, /gradingService\.reviews/)
  assert.match(view, /gradingService\.scales/)
  assert.match(view, /gradingService\.releases/)

  for (const path of [
    '/grading?view=dashboard',
    '/grading?view=periods',
    '/grading?view=configuration&config=scales',
    '/grading?view=professors',
    '/grading/student-history',
  ]) assert.match(access, new RegExp(path.replace(/[?&]/g, '\\$&')))
  assert.doesNotMatch(access, /name: 'grading-reviews'/)
  assert.doesNotMatch(access, /name: 'grading-scales'/)
  assert.doesNotMatch(access, /name: 'grading-releases'/)
  assert.match(view, /staffViews=\['dashboard','reviews','periods','configuration','scales','releases','professors'\]/)
  assert.match(view, /class="registrar-configuration-tabs"/)
  assert.match(view, /openConfigurationTab\('scales'\)/)
  assert.match(view, /openConfigurationTab\('releases'\)/)
  assert.match(view, /:data-institute="institute\.code"/)
  assert.match(view, /INSTITUTE_COURSE_CODES/)
  assert.match(view, /instituteCourseIds/)
  assert.match(view, /mergeInstituteReviews/)
  assert.match(view, /courseIds\.map\(courseId=>gradingService\.reviews\(\{\.\.\.filters,course_id:courseId\}\)\)/)
  assert.match(view, /route\.query\.institute/)
  assert.match(view, /<InstituteHero/)
  assert.match(instituteHero, /Back to Institutes/)
  assert.match(instituteHero, /desktop-institute-watermark/)
  assert.match(instituteHero, /desktop-institute-watermark-zone/)
  assert.match(instituteHero, /mask-image: linear-gradient/)
  assert.match(instituteHero, /opacity: \.065/)
  assert.match(instituteHero, /object-fit: cover/)
  assert.match(instituteHero, /object-position: var\(--institute-watermark-position\)/)
  assert.match(instituteHero, /transform: scale\(var\(--institute-watermark-scale\)\)/)
  assert.match(instituteBranding, /IBE:[\s\S]*ibeLogo/)
  assert.match(instituteBranding, /ICS:[\s\S]*icsLogo/)
  assert.match(instituteBranding, /ITE:[\s\S]*iteLogo/)
  assert.match(instituteBranding, /ICS:[\s\S]*tone: 'technology'[\s\S]*accent: '#167c8c'/)
  assert.match(instituteBranding, /ITE:[\s\S]*tone: 'education'[\s\S]*accent: '#2563a6'/)
  assert.match(instituteBranding, /IBE:[\s\S]*tone: 'business'[\s\S]*accent: '#14633f'/)
  assert.match(view, /:style="institutePageStyle"/)
  assert.match(css, /\.institute-grade-sheets \.grading-button\.primary/)
  assert.match(view, /backToInstitutes/)
  assert.match(view, /v-for="c in instituteCourseOptions"/)
  assert.doesNotMatch(view, /Institute-level server filtering is unavailable/)
  assert.doesNotMatch(view, />Clear context</)
  assert.match(css, /data-institute="IBE"/)
  assert.match(css, /data-institute="ICS"/)
  assert.match(css, /data-institute="ITE"/)
  assert.match(css, /\.registrar-institute-mark \{[^}]*overflow: hidden/)
  assert.match(css, /\.registrar-institute-mark img \{[^}]*border-radius: 50%/)
  assert.match(css, /\.registrar-scale-intro > div/)
  assert.match(css, /\.registrar-scale-table table \{ min-width: 760px; table-layout: fixed; \}/)
  assert.match(css, /\.registrar-period-table table \{ min-width: 900px; table-layout: fixed; \}/)
  assert.match(css, /\.grading-professor-repository \.account-table-wrap th/)
  assert.match(css, /\.grade-history-directory-table th/)
  assert.match(context, /destination\.path === route\.fullPath/)
  assert.match(context, /destination\.activeQuery/)
  assert.ok((css.match(/html\.cdm-desktop-client/g) || []).length > 20)
  assert.match(css, /\.registrar-institute-grid/)
  assert.match(css, /grid-template-columns: repeat\(3, minmax\(0, 1fr\)\)/)
  assert.match(css, /\.registrar-review-filters/)
  assert.match(css, /\.desktop-registrar-grading \.grading-scale-form/)
  assert.match(css, /font-size: 13px/)
})

test('Registrar redesign does not alter protected Professor Grade Entry contracts', async () => {
  const [view, service] = await Promise.all([
    source('modules/grading/GradingView.vue'),
    source('modules/grading/gradingService.js'),
  ])

  assert.match(view, /website-professor-grading/)
  assert.doesNotMatch(view, /ProfessorGradeMessages|Grade Messages/)
  assert.match(view, /openWorkbookWorkflow/)
  assert.match(service, /\/grading\/reviews/)
  assert.match(service, /\/grading\/scales/)
  assert.match(service, /\/grading\/releases/)
  assert.match(service, /\/grading\/sheets\/\$\{sheet\}\/workbook\/apply/)
})
