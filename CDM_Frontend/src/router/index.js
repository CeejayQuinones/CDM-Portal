import { createRouter, createWebHashHistory } from 'vue-router'
import { useAuthStore } from '../stores/authStore'
import { ROLES, ROUTE_ROLES, canAccess, dashboardForRole } from '../config/accessControl'
import { performanceMonitor } from '../services/performance/performanceMonitor'

const AuthLayout = () => import('../layouts/AuthLayout.vue')
const DashboardLayout = () => import('../layouts/DashboardLayout.vue')
const DashboardView = () => import('../views/DashboardView.vue')
const LoginView = () => import('../views/LoginView.vue')
const PublicLegalView = () => import('../views/PublicLegalView.vue')
const UnauthorizedView = () => import('../views/UnauthorizedView.vue')
const GuestDashboardView = () => import('../views/GuestDashboardView.vue')
const RoleDashboardView = () => import('../views/RoleDashboardView.vue')
const SettingsView = () => import('../views/SettingsView.vue')
const ComingSoonView = () => import('../views/ComingSoonView.vue')
const AdmissionView = () => import('../modules/admission/AdmissionView.vue')
const EnrollmentView = () => import('../modules/enrollment/EnrollmentView.vue')
const GradingView = () => import('../modules/grading/GradingView.vue')
const MonitoringView = () => import('../modules/monitoring/MonitoringView.vue')
const DocumentTypesManagementView = () => import('../modules/document-request/DocumentTypesManagementView.vue')
const StudentDocumentRequestView = () => import('../modules/document-request/StudentDocumentRequestView.vue')
const RegistrarDocumentRequestView = () => import('../modules/document-request/RegistrarDocumentRequestView.vue')
const RegistrarAppointmentsView = () => import('../modules/document-request/RegistrarAppointmentsView.vue')
const RegistrarDocumentRequestHistoryView = () =>
  import('../modules/document-request/RegistrarDocumentRequestHistoryView.vue')
const StudentRecordsView = () => import('../modules/student-management/StudentRecordsView.vue')
const StudentProfileView = () => import('../modules/student-management/StudentProfileView.vue')
const StudentDocumentsView = () => import('../modules/student-management/StudentDocumentsView.vue')
const PhysicalRecordsView = () => import('../modules/student-management/PhysicalRecordsView.vue')
const EventAttendanceView = () => import('../modules/event-attendance/EventAttendanceView.vue')
const RegistrarDashboardView = () => import('../modules/registrar-dashboard/RegistrarDashboardView.vue')

const protectedRoute = (route) => ({
  ...route,
  meta: { requiresAuth: true, ...route.meta },
})

const routes = [
  {
    path: '/login',
    component: AuthLayout,
    meta: { guestOnly: true },
    children: [
      {
        path: '',
        name: 'login',
        component: LoginView,
        meta: { title: 'Sign in', guestOnly: true },
      },
      {
        path: '/register',
        name: 'register',
        redirect: { name: 'login', query: { signup: '1' } },
        meta: { title: 'Register', guestOnly: true },
      },
    ],
  },
  {
    path: '/terms',
    name: 'terms',
    component: PublicLegalView,
    meta: { title: 'Terms & Conditions' },
  },
  {
    path: '/privacy',
    name: 'privacy',
    component: PublicLegalView,
    meta: { title: 'Privacy Policy' },
  },
  {
    path: '/',
    component: DashboardLayout,
    meta: { requiresAuth: true },
    children: [
      protectedRoute({
        path: '',
        name: 'home',
        component: DashboardView,
        meta: { title: 'Dashboard', roles: ROUTE_ROLES.home },
      }),
      protectedRoute({
        path: 'settings',
        name: 'settings',
        component: SettingsView,
        meta: { title: 'Settings', roles: ROUTE_ROLES.home },
      }),
      protectedRoute({
        path: 'guest-dashboard',
        name: 'guest-dashboard',
        component: GuestDashboardView,
        meta: {
          title: 'Guest Dashboard',
          roles: ROUTE_ROLES['guest-dashboard'],
        },
      }),
      protectedRoute({
        path: 'student-dashboard',
        name: 'student-dashboard',
        component: RoleDashboardView,
        props: { role: ROLES.STUDENT },
        meta: {
          title: 'Student Dashboard',
          roles: ROUTE_ROLES['student-dashboard'],
        },
      }),
      protectedRoute({
        path: 'professor-dashboard',
        name: 'professor-dashboard',
        component: RoleDashboardView,
        props: { role: ROLES.PROFESSOR },
        meta: {
          title: 'Professor Dashboard',
          roles: ROUTE_ROLES['professor-dashboard'],
        },
      }),
      protectedRoute({
        path: 'registrar-dashboard',
        name: 'registrar-dashboard',
        component: RegistrarDashboardView,
        meta: {
          title: 'Registrar Dashboard',
          roles: ROUTE_ROLES['registrar-dashboard'],
        },
      }),
      protectedRoute({
        path: 'admin-dashboard',
        name: 'admin-dashboard',
        component: RoleDashboardView,
        props: { role: ROLES.ADMIN },
        meta: {
          title: 'Admin Dashboard',
          roles: ROUTE_ROLES['admin-dashboard'],
        },
      }),
      protectedRoute({
        path: 'profile',
        name: 'guest-profile',
        component: ComingSoonView,
        props: {
          title: 'My Profile',
          description: 'Profile management will be delivered by its assigned module team.',
        },
        meta: { title: 'My Profile', roles: ROUTE_ROLES['guest-profile'] },
      }),
      protectedRoute({
        path: 'activate-student-account',
        name: 'activate-student-account',
        component: ComingSoonView,
        props: {
          title: 'Activate Student Account',
          description:
            'Student account activation will be available after registrar verification workflows are released.',
        },
        meta: {
          title: 'Activate Student Account',
          roles: ROUTE_ROLES['activate-student-account'],
        },
      }),
      protectedRoute({
        path: 'admission',
        name: 'admission',
        component: AdmissionView,
        meta: { title: 'Admission', roles: ROUTE_ROLES.admission },
      }),
      protectedRoute({
        path: 'enrollment',
        name: 'enrollment',
        component: EnrollmentView,
        meta: { title: 'Enrollment', roles: ROUTE_ROLES.enrollment },
      }),
      protectedRoute({
        path: 'grading',
        name: 'grading',
        component: GradingView,
        meta: { title: 'Grading', roles: ROUTE_ROLES.grading },
      }),
      protectedRoute({
        path: 'monitoring',
        name: 'monitoring',
        component: MonitoringView,
        meta: { title: 'Monitoring', roles: ROUTE_ROLES.monitoring },
      }),
      protectedRoute({
        path: 'document-requests',
        name: 'student-document-requests',
        component: StudentDocumentRequestView,
        meta: {
          title: 'Document Requests',
          roles: ROUTE_ROLES['student-document-requests'],
        },
      }),
      protectedRoute({
        path: 'document-requests/appointments',
        name: 'student-document-appointments',
        redirect: (to) => ({
          name: 'student-document-requests',
          query: { ...to.query, panel: 'appointment' },
        }),
        meta: {
          title: 'Document Requests',
          roles: ROUTE_ROLES['student-document-appointments'],
        },
      }),
      protectedRoute({
        path: 'registrar/document-types',
        name: 'registrar-document-types',
        component: DocumentTypesManagementView,
        meta: {
          title: 'Document Types',
          roles: ROUTE_ROLES['registrar-document-types'],
        },
      }),
      protectedRoute({
        path: 'registrar/document-requests',
        name: 'registrar-document-requests',
        component: RegistrarDocumentRequestView,
        meta: {
          title: 'Document Requests',
          roles: ROUTE_ROLES['registrar-document-requests'],
        },
      }),
      protectedRoute({
        path: 'registrar/appointments',
        name: 'registrar-document-appointments',
        component: RegistrarAppointmentsView,
        meta: {
          title: 'Appointments & Release',
          roles: ROUTE_ROLES['registrar-document-appointments'],
        },
      }),
      protectedRoute({
        path: 'registrar/document-requests/history',
        name: 'registrar-document-request-history',
        component: RegistrarDocumentRequestHistoryView,
        meta: {
          title: 'Document Request History',
          roles: ROUTE_ROLES['registrar-document-request-history'],
        },
      }),
      protectedRoute({
        path: 'student-management',
        name: 'student-management',
        component: StudentRecordsView,
        meta: {
          title: 'Student Management',
          roles: ROUTE_ROLES['student-management'],
        },
      }),
      protectedRoute({
        path: 'student-management/physical-records',
        name: 'physical-records',
        component: PhysicalRecordsView,
        meta: {
          title: 'Physical Records',
          roles: ROUTE_ROLES['physical-records'],
        },
      }),
      protectedRoute({
        path: 'student-management/:id',
        name: 'student-details',
        component: StudentProfileView,
        meta: {
          title: 'Student Profile',
          roles: ROUTE_ROLES['student-management'],
        },
      }),
      protectedRoute({
        path: 'student-management/:id/documents',
        name: 'student-documents',
        component: StudentDocumentsView,
        meta: {
          title: 'Student Documents',
          roles: ROUTE_ROLES['student-management'],
        },
      }),
      protectedRoute({
        path: 'event-attendance',
        name: 'event-attendance',
        component: EventAttendanceView,
        meta: {
          title: 'Event Attendance',
          roles: ROUTE_ROLES['event-attendance'],
        },
      }),
      protectedRoute({
        path: 'unauthorized',
        name: 'unauthorized',
        component: UnauthorizedView,
        meta: { title: 'Unauthorized', roles: ROUTE_ROLES.home },
      }),
    ],
  },
  { path: '/:pathMatch(.*)*', redirect: '/' },
]

const router = createRouter({ history: createWebHashHistory(), routes })

router.beforeEach(async (to) => {
  performanceMonitor.beginRoute(to.name || to.path)
  const authStore = useAuthStore()
  await authStore.initialize()

  if (to.matched.some((record) => record.meta.guestOnly) && authStore.isAuthenticated)
    return dashboardForRole(authStore.currentRole)
  if (!to.matched.some((record) => record.meta.requiresAuth)) return true
  if (!authStore.isAuthenticated) return { name: 'login', query: { redirect: to.fullPath } }
  if (to.name === 'home') return dashboardForRole(authStore.currentRole)
  if (!canAccess(authStore.currentRole, to.meta.roles || ALL_ROLES)) return { name: 'unauthorized' }
  return true
})

router.afterEach((to) => {
  document.title = to.meta.title ? `${to.meta.title} | CDM Portal` : 'CDM Portal'
  requestAnimationFrame(() => performanceMonitor.markRouteRendered())
})

export default router
