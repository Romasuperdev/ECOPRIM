import apiClient from '../../api/client'

const BASE = '/mon-espace/enseignant'

export async function fetchMesClasses() {
  const { data } = await apiClient.get(`${BASE}/classes`)
  return data
}

export async function fetchMesEleves(classe) {
  const { data } = await apiClient.get(`${BASE}/classes/${classe}/eleves`, { params: { per_page: 200 } })
  return data?.data ?? data ?? []
}

export async function fetchCahierReferentiels(classe) {
  const { data } = await apiClient.get(`${BASE}/cahier-textes/referentiels`, { params: { classe } })
  return data
}

export async function fetchCahiers(classe) {
  const { data } = await apiClient.get(`${BASE}/cahier-textes`, { params: { classe } })
  return data
}

export async function creerCahier(payload) {
  const { data } = await apiClient.post(`${BASE}/cahier-textes`, payload)
  return data
}

export async function modifierCahier(id, payload) {
  const { data } = await apiClient.put(`${BASE}/cahier-textes/${id}`, payload)
  return data
}

export async function supprimerCahier(id) {
  await apiClient.delete(`${BASE}/cahier-textes/${id}`)
}

export async function enregistrerLigneCahier(id, payload) {
  const { data } = await apiClient.put(`${BASE}/cahier-textes/${id}/lignes`, payload)
  return data
}

export async function supprimerLigneCahier(id, matiere) {
  await apiClient.delete(`${BASE}/cahier-textes/${id}/lignes/${encodeURIComponent(matiere)}`)
}

export async function fetchMonEmploi(classe) {
  const { data } = await apiClient.get(`${BASE}/emplois-du-temps`, { params: { classe } })
  return data
}

export async function fetchMonEmploiReferentiels() {
  const { data } = await apiClient.get(`${BASE}/emplois-du-temps/referentiels`)
  return data
}

export async function fetchMesAbsences(classeCode) {
  const { data } = await apiClient.get(`${BASE}/absences`, { params: { classe_code: classeCode, per_page: 200 } })
  return data?.data ?? data ?? []
}

export async function creerAbsence(payload) {
  const { data } = await apiClient.post(`${BASE}/absences`, payload)
  return data
}

export async function modifierAbsence(id, payload) {
  const { data } = await apiClient.put(`${BASE}/absences/${id}`, payload)
  return data
}

export async function supprimerAbsence(id) {
  await apiClient.delete(`${BASE}/absences/${id}`)
}
