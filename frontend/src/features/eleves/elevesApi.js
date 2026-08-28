import apiClient from '../../api/client'

export async function fetchEleves(page = 1) {
  const { data } = await apiClient.get('/eleves', { params: { page } })
  return data
}

export async function fetchEleve(id) {
  const { data } = await apiClient.get(`/eleves/${id}`)
  return data
}

export async function createEleve(payload) {
  const { data } = await apiClient.post('/eleves', payload)
  return data
}

export async function updateEleve(id, payload) {
  const { data } = await apiClient.put(`/eleves/${id}`, payload)
  return data
}

export async function deleteEleve(id) {
  await apiClient.delete(`/eleves/${id}`)
}
