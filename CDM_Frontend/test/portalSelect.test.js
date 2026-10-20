import assert from 'node:assert/strict'
import { createRequire } from 'node:module'
import { readFile } from 'node:fs/promises'
import { fileURLToPath } from 'node:url'
import test from 'node:test'
import { build } from 'esbuild'
import { compileScript, parse } from '@vue/compiler-sfc'

const root = fileURLToPath(new URL('../', import.meta.url))

const compilePortalSelect = async () => {
  const bundle = await build({
    absWorkingDir: root,
    bundle: true,
    write: false,
    platform: 'node',
    format: 'cjs',
    stdin: {
      resolveDir: root,
      contents: `
        export { createRenderer, h, nextTick, ref } from 'vue'
        export { default as PortalSelect } from './src/components/PortalSelect.vue'
      `,
    },
    plugins: [{
      name: 'portal-select-component',
      setup(builder) {
        builder.onLoad({ filter: /PortalSelect\.vue$/ }, async ({ path }) => {
          const { descriptor } = parse(await readFile(path, 'utf8'), { filename: path })
          return { contents: compileScript(descriptor, { id: path, inlineTemplate: true }).content, loader: 'js' }
        })
      },
    }],
  })
  const compiled = { exports: {} }
  new Function('require', 'module', 'exports', bundle.outputFiles[0].text)(createRequire(import.meta.url), compiled, compiled.exports)
  return compiled.exports
}

test('PortalSelect closes once outside, supports keyboard selection, and cleans up its listener', async () => {
  const listeners = new Map()
  let addCount = 0
  let removeCount = 0
  let focused = null
  const fakeDocument = {
    addEventListener(type, listener, capture) {
      listeners.set(type, { listener, capture })
      addCount += 1
    },
    removeEventListener(type, listener, capture) {
      const registered = listeners.get(type)
      assert.equal(registered?.listener, listener)
      assert.equal(registered?.capture, capture)
      listeners.delete(type)
      removeCount += 1
    },
  }

  const { createRenderer, h, nextTick, ref, PortalSelect } = await compilePortalSelect()
  globalThis.document = fakeDocument
  const makeNode = (type, text = '') => ({
    type,
    text,
    props: {},
    children: [],
    parent: null,
    contains(target) {
      for (let node = target; node; node = node.parent) if (node === this) return true
      return false
    },
    focus() { focused = this },
  })
  const detach = child => {
    if (child.parent) child.parent.children.splice(child.parent.children.indexOf(child), 1)
  }
  const renderer = createRenderer({
    createElement: makeNode,
    createText: text => makeNode('#text', text),
    createComment: text => makeNode('#comment', text),
    setText: (target, text) => { target.text = text },
    setElementText: (target, text) => { target.text = text; target.children = [] },
    patchProp: (target, key, _previous, value) => { target.props[key] = value },
    insert: (child, parent, anchor = null) => {
      detach(child)
      parent.children.splice(anchor ? parent.children.indexOf(anchor) : parent.children.length, 0, child)
      child.parent = parent
    },
    remove: detach,
    parentNode: target => target.parent,
    nextSibling: target => target.parent?.children[target.parent.children.indexOf(target) + 1],
  })
  const find = (node, predicate) => predicate(node) ? node : node.children.map(child => find(child, predicate)).find(Boolean)
  const findAll = (node, predicate) => [
    ...(predicate(node) ? [node] : []),
    ...node.children.flatMap(child => findAll(child, predicate)),
  ]
  const keyEvent = key => ({ key, preventDefault() {} })
  const model = ref('A')
  const tree = makeNode('root')
  const app = renderer.createApp({
    setup: () => () => h(PortalSelect, {
      modelValue: model.value,
      options: ['A', 'B', 'C'],
      ariaLabel: 'Correct answer',
      'onUpdate:modelValue': value => { model.value = value },
    }),
  })

  app.mount(tree)
  await nextTick()
  assert.equal(addCount, 0)

  let trigger = find(tree, node => node.props.role === 'combobox')
  assert.equal(trigger.props['aria-expanded'], false)
  trigger.props.onClick()
  await nextTick()
  trigger = find(tree, node => node.props.role === 'combobox')
  assert.equal(addCount, 1)
  assert.equal(listeners.get('pointerdown').capture, true)
  assert.equal(trigger.props['aria-expanded'], true)
  assert.equal(findAll(tree, node => node.props.role === 'listbox').length, 1)

  const external = makeNode('button')
  const outsideListener = listeners.get('pointerdown').listener
  outsideListener({ target: external })
  await nextTick()
  assert.equal(find(tree, node => node.props.role === 'combobox').props['aria-expanded'], false)
  assert.equal(listeners.has('pointerdown'), false)
  outsideListener({ target: external })
  await nextTick()
  assert.equal(findAll(tree, node => node.props.role === 'listbox').length, 0, 'a second outside event must not reopen the menu')

  trigger = find(tree, node => node.props.role === 'combobox')
  trigger.props.onKeydown(keyEvent('ArrowDown'))
  await nextTick()
  trigger.props.onKeydown(keyEvent('ArrowDown'))
  trigger.props.onKeydown(keyEvent('Enter'))
  await nextTick()
  assert.equal(model.value, 'B')
  assert.equal(findAll(tree, node => node.props.role === 'listbox').length, 0)
  assert.equal(focused.props.role, 'combobox')

  trigger = find(tree, node => node.props.role === 'combobox')
  trigger.props.onClick()
  await nextTick()
  trigger.props.onKeydown(keyEvent('Escape'))
  await nextTick()
  assert.equal(findAll(tree, node => node.props.role === 'listbox').length, 0)

  trigger = find(tree, node => node.props.role === 'combobox')
  trigger.props.onClick()
  await nextTick()
  assert.equal(listeners.has('pointerdown'), true)
  app.unmount()
  assert.equal(removeCount, addCount)
  assert.equal(listeners.has('pointerdown'), false)
})

test('PortalSelect exposes disabled, error, ARIA, and theme-token styling', async () => {
  const source = await readFile(new URL('../src/components/PortalSelect.vue', import.meta.url), 'utf8')

  assert.match(source, /role="combobox"/)
  assert.match(source, /role="listbox"/)
  assert.match(source, /role="option"/)
  assert.match(source, /:aria-invalid="error \? 'true' : undefined"/)
  assert.match(source, /:disabled="disabled"/)
  assert.match(source, /var\(--bg-input/)
  assert.match(source, /var\(--text-primary/)
  assert.match(source, /@media \(prefers-reduced-motion: reduce\)/)
})
