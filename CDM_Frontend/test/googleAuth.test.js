import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'

const login = readFileSync(new URL('../src/views/LoginView.vue', import.meta.url), 'utf8')
const store = readFileSync(new URL('../src/stores/authStore.js', import.meta.url), 'utf8')
const styles = readFileSync(new URL('../src/assets/styles/public-home.css', import.meta.url), 'utf8')
const frontendSources = [login, store, readFileSync(new URL('../src/services/apiClient.js', import.meta.url), 'utf8')].join('\n')
const developmentEnvironment = readFileSync(new URL('../.env.development', import.meta.url), 'utf8')

test('login keeps password access and clearly offers Google for existing Professor and Student accounts', () => {
  for (const text of ['Username', 'Password', 'Sign in to portal', 'Continue with Google', 'Professor and Student accounts must already exist']) {
    assert.match(login, new RegExp(text, 'i'))
  }
  assert.match(login, /clientPlatform === 'web'/)
  assert.match(login, /Google does not create an account/)
  assert.doesNotMatch(frontendSources, /This (Professor|Student) account must sign in with Google/)
})

test('Google callback uses a one-time exchange and the existing auth store', () => {
  assert.match(login, /event\.data\?\.type !== 'cdm-google-auth'/)
  assert.match(login, /authStore\.exchangeGoogleCode/)
  assert.match(store, /post\('\/auth\/google\/exchange'/)
  assert.match(store, /token\.value = data\.data\.token/)
  assert.match(store, /AUTH_STORAGE_KEY = 'cdm_portal_auth'/)
})

test('frontend contains no Google client secret', () => {
  assert.doesNotMatch(frontendSources, /GOOGLE_CLIENT_SECRET|client_secret/i)
})

test('Google sign-in uses a recognizable accessible secondary button treatment', () => {
  for (const color of ['#4285F4', '#34A853', '#FBBC05', '#EA4335']) assert.match(login, new RegExp(color, 'i'))
  assert.match(login, /class="google-sign-in-icon"/)
  assert.match(login, /:disabled="isLoading" @click="googleSignIn"/)
  for (const state of [':hover:not(:disabled)', ':active:not(:disabled)', ':focus-visible', ':disabled']) assert.ok(styles.includes(`google-sign-in-button${state}`))
  assert.match(styles, /\.google-sign-in-icon \{ width: 20px; height: 20px;/)
})

test('local OAuth starts on the same localhost backend host used by the callback', () => {
  assert.match(developmentEnvironment, /^VITE_API_BASE_URL=http:\/\/localhost:8000\/api$/m)
  assert.doesNotMatch(developmentEnvironment, /172\.25\./)
  assert.match(login, /new URL\(apiClient\.defaults\.baseURL \|\| '\/api', window\.location\.origin\)/)
})
