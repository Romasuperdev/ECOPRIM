import apiClient from '../../api/client'

export async function fetchDashboardStats() {
  const { data } = await apiClient.get('/dashboard/stats')
  return data
}
