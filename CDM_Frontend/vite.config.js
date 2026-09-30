import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { VitePWA } from 'vite-plugin-pwa'

export const clientPlatformForMode = (mode) => {
  if (mode === 'desktop') return 'desktop'
  if (mode === 'mobile' || mode === 'demo-mobile') return 'mobile'
  return 'web'
}

export default defineConfig(({ mode }) => ({
  base: './',

  define: {
    'import.meta.env.VITE_CDM_CLIENT': JSON.stringify(clientPlatformForMode(mode)),
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
}))
