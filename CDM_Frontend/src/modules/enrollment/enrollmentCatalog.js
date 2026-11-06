import { apiClient } from '../../services/apiClient'
export { curriculumGroups } from './enrollmentCatalogPresentation.js'

export async function getEnrollmentCatalog() {
  const response = await apiClient.get('/enrollment/academic/catalog')

  return response.data.data
}

