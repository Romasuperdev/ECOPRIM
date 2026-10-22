import apiClient from '../../api/client'

/**
 * Aide (documentation interne).
 *
 * Lecture : tout compte connecté, ne voit que l'actif. Gestion : Super Admin, voit tout
 * (actif ou non) — c'est de là qu'on réactive un article retiré de la lecture.
 */
export const fetchAide = () => apiClient.get('aide').then((r) => r.data)

export const rechercherAide = (q) =>
  apiClient.get('aide/recherche', { params: { q } }).then((r) => r.data)

export const fetchArticleAide = (id) => apiClient.get(`aide/articles/${id}`).then((r) => r.data)

// --- Gestion (console générale) ---

export const fetchAideGestion = () => apiClient.get('console/aide').then((r) => r.data)

export const enregistrerCategorieAide = ({ id, ...v }) =>
  (id ? apiClient.put(`console/aide/categories/${id}`, v) : apiClient.post('console/aide/categories', v))
    .then((r) => r.data)

export const supprimerCategorieAide = (id) => apiClient.delete(`console/aide/categories/${id}`)

export const enregistrerArticleAide = ({ id, ...v }) =>
  (id ? apiClient.put(`console/aide/articles/${id}`, v) : apiClient.post('console/aide/articles', v))
    .then((r) => r.data)

export const supprimerArticleAide = (id) => apiClient.delete(`console/aide/articles/${id}`)
