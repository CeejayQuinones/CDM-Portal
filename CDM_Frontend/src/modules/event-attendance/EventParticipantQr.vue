<script setup>
import { onBeforeUnmount, ref } from 'vue'
import QRCode from 'qrcode'
import { eventErrorMessage, eventService } from './eventService'

const props = defineProps({ event: { type: Object, required: true } })
const image = ref('')
const mode = ref('dynamic')
const expiresAt = ref(null)
const loading = ref(false)
const error = ref('')
let refreshTimer

async function generate(selectedMode = mode.value) {
  clearTimeout(refreshTimer)
  mode.value = selectedMode
  loading.value = true
  error.value = ''
  try {
    const result = await eventService.participantQr(props.event.id, selectedMode)
    image.value = await QRCode.toDataURL(result.token, { width: 300, margin: 2, errorCorrectionLevel: 'M' })
    expiresAt.value = result.expires_at
    if (selectedMode === 'dynamic') refreshTimer = setTimeout(() => generate('dynamic'), 30000)
  } catch (cause) { image.value = ''; error.value = eventErrorMessage(cause) }
  finally { loading.value = false }
}
onBeforeUnmount(() => clearTimeout(refreshTimer))
</script>

<template>
  <section class="participant-qr" aria-labelledby="participant-qr-title"><h3 id="participant-qr-title">My Participant QR</h3><p>Show the QR requested by assigned Event personnel. Dynamic codes refresh automatically.</p><div class="scanner-actions"><button class="event-button secondary" type="button" :disabled="loading" @click="generate('static')">Show Static QR</button><button class="event-button primary" type="button" :disabled="loading" @click="generate('dynamic')">Show Dynamic QR</button></div><p v-if="error" class="event-alert error" role="alert">{{ error }}</p><div v-if="image" class="qr-panel"><div class="qr-surface"><img :src="image" :alt="`${mode} participant QR code`" /></div><strong>{{ mode === 'static' ? 'Static participant QR' : 'Dynamic participant QR' }}</strong><span v-if="expiresAt">Short-lived code · refreshes automatically</span></div></section>
</template>
