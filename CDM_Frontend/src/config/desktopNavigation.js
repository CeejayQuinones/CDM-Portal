const destinationFromItem = item => ({
  name: item.name,
  label: item.label,
  path: item.path,
  activeRoutes: item.activeRoutes || [item.name],
  activeQuery: item.activeQuery,
})

export function desktopModulesFromMenu(items = []) {
  return items.map(item => ({
    name: item.name,
    label: item.label
      .replace('Registrar Dashboard', 'Overview')
      .replace('Admin Dashboard', 'Overview')
      .replace('Student Management', 'Students')
      .replace('Document Requests', 'Documents'),
    destinations: item.children?.map(destinationFromItem) || [destinationFromItem(item)],
  }))
}

export const destinationContainsDesktopRoute = (destination, route) => {
  if (destination.name === route.name || destination.activeRoutes?.includes(route.name)) return true
  const [path, query = ''] = destination.path.split('?')
  if (path !== route.path) return false
  const expectedQuery = destination.activeQuery || Object.fromEntries(new URLSearchParams(query))
  return Object.entries(expectedQuery).every(([key, value]) => String(route.query?.[key] ?? '') === String(value))
}

export const desktopModuleContainsRoute = (module, route) =>
  module?.destinations.some(destination => destinationContainsDesktopRoute(destination, route)) === true

export const desktopModuleForRoute = (modules, route) =>
  modules.find(module => desktopModuleContainsRoute(module, route)) || null

