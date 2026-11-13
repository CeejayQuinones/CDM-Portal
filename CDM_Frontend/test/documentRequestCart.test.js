import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'
import { addCartDocument, cartIsComplete, cartSubmissionPayload, estimatedCartFee, estimatedItemFee } from '../src/modules/document-request/documentRequestCart.js'
import { groupRetrievalTasks } from '../src/modules/document-request/documentRequestRetrieval.js'
import { groupRequestStatus, groupStudentSubmissions, retrievalPageSummary } from '../src/modules/document-request/documentRequestWorkspace.js'

const read = (path) => readFileSync(new URL(path, import.meta.url), 'utf8')
const student = read('../src/modules/document-request/StudentDocumentRequestView.vue')
const registrar = read('../src/modules/document-request/RegistrarDocumentRequestView.vue')
const retrieval = read('../src/modules/document-request/RegistrarPhysicalRetrieval.vue')
const retrievalGrouping = read('../src/modules/document-request/documentRequestRetrieval.js')
const groups = read('../src/modules/document-request/RegistrarDocumentGroups.vue')
const unified = read('../src/modules/document-request/RegistrarStudentRequests.vue')
const documentStyles = read('../src/modules/document-request/documentRequest.css')
const sharedButton = read('../src/design-system/CdmButton.vue')
const offline = read('../src/services/offline/offlineApi.js')

test('cart deduplicates document types and retains per-item copy and purpose input', () => {
  const first = { id: 11, document_name: 'Transcript', processing_fee: 50 }
  const second = { id: 12, document_name: 'Certificate', processing_fee: 25 }
  const cart = addCartDocument(addCartDocument([], first), second)
  assert.equal(addCartDocument(cart, first), cart)
  cart[0].quantity = 2
  cart[1].overridePurpose = true
  cart[1].purposeCategory = 'Others'
  cart[1].purposeDetails = '  School transfer  '
  assert.equal(cartIsComplete(cart, 'Scholarship', ''), true)
  assert.deepEqual(cartSubmissionPayload(cart, 'Scholarship', '', 'key').items, [
    { document_type_id: 11, quantity: 2, purpose_category: 'Scholarship', purpose_details: null },
    { document_type_id: 12, quantity: 1, purpose_category: 'Others', purpose_details: 'School transfer' },
  ])
  cart[1].purposeDetails = '  '
  assert.equal(cartIsComplete(cart, 'Scholarship', ''), false)
  cart[1].purposeDetails = 'Valid'
  cart[0].quantity = 11
  assert.equal(cartIsComplete(cart, 'Scholarship', ''), false)
})

test('cart estimates waive the first daily request for each document and charge repeat requests per copy', () => {
  const first = addCartDocument([], { id: 11, processing_fee: 150, first_request_free_today: true })[0]
  const repeat = addCartDocument([], { id: 12, processing_fee: 25, first_request_free_today: false })[0]
  first.quantity = 3
  repeat.quantity = 2

  assert.equal(estimatedItemFee(first), 0)
  assert.equal(estimatedItemFee(repeat), 50)
  assert.equal(estimatedCartFee([first, repeat]), 50)
  assert.deepEqual(cartSubmissionPayload([first, repeat], 'Scholarship', '', 'key').items.map((item) => item.quantity), [3, 2])
})

test('student group tracking and unified Registrar processing retain independent request actions', () => {
  assert.match(student, /group\.group_reference/)
  assert.match(student, /createRequestBatch/)
  assert.match(registrar, /RegistrarStudentRequests/)
  assert.match(registrar, /RegistrarPhysicalRetrieval/)
  assert.match(registrar, /@process="selectRequest"/)
  assert.match(registrar, /return-only/)
  assert.match(registrar, /error && !selected/)
  assert.match(registrar, /selectedRequestSequence/)
  assert.match(retrievalGrouping, /cabinet_slot/)
  assert.match(retrieval, /No drawer assigned/)
})

test('retrieval grouping uses current location while awaiting and stored snapshot after retrieval', () => {
  const slot = { slot_code: 'A1', cabinet: { cabinet_code: 'A' } }
  const awaiting = { id: 1, status: 'awaiting', student: { physical_record_location: { cabinet_slot: slot } } }
  const second = { id: 2, status: 'awaiting', student: { physical_record_location: { cabinet_slot: slot } } }
  const missing = { id: 3, status: 'awaiting', student: {} }
  const retrieved = { id: 4, status: 'retrieved', cabinet_code_snapshot: 'B', slot_code_snapshot: 'B2', student: { physical_record_location: { cabinet_slot: slot } } }
  assert.deepEqual(groupRetrievalTasks([awaiting, second, missing, retrieved]).map((group) =>
    [group.name, group.drawers.map((drawer) => [drawer.name, drawer.items.map((item) => item.id)])]), [
    ['A', [['A1', [1, 2]]]],
    ['Unassigned location', [['No drawer assigned', [3]]]],
    ['B', [['B2', [4]]]],
  ])
})

test('group status is derived only when every document has the same status', () => {
  assert.equal(groupRequestStatus([{ status: 'pending' }, { status: 'pending' }]), 'Pending')
  assert.equal(groupRequestStatus([{ status: 'pending' }, { status: 'approved' }]), 'Mixed statuses')
  assert.equal(groupRequestStatus([]), 'No requests')
})

test('student details keep separate batch submissions and historical single-request identity', () => {
  const requests = [
    { id: 8, document_request_batch_id: 2, batch: { group_reference: 'GROUP-000002', created_at: '2026-11-11' } },
    { id: 7, document_request_batch_id: 2, batch: { group_reference: 'GROUP-000002', created_at: '2026-11-11' } },
    { id: 3, document_request_batch_id: null, request_reference: 'REQ-000003', created_at: '2026-10-01' },
  ]
  assert.deepEqual(groupStudentSubmissions(requests).map((group) => [group.reference, group.requests.map((request) => request.id)]), [
    ['GROUP-000002', [8, 7]],
    ['REQ-000003', [3]],
  ])
})

test('retrieval counts are explicitly page-scoped and flag incomplete locations', () => {
  const located = { cabinet_slot: { slot_code: 'A1', cabinet: { cabinet_code: 'A' } } }
  assert.deepEqual(retrievalPageSummary([
    { status: 'awaiting', student: { physical_record_location: located } },
    { status: 'awaiting', student: { physical_record_location: null } },
    { status: 'retrieved', student: { physical_record_location: null } },
  ]), { awaiting: 2, retrieved: 1, missing: 1 })
})

test('unified Registrar workspace keeps student detail, contextual retrieval, filters and actions', () => {
  assert.match(groups, /<details v-for="group in groups"/)
  assert.match(groups, /:aria-expanded="expandedIds\.includes\(group\.id\)"/)
  assert.match(unified, /registrarRequestStudents/)
  assert.match(unified, /sameLocationRequests/)
  assert.match(unified, /bulkRejectRequests/)
  assert.match(unified, /updateRetrievalTask/)
  assert.match(unified, /@keydown\.esc\.stop="closeStudent"/)
  assert.match(unified, /No matching active requests/)
  assert.match(unified, /Find requests in the same location/)
  assert.match(unified, /No physical location is assigned/)
  assert.match(unified, /@click="emit\('process', item\)"/)
  assert.match(unified, /selectedStudentId\.value && detail\.value/)
  assert.match(unified, /locationSequence/)
  assert.match(unified, /student\.pending_requests_count.*student\.approved_requests_count/)
  assert.match(offline, /registrar\/document-request-students/)
  assert.doesNotMatch(registrar, /dr-workspace-tabs/)
  assert.doesNotMatch(registrar, /dr-return-access/)
  assert.match(documentStyles, /prefers-reduced-motion: reduce/)
})

test('Registrar request actions use the Admission Results button primitive and appropriate variants', () => {
  assert.match(registrar, /import CdmButton/)
  assert.match(unified, /import CdmButton/)
  assert.match(retrieval, /import CdmButton/)
  assert.match(unified, /<CdmButton[^>]*@click="openStudent\(student\.id\)"/)
  assert.match(unified, /<CdmButton[^>]*@click="emit\('process', item\)"/)
  assert.match(unified, /variant="outline"[^>]*@click="closeStudent"/)
  assert.match(unified, /variant="danger"[^>]*@submit\.prevent="rejectSelected"|variant="danger"[^>]*:disabled="rejecting/)
  assert.match(retrieval, /variant="outline"[^>]*@click="transition\(task, 'return'\)"/)
  assert.match(sharedButton, /--cdm-control-md/)
  assert.match(sharedButton, /--cdm-focus/)
  assert.match(sharedButton, /prefers-reduced-motion:reduce/)
})

test('return-only queue shows authorized total and a compact expandable list without losing Mark Returned', () => {
  assert.match(registrar, /<RegistrarPhysicalRetrieval ref="returnQueue" return-only/)
  assert.match(retrieval, /status = ref\(props\.returnOnly \? 'retrieved' : ''\)/)
  assert.match(retrieval, /api\.retrievalTasks\(/)
  assert.match(retrieval, /total\.value = result\.total/)
  assert.match(retrieval, /All folders accounted for/)
  assert.match(retrieval, /\{\{ total \}\}.*out of storage/)
  assert.match(retrieval, /:aria-expanded="expanded" aria-controls="return-folder-list"/)
  assert.match(retrieval, /v-if="!error && \(!returnOnly \|\| \(expanded && total > 0\)\)"/)
  assert.match(retrieval, /cabinet_code_snapshot|groupRetrievalTasks/)
  assert.match(retrieval, /Mark returned/)
  assert.match(retrieval, /api\.updateRetrievalTask\(task\.id, action\)/)
  assert.match(registrar, /@retrieval-change="returnQueue\?\.refresh\(\)"/)
  assert.match(registrar, /@changed="unifiedWorkspace\?\.refresh\(\)"/)
})
