import { ROUTE_ROLES } from '../../config/accessControl.js'

const AdmissionView = () => import('./AdmissionView.vue')
const Exam = () => import('./AdmissionExamView.vue')
const Result = () => import('./AdmissionResultView.vue')
const Recommendation = () => import('./AdmissionRecommendationView.vue')
const Exams = () => import('./AdmissionExamsView.vue')
const Admin = () => import('./AdmissionAdminView.vue')
const Registrar = () => import('./AdmissionRegistrarView.vue')

// Paths are relative to the existing authenticated DashboardLayout.
export const admissionRoutes = [
  { name: 'admission', path: 'admission', title: 'Admission Status' },
  { name: 'student-admission-exam', path: 'admission/exam', title: 'Entrance Exam' },
  { name: 'student-admission-result', path: 'admission/result', title: 'Result' },
  { name: 'student-admission-recommendation', path: 'admission/recommendation', title: 'Recommendation' },
  { name: 'registrar-admissions', path: 'registrar/admissions', title: 'Applicants' },
  { name: 'registrar-admission-results', path: 'registrar/admissions/results', title: 'Results' },
  { name: 'registrar-admission-history', path: 'registrar/admissions/history', title: 'Admission History' },
  { name: 'admin-admission-exams', path: 'admin/admissions/exams', title: 'Exams' },
  { name: 'admin-admission-questions', path: 'admin/admissions/questions', title: 'Exam Questions' },
  { name: 'admin-admission-programs', path: 'admin/admissions/programs', title: 'Programs' },
].map(({ name, path, title }) => ({
  name,
  path,
  component: name === 'admin-admission-exams' ? Exams : name === 'admission' ? AdmissionView : name.startsWith('admin-') ? Admin : name.startsWith('registrar-') ? Registrar : name.endsWith('-exam') ? Exam : name.endsWith('-result') ? Result : Recommendation,
  props: { title, mode: path.split('/').at(-1) === 'admissions' ? 'applicants' : path.split('/').at(-1) },
  meta: { title, roles: ROUTE_ROLES[name] },
}))

admissionRoutes.push(
  { name: 'registrar-admission-review', path: 'registrar/admissions/review', redirect: '/registrar/admissions/results', meta: { roles: ROUTE_ROLES['registrar-admission-review'] } },
  { name: 'admin-admission-cycles', path: 'admin/admissions/cycles', redirect: '/admin/admissions/exams', meta: { roles: ROUTE_ROLES['admin-admission-cycles'] } },
)
