import apiClient from '../../api/client'

export const fetchGrilleCoefficients = () =>
  apiClient.get('coefficients').then((r) => r.data)

/** La grille résolue d'une classe : ce que la saisie des notes appliquera. */
export const fetchCoefficientsClasse = (classe) =>
  apiClient.get(`coefficients/classe/${encodeURIComponent(classe)}`).then((r) => r.data)

/**
 * Enregistrement en masse. Une ligne dont le coefficient est null SUPPRIME la surcharge :
 * la matière retombe sur la grille commune, au lieu de se voir poser un zéro.
 */
export const enregistrerCoefficients = (lignes, toutesAnnees = false) =>
  apiClient.put('coefficients', { lignes, toutes_annees: toutesAnnees }).then((r) => r.data)

export const supprimerCoefficient = (id) =>
  apiClient.delete(`coefficients/${id}`).then((r) => r.data)
