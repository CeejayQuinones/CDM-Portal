<script setup>
import { computed, onBeforeUnmount, ref } from 'vue'
import { Capacitor } from '@capacitor/core'
import { Directory, Filesystem } from '@capacitor/filesystem'
import { Share } from '@capacitor/share'
import QRCode from 'qrcode'
import { eventErrorMessage, eventService } from './eventService'

const props = defineProps({ event: { type: Object, required: true } })
const image = ref('')
const mode = ref('dynamic')
const expiresAt = ref(null)
const loading = ref(false)
const error = ref('')
const notice = ref('')
let refreshTimer
const availableModes = computed(() => props.event.participant_qr_modes
  || (props.event.attendance_policy?.methods || []).filter((method) => ['participant_static', 'participant_dynamic'].includes(method)).map((method) => method.replace('participant_', '')))
const canGenerateStatic = computed(() => availableModes.value.includes('static'))
const canGenerateDynamic = computed(() => availableModes.value.includes('dynamic'))

async function generate(selectedMode = mode.value) {
  clearTimeout(refreshTimer)
  mode.value = selectedMode
  loading.value = true
  error.value = ''
  notice.value = ''
  try {
    const result = await eventService.participantQr(props.event.id, selectedMode)
    image.value = await QRCode.toDataURL(result.token, { width: 300, margin: 2, errorCorrectionLevel: 'M' })
    expiresAt.value = result.expires_at
    if (selectedMode === 'dynamic') refreshTimer = setTimeout(() => generate('dynamic'), 30000)
  } catch (cause) { image.value = ''; error.value = eventErrorMessage(cause) }
  finally { loading.value = false }
}
async function download() {
  if (!image.value) return
  if (mode.value !== 'static') {
    error.value = 'Dynamic QR codes are short-lived and cannot be saved. Show the Static QR to save a reusable code.'
    return
  }
  const filename = `cdm-event-${props.event.id}-static-participant-qr.png`
  if (Capacitor.isNativePlatform()) {
    loading.value = true
    error.value = ''
    notice.value = ''
    let path = ''
    try {
      path = `shared/${Date.now()}-${filename}`
      const written = await Filesystem.writeFile({ path, data: image.value.split(',')[1], directory: Directory.Cache, recursive: true })
      await Share.share({ title: 'CDM Event participant QR', text: 'Save or share this static participant QR.', url: written.uri, dialogTitle: 'Save static participant QR' })
      notice.value = 'The Android save/share panel was opened.'
    } catch (cause) {
      if (!/cancel/i.test(String(cause))) error.value = 'The QR image could not be opened in Android’s save/share panel.'
    } finally {
      if (path) await Filesystem.deleteFile({ path, directory: Directory.Cache }).catch(() => {})
      loading.value = false
    }
    return
  }
  const link = document.createElement('a')
  link.href = image.value
  link.download = filename
  link.click()
  notice.value = 'Static participant QR downloaded.'
}
onBeforeUnmount(() => clearTimeout(refreshTimer))
</script>

<template>
  <section class="participant-qr" aria-labelledby="participant-qr-title"><h3 id="participant-qr-title">My Participant QR</h3><p>Show this QR to authorized Event personnel. Approved Moderators scan Static Participant QR; Event Staff scan Dynamic Participant QR.</p><div class="scanner-actions"><button v-if="canGenerateStatic" class="event-button primary" type="button" :disabled="loading" @click="generate('static')">Show Static Participant QR</button><button v-if="canGenerateDynamic" class="event-button secondary" type="button" :disabled="loading" @click="generate('dynamic')">Show Dynamic Participant QR</button></div><p v-if="error" class="event-alert error" role="alert">{{ error }}</p><p v-if="notice" class="event-alert success" role="status">{{ notice }}</p><div v-if="image" class="qr-panel"><div class="qr-surface"><img :src="image" :alt="`${mode} participant QR code`" /></div><strong>{{ mode === 'static' ? 'Static participant QR' : 'Dynamic participant QR' }}</strong><span v-if="expiresAt">Short-lived code · refreshes automatically</span><button v-if="mode === 'static'" class="event-button secondary" type="button" :disabled="loading" @click="download">Save Static QR</button><small v-else>Dynamic QR codes are display-only and are never saved.</small></div></section>
</template>
