export const WEBSITE_DESKTOP_SIDEBAR_QUERY = '(min-width: 861px)'
export const WEBSITE_SIDEBAR_LAYOUT_EVENT = 'cdm:website-sidebar-layout'

export function websiteSidebarStorageKey(account) {
  return `cdm-website-sidebar:${account || 'session'}`
}

export function readWebsiteSidebarPreference(storage, account) {
  return storage.getItem(websiteSidebarStorageKey(account)) === 'collapsed'
}

export function writeWebsiteSidebarPreference(storage, account, collapsed) {
  storage.setItem(websiteSidebarStorageKey(account), collapsed ? 'collapsed' : 'expanded')
}
