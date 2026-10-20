import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'
import { compileStyle, parse } from '@vue/compiler-sfc'

const source = path => readFile(new URL(`../${path}`, import.meta.url), 'utf8')

test('dark mode uses a solid near-black page and a shared CSS-only grid', async () => {
  const [backdrop, theme, layout] = await Promise.all([
    source('src/components/PortalAnimatedBackdrop.vue'),
    source('src/assets/styles/student-theme.css'),
    source('src/layouts/DashboardLayout.vue'),
  ])

  assert.match(theme, /html\[data-student-theme='dark'\][\s\S]*?--bg-page: #070a08/)
  assert.match(backdrop, /data-student-theme='dark'[^}]*background: #070a08/)
  assert.match(backdrop, /background-image:[\s\S]*?linear-gradient\(rgb\(20 130 80 \/ 9%\) 1px, transparent 1px\),[\s\S]*?linear-gradient\(90deg, rgb\(20 130 80 \/ 9%\) 1px, transparent 1px\)/)
  assert.match(backdrop, /background-size: 64px 64px/)
  assert.match(backdrop, /\.portal-animated-backdrop\s*\{[\s\S]*?pointer-events: none/)
  assert.equal(layout.match(/<PortalAnimatedBackdrop/g)?.length, 1)
  assert.doesNotMatch(backdrop, /url\([^)]*\.(?:png|jpe?g|webp|svg)/i)
  assert.doesNotMatch(backdrop, /data-student-theme='dark'[^}]*radial-gradient/)
})

test('dark surfaces remain opaque and semantic while light presentation stays intact', async () => {
  const [backdrop, theme, main] = await Promise.all([
    source('src/components/PortalAnimatedBackdrop.vue'),
    source('src/assets/styles/student-theme.css'),
    source('src/assets/styles/main.css'),
  ])

  for (const declaration of [
    '--bg-surface: #0d120f',
    '--bg-surface-alt: #121915',
    '--bg-surface-raised: #17201a',
    '--bg-input: #111814',
    '--bg-header: rgb(13 18 15 / 98%)',
  ]) assert.match(theme, new RegExp(declaration.replace(/[()]/g, '\\$&')))

  assert.match(theme, /background-color: rgb\(0 0 0 \/ 70%\)/)
  assert.match(backdrop, /linear-gradient\(145deg, #fbf8ed/)
  assert.match(backdrop, /\.portal-decoration-cluster\.is-visible[\s\S]*?animation: portal-decoration-pop/)
  assert.match(main, /:root \{[\s\S]*?--bg-page: #f8f7f1/)
  assert.match(theme, /--success:[\s\S]*?--warning:[\s\S]*?--info:/)
})

test('dark grid is static and existing reduced-motion safeguards remain active', async () => {
  const backdrop = await source('src/components/PortalAnimatedBackdrop.vue')

  const darkGridRule = backdrop.match(/:global\(html\[data-student-theme='dark'\] \.portal-animated-backdrop::before\) \{([\s\S]*?)\n\}/)?.[1] || ''
  assert.doesNotMatch(darkGridRule, /animation|transition/)
  assert.match(darkGridRule, /transform: none/)
  assert.match(backdrop, /@media \(prefers-reduced-motion: reduce\)[\s\S]*?animation: none/)
  assert.match(backdrop, /@media \(max-width: 700px\)/)
  assert.match(backdrop, /overflow: hidden/)
})

test('compiled theme selectors target only the shared backdrop and decorations', async () => {
  const filename = 'src/components/PortalAnimatedBackdrop.vue'
  const backdrop = await source(filename)
  const descriptor = parse(backdrop, { filename }).descriptor
  const compiled = compileStyle({
    source: descriptor.styles[0].content,
    filename,
    id: 'data-v-dark-grid-test',
    scoped: true,
  })

  assert.deepEqual(compiled.errors, [])
  assert.match(compiled.code, /html\[data-student-theme='dark'\] \.portal-animated-backdrop::after/)
  assert.match(compiled.code, /html\[data-student-theme='system'\] \.portal-decoration-cluster/)
  assert.doesNotMatch(compiled.code, /html\[data-student-theme='(?:dark|system)'\](?:,html\[data-student-theme='(?:dark|system)'\])?\s*\{[^}]*display:\s*none/)
})
