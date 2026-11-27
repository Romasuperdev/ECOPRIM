import { useSyncExternalStore } from 'react'
import { envoyerAppel } from '../features/portail-enseignant/portailEnseignantApi'

/*
 * File d'attente des appels faits sans réseau.
 *
 * Une salle de classe sans Wi-Fi ni 4G ne doit pas empêcher l'appel : s'il ne peut pas
 * partir, il est gardé sur le téléphone (localStorage) et réexpédié dès que le réseau
 * revient — à l'événement « online », à l'ouverture de l'application, et toutes les
 * minutes. Chaque appel porte un identifiant d'envoi : le serveur ignore un doublon, si
 * bien qu'un appel renvoyé deux fois n'est enregistré qu'une fois.
 *
 * Un refus du serveur (403, 422 : classe retirée, élève parti) ne se réessaie pas : il est
 * retiré de la file et signalé, sinon il bloquerait les suivants pour toujours.
 */
const CLE = 'nexora-appels-en-attente'
const CLE_REFUS = 'nexora-appels-refuses'
const abonnes = new Set()
let instantane = lire(CLE)
let refuses = lire(CLE_REFUS)
let enCours = false

function lire(cle) {
  try {
    return JSON.parse(localStorage.getItem(cle) || '[]')
  } catch {
    return []
  }
}

function ecrire(cle, valeur) {
  try {
    localStorage.setItem(cle, JSON.stringify(valeur))
  } catch { /* stockage plein ou refusé : la file reste en mémoire pour cette session */ }
}

function publier() {
  instantane = lire(CLE)
  refuses = lire(CLE_REFUS)
  abonnes.forEach((f) => f())
}

export function identifiantEnvoi() {
  if (window.crypto?.randomUUID) return window.crypto.randomUUID()
  // crypto.randomUUID n'existe qu'en HTTPS : en http://10.8.0.5, un identifiant maison.
  return `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 12)}`
}

/** Pas de réponse du tout : réseau coupé, serveur injoignable. Un 4xx/5xx, lui, a répondu. */
export function erreurReseau(erreur) {
  return !erreur?.response
}

export function mettreEnAttente(appel) {
  // Un nouvel appel du même créneau remplace l'ancien encore en attente.
  const file = lire(CLE).filter((a) => !(a.classe === appel.classe && a.date === appel.date && a.creneau === appel.creneau))
  file.push({ ...appel, misEnAttenteLe: new Date().toISOString() })
  ecrire(CLE, file)
  publier()
}

/** Réexpédie la file, dans l'ordre. S'arrête au premier échec réseau. */
export async function viderFileAttente() {
  if (enCours) return
  enCours = true
  try {
    for (const appel of lire(CLE)) {
      try {
        await envoyerAppel(appel)
      } catch (erreur) {
        if (erreurReseau(erreur)) break
        ecrire(CLE_REFUS, [...lire(CLE_REFUS), {
          ...appel,
          motif: erreur.response?.data?.message ?? 'Refusé par le serveur.',
        }])
      }
      ecrire(CLE, lire(CLE).filter((a) => a.envoi !== appel.envoi))
      publier()
    }
  } finally {
    enCours = false
  }
}

export function oublierRefuses() {
  ecrire(CLE_REFUS, [])
  publier()
}

let demarree = false
/** À appeler une fois, dans l'espace Enseignant. */
export function demarrerFileAttente() {
  if (demarree) return
  demarree = true
  window.addEventListener('online', viderFileAttente)
  window.setInterval(() => { if (lire(CLE).length) viderFileAttente() }, 60_000)
  viderFileAttente()
}

function abonner(rappel) {
  abonnes.add(rappel)
  return () => abonnes.delete(rappel)
}

export function useFileAttenteAppels() {
  const file = useSyncExternalStore(abonner, () => instantane)
  const rejets = useSyncExternalStore(abonner, () => refuses)
  return { enAttente: file, refuses: rejets }
}

// --- Liste de classe gardée pour faire l'appel hors connexion ---------------------

export function garderFeuille(classe, feuille) {
  ecrire(`nexora-feuille-${classe}`, {
    classe_libelle: feuille.classe_libelle,
    eleves: feuille.eleves.map(({ matricule, nom, prenom, sexe }) => ({ matricule, nom, prenom, sexe })),
  })
}

export function feuilleGardee(classe) {
  try {
    return JSON.parse(localStorage.getItem(`nexora-feuille-${classe}`) || 'null')
  } catch {
    return null
  }
}
