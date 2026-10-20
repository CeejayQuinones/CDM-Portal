import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

import { apiBaseUrlForMode, assertProductionApiBaseUrl, clientPlatformForMode } from '../vite.config.js'
import { isRoleAllowedOnClient } from '../src/config/clientPlatform.js'
import { loginErrorMessage, runLoginRequest } from '../src/utils/loginFlow.js'

const expectedPlatformMatrix = {
  Admin: { desktop: true, web: false, mobile: false },
  'Registrar Staff': { desktop: true, web: false, mobile: false },
  Professor: { desktop: false, web: true, mobile: true },
  Student: { desktop: false, web: true, mobile: true },
  Guest: { desktop: false, web: true, mobile: true },
}

test('all 15 role and platform combinations match the official access matrix', () => {
  for (const [role, platforms] of Object.entries(expectedPlatformMatrix)) {
    for (const [platform, allowed] of Object.entries(platforms)) {
      assert.equal(isRoleAllowedOnClient(role, platform), allowed, `${role} on ${platform}`)
    }
  }
})

test('Vite modes select the fixed CDM client platform', () => {
  assert.equal(clientPlatformForMode('production'), 'web')
  assert.equal(clientPlatformForMode('development'), 'web')
  assert.equal(clientPlatformForMode('desktop'), 'desktop')
  assert.equal(clientPlatformForMode('desktop-development'), 'desktop')
  assert.equal(clientPlatformForMode('mobile'), 'mobile')
  assert.equal(clientPlatformForMode('mobile-development'), 'mobile')
  assert.equal(clientPlatformForMode('demo-mobile'), 'mobile')
})

test('development modes retain explicit local API endpoints', () => {
  const env = {
    VITE_API_BASE_URL: 'http://192.168.100.34:8000/api',
  }

  assert.equal(apiBaseUrlForMode('desktop', env), 'http://127.0.0.1:8000/api')
  assert.equal(apiBaseUrlForMode('production', env), env.VITE_API_BASE_URL)
  assert.equal(
    apiBaseUrlForMode('desktop', { ...env, VITE_DESKTOP_API_BASE_URL: 'https://desktop-api.example/api' }),
    'https://desktop-api.example/api',
  )
})

test('production builds require explicit public HTTPS API endpoints', () => {
  assert.throws(() => apiBaseUrlForMode('production', {}, 'build'), /VITE_API_BASE_URL is required/)
  assert.throws(
    () => apiBaseUrlForMode('desktop', { VITE_DESKTOP_API_BASE_URL: 'http://127.0.0.1:8000/api' }, 'build'),
    /HTTPS and a non-development host/,
  )
  assert.throws(
    () => apiBaseUrlForMode('mobile', { VITE_API_BASE_URL: 'http://10.0.2.2:8000/api' }, 'build'),
    /HTTPS and a non-development host/,
  )
  assert.throws(
    () => assertProductionApiBaseUrl('https://192.168.100.34/api', 'VITE_API_BASE_URL'),
    /non-development host/,
  )
  assert.equal(
    apiBaseUrlForMode('production', { VITE_API_BASE_URL: 'https://api.cdm.example/api/' }, 'build'),
    'https://api.cdm.example/api',
  )
  assert.equal(
    apiBaseUrlForMode('desktop', { VITE_DESKTOP_API_BASE_URL: 'https://api.cdm.example/api' }, 'build'),
    'https://api.cdm.example/api',
  )
})

test('Android release networking is HTTPS-only while debug keeps local HTTP support', async () => {
  const [capacitor, releaseManifest, releaseNetwork, debugManifest, debugNetwork] = await Promise.all([
    readFile(new URL('../capacitor.config.json', import.meta.url), 'utf8'),
    readFile(new URL('../android/app/src/main/AndroidManifest.xml', import.meta.url), 'utf8'),
    readFile(new URL('../android/app/src/main/res/xml/network_security_config.xml', import.meta.url), 'utf8'),
    readFile(new URL('../android/app/src/debug/AndroidManifest.xml', import.meta.url), 'utf8'),
    readFile(new URL('../android/app/src/debug/res/xml/network_security_config.xml', import.meta.url), 'utf8'),
  ])

  assert.match(capacitor, /"androidScheme": "https"/)
  assert.match(releaseManifest, /usesCleartextTraffic="false"/)
  assert.match(releaseNetwork, /cleartextTrafficPermitted="false"/)
  assert.doesNotMatch(releaseNetwork, /192\.168\.|10\.0\.2\.2|127\.0\.0\.1/)
  assert.match(debugManifest, /usesCleartextTraffic="true"/)
  assert.match(debugNetwork, /cleartextTrafficPermitted="true"/)
})

test('the shared API client sends the build-owned platform header', async () => {
  const source = await readFile(new URL('../src/services/apiClient.js', import.meta.url), 'utf8')

  assert.match(source, /'X-CDM-Client': clientPlatform/)
  assert.doesNotMatch(source, /localStorage\.getItem\([^)]*client/i)
})

test('Professor Mobile navigation preserves dashboard and academic workflows', async () => {
  const navigation = await readFile(new URL('../src/stores/navigation.js', import.meta.url), 'utf8')
  const router = await readFile(new URL('../src/router/index.js', import.meta.url), 'utf8')

  assert.doesNotMatch(navigation, /role === 'Professor' && clientPlatform === 'mobile'/)
  assert.doesNotMatch(router, /authStore\.currentRole === ROLES\.PROFESSOR && clientPlatform === 'mobile'/)
  assert.match(router, /const landingForRole = \(role\) => dashboardForRole\(role\)/)
})

test('router blocks persisted cross-platform roles and clears their local session', async () => {
  const router = await readFile(new URL('../src/router/index.js', import.meta.url), 'utf8')

  assert.match(router, /!isRoleAllowedOnClient\(authStore\.currentRole\)/)
  assert.match(router, /authStore\.clearAuth\(\)/)
  assert.match(router, /platformDenied: '1'/)
})

test('Guest mobile uses the Guest dashboard while Event QR operations stay role restricted', async () => {
  const [access, router, navigation] = await Promise.all([
    readFile(new URL('../src/config/accessControl.js', import.meta.url), 'utf8'),
    readFile(new URL('../src/router/index.js', import.meta.url), 'utf8'),
    readFile(new URL('../src/stores/navigation.js', import.meta.url), 'utf8'),
  ])

  assert.match(access, /\[ROLES\.GUEST\]: '\/guest-dashboard'/)
  assert.match(router, /\['Professor', 'Student'\]\.includes\(role\) && clientPlatform === 'mobile'/)
  assert.match(navigation, /\['Professor', 'Student'\]\.includes\(role\) && clientPlatform === 'mobile'/)
  assert.match(router, /role === ROLES\.PROFESSOR && clientPlatform === 'mobile'/)
  assert.match(router, /to\.meta\.eventReports && !canAccessEventReportsClient/)
})

test('login renders failures in an accessible alert', async () => {
  const source = await readFile(new URL('../src/views/LoginView.vue', import.meta.url), 'utf8')

  assert.match(source, /formError\.value = message/)
  assert.match(source, /role="alert"/)
})

test('desktop login failure displays the backend 403 and always resets loading', async () => {
  const loading = []
  let displayedMessage = ''
  const forbidden = Object.assign(new Error('forbidden'), {
    response: { status: 403, data: { message: 'Student accounts cannot access the Registrar Desktop application.' } },
  })

  const result = await runLoginRequest({
    credentials: { username: 'student', password: 'secret' },
    login: async () => { throw forbidden },
    redirect: async () => { throw new Error('redirect should not run') },
    onError: (_error, message) => { displayedMessage = message },
    setLoading: (value) => loading.push(value),
  })

  assert.equal(result, null)
  assert.equal(displayedMessage, forbidden.response.data.message)
  assert.deepEqual(loading, [true, false])
})

test('successful desktop staff login resolves and resets loading', async () => {
  const loading = []
  const registrar = { role: { role_name: 'Registrar Staff' } }
  let redirectedUser = null

  const result = await runLoginRequest({
    credentials: { username: 'registrar1', password: 'secret' },
    login: async () => registrar,
    redirect: async (user) => { redirectedUser = user },
    onError: () => { throw new Error('error handler should not run') },
    setLoading: (value) => loading.push(value),
  })

  assert.equal(result, registrar)
  assert.equal(redirectedUser, registrar)
  assert.deepEqual(loading, [true, false])
})

test('network and timeout failures use safe actionable messages', () => {
  assert.match(loginErrorMessage({ code: 'ECONNABORTED' }), /did not respond in time/)
  assert.match(loginErrorMessage({ request: {} }), /Unable to reach the CDM server/)
})

test('Electron keeps renderer isolation and restricts external navigation', async () => {
  const source = await readFile(new URL('../electron/main.cjs', import.meta.url), 'utf8')

  assert.match(source, /contextIsolation:\s*true/)
  assert.match(source, /nodeIntegration:\s*false/)
  assert.doesNotMatch(source, /webSecurity:\s*false/)
  assert.match(source, /protocol === 'https:' \|\| protocol === 'http:'/)
  assert.match(source, /webContents\.on\('will-navigate'/)
})
