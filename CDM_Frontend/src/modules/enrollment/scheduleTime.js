export const isValidScheduleTime = value => {
  const match = /^(\d{2}):(\d{2})$/.exec(String(value || '').trim())
  return Boolean(match && Number(match[1]) < 24 && Number(match[2]) < 60)
}

export const scheduleTimeToMinutes = value => {
  if (!isValidScheduleTime(value)) return null
  const [hours, minutes] = value.split(':').map(Number)
  return hours * 60 + minutes
}

export const meetingTimeState = meeting => {
  if (!meeting?.start_time || !meeting?.end_time) return { valid: false, duration: 0, error: 'Choose both a start and end time.' }
  const start = scheduleTimeToMinutes(meeting.start_time)
  const end = scheduleTimeToMinutes(meeting.end_time)
  if (start === null || end === null) return { valid: false, duration: 0, error: 'Use a valid time in HH:MM format.' }
  if (end <= start) return { valid: false, duration: 0, error: 'End time must be later than start time.' }
  return { valid: true, duration: end - start, error: '' }
}

export const weeklyScheduleMinutes = meetings => (meetings || []).reduce((total, meeting) => total + meetingTimeState(meeting).duration, 0)

export const formatScheduleDuration = minutes => {
  const hours = Math.floor(Number(minutes || 0) / 60)
  const remainder = Number(minutes || 0) % 60
  if (!hours) return `${remainder} min`
  return remainder ? `${hours} hr ${remainder} min` : `${hours} hr`
}

export const formatScheduleTime = value => {
  if (!isValidScheduleTime(value)) return value || ''
  const [hours, minutes] = value.split(':').map(Number)
  const period = hours >= 12 ? 'PM' : 'AM'
  const displayHour = hours % 12 || 12
  return `${displayHour}:${String(minutes).padStart(2, '0')} ${period}`
}

export const scheduleTimeParts = value => {
  if (!isValidScheduleTime(value)) return { hour: '', minute: '', period: 'AM' }
  const [hours, minutes] = value.split(':').map(Number)
  return { hour: String(hours % 12 || 12), minute: String(minutes).padStart(2, '0'), period: hours >= 12 ? 'PM' : 'AM' }
}

export const scheduleTimeFromParts = (hour, minute, period) => {
  const parsedHour = Number(hour)
  const parsedMinute = Number(minute)
  if (!Number.isInteger(parsedHour) || parsedHour < 1 || parsedHour > 12 || !Number.isInteger(parsedMinute) || parsedMinute < 0 || parsedMinute > 59 || !['AM', 'PM'].includes(period)) return ''
  const hours = parsedHour % 12 + (period === 'PM' ? 12 : 0)
  return `${String(hours).padStart(2, '0')}:${String(parsedMinute).padStart(2, '0')}`
}

export const spinScheduleHour = (hour, direction) => {
  const current = Number(hour)
  if (!Number.isInteger(current) || current < 1 || current > 12) return direction > 0 ? '1' : '12'
  return String(((current - 1 + direction + 12) % 12) + 1)
}

export const spinScheduleMinute = (minute, direction) => {
  const current = Number(minute)
  const base = Number.isInteger(current) && current >= 0 && current <= 59 ? current : 0
  return String((base + direction * 15 + 60) % 60).padStart(2, '0')
}
