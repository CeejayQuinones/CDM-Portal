import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const source = path => readFile(new URL(`../${path}`, import.meta.url), 'utf8')

test('shared portal backdrop renders distributed institute decorations accessibly', async () => {
  const backdrop = await source('src/components/PortalAnimatedBackdrop.vue')

  assert.equal(backdrop.match(/class="portal-decoration-cluster/g)?.length, 4)
  assert.ok((backdrop.match(/aria-hidden="true"/g)?.length || 0) >= 5)
  assert.match(backdrop, /cluster-ics parallax-far/)
  assert.match(backdrop, /cluster-ite parallax-mid/)
  assert.match(backdrop, /cluster-ibe parallax-near/)
  assert.match(backdrop, /cluster-campus parallax-mid/)
  assert.match(backdrop, /\.cluster-ite \{ top: 96vh/)
  assert.match(backdrop, /\.cluster-ibe \{ top: 148vh/)
  assert.match(backdrop, /\.cluster-campus \{ top: 218vh/)
})

test('shared decorations use one observer and one passive window scroll lifecycle', async () => {
  const backdrop = await source('src/components/PortalAnimatedBackdrop.vue')

  assert.equal(backdrop.match(/new IntersectionObserver/g)?.length, 1)
  assert.equal(backdrop.match(/addEventListener\('scroll'/g)?.length, 1)
  assert.equal(backdrop.match(/removeEventListener\('scroll'/g)?.length, 1)
  assert.match(backdrop, /classList\.add\('is-visible'\)/)
  assert.match(backdrop, /requestAnimationFrame\(updateParallax\)/)
  assert.match(backdrop, /addEventListener\('scroll', queueParallax, \{ passive: true \}\)/)
  assert.match(backdrop, /speeds = mobile \? \[0\.008, 0\.014, 0\.02\] : \[0\.02, 0\.04, 0\.06\]/)
  assert.match(backdrop, /decorationObserver\?\.disconnect\(\)/)
  assert.match(backdrop, /cancelAnimationFrame\(parallaxFrame\)/)
})

test('shared decorative CSS preserves safe motion, role restraint, and mobile limits', async () => {
  const backdrop = await source('src/components/PortalAnimatedBackdrop.vue')

  assert.match(backdrop, /\.portal-animated-backdrop\s*\{[\s\S]*?pointer-events: none/)
  assert.match(backdrop, /\.portal-decoration-icon\s*\{[\s\S]*?opacity: 0;[\s\S]*?translate3d\(0, 1\.5rem, 0\) scale\(0\.9\)/)
  assert.match(backdrop, /@keyframes portal-decoration-pop[\s\S]*?scale\(1\.03\)/)
  assert.match(backdrop, /\.portal-animated-backdrop\.is-professor/)
  assert.match(backdrop, /\.portal-animated-backdrop\.is-staff/)
  assert.match(backdrop, /@media \(prefers-reduced-motion: reduce\)[\s\S]*?\.portal-decoration-cluster[\s\S]*?transform: none/)
  assert.match(backdrop, /@media \(max-width: 700px\)[\s\S]*?\.cluster-ibe,[\s\S]*?\.cluster-campus \{ display: none/)
  assert.match(backdrop, /overflow: hidden/)
})
