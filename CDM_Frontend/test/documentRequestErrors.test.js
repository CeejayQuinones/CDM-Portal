import assert from 'node:assert/strict'
import test from 'node:test'
import { documentRequestErrorMessage } from '../src/modules/document-request/documentRequestErrors.js'

test('request forms display the first field error before the response summary', () => {
  assert.equal(documentRequestErrorMessage({ response: { data: {
    message: 'The given data was invalid.',
    errors: { appointment_date: ['This date is unavailable.', 'Choose another date.'], reason: ['A reason is required.'] },
  } } }), 'This date is unavailable.')
})

test('empty field errors preserve the server message and its existing precedence', () => {
  for (const errors of [undefined, {}, { reason: [] }, { reason: [''], date: ['Later field error'] }]) {
    assert.equal(documentRequestErrorMessage({ response: { data: { message: 'Request cannot be completed.', errors } } }), 'Request cannot be completed.')
  }
})

test('missing response messages preserve the existing request-form fallback', () => {
  for (const error of [new Error('Network Error'), {}, { response: {} }, { response: { data: { message: '', errors: {} } } }]) {
    assert.equal(documentRequestErrorMessage(error), 'The request could not be completed.')
  }
})
