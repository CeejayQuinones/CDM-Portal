<script setup>
import { computed, nextTick, onBeforeUnmount, ref } from 'vue'
import { apiClient } from '../../services/apiClient'
import { clientPlatform } from '../../config/clientPlatform'
import EnrollmentDialog from './EnrollmentDialog.vue'

const props = defineProps({
  enrollmentId: { type: [Number, String], required: true },
  studentName: { type: String, default: 'Student' },
  academicTerm: { type: String, default: 'Finalized academic term' },
})

const open = ref(false)
const busy = ref(false)
const error = ref('')
const previewUrl = ref('')
const previewBlob = ref(null)
const previewFilename = ref('official-verification-registration-form.pdf')
const frame = ref(null)
const printWhenReady = ref(false)
const previewSource = computed(() => previewUrl.value ? `${previewUrl.value}#view=FitH&zoom=page-width&toolbar=1` : '')

function filename(response) {
  return response.headers?.['content-disposition']?.match(/filename="?([^";]+)"?/)?.[1] || 'official-verification-registration-form.pdf'
}

async function responseError(reason) {
  if (reason?.response?.data instanceof Blob) {
    try {
      const payload = JSON.parse(await reason.response.data.text())
      if (payload?.message) return payload.message
    } catch {
      // Use the safe fallback below when an error body is not JSON.
    }
  }
  return reason?.response?.data?.message || 'Unable to generate the OVRF. Reload the enrollment record and try again.'
}

function replacePreview(blob) {
  if (previewUrl.value) URL.revokeObjectURL(previewUrl.value)
  previewBlob.value = blob
  previewUrl.value = URL.createObjectURL(blob)
}

async function mobileDocumentAction(blob, name, action) {
  const [{ Filesystem, Directory }, { Share }] = await Promise.all([
    import('@capacitor/filesystem'),
    import('@capacitor/share'),
  ])
  const base64 = await new Promise((resolve, reject) => {
    const reader = new FileReader()
    reader.onerror = () => reject(reader.error || new Error('Unable to read the generated OVRF.'))
    reader.onload = () => resolve(String(reader.result).split(',')[1] || '')
    reader.readAsDataURL(blob)
  })
  const saved = await Filesystem.writeFile({ path: name, data: base64, directory: Directory.Cache, recursive: true })
  await Share.share({
    title: `Official Verification / Validated Registration Form — ${props.studentName}`,
    text: action === 'print'
      ? 'Open the OVRF with a PDF viewer or print service. Physical Registrar verification, signatures, and stamping are still required.'
      : 'Save or share this generated OVRF. Physical Registrar verification, signatures, and stamping are still required.',
    url: saved.uri,
    dialogTitle: action === 'print' ? 'Print or open OVRF' : 'Save or share OVRF',
  })
}

async function loadPreview({ print = false } = {}) {
  open.value = true
  error.value = ''
  printWhenReady.value = print
  if (previewUrl.value) {
    if (print) await printPreview()
    return
  }
  busy.value = true
  try {
    const response = await apiClient.get(`/enrollment/academic/records/${props.enrollmentId}/ovrf`, {
      params: { disposition: 'inline' },
      responseType: 'blob',
    })
    previewFilename.value = filename(response)
    replacePreview(response.data)
    await nextTick()
    if (print && clientPlatform === 'mobile') await printPreview()
  } catch (reason) {
    error.value = await responseError(reason)
    printWhenReady.value = false
  } finally {
    busy.value = false
  }
}

async function download() {
  busy.value = true
  error.value = ''
  try {
    const response = await apiClient.get(`/enrollment/academic/records/${props.enrollmentId}/ovrf`, {
      params: { disposition: 'attachment' },
      responseType: 'blob',
    })
    if (clientPlatform === 'mobile') {
      await mobileDocumentAction(response.data, filename(response), 'download')
      return
    }
    const url = URL.createObjectURL(response.data)
    const anchor = document.createElement('a')
    anchor.href = url
    anchor.download = filename(response)
    document.body.appendChild(anchor)
    anchor.click()
    anchor.remove()
    URL.revokeObjectURL(url)
  } catch (reason) {
    open.value = true
    error.value = await responseError(reason)
  } finally {
    busy.value = false
  }
}

async function printPreview() {
  if (clientPlatform === 'mobile' && previewBlob.value) {
    try {
      busy.value = true
      await mobileDocumentAction(previewBlob.value, previewFilename.value, 'print')
    } catch (reason) {
      error.value = await responseError(reason)
    } finally {
      busy.value = false
      printWhenReady.value = false
    }
    return
  }
  await nextTick()
  const target = frame.value?.contentWindow
  if (target?.print) {
    target.focus?.()
    target.print()
    printWhenReady.value = false
  }
}

function onPreviewLoaded() {
  if (printWhenReady.value) printPreview()
}

function close() {
  open.value = false
  error.value = ''
  printWhenReady.value = false
}

onBeforeUnmount(() => {
  if (previewUrl.value) URL.revokeObjectURL(previewUrl.value)
})
</script>

<template>
  <div class="en-ovrf-actions en-no-print" aria-label="Official Verification Registration Form actions">
    <button type="button" class="en-modern-outline" :disabled="busy" @click="loadPreview()">Preview OVRF</button>
    <button type="button" class="en-modern-outline" :disabled="busy" @click="download">Download OVRF</button>
    <button type="button" class="en-modern-primary" :disabled="busy" @click="loadPreview({ print: true })">Print OVRF</button>
  </div>

  <EnrollmentDialog v-if="open" class="en-ovrf-overlay" labelledby="en-ovrf-title" :busy="busy" wide @cancel="close">
    <section class="en-ovrf-dialog" :class="{ 'has-preview': previewUrl && !busy && !error, compact: !previewUrl || busy || error }">
      <header>
        <div>
          <p>Official enrollment document</p>
          <h2 id="en-ovrf-title">OVRF preview</h2>
          <span>{{ studentName }} · {{ academicTerm }}</span>
        </div>
        <button type="button" aria-label="Close OVRF preview" :disabled="busy" @click="close">×</button>
      </header>
      <div class="en-ovrf-dialog-body">
        <p class="en-ovrf-validation-note"><strong>Physical verification required.</strong> Downloading or printing does not validate this form. The Registrar's Office must physically verify, sign, and stamp it.</p>
        <p v-if="busy" class="en-ovrf-state" role="status">Generating the authoritative OVRF…</p>
        <div v-else-if="error" class="en-ovrf-state en-ovrf-error" role="alert">
          <p>{{ error }}</p>
          <button type="button" class="en-modern-outline" @click="loadPreview({ print: printWhenReady })">Try again</button>
        </div>
        <div v-else-if="previewUrl" class="en-ovrf-document">
          <iframe ref="frame" class="en-ovrf-preview" :src="previewSource" title="Official Verification Registration Form PDF preview" @load="onPreviewLoaded" />
          <button v-if="clientPlatform === 'mobile'" type="button" class="en-ovrf-open-link" :disabled="busy" @click="printPreview">Open PDF in the device viewer</button>
          <a v-else class="en-ovrf-open-link" :href="previewSource" target="_blank" rel="noopener">Open PDF preview in a new window</a>
        </div>
      </div>
      <footer>
        <button type="button" class="en-modern-outline" :disabled="busy" @click="close">Close</button>
        <button type="button" class="en-modern-outline" :disabled="busy" @click="download">Download OVRF</button>
        <button type="button" class="en-modern-primary" :disabled="busy || !previewUrl" @click="printPreview">Print OVRF</button>
      </footer>
    </section>
  </EnrollmentDialog>
</template>

<style scoped>
.en-ovrf-actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; }
.en-ovrf-actions button { min-height: 40px; }
:global(.enrollment-workspace .en-overlay.en-ovrf-overlay > .en-dialog) { width: min(960px, calc(100vw - 32px)); max-height: calc(100dvh - 32px); overflow: hidden; padding: 0; }
.en-ovrf-dialog { display: grid; grid-template-rows: auto minmax(0, 1fr) auto; width: 100%; max-width: 100%; max-height: calc(100dvh - 32px); overflow: hidden; background: var(--desktop-surface, #fff); color: var(--desktop-text, #173128); }
.en-ovrf-dialog.has-preview { height: min(820px, calc(100dvh - 32px)); }
.en-ovrf-dialog.compact { height: auto; }
.en-ovrf-dialog > header, .en-ovrf-dialog > footer { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 14px 18px; border-bottom: 1px solid var(--desktop-border, #d8e2dd); }
.en-ovrf-dialog > header p, .en-ovrf-dialog > header h2, .en-ovrf-dialog > header span { margin: 0; }
.en-ovrf-dialog > header p { color: var(--desktop-accent, #176b46); font-size: 11px; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; }
.en-ovrf-dialog > header h2 { font-size: 20px; }
.en-ovrf-dialog > header span { color: var(--desktop-muted, #64766f); font-size: 12px; }
.en-ovrf-dialog > header > button { min-width: 40px; min-height: 40px; border: 0; border-radius: 8px; background: transparent; color: inherit; font-size: 22px; cursor: pointer; }
.en-ovrf-dialog-body { display: grid; grid-template-rows: auto minmax(0, 1fr); gap: 10px; min-width: 0; min-height: 0; overflow: hidden; padding: 12px 14px; background: var(--desktop-surface-muted, #f3f7f5); }
.en-ovrf-validation-note { margin: 0 0 12px; padding: 9px 11px; border: 1px solid #dfc56e; border-radius: 8px; background: #fff8df; color: #604c12; font-size: 12px; }
.en-ovrf-document { display: grid; grid-template-rows: minmax(0, 1fr) auto; gap: 6px; min-width: 0; min-height: 0; overflow: hidden; }
.en-ovrf-preview { display: block; width: 100%; height: 100%; min-width: 0; min-height: 0; overflow: hidden; border: 1px solid var(--desktop-border, #cbd8d2); border-radius: 8px; background: #fff; }
.en-ovrf-state { display: grid; place-items: center; min-height: 120px; margin: 0; padding: 18px; text-align: center; }
.en-ovrf-error { align-content: center; gap: 10px; color: #9b2d27; }
.en-ovrf-open-link { justify-self: start; margin: 0; border: 0; background: transparent; color: var(--desktop-accent, #176b46); font: inherit; font-weight: 700; cursor: pointer; }
.en-ovrf-dialog > footer { justify-content: flex-end; border-top: 1px solid var(--desktop-border, #d8e2dd); border-bottom: 0; }
.en-ovrf-dialog button:focus-visible, .en-ovrf-open-link:focus-visible { outline: 3px solid var(--website-focus, #d4a72c); outline-offset: 2px; }
@media (max-width: 640px) {
  .en-ovrf-actions { display: grid; grid-template-columns: 1fr; }
  .en-ovrf-actions button { min-height: 44px; }
  :global(.enrollment-workspace .en-overlay.en-ovrf-overlay > .en-dialog) { width: calc(100vw - 16px); max-height: calc(100dvh - 16px); }
  .en-ovrf-dialog { max-height: calc(100dvh - 16px); }
  .en-ovrf-dialog.has-preview { height: calc(100dvh - 16px); }
  .en-ovrf-dialog > header, .en-ovrf-dialog > footer { padding: 12px; }
  .en-ovrf-dialog > footer { flex-wrap: wrap; }
  .en-ovrf-dialog > footer button { flex: 1 1 130px; min-height: 44px; }
  .en-ovrf-dialog-body { padding: 10px; }
}
</style>
