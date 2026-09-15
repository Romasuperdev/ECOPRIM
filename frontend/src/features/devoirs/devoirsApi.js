import apiClient from '../../api/client'

export async function fetchReferentielsDevoir() {
  const { data } = await apiClient.get('/devoirs/referentiels')
  return data
}

export async function fetchDevoirs(classe) {
  const { data } = await apiClient.get('/devoirs', { params: classe ? { classe } : {} })
  return data
}

export async function creerDevoir(payload) {
  const { data } = await apiClient.post('/devoirs', payload)
  return data
}

export async function modifierDevoir(id, payload) {
  const { data } = await apiClient.put(`/devoirs/${id}`, payload)
  return data
}

export async function supprimerDevoir(id) {
  await apiClient.delete(`/devoirs/${id}`)
}
