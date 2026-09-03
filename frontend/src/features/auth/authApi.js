import axios from 'axios'
import apiClient, { API_ROOT_URL } from '../../api/client'

// Sanctum SPA : un cookie CSRF doit être posé avant tout appel POST authentifié par session
export async function ensureCsrfCookie() {
  await axios.get(`${API_ROOT_URL}/sanctum/csrf-cookie`, { withCredentials: true })
}

export async function login({ email, password }) {
  await ensureCsrfCookie()
  const { data } = await apiClient.post('/login', { email, password })
  return data
}

/**
 * Société et établissement rattachés à un identifiant, affichés sur la page de connexion
 * avant la saisie du mot de passe. Endpoint public et limité en débit : il ne renvoie que
 * des libellés, et { null, null } pour un identifiant inconnu, désactivé ou non rattaché.
 */
export async function fetchRattachementDuCompte(identifiant) {
  const { data } = await apiClient.post('/etablissement-du-compte', { identifiant })
  return { etablissement: data.etablissement ?? null, societe: data.societe ?? null }
}

export async function logout() {
  await apiClient.post('/logout')
}

export async function fetchMe() {
  const { data } = await apiClient.get('/me')
  return data
}
