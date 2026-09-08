import apiClient from '../../api/client'

const BASE = '/mon-espace/parent'

export async function fetchMesEnfants() {
  const { data } = await apiClient.get(`${BASE}/enfants`)
  return data
}

export async function fetchEnfant(matricule) {
  const { data } = await apiClient.get(`${BASE}/enfants/${matricule}`)
  return data
}

export async function fetchAbsencesEnfant(matricule) {
  const { data } = await apiClient.get(`${BASE}/enfants/${matricule}/absences`, { params: { per_page: 200 } })
  return data?.data ?? data ?? []
}

export async function fetchCahierTextesEnfant(matricule) {
  const { data } = await apiClient.get(`${BASE}/enfants/${matricule}/cahier-textes`)
  return data
}

export async function fetchMoyennesEnfant(matricule) {
  const { data } = await apiClient.get(`${BASE}/enfants/${matricule}/moyennes`)
  return data
}

// Même patron que features/impressions/impressionApi.js : le PDF est servi par l'API
// authentifiée, un window.open direct perdrait le cookie de session.
export async function telechargerBulletinEnfant(matricule) {
  const { data } = await apiClient.get(`${BASE}/enfants/${matricule}/bulletin`, { responseType: 'blob' })
  const blob = new Blob([data], { type: 'application/pdf' })
  const lien = URL.createObjectURL(blob)
  const onglet = window.open(lien, '_blank')
  if (!onglet) {
    const a = document.createElement('a')
    a.href = lien
    a.download = `bulletin-${matricule}.pdf`
    a.click()
  }
  window.setTimeout(() => URL.revokeObjectURL(lien), 60000)
}
