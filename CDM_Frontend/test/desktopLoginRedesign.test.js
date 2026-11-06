import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'
import { compileScript, compileStyle, parse } from '@vue/compiler-sfc'

const read = path => readFile(new URL(`../${path}`, import.meta.url), 'utf8')

test('Electron login has a dedicated branded glass workspace using repository assets', async () => {
  const [view, css] = await Promise.all([
    read('src/views/LoginView.vue'),
    read('src/assets/styles/desktop-login.css'),
  ])

  assert.match(view, /v-else-if="clientPlatform === 'desktop'"/)
  assert.match(view, /images\/cdm_logo\.png/)
  assert.match(view, /images\/CDM-bdng\.png/)
  assert.match(view, /class="desktop-login-building"/)
  assert.match(view, /alt="Colegio de Montalban seal"/)
  assert.match(view, /class="desktop-login-glass"/)
  assert.match(css, /backdrop-filter:blur\(24px\)/)
  assert.match(css, /object-fit:contain/)
  assert.match(css, /mask-image:radial-gradient/)
})

test('desktop form preserves accessible login behavior and remains isolated', async () => {
  const [view, css] = await Promise.all([
    read('src/views/LoginView.vue'),
    read('src/assets/styles/desktop-login.css'),
  ])
  const { descriptor } = parse(view, { filename: 'LoginView.vue' })

  assert.doesNotThrow(() => compileScript(descriptor, { id: 'desktop-login', inlineTemplate: true }))
  assert.deepEqual(compileStyle({ source: css, filename: 'desktop-login.css', id: 'desktop-login', scoped: true }).errors, [])
  assert.match(view, /<form novalidate @submit\.prevent="submit">/)
  assert.match(view, /id="desktop-username"[\s\S]*autocomplete="username"/)
  assert.match(view, /id="desktop-password"[\s\S]*autocomplete="current-password"/)
  assert.match(view, /showPassword \? 'Hide password' : 'Show password'/)
  assert.match(view, /class="desktop-login-error" role="alert"/)
  assert.match(view, /class="desktop-login-submit" type="submit" :disabled="isLoading"/)
  assert.doesNotMatch(css, /html|body|\.public-page|\.login-card/)
})

test('desktop composition adapts without horizontal overflow and respects reduced motion', async () => {
  const css = await read('src/assets/styles/desktop-login.css')
  assert.match(css, /width:min\(1080px,calc\(100vw - 64px\)\)/)
  assert.match(css, /@media \(min-width:761px\) and \(max-height:700px\)/)
  assert.match(css, /@media \(max-width:760px\)/)
  assert.match(css, /grid-template-columns:1fr/)
  assert.match(css, /overflow-y:auto/)
  assert.match(css, /@media \(prefers-reduced-motion:reduce\)/)
})
