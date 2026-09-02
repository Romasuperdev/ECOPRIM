import apiClient from '../../api/client'

export async function envoyer(payload) {
  const { data } = await apiClient.post('/communication/envoyer', payload)
  return data
}

export async function fetchHistorique(q = '') {
  const { data } = await apiClient.get('/communication/historique', { params: { q: q || undefined } })
  return data.data
}
