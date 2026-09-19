import assert from 'node:assert/strict'
import { mkdtemp, readFile, rm } from 'node:fs/promises'
import path from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'
import test from 'node:test'
import { build } from 'esbuild'
import { compileScript, parse } from '@vue/compiler-sfc'
import { createSSRApp, h } from 'vue'
import 'vue-router'
import { renderToString } from '@vue/server-renderer'
import { createPinia, setActivePinia } from 'pinia'
import { ROLES } from '../src/config/accessControl.js'

const frontend = fileURLToPath(new URL('../', import.meta.url))
const groups = {
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
          export { auth } from './src/stores/authStore';`,
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
            contents: `export const auth = { currentRole: 'Student', isAuthenticated: true, async initialize() {} };
              export const useAuthStore = () => auth;`,
          }))
          builder.onResolve({ filter: /services\/performance\/performanceMonitor$/ }, () => ({ path: 'performance', namespace: 'test-performance' }))
          builder.onLoad({ filter: /.*/, namespace: 'test-performance' }, () => ({
            contents: 'export const performanceMonitor = { beginRoute() {}, markRouteRendered() {} };',
          }))
          builder.onLoad({ filter: /\.vue$/ }, async ({ path: filename }) => {
            if (!filename.includes('/modules/admission/')) return { contents: 'export default { render() { return null } }' }
            const { descriptor } = parse(await readFile(filename, 'utf8'), { filename })
            return { contents: compileScript(descriptor, { id: filename, inlineTemplate: true }).content, resolveDir: path.dirname(filename) }
          })
        },
      }],
    })
    const { router, auth, useNavigationStore } = await import(pathToFileURL(path.join(temporary, 'harness.mjs')))
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
            assert.match(html, /Coming soon/)
            assert.match(html, /not available yet/)
            assert.doesNotMatch(html, /<button|<form|<input/)
          }
        }
      })
    }
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
