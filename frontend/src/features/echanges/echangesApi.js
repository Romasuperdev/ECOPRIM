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

export const fetchCatalogueEchanges = () =>
  apiClient.get('echanges/catalogue').then((r) => r.data)

export const exporterJeux = (jeux) =>
  telecharger('echanges/export', jeux?.length ? { jeux: jeux.join(',') } : undefined)

export const telechargerModele = (jeu) => telecharger('echanges/modele', { jeux: jeu })

/** Premier temps : le rapport. Rien n'est écrit en base. */
export const analyserImport = (jeu, fichier, options = {}) => {
  const charge = new FormData()
  charge.append('fichier', fichier)
  if (options.bareme) charge.append('bareme', options.bareme)

  return apiClient
    .post(`echanges/import/${jeu}/analyse`, charge)
    .then((r) => r.data)
}

/** Second temps : on applique le rapport qui vient d'être lu. */
export const appliquerImport = (jeton) =>
  apiClient.post('echanges/import', { jeton }).then((r) => r.data)
