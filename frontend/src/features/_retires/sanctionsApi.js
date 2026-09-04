import apiClient from '../../api/client'

export async function fetchSanctions(page = 1) {
  const { data } = await apiClient.get('/sanctions', { params: { page } })
  return data
}

export async function fetchSanction(id) {
  const { data } = await apiClient.get(`/sanctions/${id}`)
  return data
}

export async function createSanction(payload) {
  const { data } = await apiClient.post('/sanctions', payload)
  return data
}

export async function updateSanction(id, payload) {
  const { data } = await apiClient.put(`/sanctions/${id}`, payload)
  return data
}

export async function deleteSanction(id) {
  await apiClient.delete(`/sanctions/${id}`)
}
