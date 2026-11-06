import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'
import { compileScript, parse } from '@vue/compiler-sfc'

const source = file => readFile(new URL(`../src/${file}`, import.meta.url), 'utf8')

test('desktop Campus Notices compiles as one accessible composer workspace', async () => {
  const view = await source('modules/campus-notices/CampusNoticesView.vue')
  const { descriptor, errors } = parse(view, { filename: 'CampusNoticesView.vue' })

  assert.deepEqual(errors, [])
  compileScript(descriptor, { id: 'campus-notices-desktop', inlineTemplate: true })
  assert.match(view, /desktop-notice-command/)
  assert.match(view, /notice-command-card/)
  assert.match(view, /notice-composer-layout/)
  assert.match(view, /aria-label="Live notice preview"/)
  assert.match(view, /aria-label="Notice audience"/)
  assert.match(view, /aria-label="Start a new notice"/)
  assert.match(view, /aria-label="Refresh notice history"/)
  assert.match(view, /:value="item">\{\{ label\(item\) \}\}<\/option>/)
  assert.match(view, /form\?\.reportValidity\(\)/)
})

test('desktop Campus Notices preserves its server-backed publishing contract', async () => {
  const [view, service] = await Promise.all([
    source('modules/campus-notices/CampusNoticesView.vue'),
    source('services/campusNoticeService.js'),
  ])

  for (const field of [
    'publication_start_at',
    'publication_end_at',
    'effective_start_at',
    'effective_end_at',
    'recurrence_weekdays',
    'requires_acknowledgement',
    'official_source',
    'affected_scope',
    'target_route',
  ]) assert.match(view, new RegExp(field))

  assert.match(view, /saveCampusNotice\(payload\(\), editingId\.value\)/)
  assert.match(view, /publishCampusNotice\(saved\.id\)/)
  assert.match(view, /archiveCampusNotice\(notice\.id\)/)
  assert.match(view, /Weather forecasts cannot publish suspensions/)
  assert.match(service, /apiClient\.post\('\/campus-notices'/)
  assert.match(service, /apiClient\.put\(`\/campus-notices\/\$\{id\}`/)
  assert.match(service, /apiClient\.post\(`\/campus-notices\/\$\{id\}\/publish`\)/)
  assert.match(service, /apiClient\.post\(`\/campus-notices\/\$\{id\}\/archive`\)/)
})
