import apiClient from '../../api/client'

export async function fetchParentsPage(page = 1) {
  const { data } = await apiClient.get('/parents', { params: { page } })
  return data
}

export async function fetchAllParents() {
  const { data } = await apiClient.get('/parents', { params: { per_page: 200 } })
  return data.data
}

export async function fetchParent(id) {
  const { data } = await apiClient.get(`/parents/${id}`)
  return data
}

export async function createParent(payload) {
  const { data } = await apiClient.post('/parents', payload)
  return data
}

export async function updateParent(id, payload) {
  const { data } = await apiClient.put(`/parents/${id}`, payload)
  return data
}

export async function deleteParent(id) {
  await apiClient.delete(`/parents/${id}`)
}

export async function attachEleveToParent(parentId, eleveId, lienParente) {
  const { data } = await apiClient.post(`/parents/${parentId}/eleves`, {
    eleve_id: eleveId,
    lien_parente: lienParente,
  })
  return data
}

export async function detachEleveFromParent(parentId, eleveId) {
  await apiClient.delete(`/parents/${parentId}/eleves/${eleveId}`)
}
