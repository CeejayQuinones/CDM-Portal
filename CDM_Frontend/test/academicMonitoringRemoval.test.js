import assert from 'node:assert/strict'
import { readFile, access } from 'node:fs/promises'
import test from 'node:test'
import { NAVIGATION_ITEMS, ROUTE_ROLES, ROLES } from '../src/config/accessControl.js'
import { desktopModulesFromMenu } from '../src/config/desktopNavigation.js'
import { websiteModulesFromMenu } from '../src/config/websiteNavigation.js'
import { isRoleAllowedOnClient } from '../src/config/clientPlatform.js'

const read = path => readFile(new URL(`../${path}`, import.meta.url), 'utf8')

test('all authorized platform menus exclude Monitoring and retain academic modules', () => {
  for (const client of ['web', 'mobile', 'desktop']) {
    for (const role of Object.values(ROLES)) {
      if (!isRoleAllowedOnClient(role, client)) continue
      const items = NAVIGATION_ITEMS.filter(item => item.roles.includes(role))
      for (const menu of [items, desktopModulesFromMenu(items), websiteModulesFromMenu(items)]) {
        assert.doesNotMatch(JSON.stringify(menu), /monitoring|study-plans|adviser-alerts/i)
      }
    }
  }
  for (const name of ['grading', 'enrollment-cor', 'student-management', 'admission']) {
    assert.ok(ROUTE_ROLES[name], `${name} authorization retained`)
  }
  assert.equal(isRoleAllowedOnClient('Registrar Staff', 'web'), false)
  assert.equal(isRoleAllowedOnClient('Professor', 'desktop'), false)
})

test('removed frontend routes use the existing safe catch-all; no deleted component imports', async () => {
  const router = await read('src/router/index.js')
  assert.doesNotMatch(router, /monitoring|MonitoringView|StudyPlansView|AdviserAlertsView/)
  assert.match(router, /path: '\/:pathMatch\(\.\*\)\*', redirect: '\/'/)
  await assert.rejects(access(new URL('../src/modules/monitoring/MonitoringView.vue', import.meta.url)))
})

test('dashboards and Buddy no longer launch or request Monitoring', async () => {
  for (const path of ['modules/dashboard/StudentDashboardView.vue', 'modules/dashboard/ProfessorDashboardView.vue', 'modules/dashboard/StudentWebsiteDashboard.vue', 'modules/dashboard/ProfessorWebsiteDashboard.vue', 'modules/dashboard/AdminDashboardView.vue', 'components/website/WebsiteTopbar.vue', 'config/buddyModuleGuidance.js']) {
    assert.doesNotMatch(await read(`src/${path}`), /monitoring|study-plans|adviser-alerts/i)
  }
})

test('shared grading and document styles survive removal of grouped Monitoring selectors', async () => {
  const css = await read('src/assets/styles/website-academic-modules.css')
  assert.match(css, /\.grading-tabs \{ width: 100%; \}/)
  assert.match(css, /\.grading-class-card \{ transition: none; \}/)
  assert.match(css, /\.student-request-summary \.dr-summary-card,/)
  assert.doesNotMatch(css, /monitoring/)
})
