export const TIME_FILTERS = Object.freeze([
  { value: 'today', label: 'Today' },
  { value: 'yesterday', label: 'Yesterday' },
  { value: 'last_7_days', label: 'Last 7 Days' },
  { value: 'this_month', label: 'This Month' },
  { value: 'all', label: 'All' },
])

const STATUS_ACCENTS = new Set(['pending', 'processing', 'ready_for_release', 'released', 'rejected', 'cancelled'])

const exactDateTimeFormatter = new Intl.DateTimeFormat('en-PH', {
  dateStyle: 'medium',
  timeStyle: 'short',
})

const exactDateFormatter = new Intl.DateTimeFormat('en-PH', {
  dateStyle: 'medium',
})

function parseDate(value) {
  if (!value) return null

  const normalized = /^\d{4}-\d{2}-\d{2}$/.test(String(value)) ? `${value}T00:00:00` : value
  const date = new Date(normalized)

  return Number.isNaN(date.getTime()) ? null : date
}

function calendarDayDifference(later, earlier) {
  const laterDay = Date.UTC(later.getFullYear(), later.getMonth(), later.getDate())
  const earlierDay = Date.UTC(earlier.getFullYear(), earlier.getMonth(), earlier.getDate())

  return Math.floor((laterDay - earlierDay) / 86_400_000)
}

export function requestReference(id) {
  return `REQ-${String(id ?? '').padStart(6, '0')}`
}

export function studentName(student) {
  const profile = student?.user?.profile || student?.user_profile || student?.userProfile

  return [profile?.first_name, profile?.middle_name, profile?.last_name, profile?.suffix].filter(Boolean).join(' ')
}

export function documentTypeAccentClass(documentName) {
  const normalizedName = String(documentName || '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, ' ')
    .trim()

  if (normalizedName.includes('birth certificate')) return 'document-type-yellow'
  if (normalizedName.includes('certificate of enrollment')) return 'document-type-green'
  if (normalizedName.includes('registration form')) return 'document-type-blue'
  if (normalizedName.includes('form 137')) return 'document-type-purple'
  if (normalizedName.includes('school card') || normalizedName.includes('report card')) return 'document-type-orange'

  return 'document-type-gray'
}

export function requestStatusAccentClass(status) {
  const normalizedStatus = String(status || '').toLowerCase()
  const accent = STATUS_ACCENTS.has(normalizedStatus) ? normalizedStatus.replaceAll('_', '-') : 'gray'

  return `status-accent-${accent}`
}

export function requestStatusFromActivity(activity) {
  if (activity?.type !== 'request') return null

  if (activity.action === 'submitted') return 'pending'
  if (['approved', 'processed', 'returned_to_processing'].includes(activity.action)) return 'processing'

  return activity.action
}

export function formatExactDateTime(value) {
  const date = parseDate(value)

  return date ? exactDateTimeFormatter.format(date) : '—'
}

export function formatExactDate(value) {
  const date = parseDate(value)

  return date ? exactDateFormatter.format(date) : '—'
}

export function formatRelativeTime(value, relativeTo = new Date()) {
  const date = parseDate(value)
  if (!date) return '—'

  const elapsedSeconds = Math.floor((relativeTo.getTime() - date.getTime()) / 1000)
  if (elapsedSeconds < 0) return formatExactDateTime(value)
  if (elapsedSeconds < 45) return 'Just now'
  if (elapsedSeconds < 90) return '1 minute ago'
  if (elapsedSeconds < 3_600) return `${Math.floor(elapsedSeconds / 60)} minutes ago`
  if (elapsedSeconds < 7_200) return '1 hour ago'
  if (elapsedSeconds < 86_400) return `${Math.floor(elapsedSeconds / 3_600)} hours ago`

  const days = calendarDayDifference(relativeTo, date)
  if (days === 1) return 'Yesterday'
  if (days < 7) return `${days} days ago`
  if (days < 14) return '1 week ago'
  if (days < 30) return `${Math.floor(days / 7)} weeks ago`

  return formatExactDate(value)
}

export function appointmentDateTime(date, time) {
  if (!date) return '—'

  const dateValue = String(date).slice(0, 10)
  const timeValue = String(time || '').slice(0, 5)

  return `${formatExactDate(dateValue)}${timeValue ? ` at ${timeValue}` : ''}`
}
