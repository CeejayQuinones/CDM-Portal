import assert from 'node:assert/strict'
import test from 'node:test'
import {
  EVENT_TIME_ZONE,
  eventDateKey,
  formatEventDateTime,
  formatEventTime,
  toEventApiDateTime,
  toEventDateTimeInput,
} from '../src/modules/event-attendance/eventDateTime.js'

test('Event edit fields and API payloads round-trip in Philippine time without drift', () => {
  const apiValue = '2027-02-10T01:15:00+00:00'
  assert.equal(EVENT_TIME_ZONE, 'Asia/Manila')
  assert.equal(toEventDateTimeInput(apiValue), '2027-02-10T09:15')
  assert.equal(toEventApiDateTime('2027-02-10T09:15'), '2027-02-10T09:15:00+08:00')
  assert.equal(toEventDateTimeInput(apiValue), toEventDateTimeInput(apiValue))
})

test('Event schedule formatting and calendar keys use Asia/Manila consistently', () => {
  const lateUtc = '2027-02-10T17:30:00+00:00'
  assert.equal(eventDateKey(lateUtc), '2027-02-11')
  assert.equal(formatEventTime(lateUtc, 'en-US'), '01:30 AM')
  assert.match(formatEventDateTime(lateUtc, { locale: 'en-US' }), /Feb 11, 2027.*1:30 AM/)
})

test('Event API datetime conversion rejects ambiguous non-form values', () => {
  assert.throws(() => toEventApiDateTime('February 10 at 9:15'), /datetime-local format/)
  assert.equal(toEventApiDateTime(''), '')
  assert.equal(toEventDateTimeInput(''), '')
})
