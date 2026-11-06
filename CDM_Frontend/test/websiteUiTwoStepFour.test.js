import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { parse, compileScript, compileStyle } from '@vue/compiler-sfc'

test('search Escape closes its own morph and restores trigger focus', async () => {
  const source = await readFile(new URL('../src/components/website/WebsiteTopbar.vue', import.meta.url), 'utf8')
  const { descriptor } = parse(source)
  assert.doesNotThrow(() => compileScript(descriptor, { id: 'topbar', inlineTemplate: true }))
  assert.match(source, /event\.key === 'Escape'[\s\S]*closeSearch\(true\)/)
  assert.match(source, /if \(restoreFocus\) nextTick\(\(\) => searchTrigger\.value\?\.focus\(\)\)/)
  assert.match(source, /document\.addEventListener\('pointerdown', handleOutsidePointer\)/)
  assert.match(source, /document\.removeEventListener\('pointerdown', handleOutsidePointer\)/)
})

test('official print overrides stay inside website print media', async () => {
  const source = await readFile(new URL('../src/assets/styles/website-academic-modules.css', import.meta.url), 'utf8')
  const result = compileStyle({ source, filename: 'website-academic-modules.css', id: 'print' })
  assert.deepEqual(result.errors, [])
  let count = 0
  result.rawResult.root.walkAtRules('media', media => {
    if (media.params !== 'print') return
    media.walkRules(rule => {
      assert.ok(rule.selector.startsWith('html.website-ui-2-client'))
      count++
    })
  })
  assert.ok(count >= 4)
})
