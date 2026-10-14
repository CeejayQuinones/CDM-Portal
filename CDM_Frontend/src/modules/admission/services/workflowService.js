import { apiClient } from '../../../services/apiClient'
export const admissionApi = async (method, path, body) => {
  const response = await apiClient.request({ method, url: '/admission/' + path, ...(method === 'get' ? { params: body } : { data: body }) })
  if (response.data?.success !== true) throw new Error('Invalid Admission response')
  return response.data.data
}
export const admissionError = (error) => {
  const status = error?.response?.status
  const code = error?.response?.data?.code
  const conflictMessages = {
    ADMISSION_STATE_CHANGED: 'The application or published result changed. Eligibility was refreshed; review the current details and try again.',
    ADMISSION_ACCEPTANCE_STALE: 'Registrar acceptance no longer matches the current published result. Renew acceptance before conversion.',
    ADMISSION_PROGRAM_CHANGED: 'The admitted program changed. Refresh and review the current program.',
    STUDENT_NUMBER_ASSIGNED: 'That official student number is already assigned. Enter a unique official student number.',
    STUDENT_NUMBER_ALLOCATION_CONFLICT: 'The next student number was assigned concurrently. Eligibility was refreshed; confirm conversion again.',
    STUDENT_NUMBER_SEQUENCE_EXHAUSTED: 'The student number sequence for this admission year is exhausted.',
    ACADEMIC_IDENTITY_CONFLICT: 'An academic identity already exists for this applicant. Registrar reconciliation is required.',
    ALREADY_CONVERTED: 'Applicant has already been converted to Student.',
  }
  if (status === 409) return conflictMessages[code] || error?.response?.data?.message || 'Another user updated this applicant. Refresh and review the current state.'
  if (status === 422) return 'Please check the form fields and try again.'
  if (status === 403) return 'This action is not available for your account or current Admission status.'
  if (status === 404) return 'No matching Admission record was found.'
  if (status === 429) return 'Too many requests. Please wait a minute and try again.'
  return 'Admission is temporarily unavailable. Please reload and try again.'
}
export const topics = ['General Mathematics', 'Science', 'Reading Comprehension', 'Logical Reasoning', 'Digital Literacy']
