import assert from 'node:assert/strict'
import test from 'node:test'
import {
  appointmentDocumentRequestQuery,
  appointmentReturnContext,
  documentRequestIdFromQuery,
  documentRequestProfileQuery,
  documentRequestReturnContext,
  documentRequestSourceQuery,
  withoutDocumentRequestFocus,
} from '../src/modules/document-request/documentRequestNavigation.js'

test('appointment context opens the exact request and returns to the same appointment', () => {
  const requestQuery = appointmentDocumentRequestQuery({ requestId: 91, appointmentId: 27 })

  assert.deepEqual(requestQuery, {
    from: 'appointment',
    request_id: 91,
    appointment_id: 27,
  })
  assert.equal(documentRequestIdFromQuery(requestQuery), 91)
  assert.deepEqual(appointmentReturnContext(requestQuery), {
    label: '← Back to Appointment',
    to: {
      name: 'registrar-document-appointments',
      query: {
        request_id: 91,
        appointment_id: 27,
        focus: 'appointment-27',
        open: 'completion',
      },
    },
  })
})

test('appointment return context rejects missing or invalid appointment identifiers', () => {
  assert.equal(appointmentReturnContext({ from: 'appointment', request_id: 91 }), null)
  assert.equal(appointmentReturnContext({ from: 'document-request', request_id: 91, appointment_id: 27 }), null)
})

test('document request context returns to and reopens the same request', () => {
  const profileQuery = documentRequestProfileQuery({
    requestId: 123,
    search: 'Maria Santos',
    timeFilter: 'month',
    pendingPage: 4,
    processingPage: 2,
  })
  const context = documentRequestReturnContext(profileQuery)

  assert.equal(context.label, '← Back to Document Request')
  assert.deepEqual(context.to, {
    name: 'registrar-document-requests',
    query: {
      request_id: 123,
      search: 'Maria Santos',
      time_filter: 'month',
      pending_page: 4,
      processing_page: 2,
    },
  })
  assert.equal(documentRequestIdFromQuery(context.to.query), 123)
})

test('physical record context returns to the same request with its queue state', () => {
  const physicalQuery = {
    ...documentRequestSourceQuery({
      requestId: 321,
      studentId: 45,
      search: 'REQ-000321',
      timeFilter: 'last_7_days',
      pendingPage: 2,
      processingPage: 1,
    }),
    cabinet: 7,
    slot: 19,
  }
  const context = documentRequestReturnContext(physicalQuery)

  assert.equal(physicalQuery.student_id, 45)
  assert.deepEqual(context.to, {
    name: 'registrar-document-requests',
    query: {
      request_id: 321,
      search: 'REQ-000321',
      time_filter: 'last_7_days',
      pending_page: 2,
      processing_page: 1,
    },
  })
})

test('student profiles without valid document request context keep their normal navigation', () => {
  assert.equal(documentRequestReturnContext({}), null)
  assert.equal(documentRequestReturnContext({ from: 'document-request', request_id: 'invalid' }), null)
  assert.equal(documentRequestIdFromQuery({ request_id: '-1' }), null)
})

test('closing a restored modal removes only request focus context', () => {
  assert.deepEqual(
    withoutDocumentRequestFocus({
      request_id: '123',
      focus: 'request-123',
      search: 'Maria Santos',
      status: 'processing',
      time_filter: 'month',
      page: '4',
    }),
    {
      search: 'Maria Santos',
      status: 'processing',
      time_filter: 'month',
      page: '4',
    },
  )
})
