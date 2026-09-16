const terminalStatuses = {
  completed: 'Completed', rejected: 'Rejected', cancelled: 'Cancelled',
  released: 'Completed', claimed: 'Completed', denied: 'Rejected', canceled: 'Cancelled', no_show: 'Cancelled',
}

export const historyStatus = value => {
  const key = String(value || '').toLowerCase()
  return Object.hasOwn(terminalStatuses, key) ? terminalStatuses[key] : null
}

export function finalizedAt(item) {
  const status = historyStatus(item?.status)
  const timestamp = status === 'Completed' ? item?.completed_at || item?.released_at
    : status === 'Rejected' ? item?.rejected_at : status === 'Cancelled' ? item?.cancelled_at : null
  return timestamp || item?.updated_at || item?.created_at || item?.request_date || null
}

export function latestHistoryAppointment(item) {
  return item?.latest_appointment || [...(item?.appointments || [])].sort((a, b) => Number(b.id) - Number(a.id))[0] || null
}

export function finalReason(item) {
  const status = historyStatus(item?.status)
  const event = [...(item?.status_changes || [])].filter(change => historyStatus(change.to_status || change.action) === status && change.reason)
    .sort((a, b) => new Date(b.created_at) - new Date(a.created_at))[0]
  return (status === 'Cancelled' && item?.cancellation_reason) || event?.reason || item?.remarks || latestHistoryAppointment(item)?.remarks || ''
}

function parseHistoryDate(value) {
  if (!value) return null
  const text = String(value)
  const normalized = /^\d{4}-\d{2}-\d{2}$/.test(text) ? `${text}T00:00:00+08:00` : text
  const parsed = new Date(normalized)
  return Number.isNaN(parsed.getTime()) ? null : parsed
}

export function historyDate(value, includeTime = false) {
  const date = parseHistoryDate(value)
  if (!date) return 'Not recorded'
  const formatted = new Intl.DateTimeFormat('en-US', { month: 'short', day: 'numeric', year: 'numeric', timeZone: 'Asia/Manila' }).format(date)
  if (!includeTime || /^\d{4}-\d{2}-\d{2}$/.test(String(value))) return formatted
  const time = new Intl.DateTimeFormat('en-US', { hour: 'numeric', minute: '2-digit', timeZone: 'Asia/Manila' }).format(date)
  return `${formatted} · ${time}`
}

export function historyAppointmentStatus(value) {
  return historyStatus(value) || ({ pending: 'Awaiting approval', confirmed: 'Confirmed', scheduled: 'Scheduled' })[value] || 'Not recorded'
}

export function historyAppointmentTime(value) {
  const match = String(value || '').match(/^([01]\d|2[0-3]):([0-5]\d)(?::[0-5]\d)?$/)
  if (!match) return 'Not recorded'
  const hour = Number(match[1])
  return `${hour % 12 || 12}:${match[2]} ${hour < 12 ? 'AM' : 'PM'}`
}

export function historyTimeline(item) {
  if (!item) return []
  const labels = {
    submitted: 'Requested', requested: 'Requested', appointment_assigned: 'Date assigned', date_assigned: 'Date assigned',
    approved: 'Approved', rejected: 'Rejected', code_verified: 'Verified', verified: 'Verified',
    completed: 'Completed', released: 'Completed', cancelled: 'Cancelled', canceled: 'Cancelled', no_show_cancelled: 'Cancelled',
  }
  const events = (item.status_changes || []).flatMap(event => {
    const label = Object.hasOwn(labels, event.action) ? labels[event.action] : null
    return label && parseHistoryDate(event.created_at) ? [{ label, at: event.created_at }] : []
  })
  const add = (label, at) => {
    if (parseHistoryDate(at) && !events.some(event => event.label === label)) events.push({ label, at })
  }
  add('Requested', item.created_at || item.request_date)
  add('Date assigned', latestHistoryAppointment(item)?.created_at)
  add('Approved', item.approved_at)
  add('Verified', item.code_verified_at)
  add('Rejected', item.rejected_at)
  add('Completed', item.completed_at || item.released_at)
  add('Cancelled', item.cancelled_at)
  return events.filter((event, index) => events.findIndex(other => other.label === event.label && new Date(other.at).getTime() === new Date(event.at).getTime()) === index)
    .sort((a, b) => new Date(a.at) - new Date(b.at))
}
