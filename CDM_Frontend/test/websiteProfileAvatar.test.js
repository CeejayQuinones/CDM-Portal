import assert from 'node:assert/strict'
import { createRequire } from 'node:module'
import { readFile } from 'node:fs/promises'
import { fileURLToPath } from 'node:url'
import test from 'node:test'
import { build } from 'esbuild'
import { compileScript, parse } from '@vue/compiler-sfc'

const source = path => readFile(new URL(`../${path}`, import.meta.url), 'utf8')

test('website shell uses one reactive avatar source without changing the legacy shell', async () => {
  const [shell, topbar, rail, dashboardProfile, settings, legacySidebar] = await Promise.all([
    source('src/layouts/WebsiteAppShell.vue'),
    source('src/components/website/WebsiteTopbar.vue'),
    source('src/components/website/WebsiteNavigationRail.vue'),
    source('src/components/website/StudentDashboardProfile.vue'),
    source('src/views/SettingsView.vue'),
    source('src/components/Sidebar.vue'),
  ])

  assert.match(shell, /useStudentProfileStore/)
  assert.match(shell, /:avatar-url="avatarUrl"/)
  assert.match(shell, /:avatar-revision="studentProfile\.websiteAvatarRevision"/)
  assert.equal((topbar.match(/<WebsiteUserAvatar/g) || []).length, 2, 'topbar trigger and account dropdown both use the shared renderer')
  assert.equal((dashboardProfile.match(/<WebsiteUserAvatar/g) || []).length, 1, 'the same Dashboard avatar remains mounted across compact and expanded layouts')
  assert.doesNotMatch(rail, /WebsiteUserAvatar|rail-avatar|open-account/, 'the approved website rail no longer owns profile identity')
  assert.match(settings, /studentProfile\.websiteAvatarUrl/)
  assert.match(settings, /studentProfile\.setProfile\(profile, studentUserId\.value\)/)
  assert.match(legacySidebar, /studentProfile\.avatarUrl/, 'Android and Electron retain the existing unversioned sidebar source')
  assert.doesNotMatch(legacySidebar, /websiteAvatarUrl/)
})

test('account-scoped avatar state invalidates same-path images and clears on identity changes', async () => {
  const root = fileURLToPath(new URL('../', import.meta.url))
  const stored = new Map([['cdm_portal_auth', JSON.stringify({
    token: 'student-token',
    currentRole: 'Student',
    currentUser: { id: 7, profile: { first_name: 'Maya', last_name: 'Santos' } },
  })]])
  globalThis.localStorage = {
    getItem: key => stored.get(key) ?? null,
    setItem: (key, value) => stored.set(key, value),
    removeItem: key => stored.delete(key),
  }

  const bundle = await build({
    absWorkingDir: root,
    bundle: true,
    write: false,
    platform: 'node',
    format: 'cjs',
    define: { 'import.meta.hot': 'undefined' },
    stdin: { resolveDir: root, contents: `
      export { createPinia } from 'pinia'
      export { nextTick } from 'vue'
      export { useAuthStore } from './src/stores/authStore.js'
      export { useStudentProfileStore } from './src/stores/studentProfile.js'
    ` },
    plugins: [{ name: 'avatar-api-boundary', setup(builder) {
      builder.onLoad({ filter: /\/services\/apiClient\.js$/ }, () => ({ contents: `
        import { resolveApiAssetUrl } from '../utils/apiAssetUrl.js'
        export const apiClient = {}
        export const apiAssetUrl = path => resolveApiAssetUrl(path, 'https://api.example.test/api', 'https://portal.example.test/')
      `, loader: 'js' }))
    } }],
  })
  const compiled = { exports: {} }
  new Function('require', 'module', 'exports', bundle.outputFiles[0].text)(createRequire(import.meta.url), compiled, compiled.exports)
  const { createPinia, nextTick, useAuthStore, useStudentProfileStore } = compiled.exports

  const pinia = createPinia()
  const auth = useAuthStore(pinia)
  const profile = useStudentProfileStore(pinia)
  profile.setProfile({ avatar_url: '/storage/student-avatars/current.png' }, 7)
  const firstUrl = profile.websiteAvatarUrl
  assert.match(firstUrl, /^https:\/\/api\.example\.test\/storage\/student-avatars\/current\.png\?cdm_avatar=\d+$/)

  profile.setProfile({ avatar_url: '/storage/student-avatars/current.png' }, 7)
  assert.notEqual(profile.websiteAvatarUrl, firstUrl, 'a replacement at the same storage path must bypass the browser cache')
  assert.equal(profile.avatarUrl, 'https://api.example.test/storage/student-avatars/current.png', 'the legacy client URL remains unchanged')

  auth.currentUser = { id: 8, profile: { first_name: 'Noah', last_name: 'Reyes' } }
  await nextTick()
  assert.equal(profile.websiteAvatarUrl, '', 'switching accounts clears the previous Student image immediately')
  profile.setProfile({ avatar_url: '/storage/student-avatars/stale.png' }, 7)
  assert.equal(profile.websiteAvatarUrl, '', 'a stale response from the former account is ignored')
  profile.setProfile({ avatar_url: '/storage/student-avatars/noah.png' }, 8)
  assert.match(profile.websiteAvatarUrl, /noah\.png\?cdm_avatar=/)

  auth.currentRole = 'Professor'
  auth.currentUser = { id: 21, profile: { profile_photo_url: '/storage/profile-photos/faculty.png' } }
  await nextTick()
  assert.equal(profile.websiteAvatarUrl, 'https://api.example.test/storage/profile-photos/faculty.png')
  auth.currentUser = { id: 22, profile: { first_name: 'Lina', last_name: 'Cruz' } }
  await nextTick()
  assert.equal(profile.websiteAvatarUrl, '', 'Professors without a supported profile photo retain the initials fallback')

  auth.currentRole = 'Guest'
  await nextTick()
  assert.equal(profile.websiteAvatarUrl, '', 'unsupported roles receive initials rather than another account image')
  auth.clearAuth()
  assert.equal(profile.websiteAvatarUrl, '', 'logout exposes no retained profile image')

  const freshPinia = createPinia()
  const freshProfile = useStudentProfileStore(freshPinia)
  assert.equal(freshProfile.websiteAvatarUrl, '', 'avatar paths are not persisted independently across browser sessions')
})

test('website avatar falls back on image failure and retries when its source changes', async () => {
  const root = fileURLToPath(new URL('../', import.meta.url))
  const bundle = await build({
    absWorkingDir: root,
    bundle: true,
    write: false,
    platform: 'node',
    format: 'cjs',
    stdin: { resolveDir: root, contents: `
      export { createRenderer, h, nextTick, ref } from 'vue'
      export { default as WebsiteUserAvatar } from './src/components/website/WebsiteUserAvatar.vue'
    ` },
    plugins: [{ name: 'avatar-component', setup(builder) {
      builder.onLoad({ filter: /\.vue$/ }, async ({ path }) => {
        const { descriptor } = parse(await readFile(path, 'utf8'), { filename: path })
        return { contents: compileScript(descriptor, { id: path, inlineTemplate: true }).content, loader: 'js' }
      })
    } }],
  })
  const compiled = { exports: {} }
  new Function('require', 'module', 'exports', bundle.outputFiles[0].text)(createRequire(import.meta.url), compiled, compiled.exports)
  const { createRenderer, h, nextTick, ref, WebsiteUserAvatar } = compiled.exports
  const node = (type, text = '') => ({ type, text, props: {}, children: [], parent: null })
  const detach = child => {
    if (child.parent) child.parent.children.splice(child.parent.children.indexOf(child), 1)
  }
  const renderer = createRenderer({
    createElement: node,
    createText: text => node('#text', text),
    createComment: text => node('#comment', text),
    setText: (target, text) => { target.text = text },
    setElementText: (target, text) => { target.text = text; target.children = [] },
    patchProp: (target, key, _, value) => { target.props[key] = value },
    insert: (child, parent, anchor = null) => {
      detach(child)
      parent.children.splice(anchor ? parent.children.indexOf(anchor) : parent.children.length, 0, child)
      child.parent = parent
    },
    remove: detach,
    parentNode: target => target.parent,
    nextSibling: target => target.parent?.children[target.parent.children.indexOf(target) + 1],
  })
  const find = (target, predicate) => predicate(target) ? target : target.children.map(child => find(child, predicate)).find(Boolean)
  const tree = node('root')
  const avatar = ref({ src: 'first.png', revision: 1 })
  renderer.createApp({ setup: () => () => h(WebsiteUserAvatar, { ...avatar.value, initials: 'MS', label: 'Maya Santos profile picture' }) }).mount(tree)
  await nextTick()
  const wrapper = find(tree, item => item.props.class === 'website-user-avatar')
  assert.equal(wrapper.props.role, 'img')
  assert.equal(wrapper.props['aria-label'], 'Maya Santos profile picture')
  assert.ok(find(wrapper, item => item.text === 'MS'), 'initials remain visible while the image loads')
  find(wrapper, item => item.type === 'img').props.onLoad()
  await nextTick()
  assert.equal(find(wrapper, item => item.text === 'MS'), undefined)
  find(wrapper, item => item.type === 'img').props.onError()
  await nextTick()
  assert.equal(find(wrapper, item => item.type === 'img'), undefined)
  assert.ok(find(wrapper, item => item.text === 'MS'))

  avatar.value = { src: 'second.png', revision: 2 }
  await nextTick()
  assert.equal(find(wrapper, item => item.type === 'img').props.src, 'second.png')
})
