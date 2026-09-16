import apiClient from '../../api/client'

// La LISTE des élèves est servie par la page Inscriptions, qui lit la même table
// avec en plus recherche, filtres et édition : `fetchEleves` n'a plus d'appelant.
// Retirés aussi : createEleve / updateEleve / deleteEleve, qui visaient des routes
// POST, PUT et DELETE /eleves inexistantes côté serveur — et ECONOMAT n'autorise
// de toute façon pas la suppression d'un élève.

export async function fetchEleve(id) {
  const { data } = await apiClient.get(`/eleves/${id}`)
  return data
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
