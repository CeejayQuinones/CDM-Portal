export const formatDashboardStatus = (value, fallback = 'Not finalized') => {
  if (!value) return fallback

  return String(value)
    .replaceAll('_', ' ')
    .toLowerCase()
    .replace(/\b\w/g, (letter) => letter.toUpperCase())
}
