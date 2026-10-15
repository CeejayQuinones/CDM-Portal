import assert from 'node:assert/strict'
import test from 'node:test'
import { resolveApiAssetUrl } from '../src/utils/apiAssetUrl.js'

test('avatar storage paths use the configured API origin rather than the frontend origin', () => {
  const page = 'https://portal.example.test/#/settings'
  for (const base of ['https://api.example.test:8443/api', 'https://api.example.test:8443/api/']) {
    assert.equal(resolveApiAssetUrl('/storage/student-avatars/photo.png', base, page), 'https://api.example.test:8443/storage/student-avatars/photo.png')
  }
  assert.equal(resolveApiAssetUrl('/storage/student-avatars/photo.png', '/api', page), 'https://portal.example.test/storage/student-avatars/photo.png')
})

test('avatar resolver supports absolute images and empty or invalid paths without a broken img source', () => {
  const page = 'https://portal.example.test/#/settings'
  assert.equal(resolveApiAssetUrl('https://cdn.example.test/photo.png', '/api', page), 'https://cdn.example.test/photo.png')
  for (const value of [null, undefined, '', 'http://[', 'javascript:alert(1)']) {
    assert.equal(resolveApiAssetUrl(value, '/api', page), '')
  }
})
