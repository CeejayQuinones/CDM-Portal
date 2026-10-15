const DOCUMENT_REQUEST_CONTEXT = Object.freeze({
  routeNames: ['registrar-document-types', 'registrar-document-requests', 'registrar-document-request-history'],
  items: [
    {
      key: 'document-types',
      label: 'Document Types',
      to: { name: 'registrar-document-types' },
      activeRouteNames: ['registrar-document-types'],
    },
    {
      key: 'document-requests',
      label: 'Document Requests',
      to: { name: 'registrar-document-requests' },
      activeRouteNames: ['registrar-document-requests', 'registrar-document-request-history'],
    },
  ],
})

const APPOINTMENT_CONTEXT = Object.freeze({
  routeNames: ['registrar-document-appointments'],
  items: [
    {
      key: 'appointments',
      label: 'Appointments',
      to: { name: 'registrar-document-appointments' },
      activeRouteNames: ['registrar-document-appointments'],
    },
    {
      key: 'availability',
      label: 'Availability',
      action: 'open-appointment-availability',
    },
  ],
})

const NAVBAR_CONTEXTS = Object.freeze([DOCUMENT_REQUEST_CONTEXT, APPOINTMENT_CONTEXT])

const queryValue = (value) => (Array.isArray(value) ? value[0] : value)

const queryMatches = (query, expected) =>
  Object.entries(expected || {}).every(([key, value]) => queryValue(query[key]) === value)

const isItemActive = (item, route, appointmentAvailabilityOpen) => {
  if (item.action) return item.action === 'open-appointment-availability' && appointmentAvailabilityOpen
  if (appointmentAvailabilityOpen) return false
  if (!item.activeRouteNames.includes(route.name)) return false
  if (item.requiredQuery && !queryMatches(route.query, item.requiredQuery)) return false
  if (item.excludedQuery && queryMatches(route.query, item.excludedQuery)) return false

  return true
}

export const navbarContextForRoute = (route, { appointmentAvailabilityOpen = false } = {}) => {
  const context = NAVBAR_CONTEXTS.find(({ routeNames }) => routeNames.includes(route.name))

  if (!context) return null

  return {
    items: context.items.map((item) => ({
      ...item,
      active: isItemActive(item, route, appointmentAvailabilityOpen),
    })),
  }
}
