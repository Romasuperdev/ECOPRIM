/*
 * Service worker de NEXORA — ce qui fait de l'application web une application installable.
 *
 *  - Hors connexion : l'interface s'ouvre quand même (dernière version gardée en cache).
 *    Les données, elles, viennent toujours de l'API : rien n'est servi périmé.
 *  - Notifications push : le serveur envoie un « réveil » sans contenu (voir
 *    backend/app/Services/Notifications/WebPush.php). Réveillé, ce script demande à l'API
 *    la dernière notification non lue, avec la session de l'utilisateur, et l'affiche.
 *
 * L'adresse de l'API est passée à l'enregistrement (sw.js?api=…) : elle diffère entre le
 * poste de développement (/api/v1) et le serveur (/ecole-primaire/backend/api/v1).
 */
const VERSION = 'nexora-v1'
const CACHE_INTERFACE = `${VERSION}-interface`
const CACHE_FICHIERS = `${VERSION}-fichiers`

const PORTEE = self.registration.scope // racine des pages, se termine par « / »
const PARAMETRES = new URL(location.href).searchParams
const API = PARAMETRES.get('api') || new URL('api/v1', PORTEE).href
// Dossier des fichiers construits : /spa/ en mode « une seule adresse », sinon la portée.
const FICHIERS = new URL(PARAMETRES.get('fichiers') || PORTEE, location.origin).href
const ICONE = new URL('icones/icone-192.png', FICHIERS).href

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_INTERFACE)
      .then((cache) => cache.add(new Request(PORTEE, { cache: 'reload' })))
      .catch(() => { /* hors ligne à l'installation : le cache se remplira à la prochaine visite */ })
      .then(() => self.skipWaiting()),
  )
})

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((cles) => Promise.all(cles.filter((c) => !c.startsWith(VERSION)).map((c) => caches.delete(c))))
      .then(() => self.clients.claim()),
  )
})

self.addEventListener('fetch', (event) => {
  const requete = event.request
  if (requete.method !== 'GET') return

  const url = new URL(requete.url)
  if (url.origin !== location.origin) return
  // L'API et l'authentification ne passent jamais par le cache.
  if (url.href.startsWith(API) || url.pathname.includes('/api/') || url.pathname.includes('/sanctum/')) return

  // Pages : le réseau d'abord ; hors ligne, la dernière interface connue.
  if (requete.mode === 'navigate') {
    event.respondWith(
      fetch(requete)
        .then((reponse) => {
          if (reponse.ok) {
            const copie = reponse.clone()
            caches.open(CACHE_INTERFACE).then((cache) => cache.put(PORTEE, copie))
          }
          return reponse
        })
        .catch(() => caches.match(PORTEE).then((r) => r || Response.error())),
    )
    return
  }

  // Fichiers construits (noms à empreinte, donc immuables) et icônes : le cache d'abord.
  if (url.pathname.includes('/assets/') || url.pathname.includes('/icones/')) {
    event.respondWith(
      caches.match(requete).then((enCache) => enCache || fetch(requete).then((reponse) => {
        if (reponse.ok) {
          const copie = reponse.clone()
          caches.open(CACHE_FICHIERS).then((cache) => cache.put(requete, copie))
        }
        return reponse
      })),
    )
  }
})

// --- Notifications push ------------------------------------------------------

async function derniereNotification() {
  try {
    const reponse = await fetch(`${API}/mon-espace/notifications/derniere`, {
      credentials: 'include',
      headers: { Accept: 'application/json' },
    })
    if (!reponse.ok) return null
    return await reponse.json()
  } catch {
    return null
  }
}

self.addEventListener('push', (event) => {
  event.waitUntil((async () => {
    const donnees = await derniereNotification()
    const n = donnees?.notification

    if (typeof donnees?.non_lues === 'number' && self.navigator.setAppBadge) {
      self.navigator.setAppBadge(donnees.non_lues).catch(() => {})
    }

    // iPhone exige qu'un push affiche toujours quelque chose : sans détail lisible
    // (session expirée, réseau coupé), un message générique.
    await self.registration.showNotification(n?.titre ?? 'NEXORA', {
      body: n ? (n.corps ?? '') : 'Vous avez une nouvelle notification de l’école.',
      icon: ICONE,
      badge: ICONE,
      tag: n ? `nexora-${n.id}` : 'nexora',
      data: { id: n?.id ?? null, lien: n?.lien ?? '/mon-espace/notifications' },
    })
  })())
})

self.addEventListener('notificationclick', (event) => {
  event.notification.close()
  const { id, lien } = event.notification.data ?? {}
  const cible = new URL(String(lien || '/mon-espace/notifications').replace(/^\//, ''), PORTEE)
  // L'application marque la notification lue à l'ouverture (paramètre « lue »).
  if (id) cible.searchParams.set('lue', id)

  event.waitUntil((async () => {
    const fenetres = await self.clients.matchAll({ type: 'window', includeUncontrolled: true })
    const ouverte = fenetres.find((f) => f.url.startsWith(PORTEE))
    if (ouverte) {
      await ouverte.focus()
      return ouverte.navigate(cible.href)
    }
    return self.clients.openWindow(cible.href)
  })())
})
