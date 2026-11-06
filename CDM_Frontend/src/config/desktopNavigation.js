const destinationFromItem = item => ({
  name: item.name,
  label: item.label,
  path: item.path,
  activeRoutes: item.activeRoutes || [item.name],
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

export const destinationContainsDesktopRoute = (destination, route) =>
  destination.path === route.fullPath
  || destination.path.split('?')[0] === route.path
  || destination.name === route.name
  || destination.activeRoutes?.includes(route.name)

export const desktopModuleContainsRoute = (module, route) =>
  module?.destinations.some(destination => destinationContainsDesktopRoute(destination, route)) === true

export const desktopModuleForRoute = (modules, route) =>
  modules.find(module => desktopModuleContainsRoute(module, route)) || null

