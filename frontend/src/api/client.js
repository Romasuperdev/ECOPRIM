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

export default apiClient
