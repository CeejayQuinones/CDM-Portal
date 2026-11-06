export const institutionProfile = Object.freeze({
  name: 'Colegio de Montalban',
  abbreviation: 'CdM',
  formerName: 'Pamantasan ng Montalban',
  type: 'Public, locally funded higher education institution',
  established: 'September 25, 2003',
  location: 'Kasiglahan Village, Barangay San Jose, Rodriguez, Rizal, Philippines',
  purpose: 'Colegio de Montalban was established to help provide accessible higher education for residents of Rodriguez and nearby communities.',
})

export const institutionHistory = Object.freeze([
  { year: '2003', title: 'Institution established', description: 'Established as Pamantasan ng Montalban through Municipal Ordinance No. 03-24.' },
  { year: '2004', title: 'Early operations', description: 'Began early operations and received authority for technical-vocational programs.' },
  { year: '2011', title: 'Degree recognition', description: 'Obtained full government recognition for several degree programs.' },
  { year: '2014', title: 'Colegio de Montalban', description: 'Renamed Colegio de Montalban through Municipal Ordinance No. 14-15.' },
])

export const academicInstitutes = Object.freeze([
  {
    code: 'ICS',
    name: 'Institute of Computer Studies',
    programs: ['BS Information Technology', 'BS Computer Engineering'],
  },
  {
    code: 'ITE',
    name: 'Institute of Education',
    programs: ['Elementary Education', 'Secondary Education', 'Early Childhood Education', 'Technology and Livelihood Education'],
  },
  {
    code: 'IBE',
    name: 'Institute of Business',
    programs: ['BS Business Administration major in Human Resource Management', 'BS Entrepreneurship'],
  },
])

export const institutionalDirection = Object.freeze({
  mission: 'To educate students to become globally competitive professionals and values-oriented individuals who can contribute their expertise to the community.',
  vision: 'To transform individuals into responsible, productive, and globally competitive citizens.',
})

export const portalServices = Object.freeze([
  { icon: 'admission', title: 'Admission', description: 'Create and follow an application through the existing applicant workflow.', access: 'Guest account required' },
  { icon: 'enrollment', title: 'Enrollment', description: 'Review eligibility, applications, status, and finalized enrollment information.', access: 'Authorized students' },
  { icon: 'subjects', title: 'Student subjects', description: 'Review the subject load recorded for a finalized enrollment.', access: 'Authorized students' },
  { icon: 'schedule', title: 'Class schedules', description: 'See assigned class times, rooms, and teaching schedules for the current term.', access: 'Authorized accounts' },
  { icon: 'grades', title: 'Academic grades', description: 'View grades only after they have been submitted and officially published.', access: 'Authorized students' },
  { icon: 'documents', title: 'Document Requests', description: 'Request and track supported documents, appointments, and claim-code updates.', access: 'Authorized accounts' },
])

export const homepageRoles = Object.freeze([
  { key: 'applicant', number: '01', title: 'Applicants', description: 'Create a Guest account, sign in, and continue through the existing Admission process.', action: 'Create Guest account' },
  { key: 'student', number: '02', title: 'Students', description: 'Sign in to view academic information and services authorized for your account.', action: 'Student login' },
  { key: 'professor', number: '03', title: 'Professors', description: 'Sign in to access authorized teaching assignments, classes, and grading tools.', action: 'Professor login' },
])
