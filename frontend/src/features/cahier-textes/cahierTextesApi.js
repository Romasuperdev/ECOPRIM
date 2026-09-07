import apiClient from '../../api/client'

export async function fetchClasses() {
  const { data } = await apiClient.get('/classes', { params: { per_page: 200 } })
  return data?.data ?? data ?? []
}

export async function fetchReferentielsCahier(classe) {
  const { data } = await apiClient.get('/cahier-textes/referentiels', { params: { classe } })
  return data
}

export async function fetchCahiers(classe) {
  const { data } = await apiClient.get('/cahier-textes', { params: { classe } })
  return data
}

export async function creerCahier(payload) {
  const { data } = await apiClient.post('/cahier-textes', payload)
  return data
}

export async function modifierCahier(id, payload) {
  const { data } = await apiClient.put(`/cahier-textes/${id}`, payload)
  return data
}

export async function supprimerCahier(id) {
  await apiClient.delete(`/cahier-textes/${id}`)
}

export async function enregistrerLigneCahier(id, payload) {
  const { data } = await apiClient.put(`/cahier-textes/${id}/lignes`, payload)
  return data
}

export async function supprimerLigneCahier(id, matiere) {
  await apiClient.delete(`/cahier-textes/${id}/lignes/${encodeURIComponent(matiere)}`)
}
