<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue'

const props = defineProps({
  action: { type: String, required: true },
  error: { type: String, default: '' },
})
const emit = defineEmits(['verified', 'expired', 'error'])

const container = ref(null)
const loading = ref(true)
const localError = ref('')
let widgetId = null

const loadTurnstile = () => {
  if (window.turnstile) return Promise.resolve(window.turnstile)
  if (window.__cdmTurnstileLoader) return window.__cdmTurnstileLoader

  window.__cdmTurnstileLoader = new Promise((resolve, reject) => {
    const existing = document.querySelector('script[data-cdm-turnstile]')
    if (existing) {
      existing.addEventListener('load', () => resolve(window.turnstile), { once: true })
      existing.addEventListener('error', reject, { once: true })
      return
    }

    const script = document.createElement('script')
    script.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit'
    script.async = true
    script.defer = true
    script.dataset.cdmTurnstile = 'true'
    script.addEventListener('load', () => resolve(window.turnstile), { once: true })
    script.addEventListener('error', (error) => {
      script.remove()
      window.__cdmTurnstileLoader = null
      reject(error)
    }, { once: true })
    document.head.appendChild(script)
  })

  return window.__cdmTurnstileLoader
}

const renderWidget = async () => {
  const sitekey = import.meta.env.VITE_TURNSTILE_SITE_KEY
  if (!sitekey) {
    loading.value = false
    localError.value = 'Human verification is not configured. Contact the portal administrator.'
    emit('error', localError.value)
    return
  }

  try {
    const turnstile = await loadTurnstile()
    await nextTick()
    if (!container.value || !turnstile) throw new Error('Turnstile is unavailable.')
    widgetId = turnstile.render(container.value, {
      sitekey,
      action: props.action,
      theme: 'auto',
      size: 'flexible',
      appearance: 'interaction-only',
      callback: (token) => {
        loading.value = false
        localError.value = ''
        emit('verified', token)
      },
      'expired-callback': () => {
        loading.value = false
        localError.value = 'Human verification expired. Please verify again.'
        emit('expired')
      },
      'error-callback': () => {
        loading.value = false
        localError.value = 'Human verification could not load. Check your connection and retry.'
        emit('error', localError.value)
      },
    })
  } catch {
    loading.value = false
    localError.value = 'Human verification could not load. Check your connection and retry.'
    emit('error', localError.value)
  }
}

const reset = () => {
  emit('verified', '')
  localError.value = ''
  if (widgetId !== null && window.turnstile) window.turnstile.reset(widgetId)
}

const retry = () => {
  localError.value = ''
  loading.value = true
  emit('error', '')
  if (widgetId !== null && window.turnstile) {
    window.turnstile.reset(widgetId)
    return
  }
  renderWidget()
}

defineExpose({ reset })

onMounted(renderWidget)
onBeforeUnmount(() => {
  if (widgetId !== null && window.turnstile) window.turnstile.remove(widgetId)
})
</script>

<template>
  <div class="turnstile-field">
    <div ref="container" class="turnstile-widget" aria-label="Human verification"></div>
    <p v-if="loading" class="turnstile-status" role="status">Preparing secure verification…</p>
    <p v-if="error || localError" class="turnstile-error" role="alert">{{ error || localError }}</p>
    <button v-if="error || localError" class="turnstile-retry" type="button" @click="retry">Retry verification</button>
  </div>
</template>

<style scoped>
.turnstile-field{display:grid;gap:6px;margin:14px 0;max-width:100%;overflow:hidden}.turnstile-widget{min-height:1px;max-width:100%}.turnstile-status,.turnstile-error{margin:0;font-size:.78rem;line-height:1.4}.turnstile-status{color:#66746c}.turnstile-error{color:#b42318;font-weight:650}.turnstile-retry{justify-self:start;border:1px solid #197548;border-radius:7px;background:transparent;padding:6px 10px;color:#176c42;font:inherit;font-size:.78rem;font-weight:750;cursor:pointer}.turnstile-retry:focus-visible{outline:3px solid rgba(25,117,72,.24);outline-offset:2px}@media(max-width:380px){.turnstile-field{margin:12px 0}}
</style>
