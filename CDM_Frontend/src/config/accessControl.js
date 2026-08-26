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
  'student-document-requests': [ROLES.STUDENT],
  'student-document-appointments': [ROLES.STUDENT],
  'registrar-document-types': [ROLES.REGISTRAR_STAFF],
  'registrar-document-requests': [ROLES.REGISTRAR_STAFF],
  'registrar-document-appointments': [ROLES.REGISTRAR_STAFF],
  'registrar-document-request-history': [ROLES.REGISTRAR_STAFF],
  'activate-student-account': [ROLES.GUEST],
  admission: [ROLES.REGISTRAR_STAFF, ROLES.ADMIN],
  enrollment: [ROLES.REGISTRAR_STAFF, ROLES.ADMIN],
  grading: [ROLES.STUDENT, ROLES.PROFESSOR, ROLES.ADMIN],
  monitoring: [ROLES.PROFESSOR, ROLES.REGISTRAR_STAFF, ROLES.ADMIN],
  'student-management': [ROLES.REGISTRAR_STAFF, ROLES.ADMIN],
  'physical-records': [ROLES.REGISTRAR_STAFF],
  'event-attendance': [ROLES.STUDENT, ROLES.PROFESSOR, ROLES.REGISTRAR_STAFF, ROLES.ADMIN],
})

export const NAVIGATION_ITEMS = Object.freeze([
  {
    name: 'registrar-dashboard',
    label: 'Registrar Dashboard',
    path: '/registrar-dashboard',
    icon: 'DB',
    roles: ROUTE_ROLES['registrar-dashboard'],
  },
  {
    name: 'guest-dashboard',
    label: 'Dashboard',
    path: '/guest-dashboard',
    icon: 'DB',
    roles: ROUTE_ROLES['guest-dashboard'],
  },
  {
    name: 'guest-profile',
    label: 'My Profile',
    path: '/profile',
    icon: 'PR',
    roles: ROUTE_ROLES['guest-profile'],
  },
  {
    name: 'document-requests-menu',
    label: 'Document Requests',
    icon: 'DR',
    roles: [ROLES.STUDENT, ROLES.REGISTRAR_STAFF],
    children: [
      {
        name: 'student-document-requests',
        label: 'Document Requests',
        path: '/document-requests',
        roles: ROUTE_ROLES['student-document-requests'],
      },
      {
        name: 'student-document-appointments',
        label: 'Appointments',
        path: '/document-requests/appointments',
        roles: ROUTE_ROLES['student-document-appointments'],
      },
      {
        name: 'registrar-document-types',
        label: 'Document Types',
        path: '/registrar/document-types',
        roles: ROUTE_ROLES['registrar-document-types'],
      },
      {
        name: 'registrar-document-requests',
        label: 'Document Requests',
        path: '/registrar/document-requests',
        roles: ROUTE_ROLES['registrar-document-requests'],
      },
      {
        name: 'registrar-document-appointments',
        label: 'Appointments',
        path: '/registrar/appointments',
        roles: ROUTE_ROLES['registrar-document-appointments'],
      },
      {
        name: 'registrar-document-request-history',
        label: 'History',
        path: '/registrar/document-requests/history',
        roles: ROUTE_ROLES['registrar-document-request-history'],
      },
    ],
  },
  {
    name: 'activate-student-account',
    label: 'Activate Student Account',
    path: '/activate-student-account',
    icon: 'AC',
    roles: ROUTE_ROLES['activate-student-account'],
    comingSoon: true,
  },
  {
    name: 'student-dashboard',
    label: 'Student Dashboard',
    path: '/student-dashboard',
    icon: 'DB',
    roles: ROUTE_ROLES['student-dashboard'],
  },
  {
    name: 'professor-dashboard',
    label: 'Professor Dashboard',
    path: '/professor-dashboard',
    icon: 'DB',
    roles: ROUTE_ROLES['professor-dashboard'],
  },
  {
    name: 'admin-dashboard',
    label: 'Admin Dashboard',
    path: '/admin-dashboard',
    icon: 'DB',
    roles: ROUTE_ROLES['admin-dashboard'],
  },
  {
    name: 'admission',
    label: 'Admission',
    path: '/admission',
    icon: 'AD',
    roles: ROUTE_ROLES.admission,
  },
  {
    name: 'enrollment',
    label: 'Enrollment',
    path: '/enrollment',
    icon: 'EN',
    roles: ROUTE_ROLES.enrollment,
  },
  {
    name: 'grading',
    label: 'Grading',
    path: '/grading',
    icon: 'GR',
    roles: ROUTE_ROLES.grading,
  },
  {
    name: 'monitoring',
    label: 'Monitoring',
    path: '/monitoring',
    icon: 'MO',
    roles: ROUTE_ROLES.monitoring,
  },
  {
    name: 'student-management-menu',
    label: 'Student Management',
    icon: 'SM',
    roles: ROUTE_ROLES['student-management'],
    children: [
      {
        name: 'student-management',
        label: 'Students',
        path: '/student-management',
        activeRoutes: ['student-management', 'student-details', 'student-documents'],
        roles: ROUTE_ROLES['student-management'],
      },
      {
        name: 'physical-records',
        label: 'Physical Records',
        path: '/student-management/physical-records',
        roles: ROUTE_ROLES['physical-records'],
      },
    ],
  },
  {
    name: 'event-attendance',
    label: 'Event Attendance',
    path: '/event-attendance',
    icon: 'EV',
    roles: ROUTE_ROLES['event-attendance'],
  },
])

export const canAccess = (role, allowedRoles = []) => allowedRoles.includes(role)
