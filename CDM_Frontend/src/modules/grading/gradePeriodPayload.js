export function parseGradePeriods(payload) {
  const periods = payload?.periods?.data
  if (!Array.isArray(periods)) throw new Error('The grade period response could not be displayed.')

  return { periods, options: payload }
}
