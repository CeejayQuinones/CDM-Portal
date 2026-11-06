const normalize = value => String(value ?? '').trim().toLowerCase()
const escapeRegExp = value => value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')

export function nextAssessmentLabel(category, assessments = []) {
  const cleanCategory = String(category ?? '').trim()
  if (!cleanCategory) return ''

  const categoryKey = normalize(cleanCategory)
  const numberedLabel = new RegExp(`^${escapeRegExp(cleanCategory)}\\s+(\\d+)$`, 'i')
  const existingLabels = new Set()
  let highest = 0

  for (const assessment of assessments) {
    const label = String(assessment?.label ?? '').trim()
    existingLabels.add(normalize(label))
    if (normalize(assessment?.category) !== categoryKey) continue
    const match = label.match(numberedLabel)
    if (match) highest = Math.max(highest, Number(match[1]))
  }

  let next = highest + 1
  while (existingLabels.has(normalize(`${cleanCategory} ${next}`))) next += 1
  return `${cleanCategory} ${next}`
}

export function assessmentLabelAfterCategoryChange({ currentLabel, category, assessments = [], automatic }) {
  return automatic ? nextAssessmentLabel(category, assessments) : currentLabel
}

export function groupReadinessIssues(errors = [], assessments = []) {
  const groups = {
    midterm: { key: 'midterm', label: 'Midterm', period: 'midterm', issues: [] },
    finals: { key: 'finals', label: 'Finals', period: 'finals', issues: [] },
    other: { key: 'other', label: 'Other requirements', period: null, issues: [] },
  }
  const assessmentPeriods = assessments
    .filter(item => ['midterm', 'finals'].includes(normalize(item?.period)) && String(item?.label ?? '').trim())
    .sort((left, right) => String(right.label).length - String(left.label).length)

  for (const value of errors) {
    const issue = String(value ?? '').trim()
    if (!issue) continue
    const issueKey = normalize(issue)
    let groupKey = /^midterm and finals\b/i.test(issue) ? 'other' : /^midterm\b/i.test(issue) ? 'midterm' : /^finals?\b/i.test(issue) ? 'finals' : ''

    if (!groupKey) {
      const matchingAssessment = assessmentPeriods.find(assessment => issueKey.includes(normalize(assessment.label)))
      groupKey = matchingAssessment ? normalize(matchingAssessment.period) : 'other'
    }

    groups[groupKey]?.issues.push(issue)
  }

  return Object.values(groups)
}

export function summarizeReadinessGroups(groups = []) {
  const summary = { midterm: 0, finals: 0, other: 0, total: 0 }

  for (const group of groups) {
    const count = Array.isArray(group?.issues) ? group.issues.length : 0
    if (Object.hasOwn(summary, group?.key) && group.key !== 'total') summary[group.key] = count
    summary.total += count
  }

  return summary
}
