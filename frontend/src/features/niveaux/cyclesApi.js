import apiClient from '../../api/client'

export async function fetchCycles() {
  const { data } = await apiClient.get('/cycles')
  return data
}

export async function createCycle(payload) {
  const { data } = await apiClient.post('/cycles', payload)
  return data
}

export async function updateCycle(id, payload) {
  const { data } = await apiClient.put(`/cycles/${id}`, payload)
  return data
}

export async function deleteCycle(id) {
  await apiClient.delete(`/cycles/${id}`)
}
