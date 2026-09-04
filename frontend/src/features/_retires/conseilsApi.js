import apiClient from '../../api/client'

export async function fetchConseils(page = 1) {
  const { data } = await apiClient.get('/conseils-classe', { params: { page } })
  return data
}

export async function fetchConseil(id) {
  const { data } = await apiClient.get(`/conseils-classe/${id}`)
  return data
}

export async function createConseil(payload) {
  const { data } = await apiClient.post('/conseils-classe', payload)
  return data
}

export async function updateConseil(id, payload) {
  const { data } = await apiClient.put(`/conseils-classe/${id}`, payload)
  return data
}

export async function deleteConseil(id) {
  await apiClient.delete(`/conseils-classe/${id}`)
}

export async function saveDeliberation(conseilId, payload) {
  const { data } = await apiClient.post(`/conseils-classe/${conseilId}/deliberations`, payload)
  return data
}

export async function deleteDeliberation(conseilId, deliberationId) {
  await apiClient.delete(`/conseils-classe/${conseilId}/deliberations/${deliberationId}`)
}
