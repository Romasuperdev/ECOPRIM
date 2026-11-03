import axios from 'axios'
import { API_BASE_URL } from '../lib/constants'

// Client Axios configuré pour l'API Laravel (Sanctum, auth SPA par cookie de session)
const apiClient = axios.create({
  baseURL: API_BASE_URL,
  withCredentials: true, // envoie/reçoit les cookies de session cross-origin
  withXSRFToken: true, // nécessaire pour que le header X-XSRF-TOKEN parte en cross-origin
  headers: {
    Accept: 'application/json',
  },
})

// Racine du backend (sans /api/v1) — utilisée pour /sanctum/csrf-cookie
export const API_ROOT_URL = API_BASE_URL.replace(/\/api\/v\d+\/?$/, '')

/** Pose (ou repose) le cookie CSRF de Sanctum. */
export async function demanderCookieCsrf() {
  await axios.get(`${API_ROOT_URL}/sanctum/csrf-cookie`, { withCredentials: true })
}

/**
 * Jeton CSRF périmé : on le renouvelle et on rejoue l'appel, une seule fois.
 *
 * Laravel répond 419 quand le cookie XSRF-TOKEN a expiré ou n'a jamais été posé — ce qui
 * arrive dès qu'un onglet reste ouvert un moment, ou après un redémarrage du serveur. Sans
 * ce rattrapage, l'utilisateur voit une erreur générique sur une connexion parfaitement
 * valide, et n'a d'autre remède que de vider ses cookies. Un seul essai : si le second 419
 * arrive, c'est autre chose, et il faut que l'erreur remonte.
 */
/**
 * Dernier rempart : aucun texte technique (SQL, trace, chemin du serveur) ne doit atteindre
 * un écran. Le serveur filtre déjà (ErreursApi.php), mais un serveur pas encore mis à jour,
 * ou laissé en APP_DEBUG=true, renverrait encore « SQLSTATE[42S02]… ». On remplace alors le
 * message par une phrase lisible ; l'original reste dans `detail`, visible en développement
 * seulement.
 */
const MOTIFS_TECHNIQUES = /SQLSTATE|ODBC|SQL Server|Connection:|select \[|Stack trace|\.php|Exception/i

export function nettoyerErreurServeur(erreur) {
  const reponse = erreur?.response
  if (!reponse || reponse.status < 500 || !reponse.data || typeof reponse.data !== 'object') return erreur

  const message = reponse.data.message
  const technique = !message || MOTIFS_TECHNIQUES.test(message) || reponse.data.exception || reponse.data.trace
  if (!technique) return erreur

  reponse.data = {
    message:
      reponse.status === 503
        ? 'Le service est momentanément indisponible. Réessayez dans un instant ; si cela persiste, prévenez votre administrateur.'
        : 'Une erreur technique empêche d’afficher ces données. Réessayez, et prévenez votre administrateur si cela persiste.'
          + (reponse.data.reference ? ` (référence ${reponse.data.reference})` : ''),
    code: reponse.data.code ?? 'ERREUR_SERVEUR',
    reference: reponse.data.reference,
    detail: reponse.data.detail ?? message,
  }
  return erreur
}

apiClient.interceptors.response.use(
  (reponse) => reponse,
  async (erreur) => {
    nettoyerErreurServeur(erreur)
    const requete = erreur.config
    if (erreur.response?.status !== 419 || !requete || requete._csrfRejoue) {
      return Promise.reject(erreur)
    }

    requete._csrfRejoue = true
    try {
      await demanderCookieCsrf()
    } catch {
      return Promise.reject(erreur) // Serveur injoignable : on remonte l'erreur d'origine.
    }

    return apiClient.request(requete)
  },
)

export default apiClient
