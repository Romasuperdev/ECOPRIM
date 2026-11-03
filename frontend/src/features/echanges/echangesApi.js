import apiClient from '../../api/client'

/**
 * Les classeurs sont servis par l'API authentifiée : on passe par apiClient (cookie de
 * session + XSRF) plutôt que par un lien direct, qui perdrait les cookies — même raison
 * que pour les PDF (voir impressionApi).
 */
async function telecharger(url, params) {
  const reponse = await apiClient.get(url, { params, responseType: 'blob' })

  const blob = new Blob([reponse.data], {
    type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
  })
  const lien = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = lien
  a.download = nomPropose(reponse.headers['content-disposition']) ?? 'nexora.xlsx'
  a.click()
  window.setTimeout(() => URL.revokeObjectURL(lien), 60000)
}

/**
 * Le nom que le serveur a donné au fichier. Sans lui, tous les exports arriveraient sous
 * le même nom dans le dossier Téléchargements, et on ne saurait plus lequel est lequel.
 */
function nomPropose(disposition) {
  if (!disposition) return null
  const encode = /filename\*=UTF-8''([^;]+)/i.exec(disposition)
  if (encode) return decodeURIComponent(encode[1])
  const simple = /filename="?([^";]+)"?/i.exec(disposition)
  return simple ? simple[1] : null
}

// `annee` : l'année scolaire choisie sur l'écran. Vide, le serveur prend celle de l'en-tête.
const avecAnnee = (params, annee) => (annee ? { ...(params ?? {}), annee } : params)

export const fetchCatalogueEchanges = (annee) =>
  apiClient.get('echanges/catalogue', { params: avecAnnee(undefined, annee) }).then((r) => r.data)

export const exporterJeux = (jeux, annee) =>
  telecharger('echanges/export', avecAnnee(jeux?.length ? { jeux: jeux.join(',') } : undefined, annee))

export const telechargerModele = (jeu, annee) => telecharger('echanges/modele', avecAnnee({ jeux: jeu }, annee))

/** Premier temps : le rapport. Rien n'est écrit en base. */
export const analyserImport = (jeu, fichier, options = {}, annee = '') => {
  const charge = new FormData()
  charge.append('fichier', fichier)
  if (options.bareme) charge.append('bareme', options.bareme)
  // « Tout importer » est le mode par défaut côté serveur : on l'envoie quand même,
  // explicitement, pour que la case décochée (mode strict) soit toujours entendue.
  charge.append('tout_importer', options.toutImporter === false ? '0' : '1')
  // L'année visée est retenue avec le rapport : l'application l'écrira dans CETTE année.
  if (annee) charge.append('annee', annee)

  return apiClient
    .post(`echanges/import/${jeu}/analyse`, charge)
    .then((r) => r.data)
}

/** Second temps : on applique le rapport qui vient d'être lu. */
export const appliquerImport = (jeton) =>
  apiClient.post('echanges/import', { jeton }).then((r) => r.data)
