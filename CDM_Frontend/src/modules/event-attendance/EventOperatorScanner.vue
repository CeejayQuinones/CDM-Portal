<script setup>
import { onBeforeUnmount, ref } from 'vue'
import { eventErrorMessage, eventService } from './eventService'

const props = defineProps({ event: { type: Object, required: true }, capabilities: { type: Object, required: true } })
const mode = ref(props.capabilities.can_scan_static_participant_qr ? 'static' : 'dynamic')
const scanning = ref(false)
const busy = ref(false)
const error = ref('')
const result = ref(null)
const scannerId = `event-operator-scanner-${props.event.id}`
let scanner

async function stop() { if (scanner?.isScanning) { try { await scanner.stop() } catch {} } scanning.value = false }
async function submit(token) {
  if (busy.value) return
  busy.value = true; error.value = ''; result.value = null
  try { result.value = await eventService.operatorScan(props.event.id, token, mode.value) }
  catch (cause) { error.value = eventErrorMessage(cause) }
  finally { busy.value = false; await stop() }
}
async function start() {
  error.value = ''; result.value = null
  try { const { Html5Qrcode } = await import('html5-qrcode'); scanner ||= new Html5Qrcode(scannerId); scanning.value = true; await scanner.start({ facingMode: 'environment' }, { fps: 10, qrbox: { width: 250, height: 250 } }, submit, () => {}) }
  catch { scanning.value = false; error.value = 'Camera is unavailable. Allow camera access or choose a QR image.' }
}
async function scanFile(event) {
  const file = event.target.files?.[0]; event.target.value = ''; if (!file) return
  try { const { Html5Qrcode } = await import('html5-qrcode'); scanner ||= new Html5Qrcode(scannerId); await stop(); await submit(await scanner.scanFile(file, true)) }
  catch { error.value = 'No readable participant QR was found in that image.' }
}
onBeforeUnmount(stop)
</script>

<template>
  <section class="operator-scanner" aria-labelledby="operator-scanner-title"><h3 id="operator-scanner-title">Attendance Scanner</h3><p>{{ mode === 'static' ? 'Registered Moderator static QR workflow.' : 'Requested Moderator dynamic QR workflow.' }}</p><div v-if="capabilities.can_scan_static_participant_qr && capabilities.can_scan_dynamic_participant_qr" class="view-switch"><button type="button" :class="{ active: mode === 'static' }" @click="mode = 'static'">Static</button><button type="button" :class="{ active: mode === 'dynamic' }" @click="mode = 'dynamic'">Dynamic</button></div><div :id="scannerId" class="scanner-viewport"></div><p v-if="error" class="event-alert error" role="alert">{{ error }}</p><p v-if="result" class="scan-result success"><strong>{{ result.message }}</strong></p><div class="scanner-actions"><button v-if="!scanning" class="event-button primary" type="button" :disabled="busy" @click="start">Open Camera</button><button v-else class="event-button secondary" type="button" @click="stop">Stop Camera</button><label class="event-button secondary file-button">Choose QR Image<input type="file" accept="image/*" @change="scanFile" /></label></div></section>
</template>
