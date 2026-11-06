export const scoreCellKey = (enrollmentSubjectId, assessmentId) => `${enrollmentSubjectId}:${assessmentId}`

const hasOwn = (object, key) => Object.prototype.hasOwnProperty.call(object, key)

export function validateScoreDraft(value, maxScore) {
  if (value === null || value === undefined || String(value).trim() === '') return 'Enter a score.'

  const numericValue = Number(value)
  if (!Number.isFinite(numericValue)) return 'Enter a valid number.'
  if (numericValue < 0) return 'Score cannot be below zero.'
  if (numericValue > Number(maxScore)) return `Maximum score is ${maxScore}.`

  return ''
}

export function isScoreDraftDirty(drafts, cellKey, persistedScore) {
  if (!hasOwn(drafts, cellKey)) return false
  const draft = drafts[cellKey]
  if (draft === null || draft === undefined || String(draft).trim() === '') return persistedScore !== null && persistedScore !== undefined && persistedScore !== ''
  if (persistedScore === null || persistedScore === undefined || persistedScore === '') return true
  return Number(draft) !== Number(persistedScore)
}

export function collectScoreBatches({ drafts, roster, assessments, persistedScore }) {
  return assessments
    .filter(assessment => assessment.status === 'active')
    .map(assessment => ({
      assessment_id: assessment.id,
      scores: roster.flatMap(student => {
        const cellKey = scoreCellKey(student.enrollment_subject_id, assessment.id)
        const existing = persistedScore(student, assessment)
        if (!isScoreDraftDirty(drafts, cellKey, existing?.score)) return []

        return [{
          enrollment_subject_id: student.enrollment_subject_id,
          score: Number(drafts[cellKey]),
          ...(existing ? { version: existing.version } : {}),
        }]
      }),
    }))
    .filter(batch => batch.scores.length)
}

export function clearSavedScoreBatch(drafts, batch) {
  const remaining = { ...drafts }
  for (const score of batch.scores || []) {
    delete remaining[scoreCellKey(score.enrollment_subject_id, batch.assessment_id)]
  }
  return remaining
}
