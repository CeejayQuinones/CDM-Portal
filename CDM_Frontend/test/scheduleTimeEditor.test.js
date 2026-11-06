import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'
import { formatScheduleDuration, meetingTimeState, scheduleTimeFromParts, scheduleTimeParts, spinScheduleHour, spinScheduleMinute, weeklyScheduleMinutes } from '../src/modules/enrollment/scheduleTime.js'

test('schedule spinner increments compact time parts while preserving exact typed minutes', () => {
  assert.equal(spinScheduleHour('12', 1), '1')
  assert.equal(spinScheduleHour('1', -1), '12')
  assert.equal(spinScheduleMinute('00', 1), '15')
  assert.equal(spinScheduleMinute('00', -1), '45')
  assert.equal(spinScheduleMinute('10', 1), '25')
  assert.equal(scheduleTimeFromParts('8', '10', 'AM'), '08:10')
  assert.deepEqual(meetingTimeState({ start_time: '08:10', end_time: '09:25' }), { valid: true, duration: 75, error: '' })
})

test('12-hour spinner values preserve noon, midnight, and backend 24-hour formatting', () => {
  assert.equal(scheduleTimeFromParts('12', '00', 'AM'), '00:00')
  assert.equal(scheduleTimeFromParts('12', '00', 'PM'), '12:00')
  assert.equal(scheduleTimeFromParts('1', '15', 'PM'), '13:15')
  assert.deepEqual(scheduleTimeParts('00:00'), { hour: '12', minute: '00', period: 'AM' })
  assert.deepEqual(scheduleTimeParts('12:00'), { hour: '12', minute: '00', period: 'PM' })
  assert.deepEqual(scheduleTimeParts('23:47'), { hour: '11', minute: '47', period: 'PM' })
})

test('daily and weekly class duration calculations reject incomplete or reversed ranges', () => {
  const meetings = [
    { day: 'Monday', start_time: '08:00', end_time: '10:00' },
    { day: 'Wednesday', start_time: '13:15', end_time: '14:45' },
    { day: 'Friday', start_time: '09:00', end_time: '10:15' },
  ]
  assert.equal(weeklyScheduleMinutes(meetings), 285)
  assert.equal(formatScheduleDuration(285), '4 hr 45 min')
  assert.match(meetingTimeState({ start_time: '', end_time: '10:00' }).error, /both/)
  assert.match(meetingTimeState({ start_time: '10:00', end_time: '09:45' }).error, /later/)
})

test('Electron time controls use an accessible compact spinner, scoped styling, and guarded save', async () => {
  const [component, staff, css] = await Promise.all([
    readFile(new URL('../src/modules/enrollment/ScheduleTimeSelect.vue', import.meta.url), 'utf8'),
    readFile(new URL('../src/modules/enrollment/EnrollmentAcademicStaff.vue', import.meta.url), 'utf8'),
    readFile(new URL('../src/modules/enrollment/registrarEnrollmentComplete.css', import.meta.url), 'utf8'),
  ])
  assert.doesNotMatch(component, /<select/)
  assert.doesNotMatch(component, /type="time"/)
  assert.match(component, /role="group"/)
  assert.match(component, /inputmode="numeric"/)
  assert.match(component, /ArrowUp/)
  assert.match(component, /ArrowDown/)
  assert.match(component, /document\.activeElement !== event\.currentTarget/)
  assert.match(component, /type="button"/)
  assert.match(staff, /Total weekly class hours/i)
  assert.match(staff, /scheduleTimesValid/)
  assert.match(staff, /meetingTimeState/)
  assert.match(staff, /focusFirstModalError/)
  assert.match(staff, /meetings\.\$\{dayIndex\}\._schedule/)
  assert.match(css, /html\.cdm-desktop-client \.en-time-spinner/)
  assert.match(css, /\.en-schedule-editor-overlay \.en-dialog[^}]+overflow: hidden/)
  assert.match(css, /\.en-schedule-dialog \.en-modern-dialog-body[^}]+overflow-y: auto/)
  assert.doesNotMatch(css, /\.en-time-native/)
})
