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

/**
 * Chiffres d'accueil de la console, bornés à la société courante. Remplace les appels
 * directs à /societes, /etablissements et /utilisateurs : un Admin Société n'a pas accès
 * au premier, et sa page d'accueil restait vide.
 */
export async function fetchConsoleTableauDeBord() {
  const { data } = await apiClient.get('console/tableau-de-bord')
  return data
}
