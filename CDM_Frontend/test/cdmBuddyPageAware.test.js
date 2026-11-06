import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

import {
  buddyGuidanceFor,
  buddyModuleForRoute,
  buddyMuteStorageKey,
  noticeMatchesBuddyModule,
} from '../src/config/buddyModuleGuidance.js'

const read = path => readFile(new URL(`../${path}`, import.meta.url), 'utf8')

test('login dashboard and eligible Student modules receive distinct contextual guidance', () => {
  assert.equal(buddyModuleForRoute('student-dashboard'), 'dashboard')
  assert.equal(buddyModuleForRoute('enrollment-status'), 'enrollment')
  assert.equal(buddyModuleForRoute('grading-student'), 'grading')
  assert.equal(buddyModuleForRoute('student-document-requests'), 'documents')

  const dashboard = buddyGuidanceFor('dashboard', 'Student', 'Maya')
  const enrollment = buddyGuidanceFor('enrollment', 'Student')
  const grades = buddyGuidanceFor('grading', 'Student')
  const documents = buddyGuidanceFor('documents', 'Student')

  assert.equal(dashboard.title, 'Welcome back, Maya')
  assert.match(enrollment.message, /enrollment status, requirements, subjects, and schedule/i)
  assert.match(grades.message, /released grades/i)
  assert.match(documents.message, /request status, appointment details, and official claim instructions/i)
  assert.equal(new Set([dashboard.id, enrollment.id, grades.id, documents.id]).size, 4)
})

test('Guest Admission uses Guest-safe guidance without invented status or dates', () => {
  assert.equal(buddyModuleForRoute('admission'), 'admission')
  assert.equal(buddyModuleForRoute('student-admission-status'), 'admission')
  const guidance = buddyGuidanceFor('admission', 'Guest')
  assert.equal(guidance.title, 'Your Admission journey')
  assert.match(guidance.message, /verified Admission status/)
  assert.doesNotMatch(guidance.message, /approved|rejected|deadline|suspended/i)
  assert.equal(guidance.kind, 'information')
})

test('official notices are preferred only when relevant and keep acknowledgement support', async () => {
  const buddy = await read('src/components/CDMBuddy.vue')
  assert.equal(noticeMatchesBuddyModule({ type: 'enrollment', source: 'campus_notice' }, 'enrollment'), true)
  assert.equal(noticeMatchesBuddyModule({ type: 'admission', source: 'campus_notice' }, 'documents'), false)
  assert.match(buddy, /findIndex\(item => noticeMatchesBuddyModule\(item, module\)\)/)
  assert.match(buddy, /acknowledgePortalNotice/)
  assert.match(buddy, /notice\.acknowledgement\?\.required/)
  assert.match(buddy, /'Acknowledge'/)
})

test('Settings is excluded while the persistent authenticated layout preserves Buddy state', async () => {
  const [buddy, layout] = await Promise.all([
    read('src/components/CDMBuddy.vue'),
    read('src/layouts/DashboardLayout.vue'),
  ])
  assert.match(buddy, /excludedRoutes = new Set\(\['settings'/)
  assert.match(buddy, /v-show="buddyVisible"/)
  assert.match(layout, /<\/main>\s*<CDMBuddy \/>/)
  assert.doesNotMatch(layout, /<router-view[^>]*>[^]*<CDMBuddy/)
})

test('mute is persistent per account, blocks automatic messages, and never marks notices', async () => {
  const [buddy, proactive] = await Promise.all([
    read('src/components/CDMBuddy.vue'),
    read('src/composables/useBuddyProactive.js'),
  ])
  assert.equal(buddyMuteStorageKey(12), 'cdm-buddy-auto-muted:12')
  assert.equal(buddyMuteStorageKey(19), 'cdm-buddy-auto-muted:19')
  assert.notEqual(buddyMuteStorageKey(12), buddyMuteStorageKey(19))
  assert.match(buddy, /localStorage\.getItem\(muteKey\.value\) === 'true'/)
  assert.match(buddy, /localStorage\.setItem\(muteKey\.value, String\(muted\.value\)\)/)
  assert.match(proactive, /if \(!module \|\| !unref\(enabled\) \|\| unref\(muted\)\) return/)
  assert.match(proactive, /watch\(muted, value => \{ if \(value\) cancel\(\); else schedule\(\{ force: true \}\) \}\)/)

  const toggleMuteBody = buddy.match(/function toggleMute\(\) \{([\s\S]*?)\n\}/)?.[1] || ''
  assert.doesNotMatch(toggleMuteBody, /markSeen|acknowledge/)
})

test('manual mascot interaction remains available while muted', async () => {
  const buddy = await read('src/components/CDMBuddy.vue')
  const toggleBody = buddy.match(/async function toggle\(\) \{([\s\S]*?)\n\}/)?.[1] || ''
  assert.match(toggleBody, /openNotice\('manual'\)/)
  assert.doesNotMatch(toggleBody, /muted/)
  assert.match(buddy, /@click="toggle"/)
})

test('rapid navigation cancels stale work and same-module route changes do not duplicate greetings', async () => {
  const proactive = await read('src/composables/useBuddyProactive.js')
  assert.match(proactive, /transitionGeneration \+= 1/)
  assert.match(proactive, /generation !== transitionGeneration/)
  assert.match(proactive, /if \(module && module !== previous\) schedule\(\)/)
  assert.match(proactive, /Date\.now\(\) - \(lastShown\.get\(module\) \|\| 0\) < BUDDY_MODULE_COOLDOWN/)
  assert.equal(buddyModuleForRoute('enrollment'), buddyModuleForRoute('enrollment-status'))
})

test('route transitions preserve position and notice collections while suppressing sensitive interactions', async () => {
  const [buddy, position] = await Promise.all([
    read('src/components/CDMBuddy.vue'),
    read('src/composables/useBuddyPosition.js'),
  ])
  assert.match(position, /buddyPositionStorageKey\(unref\(accountKey\)\)/)
  assert.match(position, /localStorage\.setItem/)
  assert.match(buddy, /form:focus-within, \[data-buddy-sensitive="true"\]/)
  assert.match(buddy, /dialog\[open\]/)
  assert.match(buddy, /watch\(moduleKey/)
  const moduleWatch = buddy.match(/watch\(moduleKey,[\s\S]*?\n\}\)/)?.[0] || ''
  assert.doesNotMatch(moduleWatch, /notices\.value\s*=|position\.resetPosition/)
})
