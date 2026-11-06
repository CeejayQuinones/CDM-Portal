import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'
import { compileScript, parse } from '@vue/compiler-sfc'

const source = file => readFile(new URL(`../src/${file}`, import.meta.url), 'utf8')
const components = ['CdmButton','CdmCard','CdmInput','CdmSelect','CdmTextarea','CdmBadge','CdmTable','CdmDialog','CdmPageHeader','CdmEmptyState','CdmLoadingState']

test('Design System 5 components compile and inherit centralized semantic tokens', async () => {
  const [tokens, main, ...files] = await Promise.all([
    source('design-system/tokens.css'), source('main.js'),
    ...components.map(name => source(`design-system/${name}.vue`)),
  ])
  for (const [index, contents] of files.entries()) {
    const filename = `${components[index]}.vue`
    const { descriptor, errors } = parse(contents, { filename })
    assert.deepEqual(errors, [], `${filename} parse errors`)
    compileScript(descriptor, { id: filename, inlineTemplate: true })
    assert.match(contents, /--cdm-/, `${filename} must consume semantic tokens`)
  }
  for (const token of ['--cdm-canvas','--cdm-surface','--cdm-border','--cdm-text','--cdm-primary','--cdm-success','--cdm-warning','--cdm-danger','--cdm-focus','--cdm-radius-md','--cdm-shadow-sm']) assert.match(tokens, new RegExp(token))
  assert.match(tokens, /data-student-theme='dark'/)
  assert.match(tokens, /prefers-color-scheme: dark/)
  assert.match(main, /design-system\/tokens\.css/)
})

test('shared controls expose accessible states without global element overrides', async () => {
  const [button, input, table, dialog, tokens] = await Promise.all([
    source('design-system/CdmButton.vue'), source('design-system/CdmInput.vue'),
    source('design-system/CdmTable.vue'), source('design-system/CdmDialog.vue'),
    source('design-system/tokens.css'),
  ])
  assert.match(button, /aria-busy/)
  assert.match(button, /cdm-button--danger/)
  assert.match(input, /aria-invalid/)
  assert.match(table, /role="region"/)
  assert.match(table, /tabindex="0"/)
  assert.match(dialog, /aria-modal="true"/)
  assert.match(dialog, /event\.key==='Escape'/)
  assert.match(dialog, /trigger\.focus/)
  assert.doesNotMatch(tokens, /^\s*(button|input|table|body)\s*\{/m)
})

test('representative Website, Electron, and Android-capable modules adopt shared primitives', async () => {
  const [student, guest, professor, registrar, admin, access] = await Promise.all([
    source('modules/enrollment/EnrollmentCourses.vue'),
    source('modules/dashboard/GuestWebsiteDashboard.vue'),
    source('modules/enrollment/EnrollmentProfessor.vue'),
    source('modules/campus-notices/CampusNoticesView.vue'),
    source('modules/account-management/AccountProvisioningView.vue'),
    source('config/accessControl.js'),
  ])
  assert.match(student, /CdmCard/); assert.match(student, /CdmPageHeader/)
  assert.match(guest, /CdmCard/); assert.match(guest, /CdmButton/)
  for (const component of ['CdmPageHeader','CdmCard','CdmTable','CdmButton','CdmEmptyState']) assert.match(professor, new RegExp(component))
  for (const component of ['CdmPageHeader','CdmInput','CdmSelect','CdmTextarea','CdmBadge']) assert.match(registrar, new RegExp(component))
  for (const component of ['CdmPageHeader','CdmDialog','CdmBadge','CdmButton']) assert.match(admin, new RegExp(component))
  assert.match(access, /'enrollment-teaching'/)
  assert.doesNotMatch(professor, /clientPlatform==='web'/)
})
