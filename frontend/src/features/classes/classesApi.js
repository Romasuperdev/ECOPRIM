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

export async function archiverClasse(id) {
  const { data } = await apiClient.post(`/classes/${id}/archiver`)
  return data
}

export async function fetchIntervenants(classeId) {
  const { data } = await apiClient.get(`/classes/${classeId}/intervenants`)
  return data
}

export async function addIntervenant(classeId, payload) {
  const { data } = await apiClient.post(`/classes/${classeId}/intervenants`, payload)
  return data
}

export async function removeIntervenant(classeId, intervenantId) {
  await apiClient.delete(`/classes/${classeId}/intervenants/${intervenantId}`)
}
