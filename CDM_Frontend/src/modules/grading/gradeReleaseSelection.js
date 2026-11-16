export function programsForInstitute(courses = [], instituteCode = '') {
  return courses.filter(course => course.department?.department_code === instituteCode)
}

export function approvedReleaseSheets(sheets = [], { yearId, semesterId, programIds = [] } = {}) {
  if (!yearId || !semesterId) return []
  const programs = new Set(programIds.map(String))
  return sheets.filter(sheet => {
    const section = sheet.section_subject?.section
    return sheet.status === 'approved'
      && String(section?.academic_year_id) === String(yearId)
      && String(section?.semester_id) === String(semesterId)
      && (!programs.size || programs.has(String(section?.course_id)))
  })
}
