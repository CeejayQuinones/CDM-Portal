const VALID_CLIENT_PLATFORMS = Object.freeze(['web', 'desktop', 'mobile'])

const configuredClient = import.meta.env?.VITE_CDM_CLIENT || 'web'

if (!VALID_CLIENT_PLATFORMS.includes(configuredClient)) {
  throw new Error(`Invalid VITE_CDM_CLIENT value: ${configuredClient}`)
}

export const clientPlatform = configuredClient
export { VALID_CLIENT_PLATFORMS }

export const PLATFORM_ACCESS_BY_ROLE = Object.freeze({
  Admin: Object.freeze(['desktop']),
  'Registrar Staff': Object.freeze(['desktop']),
  Professor: Object.freeze(['web', 'mobile']),
  Student: Object.freeze(['web', 'mobile']),
  Guest: Object.freeze(['web', 'mobile']),
})

export const isRoleAllowedOnClient = (role, client = clientPlatform) =>
  PLATFORM_ACCESS_BY_ROLE[role]?.includes(client) === true
