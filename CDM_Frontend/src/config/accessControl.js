export const ROLES = Object.freeze({
  GUEST: 'Guest',
  STUDENT: 'Student',
  PROFESSOR: 'Professor',
  REGISTRAR_STAFF: 'Registrar Staff',
  ADMIN: 'Admin',
})

export const ROLE_DASHBOARDS = Object.freeze({
  [ROLES.GUEST]: '/guest-dashboard',
  [ROLES.STUDENT]: '/student-dashboard',
  [ROLES.PROFESSOR]: '/professor-dashboard',
  [ROLES.REGISTRAR_STAFF]: '/registrar-dashboard',
  [ROLES.ADMIN]: '/admin-dashboard',
})

export const dashboardForRole = (role) => ROLE_DASHBOARDS[role] || '/unauthorized'

export const ROUTE_ROLES = Object.freeze({
  home: Object.values(ROLES),
  'guest-dashboard': [ROLES.GUEST],
  'student-dashboard': [ROLES.STUDENT],
  'professor-dashboard': [ROLES.PROFESSOR],
  'registrar-dashboard': [ROLES.REGISTRAR_STAFF],
  'admin-dashboard': [ROLES.ADMIN],
  'guest-profile': [ROLES.GUEST],
  'document-request': Object.values(ROLES),
  appointments: [ROLES.GUEST],
  'activate-student-account': [ROLES.GUEST],
  admission: [ROLES.REGISTRAR_STAFF, ROLES.ADMIN],
  enrollment: [ROLES.REGISTRAR_STAFF, ROLES.ADMIN],
  grading: [ROLES.STUDENT, ROLES.PROFESSOR, ROLES.ADMIN],
  monitoring: [ROLES.PROFESSOR, ROLES.REGISTRAR_STAFF, ROLES.ADMIN],
  'student-management': [ROLES.REGISTRAR_STAFF, ROLES.ADMIN],
  'event-attendance': [ROLES.STUDENT, ROLES.PROFESSOR, ROLES.REGISTRAR_STAFF, ROLES.ADMIN],
})

export const NAVIGATION_ITEMS = Object.freeze([
  { name: 'guest-dashboard', label: 'Dashboard', path: '/guest-dashboard', icon: 'DB', roles: ROUTE_ROLES['guest-dashboard'] },
  { name: 'guest-profile', label: 'My Profile', path: '/profile', icon: 'PR', roles: ROUTE_ROLES['guest-profile'] },
  { name: 'document-request', label: 'Document Requests', path: '/document-request', icon: 'DR', roles: ROUTE_ROLES['document-request'] },
  { name: 'appointments', label: 'Appointments', path: '/appointments', icon: 'AP', roles: ROUTE_ROLES.appointments },
  { name: 'activate-student-account', label: 'Activate Student Account', path: '/activate-student-account', icon: 'AC', roles: ROUTE_ROLES['activate-student-account'], comingSoon: true },
  { name: 'student-dashboard', label: 'Student Dashboard', path: '/student-dashboard', icon: 'DB', roles: ROUTE_ROLES['student-dashboard'] },
  { name: 'professor-dashboard', label: 'Professor Dashboard', path: '/professor-dashboard', icon: 'DB', roles: ROUTE_ROLES['professor-dashboard'] },
  { name: 'registrar-dashboard', label: 'Registrar Dashboard', path: '/registrar-dashboard', icon: 'DB', roles: ROUTE_ROLES['registrar-dashboard'] },
  { name: 'admin-dashboard', label: 'Admin Dashboard', path: '/admin-dashboard', icon: 'DB', roles: ROUTE_ROLES['admin-dashboard'] },
  { name: 'admission', label: 'Admission', path: '/admission', icon: 'AD', roles: ROUTE_ROLES.admission },
  { name: 'enrollment', label: 'Enrollment', path: '/enrollment', icon: 'EN', roles: ROUTE_ROLES.enrollment },
  { name: 'grading', label: 'Grading', path: '/grading', icon: 'GR', roles: ROUTE_ROLES.grading },
  { name: 'monitoring', label: 'Monitoring', path: '/monitoring', icon: 'MO', roles: ROUTE_ROLES.monitoring },
  { name: 'student-management', label: 'Student Management', path: '/student-management', icon: 'SM', roles: ROUTE_ROLES['student-management'] },
  { name: 'event-attendance', label: 'Event Attendance', path: '/event-attendance', icon: 'EV', roles: ROUTE_ROLES['event-attendance'] },
])

export const canAccess = (role, allowedRoles = []) => allowedRoles.includes(role)
