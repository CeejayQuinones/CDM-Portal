export const STUDENT_DASHBOARD_PROFILE_EVENT = 'cdm:student-dashboard-profile'

export const studentDashboardProfileContext = (identity = {}, profile = {}) => ({
  studentNumber: identity.student_number || '',
  officialName: identity.name || [profile.first_name, profile.middle_name, profile.last_name, profile.suffix].filter(Boolean).join(' '),
  email: profile.email || '',
  contactNumber: profile.contact_number || '',
  program: identity.program_code || identity.program || '',
  programName: identity.program || '',
  yearLevel: identity.year_level ? `Year ${identity.year_level}` : '',
  section: identity.section || '',
})
