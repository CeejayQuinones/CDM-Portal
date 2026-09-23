import { ROUTE_ROLES } from '../../config/accessControl.js'

const AdmissionView = () => import('./AdmissionView.vue')
const AdmissionPlaceholder = () => import('./components/AdmissionPlaceholder.vue')

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
  { name: 'admin-admission-questions', path: 'admin/admissions/questions', title: 'Question Bank' },
  { name: 'admin-admission-programs', path: 'admin/admissions/programs', title: 'Program Configuration' },
].map(({ name, path, title }) => ({
  name,
  path,
  component: name === 'admission' ? AdmissionView : AdmissionPlaceholder,
  props: { title },
  meta: { title, roles: ROUTE_ROLES[name] },
}))
