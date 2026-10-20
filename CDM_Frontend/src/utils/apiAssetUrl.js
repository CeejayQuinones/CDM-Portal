// Storage paths belong to the API origin, which can differ from the SPA origin.
export function resolveApiAssetUrl(path, apiBaseURL, pageURL) {
  if (!path) return ''
  try {
    const url = new URL(path, new URL(apiBaseURL || '/api', pageURL))
    return ['http:', 'https:', 'blob:'].includes(url.protocol) || url.href.startsWith('data:image/') ? url.href : ''
  } catch {
    return ''
  }
}
