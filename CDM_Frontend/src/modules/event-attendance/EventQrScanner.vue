<script setup>
import { onBeforeUnmount, ref } from 'vue'
import { eventErrorMessage, eventService } from './eventService'

const props = defineProps({ event: { type: Object, required: true } })
const scannerId = `event-qr-scanner-${props.event.id}`
const scanning = ref(false), submitting = ref(false), error = ref(''), result = ref(null)
let scanner
const scanMessages = {
  NOT_ELIGIBLE: 'You are not eligible to attend this Event.',
  SESSION_CLOSED: 'Attendance is closed for this Event.',
  EXPIRED_TOKEN: 'This QR code has expired. Scan the current code.',
  ALREADY_RECORDED: 'Your attendance was already recorded.',
}

async function stop() { if (scanner?.isScanning) { try { await scanner.stop() } catch {} } scanning.value = false }
async function submit(token) { if (submitting.value) return; submitting.value = true; error.value = ''; try { result.value = await eventService.scanAttendance(props.event.id, token) } catch (e) { const data = e.response?.data; error.value = data?.message || scanMessages[data?.code] || eventErrorMessage(e); result.value = { code: data?.code || 'SCAN_ERROR', message: error.value } } finally { submitting.value = false; await stop() } }
async function start() {
  error.value = ''; result.value = null
  try {
    const { Html5Qrcode } = await import('html5-qrcode')
    scanner ||= new Html5Qrcode(scannerId)
    scanning.value = true
    await scanner.start({ facingMode: 'environment' }, { fps: 10, qrbox: { width: 250, height: 250 } }, submit, () => {})
  } catch (e) { scanning.value = false; error.value = /permission|notallowed/i.test(String(e)) ? 'Camera permission was denied. Allow camera access or choose a QR image.' : 'Camera is unavailable. Choose a QR image or try another device.' }
}
async function scanFile(event) {
  const file = event.target.files?.[0]; if (!file) return
  error.value = ''; result.value = null
  try { const { Html5Qrcode } = await import('html5-qrcode'); scanner ||= new Html5Qrcode(scannerId); await stop(); await submit(await scanner.scanFile(file, true)) } catch { error.value = 'No readable QR code was found in that image.' }
}
onBeforeUnmount(stop)
</script>

<template>
  <section class="student-scanner" aria-labelledby="scan-title"><h3 id="scan-title">Scan QR</h3><p>Scan the current code displayed by Event staff. Codes rotate every 30 seconds.</p><div :id="scannerId" class="scanner-viewport"></div><p v-if="error" class="event-alert error" role="alert">{{ error }}</p><div v-if="result" class="scan-result" :class="{ success: result.code === 'ATTENDANCE_RECORDED' || result.code === 'ALREADY_RECORDED' }"><strong>{{ result.message }}</strong><template v-if="result.data"><span>{{ result.data.event.title }}</span><span>{{ new Date(result.data.attendance.checked_in_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) }} · {{ result.data.attendance.status }}</span></template></div><div class="scanner-actions"><button v-if="!scanning" class="event-button primary" type="button" :disabled="submitting" @click="start">Open Camera</button><button v-else class="event-button secondary" type="button" @click="stop">Stop Camera</button><label class="event-button secondary file-button">Choose QR Image<input type="file" accept="image/*" @change="scanFile" /></label></div></section>
</template>
