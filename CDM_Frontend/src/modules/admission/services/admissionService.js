import { apiClient } from '../../../services/apiClient'

export const fetchAdmissionIdentity = async () => {
  const { data } = await apiClient.get('/admission/me')
  const identity = data?.data
  if (data?.success !== true || typeof identity?.has_application !== 'boolean'
    || (identity.has_application && (!identity.application?.applicant_number || !identity.application?.cycle))
    || (!identity.has_application && identity.application !== null)) {
    throw new Error('Invalid admission identity response.')
  }
  return identity
}
