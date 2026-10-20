export const ASSESSMENT_CATEGORIES = ['Quiz', 'Activity', 'Recitation', 'Major Exam']

export function parseBulkAssessments(value, startOrder = 1) {
  const rows = []
  const errors = []

  String(value || '').split(/\r?\n/).forEach((source, index) => {
    if (!source.trim()) return
    const columns = source.includes('\t') ? source.split('\t') : source.split('|')
    const [label = '', category = '', score = '', ...extra] = columns.map((column) => column.trim())
    const maxScore = Number(score)
    const line = index + 1
    if (extra.length || !label || !category || !score) {
      errors.push(`Line ${line}: use Label | Category | Maximum score.`)
      return
    }
    if (!ASSESSMENT_CATEGORIES.includes(category)) {
      errors.push(`Line ${line}: category must be ${ASSESSMENT_CATEGORIES.join(', ')}.`)
      return
    }
    if (!Number.isFinite(maxScore) || maxScore <= 0 || maxScore > 999999.99) {
      errors.push(`Line ${line}: maximum score must be greater than 0.`)
      return
    }
    rows.push({ label, category, max_score: maxScore, display_order: startOrder + rows.length })
  })

  const labels = rows.map(({ label }) => label.trim().toLocaleLowerCase())
  if (new Set(labels).size !== labels.length) errors.push('Assessment labels must be unique in this import.')
  if (!rows.length && !errors.length) errors.push('Paste at least one assessment.')

  return { rows, errors }
}
