import apiClient from '../../api/client'

/**
 * Traçabilité (ECONOMAT.T_TRACABILITE) — lecture seule.
 *
 * La réponse porte, en plus des lignes, la correspondance colonne → rôle métier découverte
 * à l'exécution, la portée appliquée et la stratégie de cloisonnement : l'écran doit pouvoir
 * dire ce qu'il montre, et pourquoi il ne montre rien.
 */
export async function fetchTracabilite(filtres = {}) {
  const { data } = await apiClient.get('tracabilite', {
    params: {
      utilisateur: filtres.utilisateur || undefined,
      action: filtres.action || undefined,
      du: filtres.du || undefined,
      au: filtres.au || undefined,
      q: filtres.q || undefined,
      page: filtres.page || undefined,
    },
  })
  return data
}

export async function fetchTracabiliteUtilisateur(id, filtres = {}) {
  const { data } = await apiClient.get(`tracabilite/utilisateurs/${id}`, {
    params: { du: filtres.du || undefined, au: filtres.au || undefined, page: filtres.page || undefined },
  })
  return data
}
