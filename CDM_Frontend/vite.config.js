import { defineConfig, loadEnv } from 'vite'
import vue from '@vitejs/plugin-vue'
import { VitePWA } from 'vite-plugin-pwa'

export const clientPlatformForMode = (mode) => {
  if (mode === 'desktop') return 'desktop'
  if (mode === 'mobile' || mode === 'demo-mobile') return 'mobile'
  return 'web'
}

export const apiBaseUrlForMode = (mode, env) => {
  if (mode === 'desktop') {
    return env.VITE_DESKTOP_API_BASE_URL || 'http://127.0.0.1:8000/api'
  }

  return env.VITE_API_BASE_URL
}

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), 'VITE_')
  const apiBaseUrl = apiBaseUrlForMode(mode, env)

  return {
    base: './',

    define: {
      'import.meta.env.VITE_CDM_CLIENT': JSON.stringify(clientPlatformForMode(mode)),
      ...(apiBaseUrl ? { 'import.meta.env.VITE_API_BASE_URL': JSON.stringify(apiBaseUrl) } : {}),
      ...(mode === 'demo-mobile' ? { 'import.meta.env.VITE_OFFLINE_DEMO': JSON.stringify('true') } : {}),
    },

    server: {
      host: '0.0.0.0',
      allowedHosts: [
        'achieving-chair-ideal-clients.trycloudflare.com',
      ],
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
