import assert from 'node:assert/strict'
import { createRequire } from 'node:module'
import { readFile } from 'node:fs/promises'
import { fileURLToPath } from 'node:url'
import test from 'node:test'
import { build } from 'esbuild'
import { compileScript, parse } from '@vue/compiler-sfc'

test('operator camera and image import use one participant QR endpoint and preserve server feedback', async () => {
  const root = fileURLToPath(new URL('../', import.meta.url))
  const requests = []
  let scanCallback

  globalThis.__eventOperatorApi = {
    async post(url, payload) {
      requests.push({ url, payload })
      if (payload.token === 'expired-token') {
        throw { response: { status: 410, data: { code: 'EXPIRED_PARTICIPANT_QR', message: 'This participant QR has expired.' } } }
      }
      return { data: { code: 'ATTENDANCE_RECORDED', message: 'Attendance was recorded for the participant.' } }
    },
  }
  globalThis.__eventQrMock = class {
    static async getCameras() { return [{ id: 'rear-camera' }] }
    isScanning = false
    async start(_, __, callback) { this.isScanning = true; scanCallback = callback }
    async stop() { this.isScanning = false }
    async scanFile(file) { return file.name === 'expired.png' ? 'expired-token' : 'image-token' }
    clear() {}
  }

  const bundle = await build({
    absWorkingDir: root,
    bundle: true,
    write: false,
    platform: 'node',
    format: 'cjs',
    stdin: {
      resolveDir: root,
      contents: `
        export { createRenderer, h, nextTick } from 'vue'
        export { default as EventOperatorScanner } from './src/modules/event-attendance/EventOperatorScanner.vue'
      `,
    },
    plugins: [{ name: 'event-operator-screen', setup(builder) {
      builder.onLoad({ filter: /\.vue$/ }, async ({ path }) => {
        const { descriptor } = parse(await readFile(path, 'utf8'), { filename: path })
        return { contents: compileScript(descriptor, { id: path, inlineTemplate: true }).content, loader: 'js' }
      })
      builder.onLoad({ filter: /\/services\/apiClient\.js$/ }, () => ({
        contents: 'export const apiClient = globalThis.__eventOperatorApi', loader: 'js',
      }))
      builder.onResolve({ filter: /^html5-qrcode$/ }, () => ({ path: 'html5-qrcode', namespace: 'event-qr' }))
      builder.onLoad({ filter: /.*/, namespace: 'event-qr' }, () => ({
        contents: 'export const Html5Qrcode = globalThis.__eventQrMock', loader: 'js',
      }))
    } }],
  })
  const compiled = { exports: {} }
  new Function('require', 'module', 'exports', bundle.outputFiles[0].text)(createRequire(import.meta.url), compiled, compiled.exports)
  const { createRenderer, h, nextTick, EventOperatorScanner } = compiled.exports

  const node = (type, text = '') => ({ type, text, props: {}, children: [], parent: null, click() {} })
  const detach = child => {
    if (child.parent) child.parent.children.splice(child.parent.children.indexOf(child), 1)
  }
  const renderer = createRenderer({
    createElement: node,
    createText: text => node('#text', text),
    createComment: text => node('#comment', text),
    setText: (target, text) => { target.text = text },
    setElementText: (target, text) => { target.text = text; target.children = [] },
    patchProp: (target, key, _, value) => { target.props[key] = value },
    insert: (child, parent, anchor = null) => {
      detach(child)
      parent.children.splice(anchor ? parent.children.indexOf(anchor) : parent.children.length, 0, child)
      child.parent = parent
    },
    remove: detach,
    parentNode: target => target.parent,
    nextSibling: target => target.parent?.children[target.parent.children.indexOf(target) + 1],
  })
  const find = (target, predicate) => predicate(target) ? target : target.children.map(child => find(child, predicate)).find(Boolean)
  const settle = async () => { await new Promise(resolve => setImmediate(resolve)); await nextTick() }
  const tree = node('root')
  globalThis.document = { querySelector: () => ({ srcObject: { getVideoTracks: () => [{ readyState: 'live' }] } }) }
  globalThis.window = { addEventListener() {} }

  const app = renderer.createApp({ render: () => h(EventOperatorScanner, {
    event: { id: 44, attendance_policy: { mode: 'check_in_only' } },
    capabilities: { can_scan_static_participant_qr: true },
  }) })
  app.mount(tree)
  try {
    const openCamera = find(tree, target => target.type === 'button' && target.text === 'Open Camera')
    await openCamera.props.onClick()
    await settle()
    assert.equal(typeof scanCallback, 'function')

    const firstScan = scanCallback(' camera-token ')
    const duplicateScan = scanCallback('camera-token')
    await Promise.all([firstScan, duplicateScan])
    await settle()
    assert.deepEqual(requests, [{
      url: '/events/44/attendance/operator-scan',
      payload: { token: 'camera-token', action: 'auto' },
    }])
    assert.ok(find(tree, target => target.props.role === 'status' && find(target, child => child.text === 'Attendance recorded.')))

    const input = find(tree, target => target.type === 'input' && target.props.type === 'file')
    await input.props.onChange({ target: { files: [new File(['qr'], 'participant.png', { type: 'image/png' })], value: 'participant.png' } })
    await settle()
    assert.deepEqual(requests[1], {
      url: '/events/44/attendance/operator-scan',
      payload: { token: 'image-token', action: 'auto' },
    })

    await input.props.onChange({ target: { files: [new File(['qr'], 'expired.png', { type: 'image/png' })], value: 'expired.png' } })
    await settle()
    assert.equal(requests[2].payload.token, 'expired-token')
    assert.ok(find(tree, target => target.props.role === 'alert' && target.text === 'This participant QR has expired.'))
  } finally {
    app.unmount()
  }
})
