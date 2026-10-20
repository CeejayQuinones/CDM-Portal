export const EVENT_TIME_ZONE = 'Asia/Manila'
export const EVENT_TIME_OFFSET = '+08:00'

const parts = (value) => Object.fromEntries(
  new Intl.DateTimeFormat('en-CA', {
    timeZone: EVENT_TIME_ZONE,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    hourCycle: 'h23',
  }).formatToParts(new Date(value)).filter(({ type }) => type !== 'literal').map(({ type, value: part }) => [type, part]),
)

export const eventDateKey = (value) => {
  const date = parts(value)
  return `${date.year}-${date.month}-${date.day}`
}

export const toEventDateTimeInput = (value) => {
  if (!value) return ''
  const date = parts(value)
  return `${date.year}-${date.month}-${date.day}T${date.hour}:${date.minute}`
}

export const toEventApiDateTime = (value) => {
  if (!value) return ''
  const match = String(value).match(/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2})(?::(\d{2}))?$/)
  if (!match) throw new TypeError('Event date/time must use the datetime-local format.')

  // The Philippines uses UTC+08:00 year-round. Sending the offset makes the API contract unambiguous.
  return `${match[1]}T${match[2]}:${match[3] || '00'}${EVENT_TIME_OFFSET}`
}

export const formatEventDateTime = (value, { dateOnly = false, dateStyle = 'medium', locale } = {}) => value
  ? new Intl.DateTimeFormat(locale, {
      timeZone: EVENT_TIME_ZONE,
      ...(dateOnly ? { dateStyle } : { dateStyle, timeStyle: 'short' }),
    }).format(new Date(value))
  : '—'

export const formatEventTime = (value, locale) => value
  ? new Intl.DateTimeFormat(locale, { timeZone: EVENT_TIME_ZONE, hour: '2-digit', minute: '2-digit' }).format(new Date(value))
  : '—'
