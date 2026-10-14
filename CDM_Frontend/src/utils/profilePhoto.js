import { apiAssetUrl } from '../services/apiClient'

export const profilePhotoUrl = (profile) => apiAssetUrl(profile?.profile_photo_url || null)
