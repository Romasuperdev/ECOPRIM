import apiClient from '../../api/client'

export async function fetchProgrammes(page = 1) {
  const { data } = await apiClient.get('/programmes', { params: { page } })
  return data
}

export async function fetchProgramme(id) {
  const { data } = await apiClient.get(`/programmes/${id}`)
  return data
}

export async function createProgramme(payload) {
  const { data } = await apiClient.post('/programmes', payload)
  return data
}

export async function updateProgramme(id, payload) {
  const { data } = await apiClient.put(`/programmes/${id}`, payload)
  return data
}

export async function deleteProgramme(id) {
  await apiClient.delete(`/programmes/${id}`)
}

export async function fetchProgrammesByMatiere(matiereId) {
  const { data } = await apiClient.get('/programmes', { params: { matiere_id: matiereId, per_page: 200 } })
  return data.data
}
