import assert from 'node:assert/strict'
import { createRequire } from 'node:module'
import { basename } from 'node:path'
import { readFile } from 'node:fs/promises'
import { fileURLToPath } from 'node:url'
import test from 'node:test'
import { build } from 'esbuild'
import { compileScript, parse } from '@vue/compiler-sfc'

test('mobile Event workspace refresh reveals an approved Moderator scanner and an ongoing multi-day Event', async () => {
  const root = fileURLToPath(new URL('../', import.meta.url))
  const baseEvent = {
    id: 44,
    title: 'Current campus Event',
    venue: 'Main Hall',
    description: '',
    starts_at: '2026-10-20T01:00:00Z',
    ends_at: '2026-10-21T01:00:00Z',
    status: 'ongoing',
    managed_status: 'published',
    audiences: [{ audience_type: 'all_users', label: 'All Students and Professors' }],
    attendance_policy: { methods: ['participant_static'] },
    capabilities: { can_operate_attendance: false, can_scan_static_participant_qr: false },
  }
  let approved = false
  globalThis.__eventWorkspaceRole = 'Student'
  globalThis.__eventWorkspaceAppListener = null
  globalThis.__eventWorkspaceApi = {
    async get(url) {
      if (url === '/events') {
        const events = approved
          ? [{ ...baseEvent, capabilities: { event_responsibility: 'registered_moderator', can_operate_attendance: true, can_scan_static_participant_qr: true } }]
          : [baseEvent]
        return { data: { data: { data: events }, event_access: {} } }
      }
      if (url === '/events/44') return { data: { data: approved ? { ...baseEvent, capabilities: { event_responsibility: 'registered_moderator', can_operate_attendance: true, can_scan_static_participant_qr: true } } : baseEvent } }
      throw new Error(`Unexpected GET ${url}`)
    },
  }

  const bundle = await build({
    absWorkingDir: root,
    bundle: true,
    write: false,
    platform: 'node',
    format: 'cjs',
    define: { 'import.meta.env.VITE_EVENT_SELF_SCAN_ENABLED': '"false"' },
    stdin: {
      resolveDir: root,
      contents: `
        export { createRenderer, nextTick } from 'vue'
        export { default as EventAttendanceView } from './src/modules/event-attendance/EventAttendanceView.vue'
      `,
    },
    plugins: [{ name: 'event-workspace-screen', setup(builder) {
      builder.onLoad({ filter: /EventAttendanceView\.vue$/ }, async ({ path }) => {
        const { descriptor } = parse(await readFile(path, 'utf8'), { filename: path })
        return { contents: compileScript(descriptor, { id: path, inlineTemplate: true }).content, loader: 'js' }
      })
      builder.onLoad({ filter: /\.vue$/ }, ({ path }) => ({
        contents: `import { h } from 'vue'; export default { name: '${basename(path, '.vue')}', render() { return h('div', { 'data-component': '${basename(path, '.vue')}' }, '${basename(path, '.vue')}') } }`,
        loader: 'js',
      }))
      builder.onLoad({ filter: /event\.css$/ }, () => ({ contents: '', loader: 'js' }))
      builder.onLoad({ filter: /\/services\/apiClient\.js$/ }, () => ({ contents: 'export const apiClient = globalThis.__eventWorkspaceApi', loader: 'js' }))
      builder.onLoad({ filter: /\/stores\/authStore\.js$/ }, () => ({ contents: 'export const useAuthStore = () => ({ get currentRole() { return globalThis.__eventWorkspaceRole } })', loader: 'js' }))
      builder.onLoad({ filter: /\/config\/clientPlatform\.js$/ }, () => ({ contents: "export const clientPlatform = 'mobile'", loader: 'js' }))
      builder.onLoad({ filter: /useEventModalScrollLock\.js$/ }, () => ({ contents: 'export const useEventModalScrollLock = () => {}', loader: 'js' }))
      builder.onResolve({ filter: /^vue-router$/ }, () => ({ path: 'vue-router', namespace: 'router-mock' }))
      builder.onLoad({ filter: /.*/, namespace: 'router-mock' }, () => ({ contents: "export const useRoute = () => ({ query: {}, name: 'event-attendance' }); export const useRouter = () => ({ replace() {} })", loader: 'js' }))
      builder.onResolve({ filter: /^@capacitor\/app$/ }, () => ({ path: 'capacitor-app', namespace: 'capacitor-app-mock' }))
      builder.onLoad({ filter: /.*/, namespace: 'capacitor-app-mock' }, () => ({ contents: "export const App = { async addListener(event, callback) { if (event === 'appStateChange') globalThis.__eventWorkspaceAppListener = callback; return { async remove() {} } } }", loader: 'js' }))
      builder.onResolve({ filter: /^@capacitor\/core$/ }, () => ({ path: 'capacitor-core', namespace: 'capacitor-core-mock' }))
      builder.onLoad({ filter: /.*/, namespace: 'capacitor-core-mock' }, () => ({ contents: 'export const Capacitor = { isNativePlatform: () => true }', loader: 'js' }))
    } }],
  })
  const compiled = { exports: {} }
  new Function('require', 'module', 'exports', bundle.outputFiles[0].text)(createRequire(import.meta.url), compiled, compiled.exports)
  const { createRenderer, nextTick, EventAttendanceView } = compiled.exports

  const node = (type, text = '') => {
    const target = { type, text, props: {}, children: [], parent: null, multiple: false, selectedIndex: -1, addEventListener() {}, removeEventListener() {}, focus() {} }
    Object.defineProperty(target, 'options', { get: () => target.children.filter(child => child.type === 'option') })
    return target
  }
  const detach = child => { if (child.parent) child.parent.children.splice(child.parent.children.indexOf(child), 1) }
  const renderer = createRenderer({
    createElement: node,
    createText: text => node('#text', text),
    createComment: text => node('#comment', text),
    setText: (target, text) => { target.text = text },
    setElementText: (target, text) => { target.text = text; target.children = [] },
    patchProp: (target, key, _, value) => { target.props[key] = value },
    insert: (child, parent, anchor = null) => { detach(child); parent.children.splice(anchor ? parent.children.indexOf(anchor) : parent.children.length, 0, child); child.parent = parent },
    remove: detach,
    parentNode: target => target.parent,
    nextSibling: target => target.parent?.children[target.parent.children.indexOf(target) + 1],
    querySelector: () => teleport,
    insertStaticContent: (content, parent, anchor = null) => {
      const child = node('#static', content)
      parent.children.splice(anchor ? parent.children.indexOf(anchor) : parent.children.length, 0, child)
      child.parent = parent
      return [child, child]
    },
  })
  const find = (target, predicate) => predicate(target) ? target : target.children.map(child => find(child, predicate)).find(Boolean)
  const settle = async () => { await new Promise(resolve => setImmediate(resolve)); await nextTick() }
  const tree = node('root')
  const teleport = node('teleport')
  globalThis.document = { activeElement: null }
  const app = renderer.createApp(EventAttendanceView)
  app.mount(tree)
  try {
    await settle()
    assert.equal(find(tree, target => target.type === 'button' && target.text === 'Open Attendance'), undefined)
    await find(tree, target => target.type === 'button' && target.text === 'View Event').props.onClick()
    await settle()
    assert.equal(find(teleport, target => target.type === 'button' && target.text === 'Scan Participant QR'), undefined)
    await find(teleport, target => target.props['aria-label'] === 'Close dialog').props.onClick()
    await settle()
    approved = true
    await globalThis.__eventWorkspaceAppListener({ isActive: true })
    await settle()
    const attendance = find(tree, target => target.type === 'button' && target.text === 'Open Attendance')
    assert.ok(attendance)
    await find(tree, target => target.type === 'button' && target.text === 'View Event').props.onClick()
    await settle()
    const scannerAction = find(teleport, target => target.type === 'button' && target.text === 'Scan Participant QR')
    assert.ok(scannerAction)
    assert.equal(find(teleport, target => target.props['data-component'] === 'EventOperatorScanner'), undefined)
    await scannerAction.props.onClick()
    await settle()
    assert.ok(find(teleport, target => target.props['data-component'] === 'EventOperatorScanner'))
  } finally {
    app.unmount()
  }

  globalThis.__eventWorkspaceRole = 'Professor'
  globalThis.__eventWorkspaceApi.get = async (url) => {
    if (url === '/events') return { data: { data: { data: [{ ...baseEvent, id: 45, title: 'New Professor-inclusive Event' }] }, event_access: {} } }
    throw new Error(`Unexpected GET ${url}`)
  }
  const professorTree = node('root')
  const professorApp = renderer.createApp(EventAttendanceView)
  professorApp.mount(professorTree)
  try {
    await settle()
    assert.ok(find(professorTree, target => target.text === 'New Professor-inclusive Event'))
  } finally {
    professorApp.unmount()
  }
})
