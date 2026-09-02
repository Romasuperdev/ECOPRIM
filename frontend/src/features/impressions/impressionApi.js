import apiClient from '../../api/client'

// Les PDF sont servis par l'API authentifiée : on passe par apiClient (cookie de
// session + XSRF) plutôt que par un window.open direct, qui perdrait les cookies.
async function ouvrirPdf(url, params) {
  const { data } = await apiClient.get(url, { params, responseType: 'blob' })
  const blob = new Blob([data], { type: 'application/pdf' })
  const lien = URL.createObjectURL(blob)
  const onglet = window.open(lien, '_blank')
  // Certains navigateurs bloquent l'ouverture : on retombe sur un téléchargement.
  if (!onglet) {
    const a = document.createElement('a')
    a.href = lien
    a.download = 'nexora.pdf'
    a.click()
  }
  // On laisse le temps au visualiseur de charger avant de libérer l'URL.
  window.setTimeout(() => URL.revokeObjectURL(lien), 60000)
}

export const imprimerFicheEleve = (code) => ouvrirPdf(`impressions/eleves/${code}`)

export const imprimerFicheEnseignant = (code) => ouvrirPdf(`impressions/enseignants/${code}`)

export const imprimerEmploiDuTemps = (params) => ouvrirPdf('impressions/emploi-du-temps', params)

export const imprimerListeClasse = (params) => ouvrirPdf('impressions/liste-classe', params)
