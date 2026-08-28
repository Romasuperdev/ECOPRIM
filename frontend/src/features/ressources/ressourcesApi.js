import apiClient from '../../api/client'

export async function fetchRessources(page = 1) {
  const { data } = await apiClient.get('/ressources', { params: { page } })
  return data
}

export async function fetchRessource(id) {
  const { data } = await apiClient.get(`/ressources/${id}`)
  return data
}

export async function createRessource(payload) {
  const { data } = await apiClient.post('/ressources', payload)
  return data
}

export async function updateRessource(id, payload) {
  const { data } = await apiClient.put(`/ressources/${id}`, payload)
  return data
}

export async function deleteRessource(id) {
  await apiClient.delete(`/ressources/${id}`)
}
