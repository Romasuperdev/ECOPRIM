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
async function telechargerPdf(url, nomFichier) {
  const { data } = await apiClient.get(url, { responseType: 'blob' })
  const blob = new Blob([data], { type: 'application/pdf' })
  const lien = URL.createObjectURL(blob)
  const onglet = window.open(lien, '_blank')
  if (!onglet) {
    const a = document.createElement('a')
    a.href = lien
    a.download = nomFichier
    a.click()
  }
  window.setTimeout(() => URL.revokeObjectURL(lien), 60000)
}

export const telechargerBulletinEnfant = (matricule) =>
  telechargerPdf(`${BASE}/enfants/${matricule}/bulletin`, `bulletin-${matricule}.pdf`)

export const telechargerCertificatScolarite = (matricule) =>
  telechargerPdf(`${BASE}/enfants/${matricule}/certificat-scolarite`, `certificat-scolarite-${matricule}.pdf`)

export const telechargerAttestationFrequentation = (matricule) =>
  telechargerPdf(`${BASE}/enfants/${matricule}/attestation-frequentation`, `attestation-frequentation-${matricule}.pdf`)

export async function fetchEmploiReferentiels() {
  const { data } = await apiClient.get(`${BASE}/emplois-du-temps/referentiels`)
  return data
}

export async function fetchEmploiDuTempsEnfant(matricule) {
  const { data } = await apiClient.get(`${BASE}/enfants/${matricule}/emploi-du-temps`)
  return data
}

export async function fetchDevoirsEnfant(matricule) {
  const { data } = await apiClient.get(`${BASE}/enfants/${matricule}/devoirs`)
  return data
}

export async function fetchEvaluationsPlanifieesEnfant(matricule) {
  const { data } = await apiClient.get(`${BASE}/enfants/${matricule}/evaluations-planifiees`)
  return data
}

export async function fetchEvenementsEnfant(matricule) {
  const { data } = await apiClient.get(`${BASE}/enfants/${matricule}/evenements`)
  return data
}
