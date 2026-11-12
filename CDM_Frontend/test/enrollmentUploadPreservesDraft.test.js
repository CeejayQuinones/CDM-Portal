import assert from 'node:assert/strict'
import { readFile, mkdtemp, rm } from 'node:fs/promises'
import path from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'
import test from 'node:test'
import { build } from 'esbuild'
import { compileScript, parse } from '@vue/compiler-sfc'
import { createRenderer, nextTick } from 'vue'

const frontend = fileURLToPath(new URL('../', import.meta.url))
const response = (data) => ({ data: { success: true, data } })
const wait = async () => { await new Promise((resolve) => setTimeout(resolve, 0)); await nextTick() }

test('Student Enrollment retains dirty personal details through upload, replacement, preview and failure', async () => {
  const temporary = await mkdtemp(path.join(frontend, 'test/.enrollment-upload-'))
  const priorWindow = globalThis.window
  const priorDocument = globalThis.document
  const priorDocumentClass = globalThis.Document
  const priorCreateUrl = globalThis.URL.createObjectURL
  const priorRevokeUrl = globalThis.URL.revokeObjectURL
  globalThis.window = { addEventListener() {}, removeEventListener() {}, confirm: () => true }
  globalThis.Document = class {}
  globalThis.document = Object.assign(new globalThis.Document(), { activeElement: null })
  globalThis.URL.createObjectURL = () => 'blob:enrollment-test'
  globalThis.URL.revokeObjectURL = () => {}
  try {
    await build({
      stdin: { contents: "export { default as EnrollmentStudent } from './src/modules/enrollment/EnrollmentStudent.vue'; export { apiState } from './src/services/apiClient';", resolveDir: frontend },
      outfile: path.join(temporary, 'harness.mjs'), bundle: true, platform: 'node', format: 'esm', packages: 'external',
      plugins: [{ name: 'enrollment-upload-test', setup(builder) {
        builder.onResolve({ filter: /services\/apiClient$/ }, () => ({ path: 'api', namespace: 'test' }))
        builder.onResolve({ filter: /config\/clientPlatform$/ }, () => ({ path: 'platform', namespace: 'test' }))
        builder.onResolve({ filter: /^vue-router$/ }, () => ({ path: 'router', namespace: 'test' }))
        builder.onLoad({ filter: /.*/, namespace: 'test' }, ({ path: name }) => ({ contents: {
          api: "export const apiState={calls:[],handler:async()=>({})};const call=(method,url,payload)=>{apiState.calls.push({method,url,payload});return apiState.handler(method,url,payload)};export const apiClient={get:(u,p)=>call('get',u,p),post:(u,p)=>call('post',u,p)};",
          platform: "export const clientPlatform='web';",
          router: 'export const onBeforeRouteLeave=()=>{};',
        }[name] }))
        builder.onLoad({ filter: /\.css$/ }, () => ({ contents: '', loader: 'js' }))
        builder.onLoad({ filter: /\.vue$/ }, async ({ path: filename }) => {
          if (filename.endsWith('/EnrollmentDialog.vue')) return { contents: "import {h} from 'vue';export default {setup(_,context){return ()=>h('div',{},context.slots.default?.())}}" }
          if (!filename.endsWith('/EnrollmentStudent.vue')) return { contents: 'export default { render(){ return null } }' }
          const { descriptor } = parse(await readFile(filename, 'utf8'), { filename })
          return { contents: compileScript(descriptor, { id: filename, inlineTemplate: true }).content, resolveDir: path.dirname(filename) }
        })
      } }],
    })
    const { EnrollmentStudent, apiState } = await import(pathToFileURL(path.join(temporary, 'harness.mjs')))
    const saved = { first_name: 'Saved', middle_name: '', last_name: 'Student', birth_date: '2004-01-01', gender: 'Female', contact_number: '09111111111', email: 'saved@example.com', address: 'Old address' }
    let version = 1
    let failUpload = null
    let failRefresh = false
    const attachments = new Map()
    const period = { id: 5, academic_year: { school_year: '2026-2027' }, semester: { semester_name: 'First Semester' }, opens_at: '2026-10-01', closes_at: '2026-12-01', state: 'open' }
    const application = () => ({ id: 11, status: 'draft', version, classification: 'regular', personal_details: { ...saved }, period })
    const requirements = () => ['Form 137', 'Form 138', 'Good Moral Certificate'].map((name, index) => {
      const id = index + 1
      return { id, name, required: true, attached: attachments.has(id), reusable: false, filename: attachments.get(id) || null, preview_kind: 'image', mime_type: 'image/png', preview_url: attachments.has(id) ? `/enrollment/applications/11/documents/${100 + id}?preview=1` : null }
    })
    apiState.handler = async (method, url, payload) => {
      if (method === 'get' && url === '/enrollment/status') return response({ eligible: true, applications_enabled: true, current_application: application(), student: { name: 'Saved Student', student_number: '26-00001', course: { course_name: 'Computing' }, curriculum: { curriculum_name: 'Curriculum' }, year_level: 1 }, period })
      if (method === 'get' && url === '/enrollment/applications/mine') return response({ data: [application()], last_page: 1 })
      if (method === 'get' && url === '/enrollment/applications/11') {
        if (failRefresh) { failRefresh = false; throw new Error('Network unavailable') }
        return response({ application: application(), requirements: requirements() })
      }
      if (method === 'get' && url.includes('?preview=1')) return { data: new Blob(['image'], { type: 'image/png' }) }
      if (method === 'post' && url === '/enrollment/applications/11/documents') {
        if (failUpload) { const status = failUpload; failUpload = null; throw { response: { status, data: status === 422 ? { errors: { file: ['The file must be a PDF, JPEG, or PNG.'] } } : {} } } }
        assert.equal(Number(payload.get('version')), version)
        const id = Number(payload.get('document_type_id'))
        attachments.set(id, `uploaded-${id}-${version}.png`)
        version++
        return response({ id: 100 + id, document_type_id: id })
      }
      if (method === 'post' && url === '/enrollment/applications/11/save') {
        assert.equal(payload.version, version)
        Object.assign(saved, payload.personal_details)
        version++
        return response(application())
      }
      throw new Error(`Unexpected ${method} ${url}`)
    }
    const renderer = createRenderer({
      createElement: (tag) => ({ tag, children: [], props: {}, listeners: {}, value: '', addEventListener(name, callback) { this.listeners[name] = callback }, removeEventListener() {}, setAttribute() {}, removeAttribute() {}, getRootNode() { return globalThis.document }, get options() { return this.children }, getClientRects() { return [1] }, focus() {} }),
      createText: (text) => ({ text }), createComment: () => ({ text: '' }),
      setText: (node, text) => { node.text = text }, setElementText: (node, text) => { node.text = text; node.children = [] },
      patchProp: (node, key, previous, next) => { node.props[key] = next },
      insert(node, parent, anchor) { if (node.parent) node.parent.children.splice(node.parent.children.indexOf(node), 1); const index = anchor ? parent.children.indexOf(anchor) : -1; parent.children.splice(index < 0 ? parent.children.length : index, 0, node); node.parent = parent },
      remove(node) { node.parent?.children.splice(node.parent.children.indexOf(node), 1) }, parentNode: (node) => node.parent, nextSibling: (node) => node.parent?.children[node.parent.children.indexOf(node) + 1] || null,
    })
    const root = { children: [] }
    const app = renderer.createApp(EnrollmentStudent)
    app.mount(root)
    const all = (node, tag) => [...(node.tag === tag ? [node] : []), ...(node.children || []).flatMap((child) => all(child, tag))]
    const textOf = (node) => [node.text || '', ...(node.children || []).map(textOf)].join(' ')
    const button = (name) => all(root, 'button').find((node) => textOf(node).trim() === name || node.props['aria-label'] === name)
    const field = (name, tag = 'input') => all(root, 'label').map((label) => ({ label, input: all(label, tag)[0] })).find(({ label, input }) => input && textOf(label).includes(name))?.input
    const edit = async (name, value, tag) => { const input = field(name, tag); assert.ok(input, `Missing ${name}`); input.props['onUpdate:modelValue'](value); await wait() }
    const upload = async (id) => {
      const input = all(root, 'input').find((node) => node.props.type === 'file' && node.props['aria-label']?.endsWith(requirements()[id - 1].name))
      assert.ok(input, `Missing upload ${id}`)
      const file = new Blob(['image'], { type: 'image/png' })
      file.name = `document-${id}.png`
      await input.props.onChange({ target: { files: [file], value: 'selected' } })
      await wait()
    }
    const assertDirty = () => {
      assert.equal(field('First name').value, 'Edited')
      assert.equal(field('Full address', 'textarea').value, 'New address')
      assert.equal(field('Email').value, 'edited@example.com')
      assert.equal(saved.first_name, 'Saved')
    }
    try {
      await wait()
      assert.ok(button('View current application'))
      await button('View current application').props.onClick()
      await wait()
      await edit('First name', 'Edited')
      await edit('Full address', 'New address', 'textarea')
      await edit('Email', 'edited@example.com')
      assertDirty()
      for (const id of [1, 2, 3]) { await upload(id); assertDirty(); assert.equal(attachments.size, id) }
      assert.match(textOf(root), /Document uploaded successfully/)
      await upload(1)
      assertDirty()
      assert.equal(attachments.size, 3)
      assert.ok(button('View full Form 137 image'))
      await button('View full Form 137 image').props.onClick({ stopPropagation() {} })
      await wait()
      assertDirty()
      failUpload = 422
      await upload(2)
      assertDirty()
      assert.match(textOf(root), /The file must be a PDF, JPEG, or PNG/)
      assert.doesNotMatch(textOf(root), /Document uploaded successfully/)
      failUpload = 503
      await upload(2)
      assertDirty()
      assert.match(textOf(root), /Enrollment is temporarily unavailable/)
      assert.equal(attachments.size, 3)
      failRefresh = true
      await upload(3)
      assertDirty()
      assert.match(textOf(root), /Retry upload status/)
      await button('Retry upload status').props.onClick()
      await wait()
      assertDirty()
      assert.doesNotMatch(textOf(root), /Status refresh needed/)
      await button('Save draft').props.onClick()
      await wait()
      assert.equal(saved.first_name, 'Edited')
      assert.equal(saved.address, 'New address')
      assert.equal(saved.email, 'edited@example.com')
      await button('All applications').props.onClick()
      await wait()
      await button('View current application').props.onClick()
      await wait()
      assert.equal(field('First name').value, 'Edited')
      assert.equal(attachments.size, 3)
    } finally { app.unmount() }
  } finally {
    globalThis.window = priorWindow
    globalThis.document = priorDocument
    globalThis.Document = priorDocumentClass
    globalThis.URL.createObjectURL = priorCreateUrl
    globalThis.URL.revokeObjectURL = priorRevokeUrl
    await rm(temporary, { recursive: true })
  }
})
