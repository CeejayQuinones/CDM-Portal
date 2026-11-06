export const WEBSITE_NAVIGATION_LAYOUT_EVENT = 'cdm:website-navigation-layout'
export const WEBSITE_DESKTOP_QUERY = '(min-width: 901px)'

export function websiteNavigationStorageKey(account) {
  return `cdm-website-navigation:${account || 'session'}`
}

export function readWebsiteNavigation(storage, account) {
  try {
    const value = JSON.parse(storage.getItem(websiteNavigationStorageKey(account)) || 'null')
    return value && typeof value === 'object'
      ? { panelOpen: false, moduleName: value.moduleName || null, collapsed: Boolean(value.collapsed) }
      : { panelOpen: false, moduleName: null, collapsed: false }
  } catch {
    return { panelOpen: false, moduleName: null, collapsed: false }
  }
}

export function writeWebsiteNavigation(storage, account, value) {
  storage.setItem(websiteNavigationStorageKey(account), JSON.stringify({
    panelOpen: Boolean(value.panelOpen),
    moduleName: value.moduleName || null,
    collapsed: Boolean(value.collapsed),
  }))
}
