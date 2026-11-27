import apiClient from '../api/client'
import { API_BASE_URL } from './constants'

/*
 * Application installable (PWA) : service worker, invitation à installer, notifications push.
 *
 * Android (Chrome) propose lui-même l'installation : on capte l'événement pour l'offrir au
 * bon moment. Sur iPhone, rien d'automatique : il faut « Partager → Sur l'écran d'accueil »,
 * et les notifications push n'y existent QUE pour une application ainsi installée (iOS 16.4
 * et plus). D'où la bannière d'explication propre à l'iPhone.
 *
 * Tout ceci exige HTTPS (ou localhost) : en http://10.8.0.5 le navigateur refuse le service
 * worker, et l'application reste une simple page web.
 */

// Dossier des fichiers construits (« /spa/ », « /ecole-primaire/ ») et racine des PAGES,
// celle du routeur. Elles diffèrent en mode « une seule adresse » : fichiers sous /spa/,
// pages à la racine. Le service worker doit couvrir les pages : il est servi à leur racine.
const BASE = import.meta.env.BASE_URL || '/'
const RACINE = `${(import.meta.env.VITE_BASENAME || '').replace(/\/$/, '')}/`

export function estIos() {
  const ua = navigator.userAgent || ''
  return /iphone|ipad|ipod/i.test(ua) || (/Macintosh/.test(ua) && navigator.maxTouchPoints > 1)
}

export function estInstallee() {
  return window.matchMedia?.('(display-mode: standalone)').matches || window.navigator.standalone === true
}

export function enregistrerServiceWorker() {
  if (!import.meta.env.PROD || !('serviceWorker' in navigator) || !window.isSecureContext) return

  const api = new URL(API_BASE_URL, window.location.origin).href.replace(/\/$/, '')
  window.addEventListener('load', () => {
    navigator.serviceWorker
      .register(`${RACINE}sw.js?api=${encodeURIComponent(api)}&fichiers=${encodeURIComponent(BASE)}`, { scope: RACINE })
      .catch(() => { /* sans service worker, l'application marche comme un site ordinaire */ })
  })
}

// --- Invitation à installer (Android, Chrome, Edge) ---------------------------

let invitation = null
const abonnes = new Set()

if (typeof window !== 'undefined') {
  window.addEventListener('beforeinstallprompt', (evenement) => {
    evenement.preventDefault() // on l'offre nous-mêmes, dans la bannière
    invitation = evenement
    abonnes.forEach((f) => f(true))
  })
  window.addEventListener('appinstalled', () => {
    invitation = null
    abonnes.forEach((f) => f(false))
  })
}

export function installationProposable() {
  return invitation !== null
}

export function surInstallationProposable(rappel) {
  abonnes.add(rappel)
  return () => abonnes.delete(rappel)
}

export async function proposerInstallation() {
  if (!invitation) return false
  invitation.prompt()
  const { outcome } = await invitation.userChoice
  invitation = null
  abonnes.forEach((f) => f(false))
  return outcome === 'accepted'
}

// --- Notifications push ----------------------------------------------------------

/**
 * 'indisponible' (navigateur ou http), 'installer' (iPhone : installer d'abord),
 * 'refuse' (bloquées dans les réglages), 'actif', 'inactif'.
 */
export async function etatPush() {
  const supporte = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window
  if (!window.isSecureContext || !import.meta.env.PROD) return 'indisponible'
  if (!supporte) return estIos() && !estInstallee() ? 'installer' : 'indisponible'
  if (Notification.permission === 'denied') return 'refuse'

  const enregistrement = await navigator.serviceWorker.getRegistration(RACINE)
  const abonnement = await enregistrement?.pushManager.getSubscription()
  return abonnement ? 'actif' : 'inactif'
}

function versOctets(base64url) {
  const remplissage = '='.repeat((4 - (base64url.length % 4)) % 4)
  const brut = atob((base64url + remplissage).replace(/-/g, '+').replace(/_/g, '/'))
  return Uint8Array.from(brut, (c) => c.charCodeAt(0))
}

/** À appeler sur un geste de l'utilisateur (clic) : c'est la seule façon d'obtenir la permission. */
export async function activerPush() {
  const permission = await Notification.requestPermission()
  if (permission !== 'granted') return permission === 'denied' ? 'refuse' : 'inactif'

  const { data } = await apiClient.get('/mon-espace/push/cle')
  if (!data.disponible) throw new Error('Les notifications ne sont pas encore prêtes sur le serveur.')

  const enregistrement = await navigator.serviceWorker.ready
  const abonnement = (await enregistrement.pushManager.getSubscription())
    ?? (await enregistrement.pushManager.subscribe({
      userVisibleOnly: true,
      applicationServerKey: versOctets(data.cle_publique),
    }))

  await apiClient.post('/mon-espace/push/abonnement', abonnement.toJSON())
  return 'actif'
}

export async function desactiverPush() {
  const enregistrement = await navigator.serviceWorker.getRegistration(RACINE)
  const abonnement = await enregistrement?.pushManager.getSubscription()
  if (abonnement) {
    await apiClient.delete('/mon-espace/push/abonnement', { data: { endpoint: abonnement.endpoint } }).catch(() => {})
    await abonnement.unsubscribe()
  }
  return 'inactif'
}

/** Pastille sur l'icône de l'application (Android, iPhone installé). */
export function majPastilleApplication(nombre) {
  if (!('setAppBadge' in navigator)) return
  const action = nombre > 0 ? navigator.setAppBadge(nombre) : navigator.clearAppBadge()
  action?.catch?.(() => {})
}
