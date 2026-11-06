const labelFor = (assessment, drafts) => String(drafts[assessment.id] ?? assessment.label ?? '').trim()

export function createColumnLabelDrafts(assessments = []) {
  return Object.fromEntries(assessments.map(assessment => [assessment.id, assessment.label]))
}

export function validateColumnLabels(assessments = [], drafts = {}) {
  const errors = {}
  const labels = new Map()

  for (const assessment of assessments) {
    const label = labelFor(assessment, drafts)
    if (!label) errors[assessment.id] = 'A column label is required.'
    else if (label.length > 80) errors[assessment.id] = 'Use 80 characters or fewer.'

    const normalized = label.toLowerCase()
    if (normalized) labels.set(normalized, [...(labels.get(normalized) || []), assessment.id])
  }

  for (const ids of labels.values()) {
    if (ids.length > 1) for (const id of ids) errors[id] = 'Column labels must be unique in this grading period.'
  }

  return errors
}

export function collectColumnLabelChanges(assessments = [], drafts = {}) {
  return assessments.flatMap(assessment => {
    const label = labelFor(assessment, drafts)
    return label === String(assessment.label ?? '').trim() ? [] : [{ assessment, label }]
  })
}
