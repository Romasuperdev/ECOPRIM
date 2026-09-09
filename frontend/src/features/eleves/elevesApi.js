import apiClient from '../../api/client'

export async function fetchEleves(page = 1) {
  const { data } = await apiClient.get('/eleves', { params: { page } })
  return data
}

export async function fetchEleve(id) {
  const { data } = await apiClient.get(`/eleves/${id}`)
  return data
}

export async function createEleve(payload) {
  const { data } = await apiClient.post('/eleves', payload)
  return data
}

export async function updateEleve(id, payload) {
  const { data } = await apiClient.put(`/eleves/${id}`, payload)
  return data
}

export async function deleteEleve(id) {
  await apiClient.delete(`/eleves/${id}`)
}

export async function fetchBulletinDonnees(id) {
  const { data } = await apiClient.get(`/eleves/${id}/bulletin/donnees`)
  return data
}

// Même patron que features/impressions/impressionApi.js : le PDF est servi par l'API
// authentifiée, un window.open direct perdrait le cookie de session.
export async function imprimerBulletin(id, matricule) {
  const { data } = await apiClient.get(`/eleves/${id}/bulletin`, { responseType: 'blob' })
  const blob = new Blob([data], { type: 'application/pdf' })
  const lien = URL.createObjectURL(blob)
  const onglet = window.open(lien, '_blank')
  if (!onglet) {
    const a = document.createElement('a')
    a.href = lien
    a.download = `bulletin-${matricule || id}.pdf`
    a.click()
  }
  window.setTimeout(() => URL.revokeObjectURL(lien), 60000)
}
