import { scoreCellKey } from './gradeSpreadsheet.js'

export const GRADE_RECOVERY_SCHEMA = 1
export const GRADE_RECOVERY_TTL_MS = 12 * 60 * 60 * 1000
export const GRADE_RECOVERY_PREFIX = 'cdm:professor-grade-recovery:v1:'

const text = value => String(value ?? '')
const same = (left, right) => text(left) === text(right)
const safeStorage = action => {
  try {
    return action()
  } catch {
    return null
  }
}

export function gradeRecoveryScope({ accountId, classContext, sheet, period }) {
  return {
    accountId: text(accountId),
    classId: text(classContext?.id),
    subjectId: text(classContext?.subject_id),
    sectionId: text(classContext?.section_id),
    sheetId: text(sheet?.id),
    sheetVersion: text(sheet?.version),
    academicYearId: text(classContext?.academic_year_id),
    academicYear: text(classContext?.academic_year),
    semesterId: text(classContext?.semester_id),
    semester: text(classContext?.semester),
    period: text(period),
  }
}

export function gradeRecoveryStorageKey(scope) {
  return `${GRADE_RECOVERY_PREFIX}${encodeURIComponent(scope.accountId)}:${encodeURIComponent(scope.classId)}:${encodeURIComponent(scope.sheetId)}:${encodeURIComponent(scope.period)}`
}

export function gradeRecoveryPreferenceKey(accountId) {
  return `${GRADE_RECOVERY_PREFIX}${encodeURIComponent(text(accountId))}:preference`
}

export function readGradeRecoveryPreference(storage, accountId) {
  return safeStorage(() => storage.getItem(gradeRecoveryPreferenceKey(accountId)) === 'enabled') === true
}

export function writeGradeRecoveryPreference(storage, accountId, enabled) {
  return safeStorage(() => {
    const key = gradeRecoveryPreferenceKey(accountId)
    if (enabled) storage.setItem(key, 'enabled')
    else storage.removeItem(key)
    return enabled
  })
}

export function createGradeRecoveryRecord({ scope, drafts, dirtyKeys, roster, assessments, persistedScore, now = Date.now() }) {
  const edits = []
  for (const student of roster || []) {
    for (const assessment of assessments || []) {
      const key = scoreCellKey(student.enrollment_subject_id, assessment.id)
      if (!dirtyKeys?.has(key) || !Object.prototype.hasOwnProperty.call(drafts, key)) continue
      const base = persistedScore(student, assessment)
      edits.push({
        enrollmentSubjectId: text(student.enrollment_subject_id),
        assessmentId: text(assessment.id),
        assessmentVersion: assessment.version === null || assessment.version === undefined ? null : text(assessment.version),
        basePresent: Boolean(base),
        baseVersion: base?.version === null || base?.version === undefined ? null : text(base.version),
        value: text(drafts[key]),
      })
    }
  }

  return {
    schema: GRADE_RECOVERY_SCHEMA,
    scope: { ...scope },
    createdAt: now,
    updatedAt: now,
    expiresAt: now + GRADE_RECOVERY_TTL_MS,
    edits,
  }
}

export function writeGradeRecovery(storage, record) {
  if (!record?.scope) return false
  return safeStorage(() => {
    const key = gradeRecoveryStorageKey(record.scope)
    if (!record.edits?.length) {
      storage.removeItem(key)
      return true
    }
    storage.setItem(key, JSON.stringify(record))
    return true
  }) === true
}

export function removeGradeRecovery(storage, scope) {
  return safeStorage(() => {
    storage.removeItem(gradeRecoveryStorageKey(scope))
    return true
  }) === true
}

const validScope = (stored, current) => ['accountId', 'classId', 'subjectId', 'sectionId', 'sheetId', 'academicYearId', 'academicYear', 'semesterId', 'semester', 'period'].every(field => same(stored?.[field], current?.[field]))

export function readGradeRecovery(storage, scope, now = Date.now()) {
  const key = gradeRecoveryStorageKey(scope)
  return safeStorage(() => {
    const raw = storage.getItem(key)
    if (!raw) return null
    let record
    try {
      record = JSON.parse(raw)
    } catch {
      storage.removeItem(key)
      return null
    }
    if (record?.schema !== GRADE_RECOVERY_SCHEMA || !validScope(record.scope, scope) || !Array.isArray(record.edits)) {
      storage.removeItem(key)
      return null
    }
    if (!Number.isFinite(record.expiresAt) || record.expiresAt <= now) {
      storage.removeItem(key)
      return null
    }
    return record
  })
}

export function clearGradeRecoveryForAccount(storage, accountId) {
  const accountPrefix = `${GRADE_RECOVERY_PREFIX}${encodeURIComponent(text(accountId))}:`
  return safeStorage(() => {
    const keys = []
    for (let index = 0; index < storage.length; index++) {
      const key = storage.key(index)
      if (key?.startsWith(accountPrefix)) keys.push(key)
    }
    for (const key of keys) storage.removeItem(key)
    return keys.length
  }) ?? 0
}

export function analyzeGradeRecovery({ record, scope, roster, assessments, persistedScore, editable }) {
  const eligible = []
  const conflicts = []
  if (!record || !validScope(record.scope, scope)) return { eligible, conflicts, invalid: true }

  const rosterById = new Map((roster || []).map((student, row) => [text(student.enrollment_subject_id), { student, row }]))
  const assessmentsById = new Map((assessments || []).map(assessment => [text(assessment.id), assessment]))
  const sheetChanged = !same(record.scope.sheetVersion, scope.sheetVersion)

  for (const edit of record.edits) {
    const rosterEntry = rosterById.get(text(edit.enrollmentSubjectId))
    const assessment = assessmentsById.get(text(edit.assessmentId))
    const location = `${rosterEntry ? `Student row ${rosterEntry.row + 1}` : 'Student no longer in roster'} · ${assessment?.label || 'Assessment no longer available'}`
    let reason = ''
    if (!editable) reason = 'The grading period is no longer editable.'
    else if (sheetChanged) reason = 'The grade sheet data version changed.'
    else if (!rosterEntry) reason = 'The Student is no longer in the authorized roster.'
    else if (!assessment || assessment.status !== 'active' || assessment.period !== scope.period) reason = 'The assessment was removed, archived, or moved.'
    else if (!same(edit.assessmentVersion, assessment.version)) reason = 'The assessment definition changed.'
    else {
      const current = persistedScore(rosterEntry.student, assessment)
      if (edit.basePresent) {
        if (!current) reason = 'The authoritative score was removed.'
        else if (edit.baseVersion === null) reason = 'The saved score has no comparable base version.'
        else if (!same(edit.baseVersion, current.version)) reason = 'A newer authoritative score exists.'
      } else if (current) reason = 'An authoritative score now exists for this cell.'
    }

    const item = { ...edit, location, reason }
    if (reason) conflicts.push(item)
    else eligible.push(item)
  }

  return { eligible, conflicts, invalid: false }
}
