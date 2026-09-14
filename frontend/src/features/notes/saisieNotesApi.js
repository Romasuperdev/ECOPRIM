import apiClient from '../../api/client'

/**
 * Saisie des notes — T_NOTEENTETE + T_NOTEDETAILS d'ECONOMAT.
 *
 * On travaille par FEUILLE : une classe, une matière, une session, un type d'évaluation.
 * Le serveur renvoie tous les élèves de la classe, notés ou non, et reçoit l'ensemble en
 * retour. Une note par appel réseau n'aurait aucun sens pour trente élèves.
 */
export async function fetchStructureNotes() {
  const { data } = await apiClient.get('/notes/structure')
  return data
}

export async function fetchFeuille(criteres) {
  const { data } = await apiClient.get('/notes/feuille', { params: criteres })
  return data
}

export async function enregistrerFeuille({ criteres, notes }) {
  const { data } = await apiClient.post('/notes/feuille', { ...criteres, notes })
  return data
}
