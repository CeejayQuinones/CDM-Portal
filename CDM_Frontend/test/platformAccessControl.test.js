import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

import { apiBaseUrlForMode, clientPlatformForMode } from '../vite.config.js'
import { loginErrorMessage, runLoginRequest } from '../src/utils/loginFlow.js'

test('Vite modes select the fixed CDM client platform', () => {
  assert.equal(clientPlatformForMode('production'), 'web')
  assert.equal(clientPlatformForMode('development'), 'web')
  assert.equal(clientPlatformForMode('desktop'), 'desktop')
  assert.equal(clientPlatformForMode('mobile'), 'mobile')
  assert.equal(clientPlatformForMode('demo-mobile'), 'mobile')
})

test('desktop mode does not inherit the web or LAN API URL', () => {
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

test('the shared API client sends the build-owned platform header', async () => {
  const source = await readFile(new URL('../src/services/apiClient.js', import.meta.url), 'utf8')

  assert.match(source, /'X-CDM-Client': clientPlatform/)
  assert.doesNotMatch(source, /localStorage\.getItem\([^)]*client/i)
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
