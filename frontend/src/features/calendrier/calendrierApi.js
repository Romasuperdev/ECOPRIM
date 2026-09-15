import apiClient from '../../api/client'

export async function fetchReferentielsEvenement() {
  const { data } = await apiClient.get('/evenements/referentiels')
  return data
}

export async function fetchEvenements(type) {
  const { data } = await apiClient.get('/evenements', { params: type ? { type } : {} })
  return data
}

export async function creerEvenement(payload) {
  const { data } = await apiClient.post('/evenements', payload)
  return data
}

export async function modifierEvenement(id, payload) {
  const { data } = await apiClient.put(`/evenements/${id}`, payload)
  return data
}

export async function supprimerEvenement(id) {
  await apiClient.delete(`/evenements/${id}`)
}
