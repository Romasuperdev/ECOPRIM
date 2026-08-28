import apiClient from '../../api/client'

export async function fetchNiveauxList() {
  const { data } = await apiClient.get('/niveaux')
  return data
}

export async function fetchNiveau(id) {
  const { data } = await apiClient.get(`/niveaux/${id}`)
  return data
}

export async function createNiveau(payload) {
  const { data } = await apiClient.post('/niveaux', payload)
  return data
}

export async function updateNiveau(id, payload) {
  const { data } = await apiClient.put(`/niveaux/${id}`, payload)
  return data
}

export async function deleteNiveau(id) {
  await apiClient.delete(`/niveaux/${id}`)
}
