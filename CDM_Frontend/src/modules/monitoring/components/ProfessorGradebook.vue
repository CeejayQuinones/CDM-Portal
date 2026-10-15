<script setup>
import { computed, ref, watch } from 'vue'
import { apiClient } from '../../../services/apiClient'
import {
  createPerformanceRecord,
  fetchGradebook,
  submitPeriodGrade,
  updatePerformanceRecord,
} from '../services/monitoringApi'

const emit = defineEmits(['changed'])

const loading = ref(true)
const exporting = ref(false)
const error = ref('')
const notice = ref('')
const rows = ref([])
const activeCode = ref('')
const selected = ref(null)
const edits = ref({})
const newHeader = ref('')

const fixedColumns = [
  { key: 'student_number', label: 'Student number' },
  { key: 'student_name', label: 'Name' },
  { key: 'year_level', label: 'Year' },
  { key: 'section_name', label: 'Section' },
  { key: 'course_code', label: 'Course' },
]

const sheets = computed(() => {
  const grouped = new Map()
  rows.value.forEach((row) => {
    const code = row.subject_code || 'Subject'
    if (!grouped.has(code)) grouped.set(code, { code, name: row.subject_name || code, rows: [] })
    grouped.get(code).rows.push(row)
  })
  return [...grouped.values()]
})

const activeSheet = computed(() => sheets.value.find((sheet) => sheet.code === activeCode.value) || sheets.value[0] || null)

const scoreColumns = computed(() => {
  const names = []
  ;(activeSheet.value?.rows || []).forEach((row) => {
    row.class_scores.forEach((score) => {
      if (!names.includes(score.assessment_name)) names.push(score.assessment_name)
    })
  })
  return names
})

const columns = computed(() => [
  ...fixedColumns,
  ...scoreColumns.value.map((name) => ({ key: `score:${name}`, label: name, score: name })),
  { key: 'new-score', label: '', fresh: true },
  { key: 'Midterm', label: 'Midterm', period: 'Midterm' },
  { key: 'Final', label: 'Final', period: 'Final' },
])

const selectionLabel = computed(() => {
  if (!selected.value) return ''
  const column = columns.value[selected.value.column]
  const row = activeSheet.value?.rows[selected.value.row]
  if (!column || !row) return ''
  return `${columnLetter(selected.value.column)}${selected.value.row + 2}  ${column.label || 'New score'} · ${row.student_name}`
})

function columnLetter(index) {
  let name = ''
  let n = index + 1
  while (n > 0) {
    n -= 1
    name = String.fromCharCode(65 + (n % 26)) + name
    n = Math.floor(n / 26)
  }
  return name
}

function cellKey(row, key) {
  return `${row.student_id}:${row.subject_code}:${key}`
}

function shown(row, column) {
  const key = cellKey(row, column.key)
  if (Object.prototype.hasOwnProperty.call(edits.value, key)) return edits.value[key]
  if (column.key === 'year_level') return row.year_level ? String(row.year_level) : ''
  if (column.score) {
    const found = row.class_scores.find((score) => score.assessment_name === column.score)
    return found ? String(found.score) : ''
  }
  if (column.period === 'Midterm') return row.midterm ?? ''
  if (column.period === 'Final') return row.final ?? ''
  return row[column.key] ?? ''
}

function locked(row, column) {
  if (!column.period && !column.score && !column.fresh) return true
  if (column.period === 'Midterm') return row.midterm_status === 'approved'
  if (column.period === 'Final') return row.final_status === 'approved'
  return false
}

function tone(row, column) {
  if (column.period === 'Midterm') return row.midterm_status
  if (column.period === 'Final') return row.final_status
  return ''
}

async function load(quiet = false) {
  if (!quiet) loading.value = true
  error.value = ''
  try {
    const payload = await fetchGradebook()
    rows.value = payload.rows || []
    if (!sheets.value.some((sheet) => sheet.code === activeCode.value)) {
      activeCode.value = sheets.value[0]?.code || ''
    }
  } catch (err) {
    error.value = err.response?.data?.message || 'Unable to load the grade sheet.'
  } finally {
    loading.value = false
  }
}

function choose(rowIndex, columnIndex) {
  selected.value = { row: rowIndex, column: columnIndex }
}

async function commit(row, column, event) {
  const value = event.target.value
  edits.value = { ...edits.value, [cellKey(row, column.key)]: value }
  const trimmed = String(value).trim()
  if (trimmed === '' || Number.isNaN(Number(trimmed))) return
  error.value = ''
  notice.value = ''
  try {
    if (column.period) {
      const current = column.period === 'Midterm' ? row.midterm : row.final
      if (Number(current) === Number(trimmed)) return
      await submitPeriodGrade(row.student_id, {
        subject_code: row.subject_code,
        period: column.period,
        grade: Number(trimmed),
      })
      notice.value = `${column.period} for ${row.student_name} was sent to the registrar.`
    } else if (column.score || column.fresh) {
      const name = column.score || newHeader.value.trim()
      if (!name) {
        error.value = 'Type the score name in the empty column header first, like Quiz 1.'
        return
      }
      const existing = row.class_scores.find((score) => score.assessment_name === name)
      if (existing) {
        if (Number(existing.score) === Number(trimmed)) return
        await updatePerformanceRecord(existing.id, { score: Number(trimmed), max_score: existing.max_score })
      } else {
        const body = new FormData()
        body.append('subject_code', row.subject_code)
        body.append('subject_name', row.subject_name || '')
        body.append('assessment_name', name)
        body.append('topic', name)
        body.append('score', trimmed)
        body.append('max_score', '100')
        await createPerformanceRecord(row.student_id, body)
      }
      if (column.fresh) newHeader.value = ''
      notice.value = `${name} was saved for ${row.student_name}.`
    } else {
      return
    }
    edits.value = {}
    await load(true)
    emit('changed')
  } catch (err) {
    error.value = err.response?.data?.message || 'Unable to save that cell.'
  }
}

async function exportFile() {
  exporting.value = true
  error.value = ''
  try {
    const response = await apiClient.get('/monitoring/gradebook/export', { responseType: 'blob' })
    const url = URL.createObjectURL(response.data)
    const link = document.createElement('a')
    link.href = url
    link.download = 'class-grades.xlsx'
    link.click()
    URL.revokeObjectURL(url)
  } catch {
    error.value = 'Unable to export the Excel file.'
  } finally {
    exporting.value = false
  }
}

watch(sheets, (next) => {
  if (!next.some((sheet) => sheet.code === activeCode.value)) activeCode.value = next[0]?.code || ''
})

load()
</script>

<template>
  <section class="excel">
    <div class="ribbon">
      <div>
        <strong>class-grades.xlsx</strong>
        <span>Type in a cell, then leave it to save. Midterm and Final go to the registrar.</span>
      </div>
      <button type="button" :disabled="exporting || !rows.length" @click="exportFile">
        {{ exporting ? 'Exporting…' : 'Export' }}
      </button>
    </div>
    <div class="formula">
      <span class="name-box">{{ selected ? columnLetter(selected.column) + (selected.row + 2) : 'A1' }}</span>
      <span class="fx">fx</span>
      <span class="formula-text">{{ selectionLabel || 'Select a cell' }}</span>
    </div>
    <p v-if="error" class="error" role="alert">{{ error }}</p>
    <p v-if="notice" class="notice">{{ notice }}</p>
    <p v-if="loading" class="muted">Opening the sheet…</p>
    <p v-else-if="!activeSheet" class="muted">No assigned students yet.</p>
    <div v-else class="grid-wrap">
      <table>
        <thead>
          <tr class="letters">
            <th class="corner"></th>
            <th v-for="(column, index) in columns" :key="`letter-${column.key}`">{{ columnLetter(index) }}</th>
          </tr>
          <tr class="labels">
            <th class="row-no">1</th>
            <th v-for="column in columns" :key="`label-${column.key}`">
              <input
                v-if="column.fresh"
                v-model="newHeader"
                class="header-input"
                type="text"
                placeholder="New score"
                aria-label="New score column"
              />
              <template v-else>{{ column.label }}</template>
            </th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(row, rowIndex) in activeSheet.rows" :key="`${row.student_id}-${row.subject_code}`">
            <th class="row-no">{{ rowIndex + 2 }}</th>
            <td
              v-for="(column, columnIndex) in columns"
              :key="`${row.student_id}-${column.key}`"
              :class="[tone(row, column), { selected: selected?.row === rowIndex && selected?.column === columnIndex, locked: locked(row, column) }]"
              @click="choose(rowIndex, columnIndex)"
            >
              <input
                v-if="!locked(row, column)"
                :value="shown(row, column)"
                type="text"
                inputmode="decimal"
                @focus="choose(rowIndex, columnIndex)"
                @blur="commit(row, column, $event)"
                @keydown.enter="$event.target.blur()"
              />
              <span v-else>{{ shown(row, column) }}</span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <div class="tabs">
      <button
        v-for="sheet in sheets"
        :key="sheet.code"
        type="button"
        :class="{ active: activeSheet?.code === sheet.code }"
        @click="activeCode = sheet.code"
      >
        {{ sheet.code }}
      </button>
    </div>
  </section>
</template>

<style scoped>
.excel {
  background: #f3f3f3;
  border: 1px solid #d0d0d0;
  color: #1a1a1a;
  display: grid;
  font-family: Calibri, "Segoe UI", sans-serif;
  gap: 0;
}
.ribbon, .formula, .tabs {
  align-items: center;
  display: flex;
  gap: 10px;
}
.ribbon {
  background: #217346;
  color: #fff;
  justify-content: space-between;
  padding: 8px 12px;
}
.ribbon strong, .ribbon span { display: block; }
.ribbon span { font-size: 0.82rem; opacity: 0.9; }
.ribbon button, .tabs button {
  background: #fff;
  border: 1px solid #c8c8c8;
  border-radius: 2px;
  color: #217346;
  cursor: pointer;
  font-weight: 700;
  min-height: 30px;
  padding: 0 12px;
}
.formula {
  background: #fff;
  border-bottom: 1px solid #d0d0d0;
  padding: 4px 8px;
}
.name-box, .fx {
  border: 1px solid #d0d0d0;
  min-width: 54px;
  padding: 4px 8px;
  text-align: center;
}
.fx { color: #666; font-style: italic; min-width: 28px; }
.formula-text { color: #333; }
.error, .notice, .muted { margin: 0; padding: 8px 12px; }
.error { color: #9b2c2c; }
.notice { color: #217346; }
.grid-wrap { background: #fff; overflow: auto; }
table { border-collapse: collapse; min-width: 920px; }
th, td {
  border: 1px solid #d4d4d4;
  font-weight: 400;
  height: 28px;
  min-width: 110px;
  padding: 0;
}
.letters th, .row-no, .labels th {
  background: #f3f3f3;
  color: #444;
  font-size: 0.78rem;
  font-weight: 700;
  min-width: 36px;
  text-align: center;
}
.labels th { min-width: 120px; padding: 0 6px; white-space: nowrap; }
.corner { min-width: 36px; }
td input, .header-input {
  background: transparent;
  border: 0;
  color: #1a1a1a;
  font: inherit;
  height: 28px;
  padding: 0 6px;
  width: 100%;
}
td.selected { outline: 2px solid #217346; outline-offset: -2px; }
td.locked { background: #f7f7f7; color: #333; padding: 0 6px; }
td.approved { background: #e5f3ea; }
td.submitted { background: #fff4cc; }
td span { display: block; padding: 0 6px; }
.tabs { background: #f3f3f3; border-top: 1px solid #d0d0d0; padding: 4px 8px 0; }
.tabs button { background: #e6e6e6; border-bottom: 0; border-radius: 4px 4px 0 0; color: #333; font-weight: 600; }
.tabs button.active { background: #fff; color: #217346; }
</style>
