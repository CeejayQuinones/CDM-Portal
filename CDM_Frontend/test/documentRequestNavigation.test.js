import assert from 'node:assert/strict'
import test from 'node:test'
import {
  documentRequestIdFromQuery,
  documentRequestProfileQuery,
  documentRequestReturnContext,
  documentRequestSourceQuery,
  withoutDocumentRequestFocus,
} from '../src/modules/document-request/documentRequestNavigation.js'

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
