import apiClient from '../../api/client'

export async function fetchInscriptions(params = {}) {
  const { data } = await apiClient.get('/inscriptions', { params })
  return data
}

export async function createInscription(payload) {
  const { data } = await apiClient.post('/inscriptions', payload)
  return data
}

export async function deleteInscription(id) {
  await apiClient.delete(`/inscriptions/${id}`)
}
