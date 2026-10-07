const VALID_CLIENT_PLATFORMS = Object.freeze(['web', 'desktop', 'mobile'])

const configuredClient = import.meta.env?.VITE_CDM_CLIENT || 'web'

if (!VALID_CLIENT_PLATFORMS.includes(configuredClient)) {
  throw new Error(`Invalid VITE_CDM_CLIENT value: ${configuredClient}`)
}

export const clientPlatform = configuredClient
export { VALID_CLIENT_PLATFORMS }
