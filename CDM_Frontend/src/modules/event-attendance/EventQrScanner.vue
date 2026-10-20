<script setup>
import { onBeforeUnmount, ref } from 'vue'
import { eventErrorMessage, eventService } from './eventService'

const props = defineProps({ event: { type: Object, required: true } })
const scannerId = `event-qr-scanner-${props.event.id}`
const scanning = ref(false), initializing = ref(false), submitting = ref(false), importing = ref(false), error = ref(''), result = ref(null), fileInput = ref(null)
let scanner
const scanMessages = {
  NOT_ELIGIBLE: 'You are not eligible to attend this Event.',
  SESSION_CLOSED: 'Attendance is closed for this Event.',
  EXPIRED_TOKEN: 'This QR code has expired. Scan the current code.',
  ALREADY_RECORDED: 'Your attendance was already recorded.',
  ATTENDANCE_CHECKED_OUT: 'Your check-out was recorded.',
  VERIFICATION_PENDING: 'Waiting for Event personnel to confirm your attendance.',
  WRONG_QR_TYPE: 'This is a participant QR. Show it to authorized Event personnel instead of scanning it here.',
}

async function stop() { if (scanner?.isScanning) { try { await scanner.stop() } catch {} } initializing.value = false; scanning.value = false }
function cameraError(cause) {
  const message = `${cause?.name || ''} ${cause?.message || cause || ''}`
  if (/notallowed|permission|denied/i.test(message)) return 'Camera permission was denied. Allow Camera for CDM Portal in Android Settings, then try again.'
  if (/notfound|devicesnotfound/i.test(message)) return 'No camera was found on this device.'
  if (/notreadable|trackstart|in use/i.test(message)) return 'The camera is in use by another app. Close it there and try again.'
  return 'Camera is unavailable. Choose a QR image or try another device.'
}
async function submit(token) {
  const decodedToken = typeof token === 'string' ? token.trim() : ''
  if (!decodedToken || submitting.value) return
  submitting.value = true; error.value = ''
  try { result.value = await eventService.scanAttendance(props.event.id, decodedToken) }
  catch (e) { const data = e.response?.data; error.value = data?.message || scanMessages[data?.code] || eventErrorMessage(e); result.value = { code: data?.code || 'SCAN_ERROR', message: error.value } }
  finally { await stop(); submitting.value = false }
}
async function start() {
  if (initializing.value || scanning.value) return
  error.value = ''; result.value = null
  initializing.value = true
  try {
    const { Html5Qrcode } = await import('html5-qrcode')
    scanner ||= new Html5Qrcode(scannerId)
    const cameras = await Html5Qrcode.getCameras()
    if (!cameras.length) throw new Error('No camera found')
    await scanner.start({ facingMode: 'environment' }, { fps: 10, qrbox: (width, height) => { const size = Math.max(160, Math.min(250, width - 24, height - 24)); return { width: size, height: size } } }, submit, () => {})
    const video = document.querySelector(`#${scannerId} video`)
    const hasLiveTrack = [...(video?.srcObject?.getVideoTracks?.() || [])].some(track => track.readyState === 'live')
    if (!hasLiveTrack) throw new Error('Camera stream did not start')
    scanning.value = true
  } catch (cause) { await stop(); error.value = cameraError(cause) }
  finally { initializing.value = false }
}
async function scanFile(event) {
  const file = event.target.files?.[0]
  importing.value = false
  if (!file) return
  error.value = ''; result.value = null
  try {
    const { Html5Qrcode } = await import('html5-qrcode')
    scanner ||= new Html5Qrcode(scannerId)
    await stop()
    await submit(await scanner.scanFile(file, false))
  } catch { error.value = 'No readable QR code was found in that image.' }
  finally {
    event.target.value = ''
    try { scanner?.clear() } catch {}
  }
}
function chooseImage() {
  importing.value = true
  fileInput.value?.click()
  // Android does not emit change when its picker is cancelled.
  window.addEventListener('focus', () => { importing.value = false }, { once: true })
}
onBeforeUnmount(async () => { importing.value = false; await stop(); try { scanner?.clear() } catch {} })
</script>

<template>
  <section class="student-scanner" aria-labelledby="scan-title"><h3 id="scan-title">Scan Event Attendance QR</h3><p>Scan only the Event QR displayed by the organizer. Do not scan your own Participant QR here.</p><div :id="scannerId" class="scanner-viewport"></div><p v-if="error" class="event-alert error" role="alert">{{ error }}</p><div v-if="result" class="scan-result" :class="{ success: ['ATTENDANCE_RECORDED','ATTENDANCE_CHECKED_OUT','ALREADY_RECORDED','VERIFICATION_PENDING'].includes(result.code) }"><strong>{{ result.message }}</strong><template v-if="result.data"><span>{{ result.data.event.title }}</span><span v-if="result.data.attendance">{{ new Date(result.data.attendance.checked_in_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) }} · {{ result.data.attendance.status }}</span><span v-else-if="result.data.verification">{{ result.data.verification.action === 'check_out' ? 'Check-out' : 'Check-in' }} confirmation pending</span></template></div><div class="scanner-actions"><button v-if="!scanning" class="event-button primary" type="button" :disabled="initializing || submitting || importing" @click="start">{{ initializing ? 'Starting camera…' : 'Open Camera' }}</button><button v-else class="event-button secondary" type="button" @click="stop">Stop Camera</button><button class="event-button secondary" type="button" :disabled="initializing || submitting || importing" @click="chooseImage">{{ importing ? 'Opening image picker…' : 'Choose Event QR Image' }}</button><input ref="fileInput" class="file-picker-input" type="file" accept="image/png,image/jpeg,image/webp" @change="scanFile" /></div></section>
</template>
