import { scoreCoordinate } from './gradeSpreadsheetInteraction.js'

const normalize = value => String(value ?? '').trim().toLocaleLowerCase()

export function createGradeStudentSearchIndex(roster) {
  return (roster || []).map(student => ({
    student,
    haystack: normalize(`${student.student_number || ''} ${student.first_name || ''} ${student.middle_name || ''} ${student.last_name || ''}`),
  }))
}

export function findIndexedGradeStudents(index, query, limit = 8) {
  const term = normalize(query)
  if (!term) return []
  const matches = []
  for (const entry of index || []) {
    if (!entry.haystack.includes(term)) continue
    matches.push(entry.student)
    if (matches.length >= limit) break
  }
  return matches
}

export function findGradeStudents(roster, query, limit = 8) {
  return findIndexedGradeStudents(createGradeStudentSearchIndex(roster), query, limit)
}

export function resolveGradeJumpTarget({ enrollmentSubjectId, assessmentId, roster, columns }) {
  const row = (roster || []).findIndex(student => String(student.enrollment_subject_id) === String(enrollmentSubjectId))
  const column = (columns || []).findIndex(item => String(item.assessment?.id ?? item.id) === String(assessmentId))
  if (row < 0 || column < 0) return null
  return scoreCoordinate(roster[row], columns[column], row, column)
}

export function gradeStudentDisplayName(student) {
  const given = [student?.first_name, student?.middle_name].filter(Boolean).join(' ')
  return [student?.last_name, given].filter(Boolean).join(', ')
}
