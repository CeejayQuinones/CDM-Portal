import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { createRequire } from 'node:module'
import { fileURLToPath } from 'node:url'
import test from 'node:test'
import { build } from 'esbuild'
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc'
import { finalReason, finalizedAt, historyAppointmentStatus, historyAppointmentTime, historyDate, historyStatus, historyTimeline, latestHistoryAppointment } from '../src/modules/document-request/documentRequestHistoryPresentation.js'

test('history maps only known terminal statuses for display without modifying records', () => {
  for (const [status, label] of [['completed', 'Completed'], ['released', 'Completed'], ['rejected', 'Rejected'], ['cancelled', 'Cancelled'], ['no_show', 'Cancelled'], ['canceled', 'Cancelled']]) {
    const record = Object.freeze({ status })
    assert.equal(historyStatus(record.status), label)
    assert.equal(record.status, status)
  }
  for (const status of [null, undefined, 'pending', 'approved', 'processing', 'constructor', '__proto__']) assert.equal(historyStatus(status), null)
  assert.equal(historyAppointmentStatus('confirmed'), 'Confirmed')
})

test('finalized dates and final reasons reflect the result rather than later edits', () => {
  const item = { status: 'cancelled', cancelled_at: '2026-09-12T08:00:00Z', updated_at: '2026-09-15T08:00:00Z', cancellation_reason: 'Student did not attend.', remarks: 'Earlier note' }
  assert.equal(finalizedAt(item), item.cancelled_at)
  assert.equal(finalReason(item), 'Student did not attend.')
  assert.equal(finalizedAt({ status: 'completed', completed_at: null, updated_at: item.updated_at }), item.updated_at)
  assert.equal(finalReason({ status: 'rejected', status_changes: [{ to_status: 'rejected', reason: 'Missing document.', created_at: item.cancelled_at }] }), 'Missing document.')
  assert.equal(finalizedAt(null), null)
  assert.equal(finalReason(null), '')
})

test('history dates are readable and use the campus timezone', () => {
  assert.equal(historyDate('2026-09-15'), 'Sep 15, 2026')
  assert.equal(historyDate('2026-09-15T06:30:00Z', true), 'Sep 15, 2026 · 2:30 PM')
  assert.equal(historyDate('2026-09-14T18:00:00Z'), 'Sep 15, 2026')
  for (const value of [null, undefined, '', 'bad date']) assert.equal(historyDate(value), 'Not recorded')
})

test('appointment times are readable without introducing a browser timezone conversion', () => {
  assert.equal(historyAppointmentTime('09:30:00'), '9:30 AM')
  assert.equal(historyAppointmentTime('14:05'), '2:05 PM')
  assert.equal(historyAppointmentTime('00:00:00'), '12:00 AM')
  assert.equal(historyAppointmentTime('12:00'), '12:00 PM')
  for (const value of [null, '', 'bad time', '24:00', '12:60']) assert.equal(historyAppointmentTime(value), 'Not recorded')
})

test('timeline is chronological, deduplicated, and never invents workflow events or shows claim data', () => {
  const item = {
    status: 'completed', created_at: '2026-09-10T00:00:00Z', approved_at: '2026-09-11T02:00:00Z',
    code_verified_at: '2026-09-15T01:00:00Z', completed_at: '2026-09-15T01:05:00Z',
    verification_code_hash: 'SECRET_HASH', _demo_claim_code: '735291',
    appointments: [{ id: 1, created_at: '2026-09-10T03:00:00Z', appointment_date: '2026-09-15' }],
    status_changes: [
      { action: 'completed', created_at: '2026-09-15T01:05:00Z' },
      { action: 'code_verified', created_at: '2026-09-15T01:00:00Z' },
      { action: 'approved', created_at: '2026-09-11T02:00:00Z' },
      { action: 'claim_code_regenerated', reason: '735291', created_at: '2026-09-12T00:00:00Z' },
    ],
  }
  assert.deepEqual(historyTimeline(item).map(event => event.label), ['Requested', 'Date assigned', 'Approved', 'Verified', 'Completed'])
  assert.doesNotMatch(JSON.stringify(historyTimeline(item)), /735291|SECRET_HASH|claim_code/)
  assert.deepEqual(historyTimeline({ status: 'completed', updated_at: '2026-09-15', appointments: [{ appointment_date: '2026-09-15' }] }), [])
  assert.deepEqual(historyTimeline(null), [])
  assert.equal(latestHistoryAppointment({ appointments: [{ id: 1 }, { id: 3 }, { id: 2 }] }).id, 3)
  assert.equal(latestHistoryAppointment(null), null)
})

test('history view compiles with read-only details and dedicated loading, empty and error states', async () => {
  const source = await readFile(new URL('../src/modules/document-request/RegistrarDocumentRequestHistoryView.vue', import.meta.url), 'utf8')
  const { descriptor } = parse(source)
  const script = compileScript(descriptor, { id: 'history-test' })
  assert.deepEqual(compileTemplate({ source: descriptor.template.content, filename: 'History.vue', id: 'history-test', compilerOptions: { bindingMetadata: script.bindings } }).errors, [])
  for (const label of ['Loading history...', 'No history found', 'No results match your filters', 'Unable to load history', 'Request Summary', 'Workflow Result', 'Timeline', 'View Details']) assert.ok(source.includes(label))
  assert.match(source, /section: 'requests'/)
  assert.match(source, /this_week/)
  assert.doesNotMatch(source, /RegistrarRecentActivity|appointmentStatus|api\.(updateRequest|verifyCode|assignAppointment)|_demo_claim_code|verification_code_hash/)
})

test('history preserves filters, pagination and deep links while ignoring stale list and detail responses', async () => {
  const root = fileURLToPath(new URL('../', import.meta.url))
  const filename = `${root}src/modules/document-request/RegistrarDocumentRequestHistoryView.vue`
  const { descriptor } = parse(await readFile(filename, 'utf8'), { filename })
  const script = compileScript(descriptor, { id: 'history-state-test' })
  const listCalls = [], detailCalls = []
  const deferredCall = (calls, input) => new Promise((resolve, reject) => calls.push({ input, resolve, reject }))
  globalThis.__historyTestApi = {
    registrarHistory: params => deferredCall(listCalls, params),
    registrarRequest: id => deferredCall(detailCalls, id),
  }
  const output = await build({
    absWorkingDir: root, bundle: true, write: false, format: 'cjs', platform: 'node',
    stdin: { resolveDir: root, contents: `export { createRenderer, nextTick } from 'vue'; export { route } from 'vue-router'; export { default as History } from './src/modules/document-request/RegistrarDocumentRequestHistoryView.vue'` },
    plugins: [{ name: 'history-state', setup(builder) {
      builder.onLoad({ filter: /RegistrarDocumentRequestHistoryView\.vue$/ }, () => ({ contents: script.content, loader: 'js' }))
      builder.onLoad({ filter: /PaginationControls\.vue$/ }, () => ({ contents: 'export default {}', loader: 'js' }))
      builder.onLoad({ filter: /documentRequestService\.js$/ }, () => ({ contents: 'export const documentRequestService = globalThis.__historyTestApi', loader: 'js' }))
      builder.onResolve({ filter: /^vue-router$/ }, () => ({ path: 'router', namespace: 'history-test' }))
      builder.onLoad({ filter: /.*/, namespace: 'history-test' }, () => ({ contents: `import { reactive } from 'vue'; export const route = reactive({ query: { time_filter: 'yesterday', search: 'Santos' } }); export const useRoute = () => route`, resolveDir: root }))
    } }],
  })
  const module = { exports: {} }
  new Function('require', 'module', 'exports', output.outputFiles[0].text)(createRequire(import.meta.url), module, module.exports)
  const { createRenderer, nextTick, route, History } = module.exports
  const renderer = createRenderer({ createComment: () => ({}), insert() {}, remove() {}, parentNode() {}, nextSibling() {} })
  let view
  const errors = []
  const app = renderer.createApp({ setup() { view = History.setup({}, { expose() {} }); return () => null } })
  app.config.errorHandler = error => errors.push(error)
  app.mount({})
  const settle = async () => { await new Promise(resolve => setImmediate(resolve)); await nextTick() }
  const record = { id: 17, status: 'completed', completed_at: '2026-09-15T00:00:00Z' }
  const result = (records, page = 1) => ({ requests: { data: records, current_page: page, last_page: 2, total: 21 } })
  try {
    assert.equal(listCalls[0].input.time_filter, 'yesterday')
    assert.equal(listCalls[0].input.search, 'Santos')
    listCalls[0].resolve(result([record, { id: 18, status: 'pending' }]))
    await settle()
    assert.deepEqual(view.requests.value.map(item => item.id), [17])

    const paging = view.changePage(2)
    assert.equal(listCalls[1].input.request_page, 2)
    assert.equal(listCalls[1].input.search, 'Santos')
    listCalls[1].resolve(result([record], 2))
    await paging
    assert.equal(view.page.value, 2)

    route.query = { request_id: '17', appointment_id: '4', search: 'REQ-000017', time_filter: 'last_7_days' }
    await nextTick()
    assert.equal(listCalls[2].input.request_id, 17)
    assert.equal(listCalls[2].input.appointment_id, 4)
    assert.equal(listCalls[2].input.time_filter, 'last_7_days')
    assert.equal(listCalls[2].input.search, 'REQ-000017')
    view.dialog.value = { open: false, showModal() { this.open = true }, close() { this.open = false } }
    listCalls[2].resolve(result([record]))
    await settle()
    assert.equal(detailCalls[0].input, 17)
    detailCalls[0].reject(new Error('offline'))
    await settle()
    assert.equal(view.detailsError.value, 'Unable to load request details')
    const retry = view.loadDetails()
    detailCalls[1].resolve({ ...record, appointments: [{ id: 4 }, { id: 5 }] })
    await retry
    assert.equal(view.selectedAppointment.value.id, 4, 'appointment links retain their selected appointment')
    view.closeDetails()

    const opening = view.openDetails(record)
    await nextTick()
    view.closeDetails()
    detailCalls[2].resolve(record)
    await opening
    assert.equal(view.details.value, null, 'late detail responses cannot reopen a closed panel')

    route.query = { request_id: '17' }
    await nextTick()
    const staleCall = listCalls.at(-1)
    const clearing = view.clearFilters()
    const freshCall = listCalls.at(-1)
    assert.equal(freshCall.input.request_id, undefined)
    assert.equal(freshCall.input.appointment_id, undefined)
    assert.equal(freshCall.input.request_page, 1)
    freshCall.resolve(result([record]))
    await clearing
    staleCall.resolve(result([{ ...record, id: 99 }]))
    await settle()
    assert.equal(view.requests.value[0].id, 17)
    assert.equal(view.dialog.value.open, false, 'stale deep links cannot open details after filters change')

    const failing = view.refresh()
    listCalls.at(-1).reject(new Error('offline'))
    await failing
    assert.equal(view.error.value, 'Unable to load history')
    const retryList = view.refresh()
    listCalls.at(-1).resolve(result([]))
    await retryList
    assert.equal(view.error.value, '')
    assert.equal(view.loading.value, false)
    assert.deepEqual(errors, [])
  } finally {
    app.unmount()
    delete globalThis.__historyTestApi
  }
})
