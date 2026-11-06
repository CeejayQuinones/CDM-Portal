import assert from 'node:assert/strict'
import { createHash } from 'node:crypto'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const source = path => readFile(new URL(`../${path}`, import.meta.url), 'utf8')

test('approved seal is the original square transparent PNG', async () => {
  const image = await readFile(new URL('../src/assets/styles/images/cdm-seal.png', import.meta.url))
  assert.equal(image.subarray(0, 8).toString('hex'), '89504e470d0a1a0a')
  assert.equal(image.readUInt32BE(16), 1254)
  assert.equal(image.readUInt32BE(20), 1254)
  assert.equal(image[25], 6) // RGBA, preserving the supplied transparency.
  assert.equal(createHash('sha256').update(image).digest('hex'), '117da29e58dc7cdcb7054072b4b09f8781ea3e99d3ccec235fa6e1f4e83d4097')
})

test('website branding selects one shared approved source without replacing nonweb branding', async () => {
  const [selector, viteConfig, seal, rail, mobile, login, legal, registration, sidebar] = await Promise.all([
    source('src/config/portalSeal.js'),
    source('vite.config.js'),
    source('src/components/website/CDMSeal.vue'),
    source('src/components/website/WebsiteNavigationRail.vue'),
    source('src/components/website/WebsiteMobileNavigation.vue'),
    source('src/views/LoginView.vue'),
    source('src/views/PublicLegalView.vue'),
    source('src/components/RegistrationWizard.vue'),
    source('src/components/Sidebar.vue'),
  ])
  assert.match(selector, /import sealUrl from '@portal-seal'/)
  assert.match(viteConfig, /clientPlatformForMode\(mode\) === 'web'/)
  assert.match(viteConfig, /cdm-seal\.png/)
  assert.match(viteConfig, /cdm_logo\.png/)
  assert.match(seal, /Colegio de Montalban seal/)
  assert.match(seal, /aspect-ratio: 1/)
  assert.match(seal, /object-fit: contain/)
  assert.match(rail, /<CDMSeal class="rail-seal"/)
  assert.match(rail, /\.rail-seal \{ width: 42px; height: 42px/)
  assert.match(rail, /\.rail-modules \{[^}]*margin-top: 12px/)
  assert.match(mobile, /<CDMSeal class="mobile-seal"/)
  assert.match(login, /portalSealUrl as logoUrl/)
  assert.match(legal, /portalSealUrl as logoUrl/)
  assert.match(registration, /v-if="clientPlatform === 'web'" class="wizard-seal"/)
  assert.match(sidebar, /cdm_logo\.png/)
})
