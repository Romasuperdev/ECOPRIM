import apiClient from '../../api/client'

export async function fetchClasses(page = 1) {
  const { data } = await apiClient.get('/classes', { params: { page } })
  return data
}

export async function fetchClasse(id) {
  const { data } = await apiClient.get(`/classes/${id}`)
  return data
}

export async function createClasse(payload) {
  const { data } = await apiClient.post('/classes', payload)
  return data
}

export async function updateClasse(id, payload) {
  const { data } = await apiClient.put(`/classes/${id}`, payload)
  return data
}

export async function deleteClasse(id) {
  await apiClient.delete(`/classes/${id}`)
}

// Les intervenants d'une classe passent désormais par la page
// « Affectation enseignant – classe » (ECONOMAT.T_CORPROFCLASSE).
