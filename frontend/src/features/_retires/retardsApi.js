import apiClient from '../../api/client'

export async function fetchRetards(page = 1) {
  const { data } = await apiClient.get('/retards', { params: { page } })
  return data
}

export async function fetchRetard(id) {
  const { data } = await apiClient.get(`/retards/${id}`)
  return data
}

export async function createRetard(payload) {
  const { data } = await apiClient.post('/retards', payload)
  return data
}

export async function updateRetard(id, payload) {
  const { data } = await apiClient.put(`/retards/${id}`, payload)
  return data
}

export async function deleteRetard(id) {
  await apiClient.delete(`/retards/${id}`)
}
