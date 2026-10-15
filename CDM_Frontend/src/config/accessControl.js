export const ROLES = Object.freeze({
  STUDENT: 'Student',
  PROFESSOR: 'Professor',
  REGISTRAR_STAFF: 'Registrar Staff',
  ADMIN: 'Admin',
})

export const ROLE_DASHBOARDS = Object.freeze({
  [ROLES.STUDENT]: '/student-dashboard',
  [ROLES.PROFESSOR]: '/professor-dashboard',
  [ROLES.REGISTRAR_STAFF]: '/registrar-dashboard',
  [ROLES.ADMIN]: '/admin-dashboard',
})

export const dashboardForRole = (role) => ROLE_DASHBOARDS[role] || '/unauthorized'

export const ROUTE_ROLES = Object.freeze({
  home: Object.values(ROLES),
  'student-dashboard': [ROLES.STUDENT],
  'professor-dashboard': [ROLES.PROFESSOR],
  'registrar-dashboard': [ROLES.REGISTRAR_STAFF],
  'admin-dashboard': [ROLES.ADMIN],
  settings: [ROLES.STUDENT],
  admission: [ROLES.REGISTRAR_STAFF, ROLES.ADMIN],
  enrollment: [ROLES.REGISTRAR_STAFF, ROLES.ADMIN],
  monitoring: [ROLES.STUDENT, ROLES.PROFESSOR, ROLES.REGISTRAR_STAFF, ROLES.ADMIN],
  'student-management': [ROLES.REGISTRAR_STAFF, ROLES.ADMIN],
  'physical-records': [ROLES.REGISTRAR_STAFF],
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
    name: 'settings',
    label: 'Settings',
    path: '/settings',
    icon: 'SE',
    roles: ROUTE_ROLES.settings,
  },
])

export const canAccess = (role, allowedRoles = []) => allowedRoles.includes(role)
