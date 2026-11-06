import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const read = path => readFile(new URL(`../${path}`, import.meta.url), 'utf8')

test('saved Enrollment documents use authenticated blob previews with visible recovery', async () => {
  const view = await read('src/modules/enrollment/EnrollmentStudent.vue')

  assert.match(view, /apiClient\.get\(document\.preview_url,\{responseType:'blob'\}\)/)
  assert.match(view, /createObjectURL/)
  assert.match(view, /revokeObjectURL/)
  assert.match(view, /@error="handlePreviewImageError\(d\)"/)
  assert.match(view, /Preview unavailable/)
  assert.match(view, /Retry preview/)
  assert.match(view, /@click\.stop="loadPreview\(d\)"/)
  assert.match(view, /preview_kind==='pdf'/)
})

test('one accessible preview surface opens add or replacement file selection', async () => {
  const view = await read('src/modules/enrollment/EnrollmentStudent.vue')

  assert.match(view, /class="enrollment-document-preview"/)
  assert.match(view, /@click="activateDocument\(d\)"/)
  assert.match(view, /fileInputs\.value\[document\.id\]\?\.click/)
  assert.match(view, /Add Document/)
  assert.match(view, /Replace Image/)
  assert.match(view, /@click\.stop="showPreview\(d\)"/)
  assert.doesNotMatch(view, /class="enrollment-document-actions"/)
})

test('preview styling supports contain scaling, hover overlay and coarse pointers', async () => {
  const css = await read('src/modules/enrollment/enrollment.css')

  assert.match(css, /\.enrollment-document-preview img[\s\S]*object-fit:contain/)
  assert.match(css, /\.enrollment-document-replace[\s\S]*transition:opacity 180ms ease/)
  assert.match(css, /:hover \.enrollment-document-replace/)
  assert.match(css, /@media \(hover:none\), \(pointer:coarse\)/)
  assert.match(css, /\.enrollment-document-mobile-edit \{ display:grid/)
  assert.match(css, /@media \(prefers-reduced-motion:reduce\)/)
})
