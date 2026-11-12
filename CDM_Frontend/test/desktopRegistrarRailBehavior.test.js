import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { fileURLToPath } from 'node:url'
import { createRequire } from 'node:module'
import test from 'node:test'
import { build } from 'esbuild'
import { compileScript, parse } from '@vue/compiler-sfc'

test('Registrar sidebar keeps labels visible, navigates, and leaves Admin icon-only', async () => {
  const root = fileURLToPath(new URL('../', import.meta.url))
  const bundle = await build({
    absWorkingDir: root, bundle: true, write: false, platform: 'node', format: 'cjs',
    stdin: { resolveDir: root, contents: `
      export { createRenderer, h, nextTick, computed } from 'vue'
      export { createRouter, createMemoryHistory, useRoute } from 'vue-router'
      export { default as Rail } from './src/components/desktop/DesktopPrimaryRail.vue'
      export { desktopModulesFromMenu, desktopModuleForRoute } from './src/config/desktopNavigation.js'
    ` },
    plugins: [{ name: 'rail-component', setup(plugin) {
      plugin.onLoad({ filter: /\.vue$/ }, async ({ path }) => {
        const { descriptor } = parse(await readFile(path, 'utf8'), { filename: path })
        return { contents: compileScript(descriptor, { id: path, inlineTemplate: true }).content, loader: 'js' }
      })
      plugin.onLoad({ filter: /\.png$/ }, () => ({ contents: 'export default "seal.png"', loader: 'js' }))
    } }],
  })
  const compiled = { exports: {} }
  new Function('require', 'module', 'exports', bundle.outputFiles[0].text)(createRequire(import.meta.url), compiled, compiled.exports)
  const { createRenderer, h, nextTick, computed, createRouter, createMemoryHistory, useRoute, Rail, desktopModulesFromMenu, desktopModuleForRoute } = compiled.exports
  const node = (type, text = '') => ({ type, text, props: {}, children: [], parent: null })
  const detach = child => {
    if (child.parent) child.parent.children.splice(child.parent.children.indexOf(child), 1)
  }
  const renderer = createRenderer({
    createElement: node, createText: text => node('#text', text), createComment: text => node('#comment', text),
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
  const all = (target, predicate) => [ ...(predicate(target) ? [target] : []), ...target.children.flatMap(child => all(child, predicate)) ]
  const byClass = (target, name) => all(target, item => String(item.props.class || '').includes(name))
  const menu = desktopModulesFromMenu([
    { name: 'registrar-dashboard', label: 'Registrar Dashboard', path: '/registrar-dashboard' },
    { name: 'enrollment', label: 'Enrollment', children: [
      { name: 'enrollment-applications', label: 'Applications', path: '/enrollment/applications' },
      { name: 'enrollment-records', label: 'Enrollment Records', path: '/enrollment/records' },
    ] },
  ])
  const router = createRouter({ history: createMemoryHistory(), routes: [
    { path: '/registrar-dashboard', component: {} },
    { path: '/enrollment/applications', component: {} },
    { path: '/enrollment/records', component: {} },
  ] })
  await router.push('/enrollment/applications')
  const tree = node('root')
  const app = renderer.createApp({ setup() {
    const route = useRoute()
    const active = computed(() => desktopModuleForRoute(menu, route))
    return () => h(Rail, { modules: menu, activeModule: active.value, registrarPresentation: true })
  } })
  app.use(router)
  app.mount(tree)
  const aside = byClass(tree, 'desktop-registrar-sidebar')[0]
  assert.ok(aside)
  assert.equal(byClass(tree, 'registrar-sidebar-brand').length, 1)
  assert.equal(byClass(tree, 'registrar-sidebar-children').length, 1)
  assert.equal(byClass(tree, 'registrar-sidebar-children')[0].children[0].children.filter(child => child.type === 'a').length, 2)
  assert.equal(byClass(tree, 'registrar-sidebar-children')[0].children[0].children.filter(child => child.type === 'a')[0].props['aria-current'], 'page')
  const enrollmentButton = byClass(tree, 'registrar-sidebar-parent')[0]
  assert.equal(enrollmentButton.props['aria-expanded'], true)
  enrollmentButton.props.onClick()
  await nextTick()
  assert.equal(enrollmentButton.props['aria-expanded'], false)
  enrollmentButton.props.onClick()
  await nextTick()
  assert.equal(enrollmentButton.props['aria-expanded'], true)
  const recordsLink = byClass(tree, 'registrar-sidebar-children')[0].children[0].children.filter(child => child.type === 'a')[1]
  assert.equal(recordsLink.props.href, '/enrollment/records')
  await router.push('/enrollment/records')
  await nextTick()
  assert.equal(router.currentRoute.value.path, '/enrollment/records')
  assert.equal(byClass(tree, 'registrar-sidebar-children')[0].children[0].children.filter(child => child.type === 'a')[1].props['aria-current'], 'page')
  assert.match(String(enrollmentButton.props.class), /active/)
  const dashboardLink = byClass(tree, 'registrar-sidebar-entry').find(item => item.type === 'a' && item.props.href === '/registrar-dashboard')
  assert.ok(dashboardLink)
  await router.push('/registrar-dashboard')
  await nextTick()
  assert.equal(router.currentRoute.value.path, '/registrar-dashboard')
  assert.equal(dashboardLink.props['aria-current'], 'page')
  assert.equal(enrollmentButton.props['aria-expanded'], false)
  app.unmount()

  const adminTree = node('root')
  const adminApp = renderer.createApp({ setup() {
    return () => h(Rail, { modules: menu, activeModule: menu[0], registrarPresentation: false })
  } })
  adminApp.use(router)
  adminApp.mount(adminTree)
  const adminAside = byClass(adminTree, 'desktop-primary-rail')[0]
  assert.ok(adminAside)
  assert.equal(byClass(adminTree, 'desktop-registrar-sidebar').length, 0)
  assert.equal(byClass(adminTree, 'desktop-rail-action').length, 2)
  adminApp.unmount()

  const mobileTree = node('root')
  let closes = 0
  const mobileApp = renderer.createApp({ setup() {
    return () => h(Rail, { modules: menu, activeModule: menu[1], registrarPresentation: true, mobileDrawer: true, drawerOpen: true, onClose: () => { closes += 1 } })
  } })
  mobileApp.use(router)
  mobileApp.mount(mobileTree)
  const mobileAside = byClass(mobileTree, 'desktop-registrar-sidebar')[0]
  assert.equal(mobileAside.props.role, 'dialog')
  assert.equal(mobileAside.props['aria-modal'], 'true')
  byClass(mobileTree, 'registrar-sidebar-close')[0].props.onClick()
  assert.equal(closes, 1)
  mobileApp.unmount()

  const originals = { document: globalThis.document, window: globalThis.window, localStorage: globalThis.localStorage, MutationObserver: globalThis.MutationObserver }
  const stored = new Map()
  let notifyTheme
  let notifySystem
  globalThis.document = { documentElement: { dataset: { studentTheme: 'light' } } }
  globalThis.localStorage = { getItem: key => stored.get(key) ?? null, setItem: (key, value) => stored.set(key, value) }
  globalThis.MutationObserver = class {
    constructor(callback) { notifyTheme = callback }
    observe() {}
    disconnect() {}
  }
  globalThis.window = { matchMedia: () => ({ matches: false, addEventListener: (_, callback) => { notifySystem = callback }, removeEventListener() {} }) }
  const themeTree = node('root')
  const themeModules = desktopModulesFromMenu([
    { name: 'registrar-dashboard', label: 'Registrar Dashboard', path: '/registrar-dashboard' },
    { name: 'registrar-settings', label: 'Settings', path: '/registrar/settings' },
  ])
  const themeApp = renderer.createApp({ setup() {
    return () => h(Rail, { modules: themeModules, activeModule: themeModules[0], registrarPresentation: true, appearanceAccount: 41 })
  } })
  try {
    themeApp.use(router)
    themeApp.mount(themeTree)
    const shortcut = () => byClass(themeTree, 'registrar-theme-shortcut')[0]
    assert.equal(shortcut().props['aria-label'], 'Switch to Dark Mode')
    shortcut().props.onClick()
    await nextTick()
    assert.equal(globalThis.document.documentElement.dataset.studentTheme, 'dark')
    assert.equal(stored.get('cdm-registrar-appearance:41'), 'dark')
    assert.equal(shortcut().props['aria-label'], 'Switch to Light Mode')
    shortcut().props.onClick()
    await nextTick()
    assert.equal(stored.get('cdm-registrar-appearance:41'), 'light')
    globalThis.document.documentElement.dataset.studentTheme = 'system'
    notifyTheme()
    notifySystem({ matches: true })
    await nextTick()
    assert.equal(shortcut().props['aria-label'], 'Switch to Light Mode')
    shortcut().props.onClick()
    await nextTick()
    assert.equal(stored.get('cdm-registrar-appearance:41'), 'light')
  } finally {
    themeApp.unmount()
    for (const [key, value] of Object.entries(originals)) {
      if (value === undefined) delete globalThis[key]
      else globalThis[key] = value
    }
  }
})
