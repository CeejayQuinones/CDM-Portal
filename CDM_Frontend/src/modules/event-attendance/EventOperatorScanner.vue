<script setup>
import { computed, onBeforeUnmount, ref } from 'vue'
import { eventErrorMessage, eventService } from './eventService'

const props = defineProps({ event: { type: Object, required: true }, capabilities: { type: Object, required: true } })
const action = ref('auto')
const scanning = ref(false)
const initializing = ref(false)
const importing = ref(false)
const busy = ref(false)
const error = ref('')
const result = ref(null)
const fileInput = ref(null)
const scannerId = `event-operator-scanner-${props.event.id}`
let scanner
const resultMessage = computed(() => result.value?.code === 'ATTENDANCE_RECORDED' ? 'Attendance recorded.' : result.value?.message)

async function stop() { if (scanner?.isScanning) { try { await scanner.stop() } catch {} } initializing.value = false; scanning.value = false }
async function submit(token) {
  const decodedToken = typeof token === 'string' ? token.trim() : ''
  if (!decodedToken || busy.value) return
  busy.value = true; error.value = ''; result.value = null
  try { result.value = await eventService.operatorScan(props.event.id, decodedToken, action.value) }
  catch (cause) { error.value = eventErrorMessage(cause) }
  finally { await stop(); busy.value = false }
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
  } catch (cause) {
    await stop()
    error.value = /permission|notallowed|denied/i.test(`${cause?.name || ''} ${cause?.message || cause || ''}`)
      ? 'Camera permission was denied. Allow Camera for CDM Portal in Android Settings, then try again.'
      : 'Camera is unavailable. Choose a QR image or try another device.'
  } finally { initializing.value = false }
}
async function scanFile(event) {
  const file = event.target.files?.[0]
  importing.value = false
  if (!file) return
  try { const { Html5Qrcode } = await import('html5-qrcode'); scanner ||= new Html5Qrcode(scannerId); await stop(); await submit(await scanner.scanFile(file, false)) }
  catch { error.value = 'No readable participant QR was found in that image.' }
  finally { event.target.value = ''; try { scanner?.clear() } catch {} }
}
function chooseImage() {
  importing.value = true
  fileInput.value?.click()
  window.addEventListener('focus', () => { importing.value = false }, { once: true })
}
onBeforeUnmount(async () => { importing.value = false; await stop(); try { scanner?.clear() } catch {} })
</script>

<template>
  <section class="operator-scanner" aria-labelledby="operator-scanner-title"><h3 id="operator-scanner-title">Scan Participant QR</h3><p>Scan the Participant QR shown on an attendee’s device. Event QRs displayed by organizers are not accepted here.</p><label v-if="event.attendance_policy?.mode === 'check_in_and_out'" class="scanner-action-select"><span>Record</span><select v-model="action"><option value="auto">Next required action</option><option value="check_in">Check-in</option><option value="check_out">Check-out</option></select></label><div :id="scannerId" class="scanner-viewport"></div><p v-if="error" class="event-alert error" role="alert">{{ error }}</p><p v-if="result" class="scan-result success" role="status"><strong>{{ resultMessage }}</strong></p><div class="scanner-actions"><button v-if="!scanning" class="event-button primary" type="button" :disabled="initializing || busy || importing" @click="start">{{ initializing ? 'Starting camera…' : 'Open Camera' }}</button><button v-else class="event-button secondary" type="button" @click="stop">Stop Camera</button><button class="event-button secondary" type="button" :disabled="initializing || busy || importing" @click="chooseImage">{{ importing ? 'Opening image picker…' : 'Choose Participant QR Image' }}</button><input ref="fileInput" class="file-picker-input" type="file" accept="image/png,image/jpeg,image/webp" @change="scanFile" /></div></section>
</template>
