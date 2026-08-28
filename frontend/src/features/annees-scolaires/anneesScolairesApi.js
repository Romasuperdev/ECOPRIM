import apiClient from '../../api/client'

export async function fetchAnneesScolairesList() {
  const { data } = await apiClient.get('/annees-scolaires')
  return data
}

export async function createAnneeScolaire(payload) {
  const { data } = await apiClient.post('/annees-scolaires', payload)
  return data
}

export async function updateAnneeScolaire(id, payload) {
  const { data } = await apiClient.put(`/annees-scolaires/${id}`, payload)
  return data
}

export async function deleteAnneeScolaire(id) {
  await apiClient.delete(`/annees-scolaires/${id}`)
}

export async function proposerReinscriptions(id) {
  const { data } = await apiClient.post(`/annees-scolaires/${id}/proposer-reinscriptions`)
  return data
}
