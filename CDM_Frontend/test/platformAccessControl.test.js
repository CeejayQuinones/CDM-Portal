import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

import { clientPlatformForMode } from '../vite.config.js'

test('Vite modes select the fixed CDM client platform', () => {
  assert.equal(clientPlatformForMode('production'), 'web')
  assert.equal(clientPlatformForMode('development'), 'web')
  assert.equal(clientPlatformForMode('desktop'), 'desktop')
  assert.equal(clientPlatformForMode('mobile'), 'mobile')
  assert.equal(clientPlatformForMode('demo-mobile'), 'mobile')
})

test('the shared API client sends the build-owned platform header', async () => {
  const source = await readFile(new URL('../src/services/apiClient.js', import.meta.url), 'utf8')

  assert.match(source, /'X-CDM-Client': clientPlatform/)
  assert.doesNotMatch(source, /localStorage\.getItem\([^)]*client/i)
})

test('login renders the safe backend platform denial message', async () => {
  const source = await readFile(new URL('../src/views/LoginView.vue', import.meta.url), 'utf8')

  assert.match(source, /error\.response\?\.data\?\.message/)
  assert.match(source, /role="alert"/)
})
