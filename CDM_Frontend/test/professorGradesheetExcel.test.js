import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const readFrontend = path => readFile(new URL(`../${path}`, import.meta.url), 'utf8')
const readBackend = path => readFile(new URL(`../../backend/${path}`, import.meta.url), 'utf8')

test('Website Professor Grade Entry exposes one guided Edit in Excel action using existing APIs', async () => {
  const [view, service] = await Promise.all([
    readFrontend('src/modules/grading/GradingView.vue'),
    readFrontend('src/modules/grading/gradingService.js'),
  ])

  assert.match(view, /isWebsitePresentation\?assessments\.value\.filter/)
  assert.match(view, /<span>Edit in Excel<\/span>/)
  assert.match(view, /class="grading-button secondary workbook-launch"/)
  assert.doesNotMatch(view, />Export Excel<\/button>/)
  assert.doesNotMatch(view, />Import Excel<\/button>/)
  assert.match(view, /modal\.value='excel-workflow'/)
  assert.match(view, /Step 1 of 5/)
  assert.match(view, /Step 2 of 5/)
  assert.match(view, /Step 3 of 5/)
  assert.match(view, /Step 4 of 5/)
  assert.match(view, /Step 5 of 5/)
  assert.match(view, /workspace\.class\.subject_code/)
  assert.match(view, /workspace\.class\.section/)
  assert.match(view, /activePeriod==='midterm'\?'Midterm':'Finals'/)
  assert.match(view, /accept="\.xlsx,application\/vnd\.openxmlformats-officedocument\.spreadsheetml\.sheet"/)
  assert.match(view, /Download Excel Gradesheet/)
  assert.match(view, /Choose or drop an Excel gradesheet/)
  assert.match(view, /Confirm & Apply Score Drafts/)
  assert.match(view, /:disabled="!canApplyWorkbook"/)
  assert.match(service, /exportWorkbook:.*responseType: 'blob'/)
  assert.match(service, /previewWorkbook:.*multipart\/form-data/)
  assert.match(service, /applyWorkbook:.*workbook\/apply/)
})

test('preview communicates blank semantics, conflicts, issues, and old-versus-new values', async () => {
  const [view, workbook] = await Promise.all([
    readFrontend('src/modules/grading/GradingView.vue'),
    readBackend('app/Services/Grading/ProfessorGradesheetWorkbookService.php'),
  ])

  for (const field of ['recognized_students', 'proposed_changes', 'unchanged_scores', 'invalid_cells', 'missing_identifiers', 'unknown_identifiers', 'conflicts']) {
    assert.match(workbook, new RegExp(`'${field}'`))
  }
  assert.match(view, /workbookPreview\.summary/)
  assert.match(view, /workbookPreview\.blocking_errors/)
  assert.match(view, /workbookPreview\.issues/)
  assert.match(view, /item\.existing\?\?'Blank'/)
  assert.match(view, /item\.imported/)
  assert.match(view, /label\.replaceAll\('_',' '\)/)
  assert.match(view, /Validation failures/)
  assert.match(view, /Score comparison/)
  assert.match(view, /Blank cells leave current scores unchanged/)
})

test('preview remains non-writing while apply uses a Website-only proposal endpoint', async () => {
  const [routes, controller, workbook, importer] = await Promise.all([
    readBackend('routes/api.php'),
    readBackend('app/Http/Controllers/Api/GradingController.php'),
    readBackend('app/Services/Grading/ProfessorGradesheetWorkbookService.php'),
    readBackend('app/Services/Grading/ProfessorGradesheetImportService.php'),
  ])

  assert.match(routes, /Route::get\('\/sheets\/\{gradeSheet\}\/workbook'/)
  assert.match(routes, /Route::post\('\/sheets\/\{gradeSheet\}\/workbook\/preview'/)
  assert.match(routes, /Route::post\('\/sheets\/\{gradeSheet\}\/workbook\/apply'/)
  assert.match(controller, /Professor Excel gradesheets are available on the Website only/)
  assert.match(workbook, /TEMPLATE_VERSION = 'CDM-PROFESSOR-GRADESHEET-1'/)
  assert.match(workbook, /hash_hmac\('sha256'/)
  assert.match(workbook, /TYPE_FORMULA/)
  assert.match(workbook, /Blank cells leave existing scores unchanged|Blank cells leave current scores unchanged|if \(\$imported === null \|\| \$imported === ''\)/)
  assert.match(workbook, /'proposal_token'/)
  assert.doesNotMatch(workbook, /GradeScore::(create|update|upsert)|->save\(\)/)
  assert.match(importer, /DB::transaction/)
  assert.match(importer, /lockForUpdate/)
  assert.match(importer, /scores\.excel_import_applied/)
  assert.match(importer, /PROPOSAL_TTL_SECONDS = 600/)
})

test('apply protects browser edits, handles expiry, prevents duplicate clicks, and refreshes authoritative data', async () => {
  const view = await readFrontend('src/modules/grading/GradingView.vue')

  assert.match(view, /workbookHasPendingBrowserChanges=computed\(\(\)=>Boolean\(dirtyScoreCount\.value\|\|editingColumns\.value\)\)/)
  assert.match(view, /Your edits have not been discarded/)
  assert.match(view, /Save or discard pending browser changes before uploading/)
  assert.match(view, /Save or discard pending browser changes before applying/)
  assert.match(view, /workbookBusy\.value=true/)
  assert.match(view, /workbookProposalExpired/)
  assert.match(view, /proposal_expires_at/)
  assert.match(view, /This import preview has expired/)
  assert.match(view, /await gradingService\.applyWorkbook/)
  assert.match(view, /await openClass\(activeClass\)/)
  assert.match(view, /workbookApplyError\.value=message/)
  assert.match(view, /Scores were not reported as saved/)
  assert.match(view, /You are about to save \$\{changes\} \$\{changes===1\?'score change':'score changes'\} as grading drafts\. This will not submit grades to the Registrar\./)
  assert.match(view, /Grades were not submitted to the Registrar/)
  assert.match(view, /openSubmissionPage/)
})

test('workflow supports accessible file selection, keyboard containment, safe close, and restart', async () => {
  const view = await readFrontend('src/modules/grading/GradingView.vue')

  assert.match(view, /role="button" tabindex="0"/)
  assert.match(view, /@keydown\.enter\.prevent="chooseWorkbook"/)
  assert.match(view, /@keydown\.space\.prevent="chooseWorkbook"/)
  assert.match(view, /@drop\.prevent="dropWorkbook"/)
  assert.match(view, /function trapWorkbookFocus/)
  assert.match(view, /event\.key==='Escape'/)
  assert.match(view, /requestAnimationFrame\(\(\)=>workbookTrigger\.value\?\.focus\(\)\)/)
  assert.match(view, /Close Edit in Excel\? The selected file and preview will be cleared\. No grades will be changed\./)
  assert.match(view, /Start again with a new workbook\?/)
  assert.match(view, /aria-live="polite"/)
})

test('Excel workflow styling uses semantic tokens and responsive horizontal review overflow', async () => {
  const css = await readFrontend('src/modules/grading/grading.css')

  assert.match(css, /\.workbook-workflow-modal \{[\s\S]*width: min\(920px, 100%\);/)
  assert.match(css, /\.workbook-progress \{[\s\S]*grid-template-columns: repeat\(5, minmax\(0, 1fr\)\);/)
  assert.match(css, /\.workbook-dropzone \{[\s\S]*border: 2px dashed var\(--border-strong\);/)
  assert.match(css, /\.workbook-preview-table-wrap \{[\s\S]*overflow: auto;/)
  assert.match(css, /\.workbook-preview-table-wrap table \{[\s\S]*min-width: 640px;/)
  assert.match(css, /\.workbook-preview-blockers \{[\s\S]*var\(--danger-bg\)/)
  assert.match(css, /\.workbook-change-status\[data-status='ready'\] \{[\s\S]*var\(--success-bg\)/)
  assert.match(css, /\.workbook-change-status\[data-status='conflict'\] \{[\s\S]*var\(--warning-bg\)/)
  assert.match(css, /@media \(max-width: 620px\) \{[\s\S]*\.workbook-workflow-modal/)
  assert.match(css, /@media \(prefers-reduced-motion: reduce\)/)
})

test('unified Excel UI remains in the Website-only Professor grade sheet branch', async () => {
  const view = await readFrontend('src/modules/grading/GradingView.vue')

  assert.match(view, /const isWebsitePresentation=clientPlatform==='web'/)
  assert.match(view, /<template v-if="isWebsitePresentation">[\s\S]*class="grading-button secondary workbook-launch"/)
  assert.match(view, /<template v-else><section class="grading-window"/)
})
