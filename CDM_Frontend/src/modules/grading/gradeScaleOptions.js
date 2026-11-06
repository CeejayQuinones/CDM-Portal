export const GRADE_POINT_OPTIONS = Object.freeze([
  '1.00',
  '1.25',
  '1.50',
  '1.75',
  '2.00',
  '2.25',
  '2.50',
  '2.75',
  '3.00',
  '4.00',
  '5.00',
])

export function normalizeGradePoint(value) {
  if (value === null || value === undefined || value === '') return ''
  if (!['number', 'string'].includes(typeof value)) return String(value)
  const numeric = Number(value)
  return Number.isFinite(numeric) && GRADE_POINT_OPTIONS.some(point => Number(point) === numeric)
    ? numeric.toFixed(2) : String(value)
}

export const GRADE_REMARK_OPTIONS = Object.freeze(['PASSED', 'FAILED'])

export function isSupportedGradeRemark(value) {
  return typeof value === 'string' && GRADE_REMARK_OPTIONS.includes(value.toUpperCase())
}

export function isSupportedGradePoint(value) {
  return GRADE_POINT_OPTIONS.includes(normalizeGradePoint(value))
}

export function gradePointForEdit(value) {
  return normalizeGradePoint(value)
}

export function gradePointForApi(value) {
  const normalized = normalizeGradePoint(value)
  if (!GRADE_POINT_OPTIONS.includes(normalized)) {
    throw new RangeError('Select a supported grade point.')
  }
  return Number(normalized)
}

export function buildGradeScalePayload(form, { approvedGradePointsOnly = true } = {}) {
  if (approvedGradePointsOnly && !isSupportedGradeRemark(form.remark)) {
    const error = new RangeError('Select PASSED or FAILED.')
    error.field = 'remark'
    throw error
  }
  return {
    ...form,
    min_percentage: Number(form.min_percentage),
    max_percentage: Number(form.max_percentage),
    grade_point: approvedGradePointsOnly ? gradePointForApi(form.grade_point) : Number(form.grade_point),
  }
}
