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

const resultDefinitions = Object.freeze([
  { key: 'midterm', label: 'Midterm', field: 'midterm_grade', width: GRADE_COLUMN_WIDTHS.result },
  { key: 'finals', label: 'Finals', field: 'finals_grade', width: GRADE_COLUMN_WIDTHS.result },
  { key: 'final-grade', label: 'Final Grade', field: 'final_grade', width: GRADE_COLUMN_WIDTHS.result },
  { key: 'equivalent', label: 'Equivalent', field: 'grade_point', width: GRADE_COLUMN_WIDTHS.result },
  { key: 'remarks', label: 'Remarks', field: 'remarks', width: GRADE_COLUMN_WIDTHS.remarks, className: 'grade-sheet-remarks' },
])

const categoryIdentity = assessment => {
  const label = String(assessment?.category || 'Other').trim() || 'Other'
  return { key: label.toLocaleLowerCase(), label }
}

export function buildGradeTableModel(assessments = []) {
  const identityColumns = identityDefinitions.map(column => ({ ...column, kind: 'identity' }))
  const renderedAssessments = assessments.filter(assessment => assessment && assessment.status !== 'archived')
  const groups = []
  const groupsByKey = new Map()

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

  const assessmentColumns = groups.flatMap(group => group.columns)
  const rawTotalColumn = { key: 'raw-total', label: 'Raw Total', kind: 'raw-total', width: GRADE_COLUMN_WIDTHS.rawTotal }
  const resultColumns = resultDefinitions.map(column => ({ ...column, kind: 'result' }))
  const columns = [...identityColumns, ...assessmentColumns, rawTotalColumn, ...resultColumns]
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
    assessmentGroups: groups,
    rawTotalColumn,
    resultColumns,
    totalWidth: columns.reduce((total, column) => total + column.width, 0),
    columnCount: columns.length,
  }
}
