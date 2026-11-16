import { pathToFileURL } from 'node:url'

const productionWebsiteHost = 'cdm-portal.pages.dev'
const productionApiHost = 'api.cdmportal.xyz'
const productionDatabaseName = 'cdm_portal'
const platforms = new Set(['web', 'mobile', 'desktop'])

function httpsOrigin(value, label) {
  if (!value) throw new Error(`${label} is required.`)
  let url
  try {
    url = new URL(value)
  } catch {
    throw new Error(`${label} must be an absolute HTTPS origin.`)
  }
  if (url.protocol !== 'https:' || url.username || url.password || url.search || url.hash || url.pathname !== '/') {
    throw new Error(`${label} must be an HTTPS origin without a path, query, or credentials.`)
  }
  if (url.hostname.endsWith('.invalid') || url.hostname === 'example.com') {
    throw new Error(`${label} must be a non-placeholder test hostname.`)
  }
  return url
}

export function validateItValidationTargets(env, platform = 'web') {
  if (!platforms.has(platform)) throw new Error(`Unknown client platform: ${platform}.`)

  const website = httpsOrigin(env.IT_VALIDATION_WEB_URL, 'IT_VALIDATION_WEB_URL')
  const api = httpsOrigin(env.IT_VALIDATION_API_URL, 'IT_VALIDATION_API_URL')
  const database = String(env.IT_VALIDATION_DB_DATABASE || '').trim()
  const clientKey = platform === 'desktop' ? 'VITE_DESKTOP_API_BASE_URL' : 'VITE_API_BASE_URL'
  const clientApi = String(env[clientKey] || '').replace(/\/$/, '')

  if (website.hostname === productionWebsiteHost || api.hostname === productionApiHost) {
    throw new Error('IT validation must not use either existing deployed hostname.')
  }
  if (website.origin === api.origin) {
    throw new Error('The test Website and API must use separate origins.')
  }
  if (!database || database.toLowerCase() === productionDatabaseName || !/^[A-Za-z0-9_]+$/.test(database)) {
    throw new Error('IT_VALIDATION_DB_DATABASE must name a separate test database.')
  }
  if (clientApi !== `${api.origin}/api`) {
    throw new Error(`${clientKey} must equal the test API origin followed by /api.`)
  }

  return { platform, website: website.origin, api: api.origin, database }
}

if (process.argv[1] && pathToFileURL(process.argv[1]).href === import.meta.url) {
  try {
    const targets = validateItValidationTargets(process.env, process.argv[2] || 'web')
    process.stdout.write(`IT validation configuration checked: ${targets.platform}; Website ${targets.website}; API ${targets.api}; database ${targets.database}. Host and database provisioning still require separate verification.\n`)
  } catch (error) {
    process.stderr.write(`IT validation build blocked: ${error.message}\n`)
    process.exitCode = 1
  }
}
