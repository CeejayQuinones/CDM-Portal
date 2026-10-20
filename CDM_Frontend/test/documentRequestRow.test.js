import assert from 'node:assert/strict'
import test from 'node:test'
import {
  mergeDocumentRequestRow,
  requestDocumentName,
  requestStudentNumber,
} from '../src/modules/document-request/documentRequestRow.js'

test('local queue movement preserves listing and detail relations when workflow data is narrow', () => {
  const listingRow = {
    id: 49812,
    status: 'pending',
    request_reference: 'REQ-049812',
    document_type: { id: 2, document_name: 'Certificate of Enrollment' },
    student: {
      id: 41,
      student_number: '24-01241',
      user: { profile: { first_name: 'Jose', last_name: 'Reyes' } },
    },
  }
  const detail = {
    id: 49812,
    purpose: 'Employment',
    student: { id: 41, user_profile: { first_name: 'Jose', middle_name: 'M.', last_name: 'Reyes' } },
  }
  const workflowUpdate = { id: 49812, status: 'processing', updated_at: '2026-08-30T08:00:00.000Z' }

  const moved = mergeDocumentRequestRow(listingRow, { id: 49812 }, detail, workflowUpdate)

  assert.equal(moved.status, 'processing')
  assert.equal(moved.document_type.document_name, 'Certificate of Enrollment')
  assert.equal(moved.student.student_number, '24-01241')
  assert.equal(moved.student.user.profile.last_name, 'Reyes')
  assert.equal(moved.student.user_profile.middle_name, 'M.')
  assert.equal(moved.purpose, 'Employment')
})

test('queue display helpers safely label incomplete nested data', () => {
  assert.equal(requestDocumentName({ id: 1 }), 'Document')
  assert.equal(requestStudentNumber({ id: 1, student: null }), 'Student number unavailable')
})
