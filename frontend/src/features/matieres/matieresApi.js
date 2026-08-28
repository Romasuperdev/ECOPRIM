import apiClient from '../../api/client'

export async function fetchMatieresPage(page = 1) {
  const { data } = await apiClient.get('/matieres', { params: { page } })
  return data
}

export async function fetchMatiere(id) {
  const { data } = await apiClient.get(`/matieres/${id}`)
  return data
}

export async function createMatiere(payload) {
  const { data } = await apiClient.post('/matieres', payload)
  return data
}

export async function updateMatiere(id, payload) {
  const { data } = await apiClient.put(`/matieres/${id}`, payload)
  return data
}

export async function deleteMatiere(id) {
  await apiClient.delete(`/matieres/${id}`)
}
