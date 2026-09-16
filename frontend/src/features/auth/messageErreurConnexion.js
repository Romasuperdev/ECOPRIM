import { API_ROOT_URL } from '../../api/client'

/**
 * Dire ce qui s'est réellement passé.
 *
 * « Une erreur est survenue » couvrait tout : serveur éteint, jeton CSRF périmé, compte
 * sans accès… L'utilisateur ressaisissait son mot de passe indéfiniment alors que le
 * problème était ailleurs. Chaque cas a maintenant sa phrase, et surtout son remède.
 */
export function messageErreurConnexion(error) {
  const statut = error?.response?.status

  // Aucune réponse du tout : le serveur n'est pas joignable (arrêté, mauvais port, ou
  // origine refusée par CORS). C'est le cas le plus fréquent et le seul que l'utilisateur
  // ne peut pas deviner.
  if (!error?.response) {
    return `Le serveur ne répond pas (${API_ROOT_URL}). Vérifiez qu'il est démarré, puis réessayez.`
  }

  if (statut === 401 || statut === 422) return 'Identifiant ou mot de passe incorrect.'
  if (statut === 419) return 'Votre session a expiré. Réessayez : la page vient de la renouveler.'
  if (statut === 429) return 'Trop de tentatives. Patientez une minute avant de réessayer.'
  if (statut === 403) return "Ce compte n'est pas autorisé à se connecter ici."

  const duServeur = error.response?.data?.message
  return statut >= 500 && duServeur
    ? `Erreur du serveur : ${duServeur}`
    : 'Une erreur est survenue. Veuillez réessayer.'
}
