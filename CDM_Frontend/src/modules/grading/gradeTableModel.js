export const GRADE_COLUMN_WIDTHS = Object.freeze({
  sequence: 48,
  studentNumber: 124,
  studentName: 200,
  assessment: 116,
  rawTotal: 104,
  result: 116,
  remarks: 128,
})

const identityDefinitions = Object.freeze([
  { key: 'sequence', label: 'No.', className: 'number', width: GRADE_COLUMN_WIDTHS.sequence },
  { key: 'student-number', label: 'Student No.', className: 'student-number', width: GRADE_COLUMN_WIDTHS.studentNumber },
  { key: 'student-name', label: 'Student Name', className: 'student-name', width: GRADE_COLUMN_WIDTHS.studentName },
])

export const GRADE_ASSESSMENT_CATEGORIES = Object.freeze(['Quiz', 'Activity', 'Recitation', 'Major Exam'])

const resultDefinitions = Object.freeze([
  { key: 'midterm', label: 'Midterm', field: 'midterm_grade', width: GRADE_COLUMN_WIDTHS.result },
  { key: 'finals', label: 'Finals', field: 'finals_grade', width: GRADE_COLUMN_WIDTHS.result },
  { key: 'final-grade', label: 'Final Grade', field: 'final_grade', width: GRADE_COLUMN_WIDTHS.result },
  { key: 'grade-point', label: 'Grade Point', field: 'grade_point', width: GRADE_COLUMN_WIDTHS.result },
  { key: 'remarks', label: 'Remarks', field: 'remarks', width: GRADE_COLUMN_WIDTHS.remarks, className: 'grade-sheet-remarks' },
])

const categoryIdentity = assessment => {
  const label = String(assessment?.category || 'Other').trim() || 'Other'
  return { key: label.toLocaleLowerCase(), label }
}

const categoryKey = value => String(value || '').trim().toLocaleLowerCase()
const gradeCollator = new Intl.Collator(undefined, { sensitivity: 'base', numeric: true })

export function compareGradeStudents(left, right) {
  for (const field of ['last_name', 'first_name', 'middle_name', 'student_number']) {
    const compared = gradeCollator.compare(String(left?.[field] || '').trim(), String(right?.[field] || '').trim())
    if (compared) return compared
  }
  return 0
}

export function compareFinalGradeResults(left, right) {
  return gradeCollator.compare(String(left?.student_name || '').trim(), String(right?.student_name || '').trim())
    || gradeCollator.compare(String(left?.student_number || '').trim(), String(right?.student_number || '').trim())
}

export function buildGradeTableModel(assessments = [], period = 'midterm') {
  const identityColumns = identityDefinitions.map(column => ({ ...column, kind: 'identity' }))
  const renderedAssessments = assessments.filter(assessment => assessment && assessment.status !== 'archived')
  const groups = GRADE_ASSESSMENT_CATEGORIES.map(label => ({ key: categoryKey(label), label, columns: [] }))
  const groupsByKey = new Map()

  for (const group of groups) groupsByKey.set(group.key, group)

  for (const assessment of renderedAssessments) {
    const category = categoryIdentity(assessment)
    let group = groupsByKey.get(category.key)
    if (!group) {
      group = { ...category, columns: [] }
      groupsByKey.set(category.key, group)
      groups.push(group)
    }
    group.columns.push({
      key: `assessment-${assessment.id}`,
      kind: 'assessment',
      width: GRADE_COLUMN_WIDTHS.assessment,
      assessment,
      groupKey: category.key,
      groupLabel: category.label,
      groupStart: group.columns.length === 0,
    })
  }

  for (const group of groups) {
    if (!group.columns.length) {
      group.columns.push({
        key: `assessment-placeholder-${group.key}`,
        kind: 'assessment-placeholder',
        width: GRADE_COLUMN_WIDTHS.assessment,
        groupKey: group.key,
        groupLabel: group.label,
        groupStart: true,
        label: 'No assessment',
      })
    }
  }

  const periodColumns = groups.flatMap(group => group.columns)
  const assessmentColumns = periodColumns.filter(column => column.kind === 'assessment')
  assessmentColumns.forEach((column, scoreIndex) => { column.scoreIndex = scoreIndex })
  const resultColumns = resultDefinitions.map(column => ({ ...column, kind: 'result' }))
  const normalizedPeriod = period === 'finals' ? 'finals' : 'midterm'
  const periodResultColumn = resultColumns.find(column => column.key === normalizedPeriod)
  const columns = [...identityColumns, periodResultColumn, ...periodColumns]
  const studentInformationColumns = identityColumns
  const studentInformationGroup = {
    width: studentInformationColumns.reduce((total, column) => total + column.width, 0),
  }

  return {
    columns,
    identityColumns,
    studentInformationColumns,
    studentInformationGroup,
    assessmentColumns,
    periodColumns,
    assessmentGroups: groups,
    periodResultColumn,
    resultColumns,
    totalWidth: columns.reduce((total, column) => total + column.width, 0),
    columnCount: columns.length,
  }
}
