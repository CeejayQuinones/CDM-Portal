import assert from 'node:assert/strict'
import test from 'node:test'
import {
  documentTypeAccentClass,
  requestStatusAccentClass,
  requestStatusFromActivity,
} from '../src/modules/document-request/documentRequestPresentation.js'

test('document type accent mapping includes every requested color and a gray fallback', () => {
  assert.equal(documentTypeAccentClass('Birth Certificate'), 'document-type-yellow')
  assert.equal(documentTypeAccentClass('Certificate of Enrollment'), 'document-type-green')
  assert.equal(documentTypeAccentClass('Registration Form'), 'document-type-blue')
  assert.equal(documentTypeAccentClass('Form 137'), 'document-type-purple')
  assert.equal(documentTypeAccentClass('School Card / Report Card'), 'document-type-orange')
  assert.equal(documentTypeAccentClass('Transcript of Records'), 'document-type-gray')
})

test('request status mapping produces the requested bottom-border accent classes', () => {
  assert.equal(requestStatusAccentClass('pending'), 'status-accent-pending')
  assert.equal(requestStatusAccentClass('processing'), 'status-accent-processing')
  assert.equal(requestStatusAccentClass('ready_for_release'), 'status-accent-ready-for-release')
  assert.equal(requestStatusAccentClass('released'), 'status-accent-released')
  assert.equal(requestStatusAccentClass('rejected'), 'status-accent-rejected')
  assert.equal(requestStatusAccentClass('cancelled'), 'status-accent-cancelled')
  assert.equal(requestStatusAccentClass('unknown'), 'status-accent-gray')
})

test('recent request workflow actions map to their corresponding request status', () => {
  assert.equal(requestStatusFromActivity({ type: 'request', action: 'submitted' }), 'pending')
  assert.equal(requestStatusFromActivity({ type: 'request', action: 'approved' }), 'processing')
  assert.equal(requestStatusFromActivity({ type: 'request', action: 'returned_to_processing' }), 'processing')
  assert.equal(requestStatusFromActivity({ type: 'request', action: 'ready_for_release' }), 'ready_for_release')
  assert.equal(requestStatusFromActivity({ type: 'request', action: 'released' }), 'released')
  assert.equal(requestStatusFromActivity({ type: 'appointment', action: 'cancelled' }), null)
})
