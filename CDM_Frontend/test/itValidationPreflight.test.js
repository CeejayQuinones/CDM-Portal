import test from 'node:test'
import assert from 'node:assert/strict'
import { validateItValidationTargets } from '../scripts/it-validation-preflight.mjs'

const testTargets = {
  IT_VALIDATION_WEB_URL: 'https://it-validation.cdm.example.edu',
  IT_VALIDATION_API_URL: 'https://api-it-validation.cdm.example.edu',
  IT_VALIDATION_DB_DATABASE: 'cdm_it_validation',
  VITE_API_BASE_URL: 'https://api-it-validation.cdm.example.edu/api',
  VITE_DESKTOP_API_BASE_URL: 'https://api-it-validation.cdm.example.edu/api',
}

test('IT validation accepts matching separate test targets for each client', () => {
  for (const platform of ['web', 'mobile', 'desktop']) {
    assert.equal(validateItValidationTargets(testTargets, platform).database, 'cdm_it_validation')
  }
})

test('IT validation rejects the existing Website, API, and database', () => {
  for (const changes of [
    { IT_VALIDATION_WEB_URL: 'https://cdm-portal.pages.dev' },
    { IT_VALIDATION_API_URL: 'https://api.cdmportal.xyz' },
    { IT_VALIDATION_DB_DATABASE: 'cdm_portal' },
  ]) {
    assert.throws(() => validateItValidationTargets({ ...testTargets, ...changes }), /must not use|separate test database/)
  }
})

test('IT validation rejects a client pointed at a different API', () => {
  assert.throws(() => validateItValidationTargets({ ...testTargets, VITE_API_BASE_URL: 'https://api.cdmportal.xyz/api' }), /must equal the test API/)
  assert.throws(() => validateItValidationTargets({ ...testTargets, VITE_DESKTOP_API_BASE_URL: 'https://api.cdmportal.xyz/api' }, 'desktop'), /must equal the test API/)
})

test('IT validation rejects placeholders and missing configuration', () => {
  assert.throws(() => validateItValidationTargets({ ...testTargets, IT_VALIDATION_API_URL: 'https://api.example.invalid' }), /non-placeholder test hostname/)
  assert.throws(() => validateItValidationTargets({}, 'web'), /required/)
})
