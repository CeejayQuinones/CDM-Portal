import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'
import { compileScript, compileStyle, parse } from '@vue/compiler-sfc'

const source = path => readFile(new URL(`../${path}`, import.meta.url), 'utf8')

test('Turnstile widget uses explicit managed rendering with accessible retry states', async () => {
  const component = await source('src/components/TurnstileVerification.vue')
  const { descriptor, errors } = parse(component)

  assert.deepEqual(errors, [])
  assert.doesNotThrow(() => compileScript(descriptor, { id: 'turnstile-verification', inlineTemplate: true }))
  assert.deepEqual(compileStyle({ source: descriptor.styles[0].content, filename: 'TurnstileVerification.vue', id: 'turnstile-verification', scoped: true }).errors, [])
  assert.match(component, /turnstile\/v0\/api\.js\?render=explicit/)
  assert.match(component, /VITE_TURNSTILE_SITE_KEY/)
  assert.match(component, /appearance: 'interaction-only'/)
  assert.match(component, /theme: 'auto'/)
  assert.match(component, /'expired-callback'/)
  assert.match(component, /'error-callback'/)
  assert.match(component, /role="alert"/)
  assert.match(component, /Retry verification/)
  assert.doesNotMatch(component, /SECRET|TURNSTILE_SECRET/i)
})

test('website and Android credentials require a disposable Turnstile token before OTP', async () => {
  const [login, store] = await Promise.all([
    source('src/views/LoginView.vue'),
    source('src/stores/authStore.js'),
  ])

  assert.match(login, /clientPlatform !== 'desktop' && !turnstileToken\.value/)
  assert.match(login, /turnstile_token: turnstileToken\.value/)
  assert.match(login, /<TurnstileVerification[^>]*action="authentication"/)
  assert.match(login, /exchangeGoogleCode\(event\.data\.code, turnstileToken\.value\)/)
  assert.match(login, /googlePopupMonitor = window\.setInterval[\s\S]*turnstileWidget\.value\?\.reset\(\)/)
  assert.match(login, /if \(event\.data\.error\)[\s\S]*turnstileToken\.value = ''[\s\S]*turnstileWidget\.value\?\.reset\(\)/)
  assert.match(store, /turnstile_token: turnstileToken/)
  assert.match(login, /if \(result\?\.requiresOtp\) return/)
  assert.match(login, /:authenticating="showPostLoginLoading"/)

  const desktop = login.match(/<div v-else-if="clientPlatform === 'desktop'"[\s\S]*?<div v-else class="public-page">/)?.[0] || ''
  assert.doesNotMatch(desktop, /TurnstileVerification/)
})

test('registration protects email-code initiation without challenging code verification', async () => {
  const registration = await source('src/components/RegistrationWizard.vue')

  assert.match(registration, /action="registration"/)
  assert.match(registration, /registration\/email-verification\/send'[\s\S]*turnstile_token: turnstileToken\.value/)
  assert.doesNotMatch(
    registration.match(/registration\/email-verification\/verify'[\s\S]*?\}\)/)?.[0] || '',
    /turnstile_token/,
  )
})

test('platform CSP permits only the Cloudflare Turnstile origin', async () => {
  const vite = await source('vite.config.js')

  assert.match(vite, /const turnstileOrigin = 'https:\/\/challenges\.cloudflare\.com'/)
  assert.match(vite, /script-src[^\n]*turnstileOrigin/)
  assert.match(vite, /connect-src/)
  assert.match(vite, /frame-src[^\n]*turnstileOrigin/)
  assert.doesNotMatch(vite, /script-src[^\n]*https:\/\/\*/)
  assert.doesNotMatch(vite, /frame-src[^\n]*https:\/\/\*/)
})
