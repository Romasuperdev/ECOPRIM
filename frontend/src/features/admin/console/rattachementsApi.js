import apiClient from '../../../api/client'

/*
 * Les deux écrans « Affectations » de la console qui rattachent un compte :
 *   — Société - Utilisateurs : compte ↔ société, au niveau de la plateforme (Super Admin) ;
 *   — Utilisateur - Établissements : compte ↔ établissement, avec un rôle.
 * Les règles vivent dans l'API ; ces fonctions ne font que l'appeler.
 */

// ── Société - Utilisateurs ───────────────────────────────────────────────────

/** Toutes les liaisons compte ↔ société (une ligne par liaison). */
export const fetchLiaisonsSocietes = () =>
  apiClient.get('plateforme/affectations').then((r) => r.data)

/** Comptes actifs et sociétés de la plateforme, pour le formulaire. */
export const fetchReferentielsSocietes = () =>
  apiClient.get('plateforme/affectations/referentiels').then((r) => r.data)

/**
 * Enregistre la liste COMPLÈTE des sociétés d'un compte : celles qui n'y figurent plus
 * sont détachées. `admins` : parmi elles, celles dont il est administrateur.
 */
export const synchroniserSocietes = ({ utilisateurId, societes, admins }) =>
  apiClient.put('plateforme/affectations/synchroniser', {
    utilisateur_id: Number(utilisateurId), societes, admins,
  }).then((r) => r.data)

/** Détache une seule société (le × de la pastille). */
export const detacherSociete = (id) => apiClient.delete(`plateforme/affectations/${id}`)

/** « Retirer tout » : détache le compte de toutes ses sociétés. */
export const detacherToutesSocietes = (utilisateurId) =>
  apiClient.delete(`plateforme/affectations/utilisateurs/${utilisateurId}`).then((r) => r.data)

// ── Utilisateur - Établissements ─────────────────────────────────────────────

/** Affectations du périmètre de l'appelant (l'API les cloisonne). */
export const fetchAffectationsEtablissement = () =>
  apiClient.get('affectations').then((r) => r.data)
