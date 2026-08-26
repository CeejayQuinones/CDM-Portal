import { createRouter, createWebHashHistory } from 'vue-router'
import { useAuthStore } from '../stores/authStore'
import { ROLES, ROUTE_ROLES, canAccess, dashboardForRole } from '../config/accessControl'
import AuthLayout from '../layouts/AuthLayout.vue'
import DashboardLayout from '../layouts/DashboardLayout.vue'
import DashboardView from '../views/DashboardView.vue'
import LoginView from '../views/LoginView.vue'
import PublicLegalView from '../views/PublicLegalView.vue'
import UnauthorizedView from '../views/UnauthorizedView.vue'
import GuestDashboardView from '../views/GuestDashboardView.vue'
import RoleDashboardView from '../views/RoleDashboardView.vue'
import SettingsView from '../views/SettingsView.vue'
import ComingSoonView from '../views/ComingSoonView.vue'
import AdmissionView from '../modules/admission/AdmissionView.vue'
import EnrollmentView from '../modules/enrollment/EnrollmentView.vue'
import GradingView from '../modules/grading/GradingView.vue'
import MonitoringView from '../modules/monitoring/MonitoringView.vue'
import DocumentTypesManagementView from '../modules/document-request/DocumentTypesManagementView.vue'
import StudentDocumentRequestView from '../modules/document-request/StudentDocumentRequestView.vue'
import StudentAppointmentsView from '../modules/document-request/StudentAppointmentsView.vue'
import RegistrarDocumentRequestView from '../modules/document-request/RegistrarDocumentRequestView.vue'
import RegistrarAppointmentsView from '../modules/document-request/RegistrarAppointmentsView.vue'
import RegistrarDocumentRequestHistoryView from '../modules/document-request/RegistrarDocumentRequestHistoryView.vue'
import StudentRecordsView from '../modules/student-management/StudentRecordsView.vue'
import StudentProfileView from '../modules/student-management/StudentProfileView.vue'
import StudentDocumentsView from '../modules/student-management/StudentDocumentsView.vue'
import PhysicalRecordsView from '../modules/student-management/PhysicalRecordsView.vue'
import EventAttendanceView from '../modules/event-attendance/EventAttendanceView.vue'

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
        component: RoleDashboardView,
        props: { role: ROLES.REGISTRAR_STAFF },
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
        component: StudentAppointmentsView,
        meta: {
          title: 'Appointments',
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
          title: 'Appointments',
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
})

export default router
