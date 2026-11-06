import { createRouter, createWebHashHistory } from 'vue-router'
import { admissionRoutes } from '../modules/admission/routes.js'
import { useAuthStore } from '../stores/authStore'
import { ROLES, ROUTE_ROLES, canAccess, dashboardForRole } from '../config/accessControl'
import { performanceMonitor } from '../services/performance/performanceMonitor'
import { clientPlatform, isRoleAllowedOnClient } from '../config/clientPlatform'

const AuthLayout = () => import('../layouts/AuthLayout.vue')
const DashboardLayout = () => import('../layouts/DashboardLayout.vue')
const DashboardView = () => import('../views/DashboardView.vue')
const LoginView = () => import('../views/LoginView.vue')
const PublicLegalView = () => import('../views/PublicLegalView.vue')
const UnauthorizedView = () => import('../views/UnauthorizedView.vue')
const GuestDashboardView = () => import('../views/GuestDashboardView.vue')
const RoleDashboardView = () => import('../views/RoleDashboardView.vue')
const StudentDashboardView = () => import('../modules/dashboard/StudentDashboardView.vue')
const ProfessorDashboardView = () => import('../modules/dashboard/ProfessorDashboardView.vue')
const AdminDashboardView = () => import('../modules/dashboard/AdminDashboardView.vue')
const SettingsView = () => import('../views/SettingsView.vue')
const EnrollmentAcademicView = () => import('../modules/enrollment/EnrollmentAcademicView.vue')
const EnrollmentSubjectManagement = () => import('../modules/enrollment/EnrollmentSubjectManagement.vue')
const EnrollmentView = () => import('../modules/enrollment/EnrollmentView.vue')
const EnrollmentCourses = () => import('../modules/enrollment/EnrollmentCourses.vue')
const EnrollmentCurriculum = () => import('../modules/enrollment/EnrollmentCurriculum.vue')
const EnrollmentSteps = () => import('../modules/enrollment/EnrollmentSteps.vue')
const GradingView = () => import('../modules/grading/GradingView.vue')
const ProfessorGradeSubmissionView = () => import('../modules/grading/ProfessorGradeSubmissionView.vue')
const StudentGradeHistoryView = () => import('../modules/grading/StudentGradeHistoryView.vue')
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
const StudentRecordReportsView = () => import('../modules/student-management/StudentRecordReportsView.vue')
const RegistrarDashboardView = () => import('../modules/registrar-dashboard/RegistrarDashboardView.vue')
const AccountProvisioningView = () => import('../modules/account-management/AccountProvisioningView.vue')
const CampusNoticesView = () => import('../modules/campus-notices/CampusNoticesView.vue')

const protectedRoute = (route) => ({
  ...route,
  meta: { requiresAuth: true, ...route.meta },
})

const landingForRole = (role) => dashboardForRole(role)

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
        meta: { title: 'Settings', roles: [ROLES.STUDENT] },
      }),
      protectedRoute({
        path: 'professor/settings',
        name: 'professor-settings',
        component: SettingsView,
        meta: { title: 'Professor Settings', roles: ROUTE_ROLES['professor-settings'], platforms: ['web'] },
      }),
      protectedRoute({
        path: 'registrar/settings',
        name: 'registrar-settings',
        component: SettingsView,
        props: { staffMode: true },
        meta: { title: 'Registrar Settings', roles: ROUTE_ROLES['registrar-settings'] },
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
        component: StudentDashboardView,
        meta: {
          title: 'Student Dashboard',
          roles: ROUTE_ROLES['student-dashboard'],
        },
      }),
      protectedRoute({
        path: 'professor-dashboard',
        name: 'professor-dashboard',
        component: ProfessorDashboardView,
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
      protectedRoute({ path: 'admin/registrars', name: 'registrar-management', component: AccountProvisioningView, meta: { title: 'Registrar Management', roles: ROUTE_ROLES['registrar-management'] } }),
      protectedRoute({ path: 'registrar/professors', name: 'professor-management', component: AccountProvisioningView, meta: { title: 'Professor Management', roles: ROUTE_ROLES['professor-management'] } }),
      protectedRoute({ path: 'campus-notices', name: 'campus-notices', component: CampusNoticesView, meta: { title: 'Campus Notices', roles: ROUTE_ROLES['campus-notices'] } }),
      ...admissionRoutes.map(protectedRoute),
      protectedRoute({ path: 'enrollment/sections', name: 'enrollment-sections', component: EnrollmentAcademicView, meta: { title: 'Sections', roles: ROUTE_ROLES['enrollment-sections'] } }),
      protectedRoute({ path: 'enrollment/subject-management', name: 'enrollment-subject-management', component: EnrollmentSubjectManagement, meta: { title: 'Subjects', roles: ROUTE_ROLES['enrollment-subject-management'] } }),
      protectedRoute({ path: 'enrollment/scheduling', name: 'enrollment-scheduling', component: EnrollmentAcademicView, meta: { title: 'Scheduling', roles: ROUTE_ROLES['enrollment-scheduling'] } }),
      protectedRoute({ path: 'enrollment/records', name: 'enrollment-records', component: EnrollmentAcademicView, meta: { title: 'Enrollment Records', roles: ROUTE_ROLES['enrollment-records'] } }),
      protectedRoute({ path: 'enrollment/subjects', name: 'enrollment-subjects', component: EnrollmentAcademicView, meta: { title: 'Subjects', roles: ROUTE_ROLES['enrollment-subjects'] } }),
      protectedRoute({ path: 'enrollment/schedule', name: 'enrollment-schedule', component: EnrollmentAcademicView, meta: { title: 'My Schedule', roles: ROUTE_ROLES['enrollment-schedule'] } }),
      protectedRoute({ path: 'enrollment/cor', name: 'enrollment-cor', component: EnrollmentAcademicView, meta: { title: 'COR', roles: ROUTE_ROLES['enrollment-cor'] } }),
      protectedRoute({ path: 'enrollment/teaching', name: 'enrollment-teaching', component: EnrollmentAcademicView, meta: { title: 'My Teaching Assignments', roles: ROUTE_ROLES['enrollment-teaching'] } }),
      protectedRoute({ path: 'enrollment/courses', name: 'enrollment-courses', component: EnrollmentCourses, meta: { title: 'Courses Offered', roles: ROUTE_ROLES['enrollment-courses'], platforms: ['web'] } }),
      protectedRoute({ path: 'enrollment/courses/:courseId/curriculum', name: 'enrollment-curriculum', component: EnrollmentCurriculum, meta: { title: 'Program Curriculum', roles: ROUTE_ROLES['enrollment-curriculum'], platforms: ['web'] } }),
      protectedRoute({ path: 'enrollment/steps', name: 'enrollment-steps', component: EnrollmentSteps, meta: { title: 'Enrollment Guide', roles: ROUTE_ROLES['enrollment-steps'], platforms: ['web'] } }),
      protectedRoute({
        path: 'enrollment/applications',
        name: 'enrollment-applications',
        component: EnrollmentView,
        meta: { title: 'Enrollment Applications', roles: ROUTE_ROLES['enrollment-applications'] },
      }),
      protectedRoute({
        path: 'enrollment/periods',
        name: 'enrollment-periods',
        component: EnrollmentView,
        meta: { title: 'Enrollment Periods', roles: ROUTE_ROLES['enrollment-periods'] },
      }),
      protectedRoute({
        path: 'enrollment/status',
        name: 'enrollment-status',
        component: EnrollmentView,
        meta: { title: 'Enrollment status', roles: ROUTE_ROLES['enrollment-status'] },
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
        path: 'grading/submission',
        name: 'grading-submission',
        component: ProfessorGradeSubmissionView,
        meta: { title: 'Grade Submission', roles: ROUTE_ROLES['grading-submission'], platforms: ['web'] },
      }),
      protectedRoute({
        path: 'grading/student-history',
        name: 'grading-student-history',
        component: StudentGradeHistoryView,
        meta: { title: 'Student Grade History', roles: ROUTE_ROLES['grading-student-history'] },
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
          query: { ...to.query },
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
        path: 'student-management/reports',
        name: 'student-record-reports',
        component: StudentRecordReportsView,
        meta: {
          title: 'Student Records Reports',
          roles: ROUTE_ROLES['student-record-reports'],
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

  if (to.matched.some((record) => record.meta.guestOnly) && authStore.isAuthenticated)
    return landingForRole(authStore.currentRole)
  if (!to.matched.some((record) => record.meta.requiresAuth)) return true
  if (!authStore.isAuthenticated) return { name: 'login', query: { redirect: to.fullPath } }
  if (!isRoleAllowedOnClient(authStore.currentRole)) {
    authStore.clearAuth()
    return { name: 'login', query: { platformDenied: '1' } }
  }
  if (to.meta.platforms && !to.meta.platforms.includes(clientPlatform)) return { name: 'unauthorized' }
  if (to.name === 'home') return landingForRole(authStore.currentRole)
  if (!canAccess(authStore.currentRole, to.meta.roles || ALL_ROLES)) return { name: 'unauthorized' }
  const admission = authStore.currentUser?.admission || {}
  if (authStore.currentRole === ROLES.GUEST && to.path.startsWith('/enrollment') && !admission.admitted_guest)
    return { name: 'unauthorized' }
  if ((admission.completed || authStore.currentRole === ROLES.STUDENT) && ['/admission/exam', '/admission/result', '/admission/recommendation'].includes(to.path))
    return { name: 'admission' }
  return true
})

router.afterEach((to) => {
  document.title = to.meta.title ? `${to.meta.title} | CDM Portal` : 'CDM Portal'
  requestAnimationFrame(() => performanceMonitor.markRouteRendered())
})

export default router
