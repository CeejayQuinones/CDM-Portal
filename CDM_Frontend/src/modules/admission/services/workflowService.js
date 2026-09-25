import { apiClient } from '../../../services/apiClient'
export const admissionApi = async (method, path, body) => {
  const response = await apiClient.request({ method, url: '/admission/' + path, ...(method === 'get' ? { params: body } : { data: body }) })
  if (response.data?.success !== true) throw new Error('Invalid Admission response')
  return response.data.data
}
export const admissionError = (error) => {
  const status = error?.response?.status
  if (status === 409) return 'This action is no longer available or the record changed. Reload to check its current status.'
  if (status === 422) return 'Please check the form fields and try again.'
  if (status === 403) return 'This action is not available for your account or current Admission status.'
  if (status === 404) return 'No matching Admission record was found.'
  if (status === 429) return 'Too many requests. Please wait a minute and try again.'
  return 'Admission is temporarily unavailable. Please reload and try again.'
}
export const topics = ['General Mathematics', 'Science', 'Reading Comprehension', 'Logical Reasoning', 'Digital Literacy']
