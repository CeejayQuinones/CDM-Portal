import assert from 'node:assert/strict'
import { mkdtemp, readFile, rm } from 'node:fs/promises'
import path from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'
import test from 'node:test'
import { build } from 'esbuild'
import { compileScript, parse } from '@vue/compiler-sfc'
import { createRenderer, createSSRApp, h, nextTick } from 'vue'
import 'vue-router'
import { renderToString } from '@vue/server-renderer'
import { createPinia, setActivePinia } from 'pinia'
import { ROLES } from '../src/config/accessControl.js'

const frontend = fileURLToPath(new URL('../', import.meta.url))
const groups = {
  [ROLES.GUEST]: ['/admission'],
  [ROLES.STUDENT]: ['/admission', '/admission/exam', '/admission/result', '/admission/recommendation'],
  [ROLES.REGISTRAR_STAFF]: ['/registrar/admissions', '/registrar/admissions/results', '/registrar/admissions/review', '/registrar/admissions/history'],
  [ROLES.ADMIN]: ['/admin/admissions/questions', '/admin/admissions/programs'],
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
              export const apiClient = { get(path) { apiState.calls.push(path); return apiState.handler(); } };`,
          }))
          builder.onLoad({ filter: /\.vue$/ }, async ({ path: filename }) => {
            if (!filename.includes('/modules/admission/')) return { contents: 'export default { render() { return null } }' }
            const { descriptor } = parse(await readFile(filename, 'utf8'), { filename })
            return { contents: compileScript(descriptor, { id: filename, inlineTemplate: true }).content, resolveDir: path.dirname(filename) }
          })
        },
      }],
    })
    const { router, auth, useNavigationStore, AdmissionView, apiState } = await import(pathToFileURL(path.join(temporary, 'harness.mjs')))
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
            const html = await renderToString(createSSRApp({ render: () => h(record.components.default, record.props.default) }))
            assert.ok(html.includes(router.currentRoute.value.meta.title))
            if (target === '/admission' && [ROLES.GUEST, ROLES.STUDENT].includes(role)) {
              assert.match(html, /Loading admission information/)
            } else {
              assert.match(html, /Coming soon/)
              assert.match(html, /not available yet/)
            }
            assert.doesNotMatch(html, /<button|<form|<input/)
          }
        }
      })
    }
    assert.deepEqual(apiState.calls, [], 'SSR and placeholder routes do not fetch or create applications')
    const renderer = createRenderer({
      createElement: (tag) => ({ tag, children: [], props: {} }),
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
      app.mount(root)
      return { root, app }
    }
    const response = (application) => ({ data: { success: true, data: { has_application: Boolean(application), application } } })
    const record = { applicant_number: 'APP-test-identity', status: 'under_review', cycle: { code: '2026', name: 'September intake' }, created_at: '2026-09-19T08:30:00+08:00', submitted_at: '2026-09-19T09:00:00+08:00', is_converted: false, converted_at: null }

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
        assert.deepEqual(apiState.calls, ['/admission/me', '/admission/me'])
      } finally { app.unmount() }
    })
    await t.test('mounted future pages and staff legacy home do not call the identity API', async () => {
      apiState.calls.length = 0
      for (const target of ['/admission/exam', '/admission/result', '/admission/recommendation']) {
        auth.currentRole = ROLES.STUDENT
        await router.push(target)
        const route = router.currentRoute.value.matched.at(-1)
        const root = { children: [] }
        const app = renderer.createApp(route.components.default, route.props.default)
        app.mount(root)
        await settle()
        assert.match(textOf(root), /Coming soon/)
        app.unmount()
      }
      auth.currentRole = ROLES.REGISTRAR_STAFF
      const root = { children: [] }
      const app = renderer.createApp(AdmissionView)
      app.mount(root)
      await settle()
      assert.match(textOf(root), /Coming soon/)
      app.unmount()
      assert.deepEqual(apiState.calls, [])
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
