import apiClient from '../../api/client'

/**
 * Console plateforme.
 *
 * Ces données vivent dans `dbmasterbacou`, partagée avec ECONOMAT et les autres
 * applications de la suite : ECOPRIM n'en est pas propriétaire. Tout y est en lecture,
 * sauf le rattachement compte ↔ société, plus bas.
 */
export const fetchApplications = () =>
  apiClient.get('plateforme/applications').then((r) => r.data)

export const fetchAffectationsPlateforme = () =>
  apiClient.get('plateforme/affectations').then((r) => r.data)

export const fetchAbonnements = () =>
  apiClient.get('plateforme/abonnements').then((r) => r.data)

export const fetchParametresSysteme = () =>
  apiClient.get('plateforme/parametres').then((r) => r.data)

// Société - Utilisateurs : la seule écriture de la console sur la plateforme.
export const fetchReferentielsRattachement = () =>
  apiClient.get('plateforme/affectations/referentiels').then((r) => r.data)

export const rattacherCompte = (v) =>
  apiClient.post('plateforme/affectations', v).then((r) => r.data)

export const definirAdminSociete = (id, adminSociete) =>
  apiClient.put(`plateforme/affectations/${id}`, { admin_societe: adminSociete })

export const detacherCompte = (id) =>
  apiClient.delete(`plateforme/affectations/${id}`)
