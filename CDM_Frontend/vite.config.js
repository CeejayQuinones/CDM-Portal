import { defineConfig, loadEnv } from 'vite'
import vue from '@vitejs/plugin-vue'
import { VitePWA } from 'vite-plugin-pwa'

export const clientPlatformForMode = (mode) => {
  if (mode === 'desktop' || mode === 'desktop-development') return 'desktop'
  if (mode === 'mobile' || mode === 'mobile-development' || mode === 'demo-mobile') return 'mobile'
  return 'web'
}

const developmentBuildModes = new Set(['demo', 'demo-mobile', 'desktop-development', 'mobile-development'])

const isDevelopmentHostname = (hostname) => {
  const normalized = hostname.toLowerCase().replace(/^\[|\]$/g, '')
  if (normalized === 'localhost' || normalized === '::1' || normalized.endsWith('.localhost')) return true
  if (/^127\./.test(normalized) || /^10\./.test(normalized) || /^192\.168\./.test(normalized)) return true

  const private172 = normalized.match(/^172\.(\d+)\./)
  return private172 ? Number(private172[1]) >= 16 && Number(private172[1]) <= 31 : false
}

export const assertProductionApiBaseUrl = (value, label) => {
  if (!value) {
    throw new Error(`${label} is required for production builds.`)
  }

  let parsed
  try {
    parsed = new URL(value)
  } catch {
    throw new Error(`${label} must be a valid absolute HTTPS URL.`)
  }

  if (parsed.protocol !== 'https:' || isDevelopmentHostname(parsed.hostname)) {
    throw new Error(`${label} must use HTTPS and a non-development host for production builds.`)
  }

  return value.replace(/\/$/, '')
}

export const apiBaseUrlForMode = (mode, env, command = 'serve') => {
  const desktop = clientPlatformForMode(mode) === 'desktop'
  const configured = desktop ? env.VITE_DESKTOP_API_BASE_URL : env.VITE_API_BASE_URL
  const productionBuild = command === 'build' && !developmentBuildModes.has(mode)

  if (productionBuild) {
    return assertProductionApiBaseUrl(
      configured,
      desktop ? 'VITE_DESKTOP_API_BASE_URL' : 'VITE_API_BASE_URL',
    )
  }

  return configured || (desktop ? 'http://127.0.0.1:8000/api' : undefined)
}

export default defineConfig(({ command, mode }) => {
  const env = loadEnv(mode, process.cwd(), 'VITE_')
  const apiBaseUrl = apiBaseUrlForMode(mode, env, command)

  return {
    base: './',

    define: {
      'import.meta.env.VITE_CDM_CLIENT': JSON.stringify(clientPlatformForMode(mode)),
      ...(apiBaseUrl ? { 'import.meta.env.VITE_API_BASE_URL': JSON.stringify(apiBaseUrl) } : {}),
      ...(mode === 'demo-mobile' ? { 'import.meta.env.VITE_OFFLINE_DEMO': JSON.stringify('true') } : {}),
    },

    server: {
      host: '0.0.0.0',
    },

    plugins: [
      vue(),
      VitePWA({
      registerType: 'autoUpdate',
      includeAssets: ['favicon.png'],
      manifest: {
        name: 'CDM Portal',
        short_name: 'CDM Portal',
        description: 'Campus management system for CDM Portal.',
        theme_color: '#1f7a5a',
        background_color: '#f6f7fb',
        display: 'standalone',
        orientation: 'portrait',
        start_url: './',
        scope: './',
        icons: [
          {
            src: 'icons/icon-192.png',
            sizes: '192x192',
            type: 'image/png',
            purpose: 'any',
          },
          {
            src: 'icons/icon-512.png',
            sizes: '512x512',
            type: 'image/png',
            purpose: 'any',
          },
          {
            src: 'icons/icon-maskable-512.png',
            sizes: '512x512',
            type: 'image/png',
            purpose: 'maskable',
          },
        ],
      },
      workbox: {
        navigateFallback: '/index.html',
        globPatterns: ['**/*.{js,css,html,svg,png,ico}'],
      },
      }),
    ],
  }
})
