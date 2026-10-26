/**
 * Message lisible d'une erreur d'API : le premier message de validation (422), sinon le
 * message du serveur, sinon `defaut`. Une absence de réponse (serveur injoignable) et une
 * session expirée (401) sont dites en clair plutôt qu'en anglais technique.
 */
export function messageErreurApi(e, defaut = 'L’opération n’a pas pu aboutir.') {
  if (!e?.response) return 'Le serveur ne répond pas. Vérifiez la connexion et réessayez.'
  if (e.response.status === 401) return 'Votre session a expiré : reconnectez-vous.'
  const erreurs = e.response.data?.errors
  if (erreurs) return Object.values(erreurs).flat()[0] ?? defaut
  return e.response.data?.message || defaut
}
