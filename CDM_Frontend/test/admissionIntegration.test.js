import assert from 'node:assert/strict'
import { mkdtemp, readFile, rm } from 'node:fs/promises'
import path from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'
import test from 'node:test'
import { build } from 'esbuild'
import { compileScript, parse } from '@vue/compiler-sfc'
import { createRenderer, createSSRApp, h, nextTick, ref } from 'vue'
import 'vue-router'
import { renderToString } from '@vue/server-renderer'
import { createPinia, setActivePinia } from 'pinia'
import { ROLES } from '../src/config/accessControl.js'

const frontend = fileURLToPath(new URL('../', import.meta.url))
const groups = {
  [ROLES.GUEST]: ['/admission', '/admission/exam', '/admission/result', '/admission/recommendation'],
  [ROLES.STUDENT]: ['/admission', '/admission/exam', '/admission/result', '/admission/recommendation'],
  [ROLES.REGISTRAR_STAFF]: ['/registrar/admissions', '/registrar/admissions/results', '/registrar/admissions/history'],
  [ROLES.ADMIN]: ['/admin/admissions/programs', '/admin/admissions/exams', '/admin/admissions/questions'],
}

test('Admission uses the portal guard, navigation store, and rendered placeholders', async (t) => {
  const temporary = await mkdtemp(path.join(frontend, 'test/.admission-'))
  const previousDocument = globalThis.document
  const previousAnimationFrame = globalThis.requestAnimationFrame
  globalThis.document = { title: '' }
  globalThis.requestAnimationFrame = (callback) => callback()
  try {
    await build({
      stdin: {
        contents: `export { default as router } from './src/router/index.js';
          export { useNavigationStore } from './src/stores/navigation.js';
          export { auth } from './src/stores/authStore';
          export { default as AdmissionDialog } from './src/modules/admission/components/AdmissionDialog.vue';
          export { default as AdmissionConversionPanel } from './src/modules/admission/components/AdmissionConversionPanel.vue';
          export { default as AdmissionView } from './src/modules/admission/AdmissionView.vue';
          export { apiState } from './src/services/apiClient';`,
        resolveDir: frontend,
      },
      outfile: path.join(temporary, 'harness.mjs'),
      bundle: true, platform: 'node', format: 'esm', packages: 'external',
      plugins: [{
        name: 'admission-test-environment',
        setup(builder) {
          builder.onResolve({ filter: /^vue-router$/, namespace: 'file' }, () => ({ path: 'router', namespace: 'memory-router' }))
          builder.onLoad({ filter: /.*/, namespace: 'memory-router' }, () => ({
            contents: `export * from 'vue-router'; import { createMemoryHistory } from 'vue-router'; export const createWebHashHistory = () => createMemoryHistory('/');`,
            resolveDir: frontend,
          }))
          builder.onResolve({ filter: /stores\/authStore$/ }, () => ({ path: 'auth', namespace: 'test-auth' }))
          builder.onLoad({ filter: /.*/, namespace: 'test-auth' }, () => ({
            contents: `import { reactive } from 'vue'; export const auth = reactive({ currentRole: 'Student', currentUser: { id: 1 }, isAuthenticated: true, async initialize() {} });
              export const useAuthStore = () => auth;`, resolveDir: frontend,
          }))
          builder.onResolve({ filter: /services\/performance\/performanceMonitor$/ }, () => ({ path: 'performance', namespace: 'test-performance' }))
          builder.onLoad({ filter: /.*/, namespace: 'test-performance' }, () => ({
            contents: 'export const performanceMonitor = { beginRoute() {}, markRouteRendered() {} };',
          }))
          builder.onResolve({ filter: /services\/apiClient$/ }, () => ({ path: 'api', namespace: 'test-api' }))
          builder.onLoad({ filter: /.*/, namespace: 'test-api' }, () => ({
            contents: `export const apiState = { calls: [], handler: async () => ({ data: { success: true, data: { has_application: false, application: null } } }) };
              export const apiClient = {
                get(path) { apiState.calls.push(path); return path.endsWith('/availability') ? Promise.resolve({ data: { success: true, data: apiState.availability || { allowed: false, reason: 'no_open_cycle' } } }) : apiState.handler(); },
                post(path) { apiState.calls.push(path); return apiState.postHandler(); },
                async request(config) { apiState.calls.push(config.url); const data = await apiState.workflow(config); return { data: { success: true, data } }; }
              };`,
          }))
          builder.onLoad({ filter: /\.vue$/ }, async ({ path: filename }) => {
            if (!filename.includes('/modules/admission/')) return { contents: 'export default { render() { return null } }' }
            const { descriptor } = parse(await readFile(filename, 'utf8'), { filename })
            return { contents: compileScript(descriptor, { id: filename, inlineTemplate: true }).content, resolveDir: path.dirname(filename) }
          })
        },
      }],
    })
    const { router, auth, useNavigationStore, AdmissionView, AdmissionConversionPanel, AdmissionDialog, apiState } = await import(pathToFileURL(path.join(temporary, 'harness.mjs')))
    setActivePinia(createPinia())
    const navigation = useNavigationStore()
    for (const role of Object.values(ROLES)) {
      await t.test(`${role}: route authorization and sidebar visibility`, async () => {
        auth.currentRole = role
        auth.isAuthenticated = true
        const visible = navigation.menuItemsForRole(role).find((item) => item.name === 'admission-menu')
        assert.deepEqual(visible?.children.map((item) => item.path) || [], groups[role] || [])
        for (const target of Object.values(groups).flat()) {
          await router.push(target)
          const legacyAccess = target === '/admission' && [ROLES.REGISTRAR_STAFF, ROLES.ADMIN].includes(role)
          const allowed = groups[role]?.includes(target) || legacyAccess
          assert.equal(router.currentRoute.value.name === 'unauthorized', !allowed, `${role}: ${target}`)
          if (allowed) {
            assert.equal(router.currentRoute.value.path, target)
            const record = router.currentRoute.value.matched.at(-1)
            const html = await renderToString(createSSRApp({ render: () => h(record.components.default, record.props.default) }).use(router))
            assert.ok(html.includes(router.currentRoute.value.meta.title))
            if (target === '/admission' && [ROLES.GUEST, ROLES.STUDENT].includes(role)) {
              assert.match(html, /Loading admission information/)
            } else {
              if (target === '/admission') { assert.match(html, /Coming soon/); assert.match(html, /not available yet/) }
            }
            if (target === '/admission') assert.doesNotMatch(html, /<button|<form|<input/)
          }
        }
      })
    }
    assert.deepEqual(apiState.calls, [], 'SSR and placeholder routes do not fetch or create applications')
    const renderer = createRenderer({
      createElement: (tag) => ({ tag, tagName: tag.toUpperCase(), children: [], props: {}, listeners: {},
        addEventListener(name, handler) { this.listeners[name] = handler }, removeEventListener() {},
        get options() { return this.children }, setAttribute() {}, removeAttribute() {} }),
      createText: (text) => ({ text }), createComment: () => ({ text: '' }),
      setText: (node, text) => { node.text = text },
      setElementText: (node, text) => { node.text = text; node.children = [] },
      patchProp: (node, key, previous, next) => { node.props[key] = next },
      insert(node, parent, anchor) {
        if (node.parent) node.parent.children.splice(node.parent.children.indexOf(node), 1)
        const index = anchor ? parent.children.indexOf(anchor) : -1
        parent.children.splice(index < 0 ? parent.children.length : index, 0, node)
        node.parent = parent
      },
      remove(node) { node.parent?.children.splice(node.parent.children.indexOf(node), 1) },
      parentNode: (node) => node.parent,
      nextSibling: (node) => node.parent?.children[node.parent.children.indexOf(node) + 1] || null,
    })
    const textOf = (node) => [node.text || '', ...(node.children || []).map(textOf)].join(' ')
    const settle = async () => { await new Promise(resolve => setTimeout(resolve, 0)); await nextTick() }
    const mount = () => {
      auth.currentRole = ROLES.GUEST
      auth.currentUser = { id: 1 }
      const root = { children: [] }
      const app = renderer.createApp(AdmissionView)
      app.use(router); app.mount(root)
      return { root, app }
    }
    const response = (application) => ({ data: { success: true, data: { has_application: Boolean(application), application } } })
    const record = { applicant_number: 'APP-test-identity', status: 'under_review', cycle: { code: '2026', name: 'September intake' }, created_at: '2026-09-19T08:30:00+08:00', submitted_at: '2026-09-19T09:00:00+08:00', is_converted: false, converted_at: null }

    await t.test('home offers only the next permitted workflow action', async () => {
      for (const [exam, result, expected] of [
        [{ eligible: true }, { published: false }, 'Take Entrance Exam'],
        [{ session: { exam_completed: false } }, { published: false }, 'Resume Exam'],
        [{ reason: 'awaiting_publication' }, { published: false }, 'Waiting for Registrar Review'],
        [{ eligible: true }, { published: true, result: { retake_eligible: true } }, 'Take Retake'],
        [{ eligible: false }, { published: true, result: { retake_eligible: false } }, 'View Recommendation'],
        [{ reason: 'bank_incomplete' }, { published: false }, 'not available yet'],
      ]) {
        apiState.handler = async () => response(record)
        apiState.workflow = async ({url}) => url.endsWith('/exam') ? exam : result
        const {root, app} = mount()
        try {
          await settle()
          const text = textOf(root)
          assert.match(text, new RegExp(expected))
          for (const label of ['Take Entrance Exam','Resume Exam','Take Retake','View Recommendation']) {
            if (label !== expected) assert.ok(!text.includes(label), label + ' must not be offered')
          }
        } finally { app.unmount() }
      }
    })

    await t.test('page calls the real Admission service and renders loading then safe identity', async () => {
      let resolve
      apiState.calls.length = 0
      apiState.handler = () => new Promise(done => { resolve = done })
      const { root, app } = mount()
      try {
        assert.match(textOf(root), /Loading admission information/)
        assert.deepEqual(apiState.calls, ['/admission/me'])
        resolve(response(record))
        await settle()
        assert.match(textOf(root), /APP-test-identity/)
        assert.match(textOf(root), /September intake/)
        assert.match(textOf(root), /Under review/)
        assert.match(textOf(root), /Sep/)
        assert.doesNotMatch(textOf(root), /2026-09-19T|Start Exam|Coming soon/)
      } finally { app.unmount() }
    })
    await t.test('empty state does not offer applicant creation or exams', async () => {
      apiState.handler = async () => response(null)
      const { root, app } = mount()
      try {
        await settle()
        assert.match(textOf(root), /No admission application yet/)
        assert.doesNotMatch(textOf(root), /Start Exam|Create application|Unable to load/)
      } finally { app.unmount() }
    })
    await t.test('errors and malformed responses show a safe error, never a false empty state', async () => {
      for (const handler of [async () => { throw new Error('SQLSTATE secret database error') }, async () => ({ data: {} })]) {
        apiState.handler = handler
        const { root, app } = mount()
        try {
          await settle()
          assert.match(textOf(root), /Unable to load admission information/)
          assert.doesNotMatch(textOf(root), /SQLSTATE|secret|No admission application yet/)
        } finally { app.unmount() }
      }
    })
    await t.test('retry recovers from an error using only another identity GET', async () => {
      apiState.handler = async () => { throw new Error('Unavailable') }
      apiState.calls.length = 0
      const { root, app } = mount()
      const findButton = (node) => node.tag === 'button' ? node : (node.children || []).map(findButton).find(Boolean)
      try {
        await settle()
        apiState.handler = async () => response(null)
        findButton(root).props.onClick()
        await settle()
        assert.match(textOf(root), /No admission application yet/)
        assert.deepEqual(apiState.calls, ['/admission/me', '/admission/me', '/admission/applications/availability'])
      } finally { app.unmount() }
    })

    const findButtonByText = (node, label) => node.tag === 'button' && textOf(node).includes(label) ? node : (node.children || []).map(child => findButtonByText(child, label)).find(Boolean)
    await t.test('eligible Guest confirms once, prevents duplicate submits and immediately sees identity', async () => {
      apiState.availability = { allowed: true, reason: null }
      apiState.handler = async () => response(null)
      apiState.calls.length = 0
      let resolve
      apiState.postHandler = () => new Promise(done => { resolve = done })
      const { root, app } = mount()
      try {
        await settle()
        findButtonByText(root, 'Start Admission').props.onClick()
        await nextTick()
        assert.match(textOf(root), /applicant number will be generated/)
        assert.equal(apiState.calls.includes('/admission/applications'), false)
        const confirm = findButtonByText(root, 'Confirm application')
        confirm.props.onClick()
        confirm.props.onClick()
        await nextTick()
        assert.equal(confirm.props.disabled, true)
        assert.equal(apiState.calls.filter(path => path === '/admission/applications').length, 1)
        resolve(response(record))
        await settle()
        assert.match(textOf(root), /APP-test-identity/)
        assert.equal(findButtonByText(root, 'Start Admission'), undefined)
      } finally { app.unmount(); apiState.availability = null }
    })

    await t.test('cancel does not create and late creation cannot leak across accounts', async () => {
      apiState.availability = { allowed: true }
      apiState.handler = async () => response(null)
      apiState.calls.length = 0
      let resolve
      apiState.postHandler = () => new Promise(done => { resolve = done })
      const { root, app } = mount()
      try {
        await settle()
        findButtonByText(root, 'Start Admission').props.onClick()
        await nextTick()
        findButtonByText(root, 'Cancel').props.onClick()
        await nextTick()
        assert.equal(apiState.calls.includes('/admission/applications'), false)
        findButtonByText(root, 'Start Admission').props.onClick()
        await nextTick()
        findButtonByText(root, 'Confirm application').props.onClick()
        auth.currentUser = { id: 99 }
        await settle()
        resolve(response(record))
        await settle()
        assert.doesNotMatch(textOf(root), /APP-test-identity/)
      } finally { app.unmount(); apiState.availability = null }
    })
    await t.test('creation errors are safe and duplicate refreshes the existing application', async () => {
      for (const [status, code, message] of [[409, 'application_exists', /already exists/], [422, 'no_open_cycle', /No admission cycle/], [403, '', /permission/], [429, '', /Too many/], [503, '', /Unable to create/]]) {
        apiState.availability = { allowed: true }
        apiState.handler = async () => response(null)
        apiState.postHandler = async () => {
          if (status === 409) apiState.handler = async () => response(record)
          throw { response: { status, data: { code, message: 'SQLSTATE secret' } } }
        }
        const { root, app } = mount()
        try {
          await settle()
          findButtonByText(root, 'Start Admission').props.onClick()
          await nextTick()
          findButtonByText(root, 'Confirm application').props.onClick()
          await settle()
          assert.match(textOf(root), message)
          assert.doesNotMatch(textOf(root), /SQLSTATE|secret/)
          if (status === 409) assert.match(textOf(root), /APP-test-identity/)
        } finally { app.unmount(); apiState.availability = null }
      }
    })
    await t.test('Student never sees creation and no-cycle Guest sees explanation', async () => {
      apiState.handler = async () => response(null)
      const { root, app } = mount()
      try {
        await settle()
        assert.match(textOf(root), /No admission cycle/)
        auth.currentRole = ROLES.STUDENT
        await settle()
        assert.equal(findButtonByText(root, 'Start Admission'), undefined)
      } finally { app.unmount() }
    })

    await t.test('all Admission workflow pages load with empty states through the portal API', async () => {
      apiState.workflow = async ({ url }) => {
        if (url.endsWith('/exam')) return { eligible: true, reason: null, attempts_submitted: 0, session: null, question_count: 100, time_limit: 120 }
        if (url.endsWith('/result')) return { published: false, result: null }
        if (url.endsWith('/recommendation')) return { recommendation: { status: 'insufficient_evidence', ranked_programs: [], message: 'No evidence yet' }, interests: {} }
        if (url.endsWith('/exams')) return { exams: [] }
        if (url.endsWith('/cycles')) return { cycles: [], academic_years: [{ id: 1, school_year: '2026-2027' }] }
        if (url.endsWith('/questions')) return { questions: { data: [], last_page: 1 }, counts: {} }
        if (url.endsWith('/programs')) return { courses: [], settings: [] }
        if (url.endsWith('/history')) return { decisions: { data: [], last_page: 1 }, events: [] }
        return { data: [], last_page: 1 }
      }
      const previousWindow = globalThis.window
      globalThis.window = { addEventListener() {}, removeEventListener() {} }
      try {
        for (const [role, paths] of Object.entries(groups)) for (const target of paths.filter(p => p !== '/admission')) {
          auth.currentRole = role
          await router.push(target)
          const route = router.currentRoute.value.matched.at(-1)
          const root = { children: [] }
          const app = renderer.createApp(route.components.default, route.props.default)
          app.use(router); app.mount(root)
          await settle()
          assert.doesNotMatch(textOf(root), /Coming soon|temporarily unavailable/)
          assert.ok(apiState.calls.some(path => path.startsWith('/admission/')))
          if (target === '/admission/exam') assert.match(textOf(root), /120 minutes/)
          app.unmount()
        }
      } finally { globalThis.window = previousWindow }
    })
    await t.test('switching Admin pages clears the previous payload before rendering form controls', async () => {
      auth.currentRole = ROLES.ADMIN
      apiState.workflow = async ({ url }) => url.endsWith('/cycles') ? { cycles: [], academic_years: [] } : { questions: { data: [], last_page: 1 }, counts: {} }
      await router.push('/admin/admissions/questions')
      const component = router.currentRoute.value.matched.at(-1).components.default
      const mode = ref('cycles'), errors = []
      const root = { children: [] }
      const app = renderer.createApp({ render: () => h(component, { mode: mode.value, title: 'Admin Admission' }) })
      app.config.errorHandler = error => errors.push(error.message)
      app.use(router); app.mount(root)
      try {
        await settle()
        mode.value = 'questions'
        await settle()
        assert.deepEqual(errors, [])
        assert.match(textOf(root), /General exam readiness/)
      } finally { app.unmount() }
    })
    await t.test('legacy staff URLs redirect to the single role-owned workflow', async () => {
      for (const [role, oldPath, newPath] of [
        [ROLES.ADMIN, '/admin/admissions/cycles', '/admin/admissions/exams'],
        [ROLES.REGISTRAR_STAFF, '/registrar/admissions/review', '/registrar/admissions/results'],
      ]) {
        auth.currentRole = role; await router.push(oldPath)
        assert.equal(router.currentRoute.value.path, newPath)
        auth.currentRole = ROLES.GUEST; await router.push('/admission'); await router.push(oldPath)
        assert.equal(router.currentRoute.value.name, 'unauthorized')
      }
    })
    await t.test('merged Results tabs and server-permitted actions drive confirmation', async () => {
      auth.currentRole = ROLES.REGISTRAR_STAFF
      const calls = []
      let row = { id: 1, version: 1, name: 'Example Applicant', applicant_number: 'APP-1', cycle: 'Intake', attempt_number: 1,
        official_status: 'pending', system_percentage: 40, system_passed: false, outcome: 'PENDING', category_scores: { Science: 8 }, category_maximums: { Science: 20 }, allowed_actions: ['approve'] }
      apiState.workflow = async config => {
        calls.push(config)
        if (config.url.endsWith('/cycles')) return [{id: 1, name: 'Intake'}]
        if (config.method === 'post') { row = {...row, version: 2, official_status: 'approved', allowed_actions: ['approve','publish']}; return {updated:1} }
        return {data:[row], last_page:1}
      }
      await router.push('/registrar/admissions/results')
      const route = router.currentRoute.value.matched.at(-1), root = {children:[]}
      const app = renderer.createApp(route.components.default,route.props.default); app.use(router); app.mount(root)
      const findForm = node => node.tag === 'form' ? node : (node.children || []).map(findForm).find(Boolean)
      const exactButton = (node, label) => node.tag === 'button' && textOf(node).trim() === label ? node : (node.children || []).map(child => exactButton(child, label)).find(Boolean)
      try {
        await settle()
        for (const label of ['All Results','Pending Review','Ready to Publish','Published','Retake']) {
          findButtonByText(root,label).props.onClick(); await settle()
        }
        assert.equal(calls.filter(c=>c.method === 'get' && c.url.endsWith('/results')).at(-1).params.tab,'retake')
        assert.equal(findButtonByText(root,'Exceptional Pass'),undefined)
        assert.equal(findButtonByText(root,'Correct result'),undefined)
        findButtonByText(root,'Inspect').props.onClick(); await nextTick()
        assert.match(textOf(root),/Science: 8 \/ 20/)
        findButtonByText(root,'Close').props.onClick(); await nextTick()
        exactButton(root,'Approve').props.onClick(); await nextTick()
        assert.equal(calls.filter(c=>c.method === 'post').length,0)
        findForm(root).props.onSubmit({preventDefault(){}}); await settle()
        assert.equal(calls.find(c=>c.method === 'post').url,'/admission/registrar/results/approve')
        assert.deepEqual(calls.find(c=>c.method === 'post').data.results,[{id:1,version:1}])
        assert.ok(findButtonByText(root,'Publish'))
      } finally { app.unmount() }
    })
    await t.test('Programs edits academic fields separately and Exams saves cycle policy', async () => {
      auth.currentRole = ROLES.ADMIN
      const topics = ['General Mathematics','Science','Reading Comprehension','Logical Reasoning','Digital Literacy']
      const writes = []
      apiState.workflow = async config => {
        if (config.method !== 'get') { writes.push(config); return {} }
        if (config.url.endsWith('/programs')) return {courses:[{id:1,course_code:'BSIT',course_name:'Information Technology',years:4,status:'active',department_id:1,updated_at:'stamp'}],settings:[],departments:[{id:1,department_name:'Computing'}]}
        if (config.url.endsWith('/exams')) return {exams:[{cycle_id:1,cycle:'Intake',version:1,policy:{title:'General Entrance Exam',status:'active',duration_minutes:120,passing_score:75,max_attempts:2,category_counts:Object.fromEntries(topics.map(t=>[t,20]))},readiness:{ready:true,counts:Object.fromEntries(topics.map(t=>[t,20]))}}]}
        return {cycles:[],academic_years:[]}
      }
      const findForm = node => node.tag === 'form' ? node : (node.children || []).map(findForm).find(Boolean)
      for (const target of ['/admin/admissions/programs','/admin/admissions/exams']) {
        await router.push(target)
        const route = router.currentRoute.value.matched.at(-1), root = {children:[]}
        const app = renderer.createApp(route.components.default,route.props.default); app.use(router); app.mount(root)
        try {
          await settle()
          if (target.endsWith('/programs')) {
            assert.ok(findButtonByText(root,'Add program'))
            findButtonByText(root,'Edit program').props.onClick(); await nextTick()
          } else { assert.match(textOf(root),/100 questions/); assert.match(textOf(root),/READY/) }
          findForm(root).props.onSubmit({preventDefault(){}}); await settle()
        } finally { app.unmount() }
      }
      assert.equal(writes[0].url,'/admission/admin/programs/1/academic')
      assert.equal(writes[0].data.expected_updated_at,'stamp')
      assert.equal(writes[1].url,'/admission/admin/exams/1')
      assert.equal(writes[1].data.version,1)
    })
    await t.test('History renders readable timeline and Applicants loads inspection before conversion', async () => {
      auth.currentRole = ROLES.REGISTRAR_STAFF
      const applicant = {id:1,name:'Example Applicant',applicant_number:'APP-1',cycle:'Intake',status:'draft',exam_status:'finalized',latest_result:'RETAKE',acceptance_status:'not_accepted',conversion_status:'not_converted',attempts:[{attempt_number:1,status:'finalized',outcome:'RETAKE'}]}
      apiState.workflow = async config => {
        if (config.url.endsWith('/history')) return {timeline:{data:[{id:'event-1',action:'Application created',actor:'Example Applicant',subject:'Example Applicant',applicant_number:'APP-1',cycle:'Intake',summary:'Application created recorded for APP-1.',created_at:'2026-09-26'}],last_page:1}}
        if (config.url.endsWith('/cycles')) return []
        if (config.url.endsWith('/conversion')) return {can_accept:false,can_convert:false,reason:'Latest result must be passed.',courses:[],curriculums:[]}
        if (config.url.endsWith('/applicants/1')) return applicant
        return {data:[applicant],last_page:1}
      }
      for (const target of ['/registrar/admissions/history','/registrar/admissions']) {
        await router.push(target)
        const route = router.currentRoute.value.matched.at(-1), root = {children:[]}
        const app = renderer.createApp(route.components.default,route.props.default); app.use(router); app.mount(root)
        try {
          await settle()
          if (target.endsWith('/history')) { assert.match(textOf(root),/Application created/); assert.match(textOf(root),/By Example Applicant/) }
          else {
            findButtonByText(root,'Inspect applicant').props.onClick(); await settle()
            assert.match(textOf(root),/Exam attempts/); assert.match(textOf(root),/RETAKE/)
            assert.equal(findButtonByText(root,'Convert to Student'),undefined)
          }
        } finally { app.unmount() }
      }
    })

    await t.test('exam starts and confirms submission using the server session', async () => {
      auth.currentRole = ROLES.GUEST
      const previousWindow = globalThis.window
      globalThis.window = { addEventListener() {}, removeEventListener() {} }
      const questions = Array.from({length:100}, (_,i) => ({ id:i+1, topic:'Science', question_text:'Assigned question '+(i+1), options:{ A:'Answer A', B:'Answer B' } }))
      const saved = { session_id:'session', revision:0, position:0, attempt_number:1, deadline:new Date(Date.now()+7200000).toISOString(), server_now:new Date().toISOString(), questions, answers:Object.fromEntries(questions.map(q=>[q.id,null])), exam_completed:false }
      const writes=[]
      apiState.workflow=async config => {
        if(config.url.endsWith('/exam')) return {eligible:true,attempts_submitted:0,session:null}
        if(config.url.endsWith('/start')) return structuredClone(saved)
        writes.push(config)
        return { ...structuredClone(saved), revision:1, exam_completed:config.url.endsWith('/submit') }
      }
      await router.push('/admission/exam')
      const route=router.currentRoute.value.matched.at(-1)
      const root={children:[]}; const app=renderer.createApp(route.components.default,route.props.default); app.use(router); app.mount(root)
      try {
        await settle()
        findButtonByText(root,'Start Exam').props.onClick(); await settle()
        assert.match(textOf(root),/Assigned question 1/)
        assert.match(textOf(root),/Question 1 \/ 100/)
        assert.match(textOf(root),/Choose one answer/)
        assert.equal(findButtonByText(root,'Previous').props.disabled,true)
        assert.equal(findButtonByText(root,'Next').props.disabled,false)
        findButtonByText(root,'Submit exam').props.onClick(); await nextTick()
        assert.equal(writes.length,0)
        findButtonByText(root,'Confirm submission').props.onClick(); await settle()
        assert.equal(writes.length,1)
        assert.match(textOf(root),/Exam submitted/)
      } finally { app.unmount(); globalThis.window=previousWindow }
    })
    await t.test('Admission confirmations restore focus, lock background scroll and handle Escape safely', async () => {
      let restored = 0, cancelled = 0
      const previousBody = document.body, previousActive = document.activeElement
      document.body = {style:{overflow:'auto'}}
      document.activeElement = {isConnected:true, focus(){restored++}}
      const busy = ref(false), root = {children:[]}
      const app = renderer.createApp({render:()=>h(AdmissionDialog,{labelledby:'test-dialog',busy:busy.value,onCancel:()=>cancelled++},{default:()=>h('h2',{id:'test-dialog'},'Confirm action')})})
      app.mount(root)
      try {
        const findDialog = node => node.props?.role === 'dialog' ? node : (node.children || []).map(findDialog).find(Boolean)
        const dialog = findDialog(root)
        assert.equal(dialog.props['aria-modal'],'true')
        assert.equal(document.body.style.overflow,'hidden')
        const escape = {key:'Escape',preventDefault(){},stopPropagation(){}}
        dialog.props.onKeydown(escape)
        assert.equal(cancelled,1)
        busy.value = true; await nextTick()
        dialog.props.onKeydown(escape)
        assert.equal(cancelled,1,'saving must not be dismissed by Escape')
      } finally {
        app.unmount()
        assert.equal(restored,1)
        assert.equal(document.body.style.overflow,'auto')
        document.body = previousBody; document.activeElement = previousActive
      }
    })
    await t.test('Admission responsive and reduced-motion styling remains isolated to Admission', async () => {
      const css = await readFile(path.join(frontend,'src/modules/admission/admission.css'),'utf8')
      assert.match(css, /@media \(prefers-reduced-motion: reduce\)/)
      assert.match(css, /animation: none !important/)
      assert.match(css, /transition: none !important/)
      assert.match(css, /\.admission-workflow \.exam-controls/)
      assert.match(css, /\.admission-workflow \.table-wrap[^}]*overflow-x: auto/)
      assert.doesNotMatch(css, /(?:^|\n)(?:body|:root|\.sidebar|\.navbar)\s*\{/)
    })

    await t.test('conversion hides ineligible actions and requires final confirmation', async () => {
      const base = { id: 5, version: 2, applicant_number: 'APP-conversion', name: 'Applicant Example',
        result_id: 8, result_version: 3, course_id: 1, curriculum_id: 2,
        courses: [{id:1,course_name:'Information Technology'}], curriculums: [{id:2,course_id:1,curriculum_code:'IT-2026'}] }
      const find = (node, tag) => node.tag === tag ? node : (node.children || []).map(child => find(child, tag)).find(Boolean)
      for (const eligible of [false, true]) {
        let converted = false
        const writes = []
        apiState.workflow = async config => {
          if (config.method === 'post') { writes.push(config); converted = true; return {converted:true} }
          return { ...base, can_accept: eligible, can_convert: eligible, reason: eligible ? null : 'Latest result must be published.',
            student: converted ? {student_number:'26-01234',admission_date:'2026-09-25',year_level:1,student_status:'regular'} : null }
        }
        const root = {children:[]}, app = renderer.createApp(AdmissionConversionPanel,{applicantId:5})
        app.use(router); app.mount(root)
        try {
          await settle()
          if (!eligible) {
            assert.equal(findButtonByText(root,'Convert to Student'), undefined)
            assert.match(textOf(root), /Latest result must be published/)
          } else {
            assert.ok(findButtonByText(root,'Convert to Student'))
            find(root,'form').props.onSubmit({preventDefault(){}})
            await nextTick()
            assert.match(textOf(root), /Confirm final conversion/)
            assert.equal(writes.length,0)
            findButtonByText(root,'Cancel').props.onClick()
            await nextTick()
            assert.equal(writes.length,0)
            find(root,'form').props.onSubmit({preventDefault(){}})
            await nextTick()
            const button = findButtonByText(root,'Confirm conversion')
            button.props.onClick(); button.props.onClick()
            await settle()
            assert.equal(writes.length,1)
            assert.equal(writes[0].url,'/admission/registrar/applicants/5/convert')
            assert.equal(writes[0].data.confirmed,true)
            assert.equal(writes[0].data.result_version,3)
            assert.match(textOf(root), /Converted to Student/)
            assert.match(textOf(root), /26-01234/)
            assert.equal(findButtonByText(root,'Convert to Student'),undefined)
          }
        } finally { app.unmount() }
      }
    })

    await t.test('late response from a previous user cannot replace the current identity', async () => {
      const pending = []
      apiState.handler = () => new Promise(resolve => pending.push(resolve))
      const { root, app } = mount()
      try {
        auth.currentUser = { id: 2 }
        await nextTick()
        pending[1](response(null))
        await settle()
        pending[0](response(record))
        await settle()
        assert.match(textOf(root), /No admission application yet/)
        assert.doesNotMatch(textOf(root), /APP-test-identity/)
      } finally { app.unmount() }
    })
    await t.test('anonymous admission navigation redirects to sign-in', async () => {
      auth.isAuthenticated = false
      for (const target of Object.values(groups).flat()) {
        await router.push(target)
        assert.equal(router.currentRoute.value.name, 'login')
        assert.equal(router.currentRoute.value.query.redirect, target)
      }
    })
  } finally {
    globalThis.document = previousDocument
    globalThis.requestAnimationFrame = previousAnimationFrame
    await rm(temporary, { recursive: true, force: true })
  }
})
