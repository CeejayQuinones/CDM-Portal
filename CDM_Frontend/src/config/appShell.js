import { ROLES } from './accessControl.js'

export const APP_SHELLS = Object.freeze({
  WEB: 'web',
  MOBILE: 'mobile',
  DESKTOP: 'desktop',
})

export const SHELL_BLOCK_STORAGE_KEY = 'cdm_shell_block'

export const MOBILE_SHELL_MESSAGE =
  'This mobile app is for students. Professors, admins, and registrar staff should use the desktop app or the website.'

export const DESKTOP_SHELL_MESSAGE =
  'This desktop app is for professors, admins, and registrar staff. Students should use the mobile app or the website.'

const DESKTOP_ROLES = new Set([ROLES.PROFESSOR, ROLES.ADMIN, ROLES.REGISTRAR_STAFF])

export function detectAppShell(runtime = currentRuntime()) {
  if (runtime.shell === APP_SHELLS.DESKTOP || runtime.electron) return APP_SHELLS.DESKTOP
  if (runtime.native) return APP_SHELLS.MOBILE
  return APP_SHELLS.WEB
}

export function shellBlockMessage(shell, role) {
  if (shell === APP_SHELLS.MOBILE && role !== ROLES.STUDENT) return MOBILE_SHELL_MESSAGE
  if (shell === APP_SHELLS.DESKTOP && !DESKTOP_ROLES.has(role)) return DESKTOP_SHELL_MESSAGE
  return ''
}

export function currentRuntime() {
  if (typeof window === 'undefined') return {}

  const userAgent = typeof navigator === 'undefined' ? '' : navigator.userAgent || ''

  return {
    shell: window.cdmShell,
    electron: /Electron/i.test(userAgent),
    native: Boolean(window.Capacitor?.isNativePlatform?.()),
  }
}
