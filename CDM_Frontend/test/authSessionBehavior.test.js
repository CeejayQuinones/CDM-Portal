import assert from 'node:assert/strict'
import test from 'node:test'
import { build } from 'esbuild'

test('HTTP 401 invalidates the live session and redirects, while other failures do not', async () => {
  const savedWindow = globalThis.window
  const savedStorage = globalThis.localStorage
  const savedAxios = globalThis.__cdmTestAxios
  const values = new Map([['cdm_portal_auth', JSON.stringify({
    token: 'old-token', currentUser: { id: 1, role: { role_name: 'Student' } }, currentRole: 'Student',
  })]])
  globalThis.localStorage = {
    getItem: (key) => values.get(key) ?? null,
    setItem: (key, value) => values.set(key, value),
    removeItem: (key) => values.delete(key),
  }
  globalThis.window = Object.assign(new EventTarget(), { location: { hash: '#/student-dashboard', href: 'https://portal.example/#/student-dashboard' } })

  try {
    const result = await build({
      stdin: {
        contents: "export { apiClient } from './src/services/apiClient.js'; export { useAuthStore } from './src/stores/authStore.js'; export { createPinia, setActivePinia } from 'pinia';",
        resolveDir: process.cwd(), sourcefile: 'auth-behavior-entry.js',
      },
      bundle: true, platform: 'node', format: 'cjs', write: false,
      define: { 'import.meta.env.VITE_API_TIMEOUT_MS': 'undefined', 'import.meta.env.VITE_API_BASE_URL': '"https://api.example/api"' },
      plugins: [{
        name: 'isolated-api',
        setup(plugin) {
          plugin.onResolve({ filter: /^axios$/ }, () => ({ path: 'axios', namespace: 'mock' }))
          plugin.onResolve({ filter: /(?:demoMode|offlineApi|performanceMonitor|clientPlatform|gradeDraftRecovery)(?:\.js)?$/ }, (args) => ({ path: args.path, namespace: 'mock' }))
          plugin.onLoad({ filter: /.*/, namespace: 'mock' }, (args) => {
            if (args.path === 'axios') return { contents: `export default { create() { const client = { defaults: { baseURL: 'https://api.example/api' }, interceptors: { request: { use() {} }, response: { use(success, failure) { client.reject = failure } } } }; globalThis.__cdmTestAxios = client; return client } }` }
            if (args.path.includes('demoMode')) return { contents: 'export const isOfflineDemo = false' }
            if (args.path.includes('offlineApi')) return { contents: 'export const createOfflineApiClient = () => null' }
            if (args.path.includes('performanceMonitor')) return { contents: 'export const performanceMonitor = { beginApiRequest() {}, endApiRequest() {} }' }
            if (args.path.includes('clientPlatform')) return { contents: "export const clientPlatform = 'web'" }
            if (args.path.includes('gradeDraftRecovery')) return { contents: 'export const clearGradeRecoveryForAccount = () => {}' }
          })
        },
      }],
    })
    const module = { exports: {} }
    new Function('module', 'exports', result.outputFiles[0].text)(module, module.exports)
    const { createPinia, setActivePinia, useAuthStore } = module.exports
    setActivePinia(createPinia())
    const store = useAuthStore()
    assert.equal(store.isAuthenticated, true)
    const expired = { config: { url: '/me' }, response: { status: 401 } }
    await assert.rejects(globalThis.__cdmTestAxios.reject(expired))
    assert.equal(store.isAuthenticated, false)
    assert.equal(values.has('cdm_portal_auth'), false)
    assert.equal(window.location.hash, '#/login')

    window.location.hash = '#/student-dashboard'
    await assert.rejects(globalThis.__cdmTestAxios.reject({ config: { url: '/me' }, response: { status: 403 } }))
    assert.equal(window.location.hash, '#/student-dashboard')

    values.set('cdm_portal_auth', JSON.stringify({ token: 'new-token' }))
    store.token = 'new-token'
    store.currentUser = { id: 1, role: { role_name: 'Student' } }
    globalThis.__cdmTestAxios.post = async (path) => {
      assert.equal(path, '/change-password')
      return { data: { data: { is_first_login: false } } }
    }
    await store.changePassword({ current_password: 'old', password: 'new' })
    assert.equal(store.isAuthenticated, false)
    assert.equal(values.has('cdm_portal_auth'), false)
  } finally {
    globalThis.window = savedWindow
    globalThis.localStorage = savedStorage
    globalThis.__cdmTestAxios = savedAxios
  }
})

test('protected router guard fails closed for missing or unauthorized role metadata', async () => {
  globalThis.__cdmRouterAuth = {
    isAuthenticated: true,
    currentRole: 'Student',
    currentUser: { role: { role_name: 'Student' } },
    initialize: async () => true,
    clearAuth() { this.isAuthenticated = false },
  }
  try {
    const result = await build({
      stdin: { contents: "export { default as router } from './src/router/index.js'", resolveDir: process.cwd(), sourcefile: 'router-guard-entry.js' },
      bundle: true, platform: 'node', format: 'cjs', write: false,
      plugins: [{
        name: 'router-guard-mocks',
        setup(plugin) {
          plugin.onResolve({ filter: /^vue-router$/ }, () => ({ path: 'vue-router', namespace: 'guard-mock' }))
          plugin.onResolve({ filter: /\.vue$/ }, (args) => ({ path: args.path, namespace: 'guard-mock' }))
          plugin.onResolve({ filter: /(?:stores\/authStore|modules\/admission\/routes\.js|services\/performance\/performanceMonitor|config\/clientPlatform)$/ }, (args) => ({ path: args.path, namespace: 'guard-mock' }))
          plugin.onLoad({ filter: /.*/, namespace: 'guard-mock' }, (args) => {
            if (args.path === 'vue-router') return { contents: 'export const createWebHashHistory = () => ({}); export const createRouter = () => ({ beforeEach(fn) { this.guard = fn }, afterEach() {} })' }
            if (args.path.endsWith('.vue')) return { contents: 'export default {}' }
            if (args.path.includes('authStore')) return { contents: 'export const useAuthStore = () => globalThis.__cdmRouterAuth' }
            if (args.path.includes('admission/routes')) return { contents: 'export const admissionRoutes = []' }
            if (args.path.includes('performanceMonitor')) return { contents: 'export const performanceMonitor = { beginRoute() {} }' }
            if (args.path.includes('clientPlatform')) return { contents: "export const clientPlatform = 'web'; export const isRoleAllowedOnClient = () => true" }
          })
        },
      }],
    })
    const module = { exports: {} }
    new Function('module', 'exports', result.outputFiles[0].text)(module, module.exports)
    const guard = module.exports.router.guard
    const to = (roles) => ({ name: 'enrollment-subjects', path: '/enrollment/subjects', fullPath: '/enrollment/subjects', meta: roles === undefined ? {} : { roles }, matched: [{ meta: { requiresAuth: true } }] })
    assert.deepEqual(await guard(to()), { name: 'unauthorized' })
    assert.deepEqual(await guard(to(['Professor'])), { name: 'unauthorized' })
    assert.equal(await guard(to(['Student'])), true)
    globalThis.__cdmRouterAuth.isAuthenticated = false
    assert.deepEqual(await guard(to(['Student'])), { name: 'login', query: { redirect: '/enrollment/subjects' } })
  } finally {
    delete globalThis.__cdmRouterAuth
  }
})
