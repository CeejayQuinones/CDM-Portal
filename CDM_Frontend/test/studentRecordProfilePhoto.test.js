import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const read = path => readFile(new URL(path, import.meta.url), 'utf8')

test('Registrar Student Records and detail use the canonical current profile photo with fallback', async () => {
  const [list, detail, helper] = await Promise.all([
    read('../src/modules/student-management/StudentRecordsView.vue'),
    read('../src/modules/student-management/StudentProfileView.vue'),
    read('../src/utils/profilePhoto.js'),
  ])

  assert.match(helper, /profile\?\.profile_photo_url/)
  assert.match(helper, /apiAssetUrl/)
  assert.match(list, /profilePhotoUrl\(student\.profile\)/)
  assert.match(list, /class="record-avatar"/)
  assert.match(list, /@error="markPhotoFailed\(student\.id\)"/)
  assert.match(detail, /profilePhotoUrl\(student\.value\?\.profile\)/)
  assert.match(detail, /@error="photoFailed = true"/)
  assert.match(detail, /photo-placeholder/)
  assert.doesNotMatch(list, /student\.profile\.profile_photo(?:[^_]|$)/)
})

test('existing Registrar photo surfaces resolve the same canonical field', async () => {
  const [physical, requests] = await Promise.all([
    read('../src/modules/student-management/PhysicalRecordsView.vue'),
    read('../src/modules/document-request/RegistrarDocumentRequestView.vue'),
  ])

  assert.match(physical, /profilePhotoUrl\(student\)/)
  assert.match(requests, /profilePhotoUrl\(studentProfile\.value\)/)
  assert.doesNotMatch(`${physical}\n${requests}`, /\.profile_photo(?:[^_]|$)/)
})
