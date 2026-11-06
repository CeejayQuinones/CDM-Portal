const storageKeys = {
  Guest: 'cdm-guest-appearance',
  Student: 'cdm-student-appearance',
  Professor: 'cdm-professor-appearance',
  'Registrar Staff': 'cdm-registrar-appearance',
  Admin: 'cdm-admin-appearance',
}
const AUTH_STORAGE_KEY = 'cdm_portal_auth'

export function portalAppearanceStorageKey(role, account) {
  if (!storageKeys[role] || account === undefined || account === null || account === '') return null
  return `${storageKeys[role]}:${encodeURIComponent(String(account))}`
}

export function storedPortalAppearance(role, account, storage = localStorage) {
  const key = portalAppearanceStorageKey(role, account)
  return key ? storage.getItem(key) || 'system' : 'system'
}

export function previewPortalAppearance(appearance, target = document.documentElement) {
  const theme = ['light', 'dark', 'system'].includes(appearance) ? appearance : 'system'
  target.dataset.studentTheme = theme
  return theme
}

export function persistPortalAppearance(appearance, role, account, storage = localStorage, target = document.documentElement) {
  if (!storageKeys[role]) return null
  const theme = previewPortalAppearance(appearance, target)
  const key = portalAppearanceStorageKey(role, account)
  if (key) storage.setItem(key, theme)
  return theme
}

export function bootstrapPortalAppearance(storage = localStorage, target = document.documentElement) {
  try {
    const auth = JSON.parse(storage.getItem(AUTH_STORAGE_KEY) || '{}')
    const role = auth.currentRole || auth.currentUser?.role?.role_name || auth.currentUser?.role
    const account = auth.currentUser?.id || auth.currentUser?.username
    if (!storageKeys[role] || !account) return null
    return previewPortalAppearance(storedPortalAppearance(role, account, storage), target)
  } catch {
    return null
  }
}

export { storageKeys as portalAppearanceStorageKeys }
