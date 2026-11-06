export const authorizedSearchDestinations = (modules = []) => modules.flatMap(module =>
  module.destinations.map(item => ({ ...item, moduleLabel: module.label })),
)

export function filterWebsiteDestinations(destinations = [], query = '', limit = 8) {
  const normalized = query.trim().toLowerCase()
  const matches = normalized
    ? destinations.filter(item => `${item.label} ${item.moduleLabel} ${item.description || ''}`.toLowerCase().includes(normalized))
    : destinations
  return matches.slice(0, limit)
}

export function moveSearchSelection(current, direction, length) {
  if (!length) return -1
  if (direction === 'next') return current < length - 1 ? current + 1 : 0
  return current > 0 ? current - 1 : length - 1
}
