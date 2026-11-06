const MODULE_PREFIXES = Object.freeze([
  ['enrollment', 'enrollment'],
  ['grading', 'grading'],
  ['student-document-', 'documents'],
  ['admission', 'admission'],
  ['student-admission-', 'admission'],
])

const DASHBOARD_ROUTES = new Set(['home', 'student-dashboard', 'guest-dashboard'])

export function buddyModuleForRoute(routeName) {
  const name = String(routeName || '')
  if (DASHBOARD_ROUTES.has(name)) return 'dashboard'
  for (const [prefix, module] of MODULE_PREFIXES) {
    if (name === prefix || name.startsWith(prefix)) return module
  }
  return name ? 'portal' : null
}

const GUIDANCE = Object.freeze({
  dashboard: {
    Student: ['Welcome back', 'Check your latest academic and enrollment updates when you are ready.'],
    Guest: ['Welcome to CDM Portal', 'You can review your Admission progress and verified application updates here.'],
  },
  enrollment: {
    Student: ['Enrollment', 'Review your enrollment status, requirements, subjects, and schedule in this module.'],
    Guest: ['Enrollment guidance', 'Follow the published enrollment steps and verify requirements before submitting information.'],
  },
  grading: {
    Student: ['My grades', 'Review officially released grades here. Contact your Professor or the Registrar through approved school channels if you need clarification.'],
  },
  documents: {
    Student: ['Document requests', 'Track request status, appointment details, and official claim instructions in this module.'],
  },
  admission: {
    Student: ['Admission', 'Review verified Admission results and application guidance in this module.'],
    Guest: ['Your Admission journey', 'Complete the required application steps and check your verified Admission status here.'],
  },
  portal: {
    Student: ['CDM Portal', 'I can help surface verified Portal updates while you work in this module.'],
    Guest: ['CDM Portal', 'I can help surface verified Admission and enrollment guidance while you are here.'],
  },
})

export const NOTICE_MODULES = Object.freeze({
  dashboard: ['campus_notice', 'academic_reminder', 'enrollment', 'admission'],
  enrollment: ['enrollment', 'registrar_reminder'],
  grading: ['academic_reminder'],
  documents: ['registrar_reminder'],
  admission: ['admission'],
  portal: ['campus_notice'],
})

export function buddyGuidanceFor(module, role, firstName = '') {
  const [title, message] = GUIDANCE[module]?.[role] || GUIDANCE.portal[role] || GUIDANCE.portal.Guest
  const personalizedTitle = module === 'dashboard' && role === 'Student' && String(firstName).trim()
    ? `Welcome back, ${String(firstName).trim()}`
    : title
  return {
    id: `context-${module}-${String(role).toLowerCase()}`,
    type: 'guidance', title: personalizedTitle, message, priority: 'low', kind: 'information',
    source: 'buddy_guide', source_label: 'CDM Buddy guide', link: null,
    acknowledgement: { required: false, acknowledged: false },
  }
}

export function noticeMatchesBuddyModule(notice, module) {
  const accepted = NOTICE_MODULES[module] || NOTICE_MODULES.portal
  return accepted.includes(notice?.type) || accepted.includes(notice?.source)
}

export function buddyMuteStorageKey(account) {
  return `cdm-buddy-auto-muted:${account || 'session'}`
}
