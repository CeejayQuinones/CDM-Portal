import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const read = path => readFile(new URL(`../${path}`, import.meta.url), 'utf8')

test('workspace refresh stages authoritative data before replacing live editing state', async () => {
  const view = await read('src/modules/grading/GradingView.vue')
  const block = view.match(/async function openClass\(item\)\{[^]*?\nasync function backToClasses/)?.[0] || ''

  assert.match(block, /const nextWorkspace=await gradingService\.workspace\(item\.id\),nextReadiness=await gradingService\.readiness\(nextWorkspace\.sheet\.id\)/)
  assert.ok(block.indexOf('nextReadiness=await') < block.indexOf('workspace.value=nextWorkspace'))
  assert.match(block, /workspace\.value=nextWorkspace;readiness\.value=nextReadiness/)
  assert.match(block, /return true/)
  assert.match(block, /catch\(e\)\{error\.value=gradingError\(e\);return false\}/)
})

test('workspace-refreshing assessment and criteria actions cannot discard score drafts', async () => {
  const view = await read('src/modules/grading/GradingView.vue')

  assert.match(view, /function openAssessment\(item=null\)\{if\(isWebsitePresentation&&dirtyScoreCount\.value\)\{error\.value='Save or discard pending score changes before adding an assessment\.'/)
  assert.match(view, /function openBulkAssessments\(\)\{if\(isWebsitePresentation&&dirtyScoreCount\.value\)\{error\.value='Save or discard pending score changes before adding assessments in bulk\.'/)
  assert.match(view, /async function saveWeights\(\)\{if\(isWebsitePresentation&&dirtyScoreCount\.value\)\{error\.value='Save or discard pending score changes before saving grading criteria\.'/)
  assert.match(view, /:disabled="!editable\|\|recoveryPending\|\|dirtyScoreCount" @click="openAssessment\(\)"/)
  assert.match(view, /:disabled="!editable\|\|recoveryPending\|\|dirtyScoreCount" @click="openBulkAssessments"/)
  assert.match(view, /:disabled="saving\|\|!editable\|\|dirtyScoreCount" @click="saveWeights"/)
})

test('column editing is protected across class, route, and submission navigation', async () => {
  const view = await read('src/modules/grading/GradingView.vue')

  assert.match(view, /async function backToClasses\(\)\{if\(editingColumns\.value\)\{error\.value='Save or cancel column changes before returning to My Classes\.'/)
  assert.match(view, /function openSubmissionPage\(\)\{if\(!workspace\.value\)return;if\(editingColumns\.value\)\{error\.value='Save or cancel column changes before opening Grade Submission\.'/)
  assert.match(view, /onBeforeRouteLeave\(\(\)=>\{[^]*if\(isWebsitePresentation&&editingColumns\.value\)\{error\.value='Save or cancel column changes before leaving Grade Entry\.';return false\}/)
  assert.match(view, /dirtyScoreCount\.value\|\|columnLabelChanges\.value\.length/)
})

test('Excel Apply and direct save report refresh failure without undoing confirmed server success', async () => {
  const view = await read('src/modules/grading/GradingView.vue')

  assert.match(view, /const refreshed=await openClass\(activeClass\);if\(!refreshed\)\{workbookBusy\.value=false/)
  assert.match(view, /Scores were saved, but Grade Entry could not refresh\. Reload the class before continuing\./)
  assert.match(view, /const recoveryScope=currentRecoveryScope\(\),activeClass=workspace\.value\.class/)
  assert.match(view, /notice\.value=`\$\{savedCount\}[^`]+saved\.`;const refreshed=await openClass\(activeClass\);if\(!refreshed\)error\.value=/)
})
