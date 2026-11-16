import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'
import { build } from 'esbuild'

const read = (path) => readFileSync(new URL(`../${path}`, import.meta.url), 'utf8')

test('web and mobile login state remains unauthenticated until OTP verification succeeds', async () => {
  const savedWindow = globalThis.window
  const savedStorage = globalThis.localStorage
  const savedApi = globalThis.__otpApi
  const values = new Map()
  globalThis.localStorage = {
    getItem: (key) => values.get(key) ?? null,
    setItem: (key, value) => values.set(key, value),
    removeItem: (key) => values.delete(key),
  }
  globalThis.window = new EventTarget()
  const calls = []
  globalThis.__otpApi = {
    async post(path, body) {
      calls.push([path, body])
      if (path === '/login') return { data: { data: {
        requires_otp: true,
        challenge_id: '11111111-1111-4111-8111-111111111111',
        masked_email: 'st*****@example.com',
        expires_at: new Date(Date.now() + 300000).toISOString(),
        resend_available_at: new Date(Date.now() + 60000).toISOString(),
      } } }
      if (path === '/auth/login/otp/resend') return { data: { data: {
        requires_otp: true,
        challenge_id: body.challenge_id,
        masked_email: 'st*****@example.com',
        expires_at: new Date(Date.now() + 300000).toISOString(),
        resend_available_at: new Date(Date.now() + 60000).toISOString(),
      } } }
      if (path === '/auth/login/otp/verify' && body.code === '000000') {
        throw Object.assign(new Error('Invalid verification code.'), {
          response: { status: 422, data: { errors: { code: ['The verification code is invalid or has expired.'] } } },
        })
      }
      if (path === '/auth/login/otp/verify') return { data: { data: {
        token: 'verified-token',
        user: { id: 7, username: 'student', role: { role_name: 'Student' } },
      } } }
      if (path === '/auth/google/exchange') return { data: { data: {
        requires_otp: true,
        challenge_id: '22222222-2222-4222-8222-222222222222',
        masked_email: 'go****@example.com',
        expires_at: new Date(Date.now() + 300000).toISOString(),
        resend_available_at: new Date(Date.now() + 60000).toISOString(),
      } } }
      throw new Error(`Unexpected path ${path}`)
    },
  }

  try {
    const result = await build({
      stdin: {
        contents: "export { useAuthStore } from './src/stores/authStore.js'; export { createPinia, setActivePinia } from 'pinia';",
        resolveDir: process.cwd(),
        sourcefile: 'otp-store-entry.js',
      },
      bundle: true,
      platform: 'node',
      format: 'cjs',
      write: false,
      plugins: [{
        name: 'otp-store-mocks',
        setup(plugin) {
          plugin.onResolve({ filter: /services\/apiClient$/ }, () => ({ path: 'api', namespace: 'otp' }))
          plugin.onResolve({ filter: /gradeDraftRecovery$/ }, () => ({ path: 'recovery', namespace: 'otp' }))
          plugin.onLoad({ filter: /.*/, namespace: 'otp' }, (args) => args.path === 'api'
            ? { contents: 'export const apiClient = globalThis.__otpApi' }
            : { contents: 'export const clearGradeRecoveryForAccount = () => {}' })
        },
      }],
    })
    const module = { exports: {} }
    new Function('module', 'exports', result.outputFiles[0].text)(module, module.exports)
    module.exports.setActivePinia(module.exports.createPinia())
    const store = module.exports.useAuthStore()

    const started = await store.login({ username: 'student', password: 'secret' })
    assert.equal(started.requiresOtp, true)
    assert.equal(store.isAuthenticated, false)
    assert.equal(store.token, null)
    assert.equal(values.has('cdm_portal_auth'), false)
    assert.equal(JSON.parse(values.get('cdm_portal_login_challenge')).masked_email, 'st*****@example.com')

    module.exports.setActivePinia(module.exports.createPinia())
    const resumedStore = module.exports.useAuthStore()
    assert.equal(resumedStore.pendingLoginChallenge.challenge_id, '11111111-1111-4111-8111-111111111111')
    assert.equal(resumedStore.isAuthenticated, false)

    await resumedStore.resendLoginOtp()
    assert.equal(calls.at(-1)[0], '/auth/login/otp/resend')
    await assert.rejects(() => resumedStore.verifyLoginOtp('000000'), /Invalid verification code/)
    assert.equal(resumedStore.isAuthenticated, false)
    assert.equal(resumedStore.token, null)
    assert.equal(resumedStore.pendingLoginChallenge.challenge_id, '11111111-1111-4111-8111-111111111111')
    assert.equal(values.has('cdm_portal_auth'), false)

    await resumedStore.verifyLoginOtp('123456')
    assert.equal(calls.at(-1)[0], '/auth/login/otp/verify')
    assert.equal(calls.at(-1)[1].code, '123456')
    assert.equal(resumedStore.isAuthenticated, true)
    assert.equal(resumedStore.currentRole, 'Student')
    assert.equal(values.has('cdm_portal_login_challenge'), false)
    assert.equal(JSON.parse(values.get('cdm_portal_auth')).token, 'verified-token')

    resumedStore.clearAuth()
    const googleResult = await resumedStore.exchangeGoogleCode('google-handoff')
    assert.equal(googleResult.requiresOtp, true)
    assert.equal(calls.at(-1)[0], '/auth/google/exchange')
    assert.deepEqual(calls.at(-1)[1], { code: 'google-handoff' })
    assert.equal(resumedStore.isAuthenticated, false)
    assert.equal(resumedStore.token, null)
    assert.equal(resumedStore.pendingLoginChallenge.challenge_id, '22222222-2222-4222-8222-222222222222')
    assert.equal(values.has('cdm_portal_auth'), false)

    values.set('cdm_portal_login_challenge', JSON.stringify({
      ...resumedStore.pendingLoginChallenge,
      expires_at: new Date(Date.now() - 1000).toISOString(),
    }))
    module.exports.setActivePinia(module.exports.createPinia())
    const expiredStore = module.exports.useAuthStore()
    assert.equal(expiredStore.pendingLoginChallenge, null)
    assert.equal(expiredStore.isAuthenticated, false)
    assert.equal(values.has('cdm_portal_login_challenge'), false)
  } finally {
    globalThis.window = savedWindow
    globalThis.localStorage = savedStorage
    globalThis.__otpApi = savedApi
  }
})

test('login verification UI is accessible, paste-friendly, and contains no OTP bypass', () => {
  const component = read('src/components/LoginOtpVerification.vue')
  const login = read('src/views/LoginView.vue')
  assert.match(component, /Verify your login/)
  assert.match(component, /autocomplete="one-time-code"/)
  assert.match(component, /inputmode="numeric"/)
  assert.match(component, /Resend code/)
  assert.match(component, /Back to sign in/)
  assert.match(component, /role="alert"/)
  assert.match(login, /authStore\.verifyLoginOtp\(code\)/)
  assert.match(login, /result\?\.requiresOtp/)
  assert.match(login, /:authenticating="showPostLoginLoading"/)
  assert.doesNotMatch(component, /skip|bypass/i)
})

test('student sign-in hint uses student number and an ID-card icon', () => {
  const login = read('src/views/LoginView.vue')
  assert.equal((login.match(/placeholder="Student number"/g) || []).length, 2)
  assert.doesNotMatch(login, /placeholder="Student number or email"/)
  assert.equal((login.match(/<rect x="3" y="5" width="18" height="14" rx="2"/g) || []).length, 2)
})
