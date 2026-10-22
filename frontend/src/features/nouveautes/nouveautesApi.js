import apiClient from '../../api/client'

/**
 * Nouveautés (notes de version).
 *
 * Lecture : tout compte connecté — versions actives, et celles que CE compte n'a pas
 * encore marquées lues. Gestion : Super Admin, toutes les versions.
 */
export const fetchNouveautes = () => apiClient.get('nouveautes').then((r) => r.data)

export const fetchNouveautesNonLues = () => apiClient.get('nouveautes/non-lues').then((r) => r.data)

export const marquerNouveauteLue = (id) => apiClient.post(`nouveautes/${id}/lue`).then((r) => r.data)

// --- Gestion (console générale) ---

export const fetchNouveautesGestion = () => apiClient.get('console/nouveautes').then((r) => r.data)

export const enregistrerVersion = ({ id, ...v }) =>
  (id ? apiClient.put(`console/nouveautes/${id}`, v) : apiClient.post('console/nouveautes', v))
    .then((r) => r.data)

export const supprimerVersion = (id) => apiClient.delete(`console/nouveautes/${id}`)

/** Clés React Query partagées : marquer lu doit rafraîchir la cloche ET les listes. */
export const CLES_NOUVEAUTES = {
  liste: ['nouveautes'],
  nonLues: ['nouveautes', 'non-lues'],
  gestion: ['console-nouveautes'],
}
