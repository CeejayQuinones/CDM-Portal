import { apiClient } from '../../../services/apiClient'

const parseIdentity = ({ data }) => {
  const identity = data?.data
  if (data?.success !== true || typeof identity?.has_application !== 'boolean'
    || (identity.has_application && (!identity.application?.applicant_number || !identity.application?.cycle))
    || (!identity.has_application && identity.application !== null)) {
    throw new Error('Invalid admission identity response.')
  }
  return identity
}

export const fetchAdmissionIdentity = async () => parseIdentity(await apiClient.get('/admission/me'))
export const createAdmissionApplication = async () => {
  const identity = parseIdentity(await apiClient.post('/admission/applications', {}))
  if (!identity.has_application) throw new Error('Missing created application.')
  return identity
}
export const fetchAdmissionAvailability = async () => {
  const { data } = await apiClient.get('/admission/applications/availability')
  if (data?.success !== true || typeof data?.data?.allowed !== 'boolean') throw new Error('Invalid availability response.')
  return data.data
}
export const admissionCreationMessage = (error) => {
  const status = error?.response?.status
  if (status === 429) return 'Too many attempts. Please wait a minute before trying again.'
  if (status === 401 || status === 403) return 'You do not have permission to start an admission application.'
  const code = error?.response?.data?.code ?? error
  return ({
    no_open_cycle: 'No admission cycle is currently open. The application period may not have started or may have closed.',
    cycle_unavailable: 'Admission applications are currently unavailable. Please try again later.',
    application_exists: 'An admission application already exists. Check your current status.',
    profile_required: 'Your portal profile is required. Please contact support.',
    permission_denied: 'You do not have permission to start an admission application.',
  })[code] || 'Unable to create your application. Please refresh your status before trying again.'
}
