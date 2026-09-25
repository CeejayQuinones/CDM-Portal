import { ROUTE_ROLES } from '../../config/accessControl.js'

const AdmissionView = () => import('./AdmissionView.vue')
const Exam = () => import('./AdmissionExamView.vue')
const Result = () => import('./AdmissionResultView.vue')
const Recommendation = () => import('./AdmissionRecommendationView.vue')
const Admin = () => import('./AdmissionAdminView.vue')
const Registrar = () => import('./AdmissionRegistrarView.vue')

// Paths are relative to the existing authenticated DashboardLayout.
export const admissionRoutes = [
  { name: 'admission', path: 'admission', title: 'Admission Status' },
  { name: 'student-admission-exam', path: 'admission/exam', title: 'Entrance Exam' },
  { name: 'student-admission-result', path: 'admission/result', title: 'Result' },
  { name: 'student-admission-recommendation', path: 'admission/recommendation', title: 'Recommendation' },
  { name: 'registrar-admissions', path: 'registrar/admissions', title: 'Applicants' },
  { name: 'registrar-admission-results', path: 'registrar/admissions/results', title: 'Exam Results' },
  { name: 'registrar-admission-review', path: 'registrar/admissions/review', title: 'Review / Publish' },
  { name: 'registrar-admission-history', path: 'registrar/admissions/history', title: 'Admission History' },
  { name: 'admin-admission-cycles', path: 'admin/admissions/cycles', title: 'Admission Cycles' },
  { name: 'admin-admission-questions', path: 'admin/admissions/questions', title: 'Question Bank' },
  { name: 'admin-admission-programs', path: 'admin/admissions/programs', title: 'Program Configuration' },
].map(({ name, path, title }) => ({
  name,
  path,
  component: name === 'admission' ? AdmissionView : name.startsWith('admin-') ? Admin : name.startsWith('registrar-') ? Registrar : name.endsWith('-exam') ? Exam : name.endsWith('-result') ? Result : Recommendation,
  props: { title, mode: path.split('/').at(-1) === 'admissions' ? 'applicants' : path.split('/').at(-1) },
  meta: { title, roles: ROUTE_ROLES[name] },
}))
