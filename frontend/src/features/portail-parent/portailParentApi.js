import apiClient from '../../api/client'

const BASE = '/mon-espace/parent'

// Le matricule entre dans le chemin : encodé, il ne casse pas l'adresse s'il porte une
// espace ou une barre.
const m = (matricule) => encodeURIComponent(matricule)

export async function fetchMesEnfants() {
  const { data } = await apiClient.get(`${BASE}/enfants`)
  return data
}

export async function fetchEnfant(matricule) {
  const { data } = await apiClient.get(`${BASE}/enfants/${m(matricule)}`)
  return data
}

export async function fetchAbsencesEnfant(matricule) {
  const { data } = await apiClient.get(`${BASE}/enfants/${m(matricule)}/absences`, { params: { per_page: 200 } })
  return data?.data ?? data ?? []
}

export async function fetchCahierTextesEnfant(matricule) {
  const { data } = await apiClient.get(`${BASE}/enfants/${m(matricule)}/cahier-textes`)
  return data
}

export async function fetchMoyennesEnfant(matricule) {
  const { data } = await apiClient.get(`${BASE}/enfants/${m(matricule)}/moyennes`)
  return data
}

// Même patron que features/impressions/impressionApi.js : le PDF est servi par l'API
// authentifiée, un window.open direct perdrait le cookie de session.
// Un refus arrive lui aussi en Blob (responseType) : on en extrait le message du serveur
// pour le montrer au parent, au lieu d'un clic qui ne fait rien.
async function telechargerPdf(url, nomFichier) {
  let data
  try {
    ({ data } = await apiClient.get(url, { responseType: 'blob' }))
  } catch (e) {
    let message = null
    try {
      message = JSON.parse(await e?.response?.data?.text?.())?.message ?? null
    } catch {
      // Corps illisible : message générique ci-dessous.
    }
    throw new Error(message || 'Document indisponible pour le moment.', { cause: e })
  }
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
  telechargerPdf(`${BASE}/enfants/${m(matricule)}/bulletin`, `bulletin-${matricule}.pdf`)

export const telechargerCertificatScolarite = (matricule) =>
  telechargerPdf(`${BASE}/enfants/${m(matricule)}/certificat-scolarite`, `certificat-scolarite-${matricule}.pdf`)

export const telechargerAttestationFrequentation = (matricule) =>
  telechargerPdf(`${BASE}/enfants/${m(matricule)}/attestation-frequentation`, `attestation-frequentation-${matricule}.pdf`)

export async function fetchEmploiReferentiels() {
  const { data } = await apiClient.get(`${BASE}/emplois-du-temps/referentiels`)
  return data
}

export async function fetchEmploiDuTempsEnfant(matricule) {
  const { data } = await apiClient.get(`${BASE}/enfants/${m(matricule)}/emploi-du-temps`)
  return data
}

export async function fetchDevoirsEnfant(matricule) {
  const { data } = await apiClient.get(`${BASE}/enfants/${m(matricule)}/devoirs`)
  return data
}

export async function fetchEvaluationsPlanifieesEnfant(matricule) {
  const { data } = await apiClient.get(`${BASE}/enfants/${m(matricule)}/evaluations-planifiees`)
  return data
}

export async function fetchEvenementsEnfant(matricule) {
  const { data } = await apiClient.get(`${BASE}/enfants/${m(matricule)}/evenements`)
  return data
}
