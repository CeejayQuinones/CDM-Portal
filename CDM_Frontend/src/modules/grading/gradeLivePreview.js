import { scoreCellKey } from './gradeSpreadsheet.js'

const scaled = value => Math.round(Number(value) * 100)
const validScore = (value, maximum) => value !== '' && value !== null && value !== undefined
  && Number.isFinite(Number(value)) && Number(value) >= 0 && Number(value) <= Number(maximum)

export function calculatePeriodPreview({ student, period, assessments, weights, drafts }) {
  const active = assessments.filter(item => item.period === period && item.status === 'active')
  if (!active.length) return { value: null, state: 'empty' }
  const periodWeights = weights.filter(item => item.period === period)
  if (Math.abs(periodWeights.reduce((sum, item) => sum + Number(item.weight_percentage), 0) - 100) > 0.005) {
    return { value: null, state: 'criteria' }
  }
  if (active.some(item => !periodWeights.some(weight => weight.category === item.category && Number(weight.weight_percentage) > 0))) {
    return { value: null, state: 'unweighted' }
  }

  let basis = 0
  let entered = 0
  let missing = false
  let zero = false
  let invalid = false
  for (const weight of periodWeights) {
    const category = active.filter(item => item.category === weight.category)
    if (!category.length && Number(weight.weight_percentage) > 0) missing = true
    const possible = category.reduce((sum, item) => sum + scaled(item.max_score), 0)
    let earned = 0
    for (const item of category) {
      const cell = scoreCellKey(student.enrollment_subject_id, item.id)
      const saved = item.scores?.find(row => String(row.enrollment_subject_id) === String(student.enrollment_subject_id))?.score
      const value = Object.hasOwn(drafts, cell) ? drafts[cell] : saved
      if (value === '' || value === null || value === undefined) { missing = true; continue }
      if (!validScore(value, item.max_score)) { invalid = true; continue }
      entered++
      if (Number(value) === 0) zero = true
      earned += scaled(value)
    }
    if (possible) basis += Math.round(earned * scaled(weight.weight_percentage) / possible)
  }

  if (invalid) return { value: null, state: 'invalid' }
  if (zero) return { value: null, state: 'inc' }
  if (!entered) return { value: null, state: 'empty' }
  return { value: basis / 100, state: missing ? 'partial' : 'complete' }
}

export function calculateLiveGradeResult({ student, sheet, drafts, scales }) {
  const args = { student, assessments: sheet.assessments || [], weights: sheet.weights || [], drafts }
  const midterm = calculatePeriodPreview({ ...args, period: 'midterm' })
  const finals = calculatePeriodPreview({ ...args, period: 'finals' })
  if (midterm.state === 'inc' || finals.state === 'inc') {
    return { midterm, finals, final: { value: null, state: 'inc' }, gradePoint: null, remarks: 'INCOMPLETE' }
  }
  if (midterm.state === 'unweighted' || finals.state === 'unweighted') {
    return { midterm, finals, final: { value: null, state: 'unweighted' }, gradePoint: null, remarks: null }
  }
  if (Math.abs(Number(sheet.midterm_weight) + Number(sheet.finals_weight) - 100) > 0.005) {
    return { midterm, finals, final: { value: null, state: 'criteria' }, gradePoint: null, remarks: null }
  }
  if (midterm.state !== 'complete' || finals.state !== 'complete') {
    return { midterm, finals, final: { value: null, state: 'pending' }, gradePoint: null, remarks: null }
  }
  const finalBasis = Math.round((scaled(midterm.value) * scaled(sheet.midterm_weight)
    + scaled(finals.value) * scaled(sheet.finals_weight)) / 10000)
  const value = finalBasis / 100
  const scale = scales.filter(item => item.is_active && Number(item.min_percentage) <= value && Number(item.max_percentage) >= value)
    .sort((a, b) => Number(b.min_percentage) - Number(a.min_percentage))[0]
  return {
    midterm,
    finals,
    final: { value, state: 'complete' },
    gradePoint: scale ? Number(scale.grade_point).toFixed(2) : null,
    remarks: scale?.remark || null,
  }
}

export function displayLiveGrade(preview) {
  if (preview?.state === 'inc') return 'INC'
  if (preview?.state === 'criteria') return 'Set criteria'
  if (preview?.state === 'unweighted') return 'Set weight'
  if (preview?.state === 'invalid') return 'Fix score'
  return preview?.value === null || preview?.value === undefined ? '—' : Number(preview.value).toFixed(2)
}
