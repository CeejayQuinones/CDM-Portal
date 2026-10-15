import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { fileURLToPath } from 'node:url'
import { createRequire } from 'node:module'
import test from 'node:test'
import { build } from 'esbuild'
import { compileScript, parse } from '@vue/compiler-sfc'

test('main sidebar follows Settings identity changes, retries, and restored Student sessions', async () => {
  const root = fileURLToPath(new URL('../', import.meta.url))
  let avatarPath = '/storage/student-avatars/saved.png'
  let preferredDisplayName
  let profileOverride
  let saveError
  let finishSave
  let settingsRequests = 0
  const settings = () => ({ data: { data: {
    profile: profileOverride === undefined ? { avatar_url: avatarPath, preferred_display_name: preferredDisplayName } : profileOverride,
    contact: {}, preferences: {},
    official: { legal_name: 'Maya Santos' }, appearance: 'system',
  } } })
  globalThis.__studentSidebarApi = {
    get: async () => { settingsRequests += 1; return settings() },
    post: async (url, payload) => {
      assert.equal(url, '/student/settings/avatar')
      avatarPath = `/storage/student-avatars/${payload.get('avatar').name}`
      return settings()
    },
    delete: async (url) => {
      assert.equal(url, '/student/settings/avatar')
      avatarPath = null
    },
    patch: async (url, payload) => {
      assert.equal(url, '/student/settings/profile')
      assert.deepEqual(Object.keys(payload).sort(), ['bio', 'preferred_display_name'])
      if (saveError) throw saveError
      preferredDisplayName = payload.preferred_display_name
      const response = settings()
      if (finishSave) await new Promise(resolve => { finishSave = resolve })
      return response
    },
  }
  globalThis.__studentProfileHot = { data: {}, accept(callback) { this.callback = callback } }

  // Compile the real components and stores together, replacing only the HTTP boundary.
  const bundle = await build({
    absWorkingDir: root, bundle: true, write: false, platform: 'node', format: 'cjs',
    define: { 'import.meta.hot': 'globalThis.__studentProfileHot' },
    stdin: { resolveDir: root, contents: `
      export { createRenderer, h, nextTick, ref } from 'vue'
      export { createPinia, defineStore } from 'pinia'
      export { createMemoryHistory, createRouter } from 'vue-router'
      export { default as Sidebar } from './src/components/Sidebar.vue'
      export { default as Settings } from './src/views/SettingsView.vue'
      export { useStudentTheme } from './src/composables/useStudentTheme.js'
      export { useAuthStore } from './src/stores/authStore.js'
      export { useStudentProfileStore } from './src/stores/studentProfile.js'
    ` },
    plugins: [{ name: 'student-avatar-components', setup(builder) {
      builder.onLoad({ filter: /\.vue$/ }, async ({ path }) => {
        const { descriptor } = parse(await readFile(path, 'utf8'), { filename: path })
        return { contents: compileScript(descriptor, { id: path, inlineTemplate: true }).content, loader: 'js' }
      })
      builder.onLoad({ filter: /\.png$/ }, () => ({ contents: 'export default "seal.png"', loader: 'js' }))
      builder.onLoad({ filter: /\/services\/apiClient\.js$/ }, () => ({ contents: `
        import { resolveApiAssetUrl } from '../utils/apiAssetUrl.js'
        export const apiClient = globalThis.__studentSidebarApi
        export const apiAssetUrl = path => resolveApiAssetUrl(path, 'https://api.example.test/api', 'https://portal.example.test/')
      `, loader: 'js' }))
    } }],
  })
  const compiled = { exports: {} }
  new Function('require', 'module', 'exports', bundle.outputFiles[0].text)(createRequire(import.meta.url), compiled, compiled.exports)
  const { createRenderer, h, nextTick, ref, createPinia, defineStore, createRouter, createMemoryHistory,
    Sidebar, Settings, useStudentTheme, useAuthStore, useStudentProfileStore } = compiled.exports
  const stored = new Map()
  globalThis.localStorage = {
    getItem: key => stored.get(key) ?? null,
    setItem: (key, value) => stored.set(key, value),
    removeItem: key => stored.delete(key),
  }
  globalThis.Document = class { documentElement = { dataset: {} } }
  globalThis.document = new Document()

  const node = (type, text = '') => ({
    type, text, props: {}, style: {}, children: [], parent: null,
    addEventListener() {}, getRootNode: () => document,
  })
  const detach = child => {
    if (child.parent) child.parent.children.splice(child.parent.children.indexOf(child), 1)
  }
  const renderer = createRenderer({
    createElement: node, createText: text => node('#text', text),
    createComment: text => node('#comment', text),
    setText: (target, text) => { target.text = text },
    setElementText: (target, text) => { target.text = text; target.children = [] },
    patchProp: (target, key, _, value) => { target.props[key] = value },
    insert: (child, parent, anchor = null) => {
      detach(child)
      parent.children.splice(anchor ? parent.children.indexOf(anchor) : parent.children.length, 0, child)
      child.parent = parent
    },
    remove: detach, parentNode: target => target.parent,
    nextSibling: target => target.parent?.children[target.parent.children.indexOf(target) + 1],
  })
  const find = (target, predicate) => predicate(target) ? target : target.children.map(child => find(child, predicate)).find(Boolean)
  const settle = async () => { await new Promise(resolve => setImmediate(resolve)); await nextTick() }
  let app
  const vueErrors = []
  const mount = async (role = 'Student', pinia = createPinia()) => {
    localStorage.setItem('cdm_portal_auth', JSON.stringify({
      token: 'test-token', currentRole: role,
      currentUser: { id: 7, profile: { first_name: 'Maya', last_name: 'Santos' } },
    }))
    const showSettings = ref(false)
    const tree = node('root')
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/:pathMatch(.*)*', component: {} }] })
    await router.push('/student-dashboard')
    app = renderer.createApp({ setup() {
      useStudentTheme(useAuthStore())
      return () => h('div', [h(Sidebar), showSettings.value ? h(Settings) : null])
    } })
    app.config.errorHandler = (error, instance, info) => { vueErrors.push({ message: error.message, info }) }
    app.use(pinia).use(router).mount(tree)
    await settle()
    return { tree, showSettings, auth: useAuthStore(pinia) }
  }
  const sidebarAvatar = tree => find(tree, target => target.props.class === 'sidebar-avatar')
  const sidebarImage = tree => find(sidebarAvatar(tree), target => target.type === 'img')
  const expectAvatar = (tree, file) => assert.equal(sidebarImage(tree)?.props.src, `https://api.example.test/storage/student-avatars/${file}`)
  const expectInitials = (tree, initials = 'MS') => {
    assert.equal(sidebarImage(tree), undefined)
    assert.ok(find(sidebarAvatar(tree), target => target.text === initials))
  }
  const sidebarName = tree => find(find(tree, target => target.props.class === 'user-copy'), target => target.type === 'strong').text
  try {
    let { tree, showSettings } = await mount()
    assert.equal(sidebarName(tree), 'Maya Santos')
    expectAvatar(tree, 'saved.png')

    // A failed first load must be retried when Settings confirms the same saved URL.
    sidebarImage(tree).props.onError()
    await settle()
    expectInitials(tree)
    showSettings.value = true
    await settle()
    expectAvatar(tree, 'saved.png')

    const upload = async name => {
      const input = find(tree, target => target.type === 'input' && target.props.type === 'file')
      await input.props.onChange({ target: { files: [new File(['image'], name, { type: 'image/png' })], value: name } })
      await settle()
      expectAvatar(tree, name)
      assert.equal(find(tree, target => target.props.class === 'avatar').children[0].props.src, sidebarImage(tree).props.src)
    }
    await upload('uploaded.png')
    await upload('replacement.png')
    showSettings.value = false
    await settle()
    expectAvatar(tree, 'replacement.png')

    app.unmount()
    ;({ tree, showSettings } = await mount())
    expectAvatar(tree, 'replacement.png')
    showSettings.value = true
    await settle()
    await find(tree, target => target.props.class === 'photo-remove').props.onClick()
    await settle()
    expectInitials(tree)

    const saveName = async name => {
      const previousName = sidebarName(tree)
      const input = find(tree, target => target.props.autocomplete === 'nickname')
      input.props['onUpdate:modelValue'](name)
      await settle()
      assert.equal(sidebarName(tree), previousName, 'an unsaved draft must not change the sidebar identity')
      await find(tree, target => target.type === 'button' && target.text === 'Save Changes').props.onClick()
      await settle()
      assert.equal(sidebarName(tree), name.trim() || 'Maya Santos')
      assert.equal(find(tree, target => target.props.role === 'alert'), undefined)
      assert.ok(find(tree, target => target.text === 'Profile saved.'))
    }
    await saveName('  May Rivera  ')
    expectInitials(tree, 'MR')
    app.unmount()
    let restored = await mount()
    ;({ tree, showSettings } = restored)
    assert.equal(sidebarName(tree), 'May Rivera', 'refresh restores the saved preference without opening Settings')
    expectInitials(tree, 'MR')
    assert.deepEqual(restored.auth.currentUser.profile, { first_name: 'Maya', last_name: 'Santos' })
    showSettings.value = true
    await settle()
    await saveName('')
    expectInitials(tree)
    await saveName('   ')
    expectInitials(tree)
    assert.deepEqual(restored.auth.currentUser.profile, { first_name: 'Maya', last_name: 'Santos' }, 'official names remain unchanged')
    app.unmount()

    preferredDisplayName = null
    restored = await mount()
    ;({ tree } = restored)
    assert.equal(sidebarName(tree), 'Maya Santos')
    expectInitials(tree)
    restored.auth.currentUser = { id: 7, username: 'student-account' }
    await settle()
    assert.equal(sidebarName(tree), 'student-account')
    restored.auth.currentUser = { id: 7 }
    await settle()
    assert.equal(sidebarName(tree), 'Portal User')
    app.unmount()

    avatarPath = '/storage/student-avatars/another-student.png'
    preferredDisplayName = 'Another Student'
    const requestsBeforeStaff = settingsRequests
    for (const role of ['Registrar Staff', 'Admin', 'Professor']) {
      ;({ tree } = await mount(role))
      expectInitials(tree)
      assert.equal(sidebarName(tree), 'Maya Santos')
      assert.equal(settingsRequests, requestsBeforeStaff, 'staff sidebar must not request Student settings')
      app.unmount()
      app = null
    }

    // Reproduce a browser retaining the avatar-only store across a component hot reload.
    const existingPinia = createPinia()
    const legacyStore = defineStore('studentProfile', () => {
      const avatarPath = ref(null), revision = ref(0)
      return { avatarPath, revision, setAvatar(path) { avatarPath.value = path; revision.value += 1 } }
    })(existingPinia)
    assert.equal(useStudentProfileStore(existingPinia), legacyStore)
    assert.throws(() => legacyStore.setProfile({}, 7), /setProfile is not a function/)
    ;({ tree, showSettings } = await mount('Student', existingPinia))
    globalThis.__studentProfileHot.data.pinia = existingPinia
    assert.equal(typeof globalThis.__studentProfileHot.callback, 'function', 'the Student store must register Pinia hot updates')
    globalThis.__studentProfileHot.callback({ useStudentProfileStore })
    assert.equal(typeof legacyStore.setProfile, 'function')
    showSettings.value = true
    await settle()
    await saveName('Updated Student')

    const input = find(tree, target => target.props.autocomplete === 'nickname')
    input.props['onUpdate:modelValue']('Unsaved name')
    await settle()
    await find(tree, target => target.type === 'button' && target.text === 'Reset').props.onClick()
    await settle()
    assert.equal(input.value, 'Updated Student')
    assert.equal(sidebarName(tree), 'Updated Student')

    input.props['onUpdate:modelValue']('Rejected name')
    await settle()
    saveError = { response: { status: 422, data: { message: 'Validation failed.', errors: {
      preferred_display_name: ['The preferred display name field must not be greater than 120 characters.'],
    } } } }
    await find(tree, target => target.type === 'button' && target.text === 'Save Changes').props.onClick()
    await settle()
    assert.equal(find(tree, target => target.props.role === 'alert').text, saveError.response.data.errors.preferred_display_name[0])
    assert.equal(sidebarName(tree), 'Updated Student')
    saveError = { response: { status: 500, data: { message: 'Profile could not be saved.', errors: {} } } }
    await find(tree, target => target.type === 'button' && target.text === 'Save Changes').props.onClick()
    await settle()
    assert.equal(find(tree, target => target.props.role === 'alert').text, 'Profile could not be saved.')
    saveError = null
    await saveName('Recovered Student')

    finishSave = true
    input.props['onUpdate:modelValue']('Saved after navigation')
    await settle()
    const pendingSave = find(tree, target => target.type === 'button' && target.text === 'Save Changes').props.onClick()
    showSettings.value = false
    await settle()
    finishSave()
    finishSave = null
    await pendingSave
    await settle()
    app.unmount()

    for (const profile of [null, {}, { preferred_display_name: null }, { preferred_display_name: 42 }]) {
      profileOverride = profile
      ;({ tree, showSettings } = await mount())
      showSettings.value = true
      await settle()
      assert.equal(sidebarName(tree), 'Maya Santos')
      assert.equal(find(tree, target => target.props.role === 'alert'), undefined)
      const nameInput = find(tree, target => target.props.autocomplete === 'nickname')
      assert.equal(nameInput.value, '')
      profileOverride = undefined
      await saveName('Valid name')
      app.unmount()
      app = null
    }
    assert.deepEqual(vueErrors, [], 'load, reset, save, null profiles and unmount must not produce Vue errors')
  } finally {
    app?.unmount()
    delete globalThis.__studentSidebarApi
    delete globalThis.__studentProfileHot
    delete globalThis.localStorage
    delete globalThis.document
    delete globalThis.Document
  }
})
