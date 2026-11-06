import { scoreCellKey } from './gradeSpreadsheet.js'

export const SCORE_HISTORY_LIMIT = 50

const hasOwn = (object, key) => Object.prototype.hasOwnProperty.call(object, key)
const assessmentFor = column => column?.assessment || column
const studentId = student => String(student?.enrollment_subject_id ?? '')
const assessmentId = column => String(assessmentFor(column)?.id ?? '')

export function scoreCoordinate(student, column, row, columnIndex) {
  const assessment = assessmentFor(column)

  return {
    row,
    column: columnIndex,
    enrollmentSubjectId: student?.enrollment_subject_id,
    assessmentId: assessment?.id,
    key: scoreCellKey(student?.enrollment_subject_id, assessment?.id),
  }
}

export function resolveScoreCoordinate(cell, roster, columns) {
  if (!cell) return null
  const row = roster.findIndex(student => studentId(student) === String(cell.enrollmentSubjectId))
  const column = columns.findIndex(item => assessmentId(item) === String(cell.assessmentId))
  if (row < 0 || column < 0) return null

  return scoreCoordinate(roster[row], columns[column], row, column)
}

export function selectionBounds(selection, roster, columns) {
  const anchor = resolveScoreCoordinate(selection?.anchor, roster, columns)
  const focus = resolveScoreCoordinate(selection?.focus, roster, columns)
  if (!anchor || !focus) return null

  return {
    top: Math.min(anchor.row, focus.row),
    bottom: Math.max(anchor.row, focus.row),
    left: Math.min(anchor.column, focus.column),
    right: Math.max(anchor.column, focus.column),
    anchor,
    focus,
  }
}

export function selectedScoreCoordinates(selection, roster, columns) {
  const bounds = selectionBounds(selection, roster, columns)
  if (!bounds) return []
  const cells = []
  for (let row = bounds.top; row <= bounds.bottom; row++) {
    for (let column = bounds.left; column <= bounds.right; column++) {
      cells.push(scoreCoordinate(roster[row], columns[column], row, column))
    }
  }

  return cells
}

export function safeScoreClipboardValue(value) {
  if (value === null || value === undefined || String(value).trim() === '') return ''
  const text = String(value).trim()
  if (Number.isFinite(Number(text))) return text

  return `'${text.replace(/[\t\r\n]+/g, ' ')}`
}

export function selectionToTsv({ selection, roster, columns, valueFor }) {
  const bounds = selectionBounds(selection, roster, columns)
  if (!bounds) return ''
  const rows = []
  for (let row = bounds.top; row <= bounds.bottom; row++) {
    const values = []
    for (let column = bounds.left; column <= bounds.right; column++) {
      values.push(safeScoreClipboardValue(valueFor(roster[row], assessmentFor(columns[column]))))
    }
    rows.push(values.join('\t'))
  }

  return rows.join('\n')
}

export function parseScoreClipboard(text) {
  const normalized = String(text ?? '').replace(/\r\n?/g, '\n')
  const rows = normalized.split('\n')
  if (rows.length > 1 && rows.at(-1) === '') rows.pop()
  const matrix = rows.map(row => row.split('\t'))
  const width = matrix[0]?.length || 0
  const rectangular = width > 0 && matrix.every(row => row.length === width)

  return { matrix, width, height: matrix.length, rectangular }
}

export function draftSnapshot(drafts, key) {
  return hasOwn(drafts, key)
    ? { exists: true, value: drafts[key] }
    : { exists: false, value: undefined }
}

const sameSnapshot = (left, right) => left.exists === right.exists && left.value === right.value

export function createDraftOperation(kind, changes, label = '') {
  const effective = changes.filter(change => !sameSnapshot(change.before, change.after))
  return effective.length ? { kind, label, changes: effective } : null
}

export function applyDraftOperation(drafts, operation, direction = 'redo') {
  const next = { ...drafts }
  for (const change of operation?.changes || []) {
    const snapshot = direction === 'undo' ? change.before : change.after
    if (snapshot.exists) next[change.key] = snapshot.value
    else delete next[change.key]
  }

  return next
}

const formulaLike = value => /^[=+@]/.test(value) || (/^-/.test(value) && !/^-\d+(?:\.\d+)?$/.test(value))

export function planScorePaste({ text, start, roster, columns, drafts, valueFor, editable = true }) {
  const parsed = parseScoreClipboard(text)
  const startCell = resolveScoreCoordinate(start, roster, columns)
  const errors = []
  if (!editable) errors.push('This grading period is locked. No clipboard values were applied.')
  if (!startCell) errors.push('Select an editable assessment score cell before pasting.')
  if (!parsed.rectangular) errors.push('Clipboard rows must form one rectangular tab-separated range.')
  if (errors.length) return { ...parsed, errors, changes: [], skippedBlanks: 0 }

  if (startCell.row + parsed.height > roster.length || startCell.column + parsed.width > columns.length) {
    errors.push('The pasted range extends beyond the available Student rows or assessment columns.')
    return { ...parsed, errors, changes: [], skippedBlanks: 0 }
  }

  const changes = []
  let skippedBlanks = 0
  for (let rowOffset = 0; rowOffset < parsed.height; rowOffset++) {
    for (let columnOffset = 0; columnOffset < parsed.width; columnOffset++) {
      const raw = parsed.matrix[rowOffset][columnOffset]
      const value = String(raw).trim()
      const row = startCell.row + rowOffset
      const column = startCell.column + columnOffset
      const student = roster[row]
      const assessment = assessmentFor(columns[column])
      const location = `Row ${row + 1}, ${assessment.label}`
      if (value === '') {
        skippedBlanks++
        continue
      }
      if (formulaLike(value)) {
        errors.push(`${location}: formulas and formula-like values are not allowed.`)
        continue
      }
      const numeric = Number(value)
      if (!Number.isFinite(numeric)) {
        errors.push(`${location}: enter a numeric score.`)
        continue
      }
      if (numeric < 0 || numeric > Number(assessment.max_score)) {
        errors.push(`${location}: score must be from 0 to ${assessment.max_score}.`)
        continue
      }

      const key = scoreCellKey(student.enrollment_subject_id, assessment.id)
      const current = valueFor(student, assessment)
      if (current !== '' && current !== null && current !== undefined && Number(current) === numeric) continue
      changes.push({
        key,
        row,
        column,
        enrollmentSubjectId: student.enrollment_subject_id,
        assessmentId: assessment.id,
        before: draftSnapshot(drafts, key),
        after: { exists: true, value },
      })
    }
  }

  return {
    ...parsed,
    errors,
    changes: errors.length ? [] : changes,
    skippedBlanks,
    selection: {
      anchor: startCell,
      focus: scoreCoordinate(
        roster[startCell.row + parsed.height - 1],
        columns[startCell.column + parsed.width - 1],
        startCell.row + parsed.height - 1,
        startCell.column + parsed.width - 1,
      ),
    },
  }
}

export function boundedHistory(history, operation, limit = SCORE_HISTORY_LIMIT) {
  if (!operation) return history
  return [...history, operation].slice(-Math.max(1, limit))
}
