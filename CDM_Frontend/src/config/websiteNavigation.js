const DASHBOARD_PATH = 'M4 4h6v6H4V4Zm10 0h6v6h-6V4ZM4 14h6v6H4v-6Zm10 0h6v6h-6v-6Z'

export const WEBSITE_NAVIGATION_META = Object.freeze({
  'guest-dashboard': { shortLabel: 'Home', description: 'Admission overview', icon: DASHBOARD_PATH },
  'student-dashboard': { shortLabel: 'Home', description: 'Academic overview', icon: DASHBOARD_PATH },
  'professor-dashboard': { shortLabel: 'Home', description: 'Teaching overview', icon: DASHBOARD_PATH },
  'admission-menu': { shortLabel: 'Admission', description: 'Application and examination', icon: 'M6 3h9l3 3v15H6V3Zm3 5h6M9 12h6m-6 4h4' },
  enrollment: { shortLabel: 'Enrollment', description: 'Status, subjects and schedule', icon: 'M4 5h16v14H4V5Zm4 4h8m-8 4h8m-8 4h5' },
  grading: { shortLabel: 'Grades', description: 'Grades and class work', icon: 'M4 19V5l8-3 8 3v14l-8 3-8-3Zm5-7 2 2 4-4' },
  'document-requests-menu': { shortLabel: 'Documents', description: 'Requests and claim tracking', icon: 'M7 3h7l4 4v14H7V3Zm7 0v5h5m-9 4h5m-5 4h5' },
  settings: { shortLabel: 'Settings', description: 'Profile and preferences', icon: 'M12 15.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Zm0-12v2m0 13v2m8.5-8.5h-2m-13 0h-2m15.28-6.28-1.42 1.42M6.64 17.36l-1.42 1.42m13.56 0-1.42-1.42M6.64 6.64 5.22 5.22' },
  'professor-settings': { shortLabel: 'Settings', description: 'Faculty account and appearance', icon: 'M12 15.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Zm0-12v2m0 13v2m8.5-8.5h-2m-13 0h-2m15.28-6.28-1.42 1.42M6.64 17.36l-1.42 1.42m13.56 0-1.42-1.42M6.64 6.64 5.22 5.22' },
})

const CHILD_DESCRIPTIONS = Object.freeze({
  'admission': 'Review application eligibility and progress',
  'student-admission-exam': 'Open an authorized entrance examination',
  'student-admission-result': 'View a published Admission result',
  'student-admission-recommendation': 'Review published program guidance',
  'enrollment-status': 'Track the current enrollment application',
  'enrollment-subjects': 'Review subjects in the finalized enrollment',
  'enrollment-schedule': 'See class times and assigned rooms',
  'enrollment-cor': 'View the Certificate of Registration',
  'enrollment-teaching': 'Review assigned sections and subjects',
  'grading-student': 'View officially published grades',
  'grading-professor': 'Open class grade sheets and assessments',
  'grading-submission': 'Review readiness and submit completed grades',
  'student-document-requests': 'Request and track official documents',
})

export function websiteModulesFromMenu(items = []) {
  return items.map((item) => {
    const meta = WEBSITE_NAVIGATION_META[item.name] || {}
    const destinations = item.children?.length
      ? item.children.map(child => ({ ...child, description: CHILD_DESCRIPTIONS[child.name] || '' }))
      : item.path
        ? [{ ...item, description: meta.description || '' }]
        : []

    return {
      ...item,
      ...meta,
      destinations,
      icon: meta.icon || DASHBOARD_PATH,
    }
  })
}

export function moduleContainsRoute(module, route) {
  if (!module || !route) return false
  return module.name === route.name
    || module.activeRoutes?.includes(route.name)
    || module.destinations?.some(destination =>
      destination.name === route.name
      || destination.path === route.path
      || destination.activeRoutes?.includes(route.name),
    )
}

export function destinationForModule(module) {
  return module?.destinations?.[0]?.path || module?.path || null
}

export function destinationContainsRouteName(destination, routeName) {
  return destination?.name === routeName || destination?.activeRoutes?.includes(routeName) === true
}

export function moduleContainsRouteName(module, routeName) {
  return module?.name === routeName
    || module?.activeRoutes?.includes(routeName) === true
    || module?.destinations?.some(destination => destinationContainsRouteName(destination, routeName)) === true
}
