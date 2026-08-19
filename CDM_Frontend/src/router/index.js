import { createRouter, createWebHashHistory } from 'vue-router'
import { useAuthStore } from '../stores/authStore'
import { ROLES, ROUTE_ROLES, canAccess, dashboardForRole } from '../config/accessControl'
import AuthLayout from '../layouts/AuthLayout.vue'
import DashboardLayout from '../layouts/DashboardLayout.vue'
import DashboardView from '../views/DashboardView.vue'
import LoginView from '../views/LoginView.vue'
import RegisterView from '../views/RegisterView.vue'
import UnauthorizedView from '../views/UnauthorizedView.vue'
import GuestDashboardView from '../views/GuestDashboardView.vue'
import RoleDashboardView from '../views/RoleDashboardView.vue'
import ComingSoonView from '../views/ComingSoonView.vue'
import AdmissionView from '../modules/admission/AdmissionView.vue'
import EnrollmentView from '../modules/enrollment/EnrollmentView.vue'
import GradingView from '../modules/grading/GradingView.vue'
import MonitoringView from '../modules/monitoring/MonitoringView.vue'
import DocumentRequestView from '../modules/document-request/DocumentRequestView.vue'
import StudentRecordsView from '../modules/student-management/StudentRecordsView.vue'
import StudentProfileView from '../modules/student-management/StudentProfileView.vue'
import StudentDocumentsView from '../modules/student-management/StudentDocumentsView.vue'
import EventAttendanceView from '../modules/event-attendance/EventAttendanceView.vue'

const protectedRoute = (route) => ({ ...route, meta: { requiresAuth: true, ...route.meta } })

const routes = [
  {
    path: '/login', component: AuthLayout, meta: { guestOnly: true }, children: [
      { path: '', name: 'login', component: LoginView, meta: { title: 'Sign in', guestOnly: true } },
      { path: '/register', name: 'register', component: RegisterView, meta: { title: 'Register', guestOnly: true } },
    ],
  },
  {
    path: '/', component: DashboardLayout, meta: { requiresAuth: true }, children: [
      protectedRoute({ path: '', name: 'home', component: DashboardView, meta: { title: 'Dashboard', roles: ROUTE_ROLES.home } }),
      protectedRoute({ path: 'guest-dashboard', name: 'guest-dashboard', component: GuestDashboardView, meta: { title: 'Guest Dashboard', roles: ROUTE_ROLES['guest-dashboard'] } }),
      protectedRoute({ path: 'student-dashboard', name: 'student-dashboard', component: RoleDashboardView, props: { role: ROLES.STUDENT }, meta: { title: 'Student Dashboard', roles: ROUTE_ROLES['student-dashboard'] } }),
      protectedRoute({ path: 'professor-dashboard', name: 'professor-dashboard', component: RoleDashboardView, props: { role: ROLES.PROFESSOR }, meta: { title: 'Professor Dashboard', roles: ROUTE_ROLES['professor-dashboard'] } }),
      protectedRoute({ path: 'registrar-dashboard', name: 'registrar-dashboard', component: RoleDashboardView, props: { role: ROLES.REGISTRAR_STAFF }, meta: { title: 'Registrar Dashboard', roles: ROUTE_ROLES['registrar-dashboard'] } }),
      protectedRoute({ path: 'admin-dashboard', name: 'admin-dashboard', component: RoleDashboardView, props: { role: ROLES.ADMIN }, meta: { title: 'Admin Dashboard', roles: ROUTE_ROLES['admin-dashboard'] } }),
      protectedRoute({ path: 'profile', name: 'guest-profile', component: ComingSoonView, props: { title: 'My Profile', description: 'Profile management will be delivered by its assigned module team.' }, meta: { title: 'My Profile', roles: ROUTE_ROLES['guest-profile'] } }),
      protectedRoute({ path: 'appointments', name: 'appointments', component: ComingSoonView, props: { title: 'Appointments', description: 'Appointment booking will be available in a future module release.' }, meta: { title: 'Appointments', roles: ROUTE_ROLES.appointments } }),
      protectedRoute({ path: 'activate-student-account', name: 'activate-student-account', component: ComingSoonView, props: { title: 'Activate Student Account', description: 'Student account activation will be available after registrar verification workflows are released.' }, meta: { title: 'Activate Student Account', roles: ROUTE_ROLES['activate-student-account'] } }),
      protectedRoute({ path: 'admission', name: 'admission', component: AdmissionView, meta: { title: 'Admission', roles: ROUTE_ROLES.admission } }),
      protectedRoute({ path: 'enrollment', name: 'enrollment', component: EnrollmentView, meta: { title: 'Enrollment', roles: ROUTE_ROLES.enrollment } }),
      protectedRoute({ path: 'grading', name: 'grading', component: GradingView, meta: { title: 'Grading', roles: ROUTE_ROLES.grading } }),
      protectedRoute({ path: 'monitoring', name: 'monitoring', component: MonitoringView, meta: { title: 'Monitoring', roles: ROUTE_ROLES.monitoring } }),
      protectedRoute({ path: 'document-request', name: 'document-request', component: DocumentRequestView, meta: { title: 'Document Requests', roles: ROUTE_ROLES['document-request'] } }),
      protectedRoute({ path: 'student-management', name: 'student-management', component: StudentRecordsView, meta: { title: 'Student Management', roles: ROUTE_ROLES['student-management'] } }),
      protectedRoute({ path: 'student-management/:id', name: 'student-details', component: StudentProfileView, meta: { title: 'Student Profile', roles: ROUTE_ROLES['student-management'] } }),
      protectedRoute({ path: 'student-management/:id/documents', name: 'student-documents', component: StudentDocumentsView, meta: { title: 'Student Documents', roles: ROUTE_ROLES['student-management'] } }),
      protectedRoute({ path: 'event-attendance', name: 'event-attendance', component: EventAttendanceView, meta: { title: 'Event Attendance', roles: ROUTE_ROLES['event-attendance'] } }),
      protectedRoute({ path: 'unauthorized', name: 'unauthorized', component: UnauthorizedView, meta: { title: 'Unauthorized', roles: ROUTE_ROLES.home } }),
    ],
  },
  { path: '/:pathMatch(.*)*', redirect: '/' },
]

const router = createRouter({ history: createWebHashHistory(), routes })

router.beforeEach(async (to) => {
  const authStore = useAuthStore()
  await authStore.initialize()

  if (to.matched.some((record) => record.meta.guestOnly) && authStore.isAuthenticated) return dashboardForRole(authStore.currentRole)
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
