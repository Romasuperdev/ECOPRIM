import apiClient from '../../api/client'

/**
 * Annuaire des parents / tuteurs, dérivé des fiches élèves (ECONOMAT.T_ETUDIANT).
 * Lecture seule : les coordonnées se corrigent dans Inscriptions, là où elles vivent.
 */
export async function fetchParents(filtres = {}) {
  const { data } = await apiClient.get('/parents', {
    params: {
      q: filtres.q || undefined,
      classe: filtres.classe || undefined,
      lien: filtres.lien || undefined,
    },
  })
  return data
}
