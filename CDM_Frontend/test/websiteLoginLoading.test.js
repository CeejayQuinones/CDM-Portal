import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'
import { compileScript, compileStyle, parse } from '@vue/compiler-sfc'
import { dashboardForRole } from '../src/config/accessControl.js'
import { runLoginRequest } from '../src/utils/loginFlow.js'

const source = path => readFile(new URL(`../${path}`, import.meta.url), 'utf8')

test('website login loader compiles with the approved seal and accessible pending copy', async () => {
  const [component, sealComponent, sealAsset] = await Promise.all([
    source('src/components/website/WebsiteLoginLoading.vue'),
    source('src/components/website/CDMSeal.vue'),
    readFile(new URL('../src/assets/styles/images/cdm-seal.png', import.meta.url)),
  ])
  const { descriptor, errors } = parse(component)

  assert.deepEqual(errors, [])
  assert.doesNotThrow(() => compileScript(descriptor, { id: 'website-login-loading', inlineTemplate: true }))
  assert.deepEqual(compileStyle({ source: descriptor.styles[0].content, filename: 'WebsiteLoginLoading.vue', id: 'website-login-loading', scoped: true }).errors, [])
  assert.match(component, /<CDMSeal class="website-login-loading__seal" decorative/)
  assert.match(sealComponent, /import \{ portalSealUrl \} from '\.\.\/\.\.\/config\/portalSeal'/)
  assert.equal(sealAsset.subarray(0, 8).toString('hex'), '89504e470d0a1a0a')
  assert.equal(sealAsset.readUInt32BE(16), 1254)
  assert.equal(sealAsset.readUInt32BE(20), 1254)
  assert.match(component, /role="status"/)
  assert.match(component, /aria-live="polite"/)
  assert.match(component, /aria-label="Signing you in"/)
  assert.match(component, /Connecting securely to CDM Portal…/)
  assert.doesNotMatch(component.split('<style')[0], /percentage|\d+%/i)
})

test('visual treatment keeps the seal stationary and animates only a subtle emerald glow', async () => {
  const component = await source('src/components/website/WebsiteLoginLoading.vue')

  assert.match(component, /background: #042017/)
  assert.match(component, /rgba\(16, 185, 129, 0\.22\)/)
  assert.match(component, /rgba\(52, 211, 153, 0\.1\)/)
  assert.match(component, /filter: blur\(32px\)/)
  assert.match(component, /website-login-glow-breathe 2\.8s ease-in-out infinite/)
  assert.match(component, /0%, 100% \{ opacity: 0\.4; \}/)
  assert.match(component, /50% \{ opacity: 0\.75; \}/)
  assert.match(component, /\.website-login-loading__seal \{[\s\S]*object-fit: contain;[\s\S]*website-login-seal-arrive 220ms/)
  assert.doesNotMatch(component, /rotate\(|gold|#(?:ffd700|d4af37|fbbf24)|border(?:-color)?:/i)
  const sealRule = component.match(/\.website-login-loading__seal \{([^}]*)\}/)?.[1] || ''
  assert.doesNotMatch(sealRule, /transform:|filter:/)
})

test('reduced motion disables entrance, glow breathing, and progress movement', async () => {
  const component = await source('src/components/website/WebsiteLoginLoading.vue')

  assert.match(component, /@media \(prefers-reduced-motion: reduce\)/)
  assert.match(component, /website-login-loading__seal,[\s\S]*website-login-loading__glow,[\s\S]*website-login-loading__progress > span[\s\S]*animation: none;[\s\S]*transition: none;/)
  assert.match(component, /website-login-loading__glow \{[\s\S]*opacity: 0\.56;/)
})

test('real login pending state covers authentication and the awaited redirect', async () => {
  const loading = []
  let finishLogin
  let finishRedirect
  const loginPending = new Promise(resolve => { finishLogin = resolve })
  const redirectPending = new Promise(resolve => { finishRedirect = resolve })
  const student = { role: { role_name: 'Student' } }

  const request = runLoginRequest({
    credentials: { username: 'student', password: 'not-logged' },
    login: () => loginPending,
    redirect: () => redirectPending,
    onError: () => assert.fail('pending login should not fail'),
    setLoading: value => loading.push(value),
  })

  await Promise.resolve()
  assert.deepEqual(loading, [true])
  finishLogin(student)
  await Promise.resolve()
  assert.deepEqual(loading, [true])
  finishRedirect()
  assert.equal(await request, student)
  assert.deepEqual(loading, [true, false])
})

test('invalid credentials and network failures remove the loader and retain actionable errors', async () => {
  for (const failure of [
    Object.assign(new Error('invalid'), { response: { status: 422, data: { message: 'Invalid credentials.' } } }),
    Object.assign(new Error('network'), { request: {} }),
  ]) {
    const loading = []
    let displayed = ''
    const result = await runLoginRequest({
      credentials: { username: 'person', password: 'not-logged' },
      login: async () => { throw failure },
      redirect: () => assert.fail('failed login must not redirect'),
      onError: (_error, message) => { displayed = message },
      setLoading: value => loading.push(value),
    })

    assert.equal(result, null)
    assert.deepEqual(loading, [true, false])
    assert.ok(displayed.length > 0)
    if (!failure.response) assert.match(displayed, /try again/i)
  }
})

test('login integration prevents duplicates, routes existing roles, and cleans up on unmount', async () => {
  const login = await source('src/views/LoginView.vue')

  assert.match(login, /const submit = async \(\) => \{\s*if \(isLoading\.value\) return/)
  assert.match(login, /:disabled="isLoading"/)
  assert.match(login, /setLoading: setLoginLoading/)
  assert.match(login, /await router\.replace\(destination\)/)
  assert.match(login, /onBeforeUnmount\(\(\) => \{[\s\S]*viewActive = false[\s\S]*removeEventListener\('message'/)
  assert.match(login, /if \(!viewActive\) return/)
  assert.equal(dashboardForRole('Student'), '/student-dashboard')
  assert.equal(dashboardForRole('Guest'), '/guest-dashboard')
  assert.equal(dashboardForRole('Professor'), '/professor-dashboard')
})

test('loader is carried only by the compile-time website homepage path', async () => {
  const [login, homepage, loader] = await Promise.all([
    source('src/views/LoginView.vue'),
    source('src/components/public/WebsitePublicHome.vue'),
    source('src/components/website/WebsiteLoginLoading.vue'),
  ])

  assert.match(login, /import\.meta\.env\.VITE_CDM_CLIENT === 'web'[\s\S]*import\('\.\.\/components\/public\/WebsitePublicHome\.vue'\)/)
  assert.match(login, /<WebsitePublicHome v-if="clientPlatform === 'web'"[\s\S]*:authenticating="isLoading"/)
  assert.match(homepage, /import WebsiteLoginLoading from '\.\.\/website\/WebsiteLoginLoading\.vue'/)
  assert.match(homepage, /<WebsiteLoginLoading v-if="authenticating"/)
  assert.doesNotMatch(login.match(/<div v-else class="public-page">[\s\S]*?<\/template>/)?.[0] || '', /WebsiteLoginLoading/)
  assert.doesNotMatch(loader, /setTimeout|setInterval|minimum|artificial/i)
})
