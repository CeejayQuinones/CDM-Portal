import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { createRequire } from 'node:module'
import { fileURLToPath } from 'node:url'
import test from 'node:test'
import { build } from 'esbuild'
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc'

test('capacity planner persists availability controls and preserves capacity behavior', async () => {
  const root = fileURLToPath(new URL('../', import.meta.url))
  const file = `${root}src/modules/document-request/RegistrarAppointmentsView.vue`
  const { descriptor } = parse(await readFile(file, 'utf8'), { filename: file })
  const script = compileScript(descriptor, { id: 'capacity-test' })
  assert.deepEqual(compileTemplate({ source: descriptor.template.content, filename: file, id: 'capacity-test', compilerOptions: { bindingMetadata: script.bindings } }).errors, [])
  for (const label of ['Weekend Settings', 'Block Saturdays', 'Block Sundays', 'Holidays', 'Block this date', 'Unblock this date']) assert.ok(descriptor.template.content.includes(label))
  assert.match(descriptor.template.content, /id="daily-capacity"[^>]*:disabled="capacityDisabled"/)
  assert.doesNotMatch(descriptor.template.content, /:disabled="isUnavailable\(day\)"/)

  let settings = { block_saturday: true, block_sunday: true }
  let blocks = [], nextId = 1, rejectSettings = false
  const overrides = new Map()
  const registryHolidays = [{ date: '2026-09-09', name: 'Foundation Day' }]
  const api = {
    registrarAppointments: async () => [],
    registrarCalendar: async month => ({
      default_capacity: 5, settings: { ...settings }, blocked_dates: structuredClone(blocks),
      holidays: registryHolidays.filter(item => item.date.startsWith(month)),
      days: Array.from({ length: new Date(`${month}-01T00:00:00Z`).getUTCMonth() === 8 ? 30 : 31 }, (_, i) => {
        const date = `${month}-${String(i + 1).padStart(2, '0')}`
        return { date, capacity: overrides.get(date) || 5, booked: i === 6 ? 5 : i === 7 ? 4 : 0, has_custom_capacity: overrides.has(date) && overrides.get(date) !== 5 }
      }),
    }),
    updateAppointmentAvailabilitySettings: async payload => {
      if (rejectSettings) throw { response: { data: { message: 'Weekend update failed.' } } }
      assert.deepEqual(Object.keys(payload).sort(), ['block_saturday', 'block_sunday'])
      settings = payload
      return { data: { ...settings }, message: 'Weekend settings saved.' }
    },
    createAppointmentBlockedDate: async payload => {
      assert.ok(payload.reason.trim())
      const block = { ...payload, date: payload.blocked_date, id: nextId++ }
      blocks.push(block)
      return { data: block, message: 'Date blocked.' }
    },
    updateAppointmentBlockedDate: async (id, payload) => {
      assert.deepEqual(payload, { is_active: false })
      blocks = blocks.filter(item => item.id !== id)
      return { message: 'Date unblocked.' }
    },
    updateDateCapacity: async (date, capacity) => { overrides.set(date, capacity) },
  }
  globalThis.__capacityTestApi = api
  const output = await build({
    absWorkingDir: root, bundle: true, write: false, format: 'cjs', platform: 'node',
    stdin: { resolveDir: root, contents: `export { createRenderer, nextTick } from 'vue'; export { default as Planner } from './src/modules/document-request/RegistrarAppointmentsView.vue'` },
    plugins: [{ name: 'capacity-component', setup(builder) {
      builder.onLoad({ filter: /RegistrarAppointmentsView\.vue$/ }, () => ({ contents: script.content, loader: 'js' }))
      builder.onLoad({ filter: /documentRequestService\.js$/ }, () => ({ contents: 'export const documentRequestService = globalThis.__capacityTestApi', loader: 'js' }))
    } }],
  })
  const module = { exports: {} }
  new Function('require', 'module', 'exports', output.outputFiles[0].text)(createRequire(import.meta.url), module, module.exports)
  const { createRenderer, nextTick, Planner } = module.exports
  const renderer = createRenderer({ createComment: () => ({}), insert() {}, remove() {}, parentNode() {}, nextSibling() {} })
  let planner
  const errors = []
  // The root is only a lifecycle scope; assertions exercise the real planner handlers and computed state.
  const app = renderer.createApp({ setup() { planner = Planner.setup({}, { expose() {} }); return () => null } })
  app.config.errorHandler = error => errors.push(error)
  app.mount({})
  try {
    planner.month.value = '2026-09'
    await planner.loadCalendar()
    const day = date => planner.calendarDays.value.find(item => item.date === date)
    assert.equal(planner.calendarLeadingDays.value, 1, 'September starts on Tuesday in every browser timezone')
    assert.equal(planner.dayStatus(day('2026-09-07')), 'Full')
    assert.equal(planner.dayStatus(day('2026-09-08')), 'Nearly Full')
    assert.equal(planner.dayStatus(day('2026-09-09')), 'Holiday')
    assert.equal(planner.dayStatus(day('2026-09-12')), 'Weekend Blocked')
    assert.equal(planner.calendarSummary.value.businessDays, 21)
    const saturday = { target: { checked: false } }
    await planner.updateWeekend('block_saturday', saturday)
    assert.equal(planner.dayStatus(day('2026-09-12')), 'Available')
    assert.equal(planner.dayStatus(day('2026-09-13')), 'Weekend Blocked')
    await planner.loadCalendar()
    assert.equal(planner.calendar.value.settings.block_saturday, false)
    rejectSettings = true
    const sunday = { target: { checked: false } }
    await planner.updateWeekend('block_sunday', sunday)
    assert.equal(sunday.target.checked, true)
    assert.equal(planner.calendarError.value, 'Weekend update failed.')
    rejectSettings = false

    planner.selectDay(day('2026-09-10'))
    planner.capacityDraft.value = 8
    await planner.saveCapacity()
    planner.blockReason.value = 'Staff training'
    await planner.blockSelectedDate()
    assert.equal(planner.selectedDate.value, '2026-09-10')
    assert.equal(planner.dayStatus(planner.selectedDay.value), 'Blocked Date')
    assert.equal(planner.capacityDisabled.value, true)
    assert.equal(planner.slotsLeft(planner.selectedDay.value), 0)
    planner.capacityDraft.value = 10
    await planner.saveCapacity()
    assert.equal(overrides.get('2026-09-10'), 8)
    await planner.loadCalendar()
    assert.equal(planner.selectedDate.value, '2026-09-10', 'blocked selections survive refresh')
    await planner.createBlock({ blocked_date: '2026-09-10', type: 'other', reason: 'Second block' })
    assert.equal(planner.selectedBlocks.value.length, 2)
    await planner.unblockDates(planner.selectedBlocks.value)
    assert.equal(planner.dayStatus(planner.selectedDay.value), 'Available')
    assert.equal(planner.selectedDay.value.capacity, 8)
    planner.resetCapacity()
    await planner.saveCapacity()
    assert.equal(planner.selectedDay.value.has_custom_capacity, false)

    planner.holidayDraft.value = { date: '2026-09-11', name: 'Local Holiday' }
    await planner.addHoliday()
    assert.equal(planner.dayStatus(planner.selectedDay.value), 'Holiday')
    assert.equal(planner.capacityDisabled.value, true)
    const holiday = planner.holidays.value.find(item => item.name === 'Local Holiday')
    await planner.unblockDates([holiday])
    assert.equal(planner.dayStatus(planner.selectedDay.value), 'Available')
    planner.selectDay(day('2026-09-09'))
    assert.equal(planner.capacityDisabled.value, true)
    assert.match(planner.dayAriaLabel(planner.selectedDay.value), /Holiday: Foundation Day/)
    planner.selectDay(day('2026-09-07'))
    planner.blockReason.value = 'Office closure'
    await planner.blockSelectedDate()
    assert.equal(planner.calendarSummary.value.booked, 9, 'blocking a day must not hide its existing bookings from the summary')
    await nextTick()
    assert.deepEqual(errors, [])
  } finally {
    app.unmount()
    delete globalThis.__capacityTestApi
  }
})
