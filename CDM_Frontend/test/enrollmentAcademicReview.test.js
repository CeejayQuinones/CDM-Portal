import assert from 'node:assert/strict'
import { mkdtemp, readFile, rm } from 'node:fs/promises'
import path from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'
import test from 'node:test'
import { build } from 'esbuild'
import { compileScript, parse } from '@vue/compiler-sfc'
import { createRenderer, nextTick } from 'vue'

const frontend = fileURLToPath(new URL('../', import.meta.url))

test('Registrar Academic Enrollment review finalizes without schedule fields and retains modal errors', async () => {
  const temporary = await mkdtemp(path.join(frontend, 'test/.academic-review-'))
  const previousDocument = globalThis.document
  const previousHTMLElement = globalThis.HTMLElement
  globalThis.HTMLElement = class {}
  globalThis.document = {
    activeElement: null,
    body: { style: { overflow: '' } },
  }

  try {
    await build({
      stdin: {
        contents: "export { default as EnrollmentLoad } from './src/modules/enrollment/EnrollmentLoad.vue'; export { apiState } from './src/services/apiClient';",
        resolveDir: frontend,
      },
      outfile: path.join(temporary, 'harness.mjs'),
      bundle: true,
      platform: 'node',
      format: 'esm',
      packages: 'external',
      plugins: [{
        name: 'academic-review-test',
        setup(builder) {
          builder.onLoad({ filter: /\.css$/ }, () => ({ contents: '', loader: 'js' }))
          builder.onResolve({ filter: /stores\/authStore$/ }, () => ({ path: 'auth', namespace: 'test' }))
          builder.onLoad({ filter: /.*/, namespace: 'test' }, ({ path: name }) => {
            if (name === 'auth') return { contents: "export const useAuthStore=()=>({currentRole:'Registrar Staff',currentUser:{id:7}})" }
            if (name === 'platform') return { contents: "export const clientPlatform='desktop'" }
            if (name === 'api') return { contents: "export const apiState={handler:async()=>({}),calls:[]};const call=(method,url,payload)=>{apiState.calls.push({method,url,payload});return apiState.handler(method,url,payload)};export const apiClient={get:(url,payload)=>call('get',url,payload),post:(url,payload)=>call('post',url,payload)}" }
            return { contents: 'export default { render(){ return null } }' }
          })
          builder.onResolve({ filter: /config\/clientPlatform$/ }, () => ({ path: 'platform', namespace: 'test' }))
          builder.onResolve({ filter: /services\/apiClient$/ }, () => ({ path: 'api', namespace: 'test' }))
          builder.onResolve({ filter: /components\/website\/(WebsiteDataTable|WebsiteEmptyState)\.vue$/ }, () => ({ path: 'stub', namespace: 'test' }))
          builder.onLoad({ filter: /\.vue$/ }, async ({ path: filename }) => {
            const { descriptor } = parse(await readFile(filename, 'utf8'), { filename })
            return { contents: compileScript(descriptor, { id: filename, inlineTemplate: true }).content, resolveDir: path.dirname(filename) }
          })
        },
      }],
    })

    const { EnrollmentLoad, apiState } = await import(pathToFileURL(path.join(temporary, 'harness.mjs')))
    const period = { academic_year: { school_year: '2026-2027' }, semester: { semester_name: 'First Semester' } }
    const section = { id: 4, section_name: 'BSIT-1A', enrolled_count: 2, reserved_count: 1, capacity: 40 }
    const application = {
      id: 12,
      status: 'approved',
      classification: 'regular',
      version: 8,
      year_level: 1,
      section_id: null,
      section: null,
      load_reviewed_at: null,
      load_reviewed_by: null,
      course: { course_code: 'BSIT', course_name: 'Bachelor of Science in Information Technology' },
      period,
      owner: { profile: { first_name: 'Kenneth', last_name: 'Gallaza' } },
      admission_applicant: { applicant_number: 'APP-1001' },
      student: null,
    }
    let invalidSubject = true
    let savedSubjectIds = []
    const payload = () => ({
      application,
      load: {
        candidates: [
          { id: 1, subject_code: 'IT101', subject_name: 'Introduction to Computing', units: 3, completed: false, prerequisite_met: true, schedule_status: 'pending' },
          { id: 2, subject_code: 'IT102', subject_name: 'Computer Programming 1', units: 3, completed: false, prerequisite_met: !invalidSubject, schedule_status: 'pending' },
        ],
        selected_subject_ids: savedSubjectIds,
        standard_subject_ids: [1, 2],
      },
      sections: { data: [section], current_page: 1, last_page: 1 },
    })
    let rejectFinalize = 'validation'
    let holdFinalize = false
    let releaseFinalize
    apiState.handler = async (method, url, request) => {
      if (method === 'get') return { data: { success: true, data: payload() } }
      if (url.endsWith('/assign')) {
        assert.deepEqual(request, { version: application.version, section_id: section.id })
        application.section_id = section.id
        application.section = section
        application.version++
        return { data: { success: true, data: application } }
      }
      if (url.endsWith('/subjects')) {
        assert.deepEqual(request, { version: application.version, subject_ids: [1, 2] })
        savedSubjectIds = [1, 2]
        application.load_reviewed_at = '2026-11-06T02:00:00Z'
        application.load_reviewed_by = 7
        application.version++
        return { data: { success: true, data: application } }
      }
      if (url.endsWith('/finalize')) {
        if (rejectFinalize === 'validation') throw { response: { status: 422, data: { errors: { section_id: ['The assigned section no longer matches the academic term.'] } } } }
        if (rejectFinalize === 'conflict') throw { response: { status: 409, data: { message: 'The section is full.' } } }
        if (holdFinalize) await new Promise(resolve => { releaseFinalize = resolve })
        application.status = 'enrolled'
        application.enrollment_id = 44
        application.version++
        return { data: { success: true, data: application } }
      }
      throw new Error(`Unexpected ${method} ${url}`)
    }

    const makeNode = tag => ({
      tag,
      tagName: String(tag).toUpperCase(),
      children: [],
      props: {},
      listeners: {},
      addEventListener(name, listener) { this.listeners[name] = listener },
      removeEventListener(name) { delete this.listeners[name] },
      get options() { return this.children },
      get value() { return this.props.value },
      set value(value) { this.props.value = value },
      focus() { globalThis.document.activeElement = this },
      getClientRects() { return [1] },
      querySelectorAll() { return [] },
    })
    const renderer = createRenderer({
      createElement: makeNode,
      createText: text => ({ text }),
      createComment: () => ({ text: '' }),
      setText: (node, text) => { node.text = text },
      setElementText: (node, text) => { node.text = text; node.children = [] },
      patchProp: (node, key, previous, next) => { node.props[key] = next },
      insert(node, parent, anchor) {
        if (node.parent) node.parent.children.splice(node.parent.children.indexOf(node), 1)
        const index = anchor ? parent.children.indexOf(anchor) : -1
        parent.children.splice(index < 0 ? parent.children.length : index, 0, node)
        node.parent = parent
        node.parentElement = parent
      },
      remove(node) { node.parent?.children.splice(node.parent.children.indexOf(node), 1) },
      parentNode: node => node.parent,
      nextSibling: node => node.parent?.children[node.parent.children.indexOf(node) + 1] || null,
      insertStaticContent(content, parent, anchor) {
        const node = { tag: 'static', text: content.replace(/<[^>]+>/g, ' '), children: [], parent, parentElement: parent }
        const index = anchor ? parent.children.indexOf(anchor) : -1
        parent.children.splice(index < 0 ? parent.children.length : index, 0, node)
        return [node, node]
      },
    })
    const all = (node, tag) => [...(node.tag === tag ? [node] : []), ...(node.children || []).flatMap(child => all(child, tag))]
    const textOf = node => [node.text || '', ...(node.children || []).map(textOf)].join(' ')
    const findButton = (root, text) => all(root, 'button').find(node => textOf(node).trim().includes(text))
    const settle = async () => { await new Promise(resolve => setTimeout(resolve, 0)); await nextTick() }
    const root = { children: [] }
    const app = renderer.createApp(EnrollmentLoad, { applicationId: 12 })
    app.component('RouterLink', { render() { return null } })
    app.mount(root)

    try {
      await settle()
      assert.match(textOf(root), /Schedule not required/)
      assert.match(textOf(root), /To be announced/)
      assert.match(textOf(root), /Schedule pending/)
      assert.match(textOf(root), /Kenneth Gallaza/)
      assert.equal(findButton(root, 'Finalize Academic Enrollment').props.disabled, true)
      assert.match(textOf(root), /IT102 has an unmet prerequisite/)
      assert.match(textOf(root), /Review the subject load using the button below/)
      assert.match(textOf(root), /Choose a section and click Assign section/)
      assert.doesNotMatch(textOf(root), /Enrollment can be finalized now/)
      assert.equal(findButton(root, 'Review subject load').props.disabled, true)
      assert.equal(apiState.calls.filter(call => call.url.endsWith('/finalize')).length, 0)

      invalidSubject = false
      const sectionSelect = all(root, 'select')[0]
      assert.ok(sectionSelect)
      sectionSelect.options.find(option => option.value === section.id).selected = true
      sectionSelect.listeners.change({ target: sectionSelect })
      await settle()
      assert.equal(findButton(root, 'Finalize Academic Enrollment').props.disabled, true)
      await findButton(root, 'Assign section').props.onClick()
      await settle()
      assert.equal(findButton(root, 'Finalize Academic Enrollment').props.disabled, true)
      assert.equal(findButton(root, 'Review subject load').props.disabled, false)
      await findButton(root, 'Review subject load').props.onClick()
      await settle()
      assert.equal(findButton(root, 'Finalize Academic Enrollment').props.disabled, false)
      assert.match(textOf(root), /Enrollment can be finalized now/)

      await findButton(root, 'Finalize Academic Enrollment').props.onClick()
      await settle()
      assert.match(textOf(root), /Confirm Academic Enrollment/)
      assert.match(textOf(root), /Schedules may remain pending/)
      assert.equal(apiState.calls.filter(call => call.url.endsWith('/finalize')).length, 0)

      await findButton(root, 'Confirm Final Enrollment').props.onClick()
      await settle()
      assert.match(textOf(root), /The assigned section no longer matches the academic term/)
      assert.match(textOf(root), /Confirm Academic Enrollment/)

      rejectFinalize = 'conflict'
      await findButton(root, 'Confirm Final Enrollment').props.onClick()
      await settle()
      assert.match(textOf(root), /The section is full/)
      assert.match(textOf(root), /Confirm Academic Enrollment/)
      const failedRequest = apiState.calls.find(call => call.url.endsWith('/finalize'))
      assert.deepEqual(failedRequest.payload, { version: 10, confirmed: true })

      rejectFinalize = false
      holdFinalize = true
      const firstSubmission = findButton(root, 'Confirm Final Enrollment').props.onClick()
      const duplicateSubmission = findButton(root, 'Confirm Final Enrollment').props.onClick()
      assert.equal(apiState.calls.filter(call => call.url.endsWith('/finalize')).length, 3)
      releaseFinalize()
      await Promise.all([firstSubmission, duplicateSubmission])
      await settle()
      assert.doesNotMatch(textOf(root), /Confirm Academic Enrollment/)
      assert.match(textOf(root), /Academic enrollment completed/)
      assert.equal(findButton(root, 'Finalize Academic Enrollment'), undefined)

      application.status = 'submitted'
      const blockedRoot = { children: [] }
      const blockedApp = renderer.createApp(EnrollmentLoad, { applicationId: 12 })
      blockedApp.component('RouterLink', { render() { return null } })
      blockedApp.mount(blockedRoot)
      try {
        await settle()
        const blockedButton = findButton(blockedRoot, 'Finalize Academic Enrollment')
        assert.equal(blockedButton.props.disabled, true)
        assert.match(textOf(blockedRoot), /The application must be approved before finalization/)
        assert.doesNotMatch(textOf(blockedRoot), /Enrollment can be finalized now/)
        const submittedCount = apiState.calls.filter(call => call.url.endsWith('/finalize')).length
        await blockedButton.props.onClick()
        assert.equal(apiState.calls.filter(call => call.url.endsWith('/finalize')).length, submittedCount)
      } finally { blockedApp.unmount() }
    } finally {
      app.unmount()
    }
  } finally {
    globalThis.document = previousDocument
    globalThis.HTMLElement = previousHTMLElement
    await rm(temporary, { recursive: true, force: true })
  }
})
