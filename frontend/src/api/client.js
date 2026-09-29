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
apiClient.interceptors.response.use(
  (reponse) => reponse,
  async (erreur) => {
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
