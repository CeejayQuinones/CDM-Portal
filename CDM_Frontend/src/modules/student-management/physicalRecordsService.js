import { apiClient } from '../../services/apiClient'

const unwrap = async (request) => (await request).data.data

export const physicalRecordsService = {
  cabinets: () => unwrap(apiClient.get('/registrar/cabinets')),
  createCabinet: (payload) => unwrap(apiClient.post('/registrar/cabinets', payload)),
  cabinet: (id) => unwrap(apiClient.get(`/registrar/cabinets/${id}`)),
  cabinetSlot: (id, page = 1) => unwrap(apiClient.get(`/registrar/cabinet-slots/${id}`, { params: { page } })),
  updateCabinetSlot: (id, payload) => unwrap(apiClient.patch(`/registrar/cabinet-slots/${id}`, payload)),
  assignStudentLocation: (studentId, payload) =>
    unwrap(apiClient.put(`/registrar/students/${studentId}/record-location`, payload)),
}
