import apiClient from '../../api/client'

export async function fetchAbsences(page = 1) {
  const { data } = await apiClient.get('/absences', { params: { page } })
  return data
}

export async function fetchAbsence(id) {
  const { data } = await apiClient.get(`/absences/${id}`)
  return data
}

export async function createAbsence(payload) {
  const { data } = await apiClient.post('/absences', payload)
  return data
}

export async function updateAbsence(id, payload) {
  const { data } = await apiClient.put(`/absences/${id}`, payload)
  return data
}

export async function deleteAbsence(id) {
  await apiClient.delete(`/absences/${id}`)
}
