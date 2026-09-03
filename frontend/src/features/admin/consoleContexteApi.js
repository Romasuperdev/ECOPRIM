import apiClient from '../../api/client'

/**
 * Contexte de la console : quelle société on administre, et lesquelles on a le droit
 * d'administrer. Renvoie aussi les deux indicateurs d'administrateur, pour n'afficher
 * la console générale qu'à ceux qui y ont accès.
 */
export async function fetchConsoleContexte() {
  const { data } = await apiClient.get('console/contexte')
  return data
}

/** Bascule la console sur une société ; un code vide ramène le Super Admin à la vue générale. */
export async function definirSocieteConsole(societeCode) {
  const { data } = await apiClient.post('console/societe', { societe_code: societeCode ?? '' })
  return data
}
