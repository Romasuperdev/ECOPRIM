import apiClient from '../../api/client'

export async function fetchEnseignantsPage(page = 1, q = '') {
  const { data } = await apiClient.get('/enseignants', { params: { page, q: q || undefined } })
  return data
}

export async function fetchEnseignant(id) {
  const { data } = await apiClient.get(`/enseignants/${id}`)
  return data
}

export async function createEnseignant(payload) {
  const { data } = await apiClient.post('/enseignants', payload)
  return data
}

export async function updateEnseignant(id, payload) {
  const { data } = await apiClient.put(`/enseignants/${id}`, payload)
  return data
}
