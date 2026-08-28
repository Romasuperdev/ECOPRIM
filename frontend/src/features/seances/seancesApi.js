import apiClient from '../../api/client'

export async function fetchSeances(page = 1) {
  const { data } = await apiClient.get('/seances', { params: { page } })
  return data
}

export async function fetchSeance(id) {
  const { data } = await apiClient.get(`/seances/${id}`)
  return data
}

export async function createSeance(payload) {
  const { data } = await apiClient.post('/seances', payload)
  return data
}

export async function updateSeance(id, payload) {
  const { data } = await apiClient.put(`/seances/${id}`, payload)
  return data
}

export async function deleteSeance(id) {
  await apiClient.delete(`/seances/${id}`)
}
