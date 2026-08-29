import { Capacitor } from '@capacitor/core'

/**
 * Resolve API base URL for web, Electron desktop, and Capacitor mobile.
 * Build-time env wins; native builds fall back to LAN/emulator-friendly hosts.
 */
export function resolveApiBaseUrl() {
  const fromEnv = import.meta.env.VITE_API_BASE_URL
  if (fromEnv) return fromEnv.replace(/\/$/, '')

  if (Capacitor.isNativePlatform()) {
    // Prefer explicit mobile host; 10.0.2.2 = Android emulator → host machine.
    const mobileHost = import.meta.env.VITE_MOBILE_API_HOST || '192.168.254.104'
    const useEmulatorLoopback = import.meta.env.VITE_ANDROID_EMULATOR === 'true'
    const host = useEmulatorLoopback ? '10.0.2.2' : mobileHost
    return `http://${host}:8000/api`
  }

  // Web + Electron on the same PC
  return 'http://127.0.0.1:8000/api'
}
