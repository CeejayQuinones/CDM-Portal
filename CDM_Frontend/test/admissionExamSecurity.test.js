import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'
import { ADMISSION_EXAM_RULES } from '../src/config/admissionExamRules.js'
import { createExamVisibilityGuard } from '../src/modules/admission/examVisibilityGuard.js'

const read = path => readFile(new URL(`../${path}`, import.meta.url), 'utf8')

test('the six examination rules are explicit, bounded, and do not claim perfect surveillance', () => {
  assert.equal(ADMISSION_EXAM_RULES.length, 6)
  assert.match(ADMISSION_EXAM_RULES[0], /desktop or laptop.*campus examination site and network/i)
  assert.match(ADMISSION_EXAM_RULES[1], /tab active|hiding|switching focus/i)
  assert.match(ADMISSION_EXAM_RULES[2], /cannot resume.*unanswered questions receive no credit/i)
  assert.match(ADMISSION_EXAM_RULES[3], /AI tools.*outside assistance/i)
  assert.match(ADMISSION_EXAM_RULES[4], /timer does not pause.*server finalizes/i)
  assert.match(ADMISSION_EXAM_RULES[5], /Registrar.*final retake.*officially published/i)
  assert.match(ADMISSION_EXAM_RULES[0], /does not independently prove your physical location/i)
})

test('starting requires an accessible acknowledgment and sends it to the existing start endpoint', async () => {
  const [view, rules] = await Promise.all([
    read('src/modules/admission/AdmissionExamView.vue'),
    read('src/modules/admission/components/AdmissionExamRules.vue'),
  ])

  assert.match(view, /<AdmissionExamRules v-model:acknowledged="rulesAcknowledged"/)
  assert.match(view, /:disabled="busy \|\| !rulesAcknowledged"/)
  assert.match(view, /exam\/start',\{ rules_acknowledged: true \}/)
  assert.match(rules, /aria-labelledby="exam-rules-title"/)
  assert.match(rules, /type="checkbox"/)
  assert.match(rules, /I have read and agree to follow all six examination rules/)
})

test('observable focus events warn and protect dirty answers without automatic blur termination', async () => {
  const [view, dialog] = await Promise.all([
    read('src/modules/admission/AdmissionExamView.vue'),
    read('src/modules/admission/components/AdmissionDialog.vue'),
  ])

  assert.match(view, /document\.addEventListener\?\.\('visibilitychange', visibilityChanged\)/)
  assert.match(view, /window\.addEventListener\('blur', windowBlurred\)/)
  assert.match(view, /function leave\(event\) \{ if \(activeAttempt\(\)\)/)
  assert.match(view, /if \(dirty && !busy\.value\) save\(false\)/)
  assert.match(view, /This event alone does not end your attempt/)
  assert.match(view, /The examination tab was left or hidden/)
  assert.match(view, /Repeated interruptions may be reviewed by authorized school staff according to approved examination policy/)
  assert.match(view, /This warning alone does not end or submit your attempt/)
  assert.match(view, /Acknowledge and return to examination/)
  assert.match(view, /:dismissible="false"/)
  assert.match(dialog, /aria-describedby/)
  assert.match(dialog, /props\.dismissible/)
  assert.match(view, /onBeforeRouteLeave/)
  assert.match(view, /server timer will continue and only server-confirmed answers are preserved/)
  assert.doesNotMatch(view, /getUserMedia|MediaRecorder|screen\.capture|contextmenu/)
  assert.doesNotMatch(view, /exam\/.+terminate/)
})

test('visibility guard deduplicates one hidden episode and separates genuine focus loss', () => {
  let active = true
  let hidden = 0
  let returned = 0
  let blurred = 0
  let nextTimer = 0
  const timers = new Map()
  const guard = createExamVisibilityGuard({
    isActive: () => active,
    onHidden: () => hidden++,
    onReturned: () => returned++,
    onBlur: () => blurred++,
    schedule(callback) { const id = ++nextTimer; timers.set(id, callback); return id },
    cancel(id) { timers.delete(id) },
  })
  const flushTimers = () => {
    const callbacks = [...timers.values()]
    timers.clear()
    callbacks.forEach(callback => callback())
  }

  guard.visibilityChanged('hidden')
  guard.visibilityChanged('hidden')
  assert.equal(hidden, 1)
  guard.visibilityChanged('visible')
  guard.visibilityChanged('visible')
  assert.equal(returned, 1)

  guard.windowBlurred()
  guard.visibilityChanged('hidden')
  flushTimers()
  assert.equal(blurred, 0, 'a hidden-tab episode must cancel its pending blur warning')
  guard.visibilityChanged('visible')
  assert.equal(returned, 2)

  guard.windowBlurred()
  flushTimers()
  assert.equal(blurred, 1)

  active = false
  guard.visibilityChanged('hidden')
  guard.windowBlurred()
  flushTimers()
  assert.equal(hidden, 2)
  assert.equal(blurred, 1)
  guard.reset()
})

test('exam taking is website-only while Admission status and results remain available elsewhere', async () => {
  const [routes, access] = await Promise.all([
    read('src/modules/admission/routes.js'),
    read('src/config/accessControl.js'),
  ])

  assert.match(routes, /student-admission-exam[^\n]+platforms: \['web'\]/)
  assert.match(access, /name: 'student-admission-exam'[\s\S]*?platforms: \['web'\]/)
  assert.doesNotMatch(routes, /student-admission-result[^\n]+platforms:/)
  assert.doesNotMatch(routes, /name: 'admission'[^\n]+platforms:/)
})

test('timer and attempt display use server values rather than a hardcoded second attempt policy', async () => {
  const view = await read('src/modules/admission/AdmissionExamView.vue')

  assert.match(view, /Date\.parse\(session\.value\.deadline\)/)
  assert.match(view, /Date\.parse\(value\.server_now\) - Date\.now\(\)/)
  assert.match(view, /session\.max_attempts/)
  assert.doesNotMatch(view, /Attempt \{\{ session\.attempt_number \}\} of 2/)
})

test('deadline status refresh is limited to once per exam session', async () => {
  const view = await read('src/modules/admission/AdmissionExamView.vue')

  assert.match(view, /deadlineRefreshSession !== session\.value\.session_id/)
  assert.match(view, /deadlineRefreshSession = session\.value\.session_id\s*\n\s*load\(\)/)
})
