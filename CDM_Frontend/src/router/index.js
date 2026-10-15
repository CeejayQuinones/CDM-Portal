import { createRouter, createWebHashHistory } from 'vue-router'
import { useAuthStore } from '../stores/authStore'
import { ROLES, ROUTE_ROLES, canAccess, dashboardForRole } from '../config/accessControl'
import { SHELL_BLOCK_STORAGE_KEY, detectAppShell, shellBlockMessage } from '../config/appShell'
import { performanceMonitor } from '../services/performance/performanceMonitor'

const AuthLayout = () => import('../layouts/AuthLayout.vue')
const DashboardLayout = () => import('../layouts/DashboardLayout.vue')
const DashboardView = () => import('../views/DashboardView.vue')
const LoginView = () => import('../views/LoginView.vue')
const PublicLegalView = () => import('../views/PublicLegalView.vue')
const UnauthorizedView = () => import('../views/UnauthorizedView.vue')
const RoleDashboardView = () => import('../views/RoleDashboardView.vue')
const StudentDashboardView = () => import('../views/StudentDashboardView.vue')
const SettingsView = () => import('../views/SettingsView.vue')
const AdmissionView = () => import('../modules/admission/AdmissionView.vue')
const EnrollmentView = () => import('../modules/enrollment/EnrollmentView.vue')
const MonitoringView = () => import('../modules/monitoring/MonitoringView.vue')
const AdviserAlertsView = () => import('../modules/monitoring/AdviserAlertsView.vue')
const StudyPlansView = () => import('../modules/monitoring/StudyPlansView.vue')
const StudentRecordsView = () => import('../modules/student-management/StudentRecordsView.vue')
const StudentProfileView = () => import('../modules/student-management/StudentProfileView.vue')
const StudentDocumentsView = () => import('../modules/student-management/StudentDocumentsView.vue')
const PhysicalRecordsView = () => import('../modules/student-management/PhysicalRecordsView.vue')
const RegistrarDashboardView = () => import('../modules/registrar-dashboard/RegistrarDashboardView.vue')
const AdminDashboardView = () => import('../modules/admin-dashboard/AdminDashboardView.vue')

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
        meta: { title: 'Settings', roles: [ROLES.STUDENT] },
      }),
      protectedRoute({
        path: 'student-dashboard',
        name: 'student-dashboard',
        component: StudentDashboardView,
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
        component: AdminDashboardView,
        meta: {
          title: 'Admin Dashboard',
          roles: ROUTE_ROLES['admin-dashboard'],
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
        path: 'monitoring',
        name: 'monitoring',
        component: MonitoringView,
        meta: { title: 'Monitoring', roles: ROUTE_ROLES.monitoring },
      }),
      protectedRoute({
        path: 'monitoring/adviser-alerts',
        name: 'monitoring-adviser-alerts',
        component: AdviserAlertsView,
        meta: { title: 'Adviser Alerts', roles: [ROLES.PROFESSOR, ROLES.REGISTRAR_STAFF, ROLES.ADMIN] },
      }),
      protectedRoute({
        path: 'monitoring/study-plans',
        name: 'monitoring-study-plans',
        component: StudyPlansView,
        meta: { title: 'Study Plans', roles: ROUTE_ROLES.monitoring },
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

  if (authStore.isAuthenticated) {
    const blocked = shellBlockMessage(detectAppShell(), authStore.currentRole)
    if (blocked) {
      sessionStorage.setItem(SHELL_BLOCK_STORAGE_KEY, blocked)
      try {
        await authStore.logout()
      } catch {
        // The session is already cleared. Continue to the login screen.
      }
      if (to.name === 'login') return true
      return { name: 'login' }
    }
  }

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
