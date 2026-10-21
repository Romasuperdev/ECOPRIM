/**
 * Dire ce qui s'est réellement passé.
 *
 * « Une erreur est survenue » couvrait tout : serveur éteint, jeton CSRF périmé, compte
 * sans accès… L'utilisateur ressaisissait son mot de passe indéfiniment alors que le
 * problème était ailleurs. Chaque cas a maintenant sa phrase, et surtout son remède.
 */
export function messageErreurConnexion(error) {
  const statut = error?.response?.status

  // Le serveur n'est pas joignable : arrêté, mauvais port, ou origine refusée par CORS.
  // C'est le cas le plus fréquent et le seul que l'utilisateur ne peut pas deviner.
  //
  // Deux formes selon le chemin emprunté. En appel direct, il n'y a AUCUNE réponse. Mais
  // le front passe désormais par le proxy de Vite, et celui-ci répond à sa place : 502
  // Bad Gateway, en texte brut, quand Laravel est éteint. Sans ce second cas, le message
  // retombait sur « Une erreur est survenue » — et l'on cherchait du côté du mot de passe
  // un serveur qui n'était simplement pas lancé.
  const duServeur = error.response?.data?.message

  if (!error?.response || [502, 503, 504].includes(statut) || (statut === 500 && !duServeur)) {
    return 'Le serveur ne répond pas. Vérifiez qu’il est démarré '
      + '(php artisan serve --port=8001), puis réessayez.'
  }

  if (statut === 401 || statut === 422) return 'Identifiant ou mot de passe incorrect.'
  if (statut === 419) return 'Votre session a expiré. Réessayez : la page vient de la renouveler.'
  if (statut === 429) return 'Trop de tentatives. Patientez une minute avant de réessayer.'
  if (statut === 403) return "Ce compte n'est pas autorisé à se connecter ici."

  // La requête a bien atteint un serveur web, mais PAS l'application : c'est Apache
  // lui-même qui répond. Sans mod_rewrite ni « AllowOverride All », /api/v1/... ne
  // correspond à aucun fichier et n'est jamais remis à Laravel.
  //
  // Le piège est que la PAGE s'affiche quand même — elle, Apache sait la servir — d'où
  // l'impression que tout est en ligne sauf la connexion, et l'on cherche du côté du mot
  // de passe. C'est la panne numéro un d'une première mise en ligne.
  if (statut === 404 || statut === 405) {
    return "L’API est introuvable à cette adresse : le serveur web ne transmet pas « /api » "
      + 'à l’application. Vérifiez « AllowOverride All » dans le VirtualHost, le module '
      + 'mod_rewrite d’Apache, et la présence du fichier public/.htaccess.'
  }

  if (statut >= 500 && duServeur) {
    return `Erreur du serveur : ${duServeur}`
  }

  // Dernier recours : on donne au moins le code. Un message sans code n'apprend rien et
  // oblige à rouvrir les outils du navigateur pour savoir de quoi l'on parle.
  return `Une erreur est survenue${statut ? ` (code ${statut})` : ''}. Veuillez réessayer.`
}
