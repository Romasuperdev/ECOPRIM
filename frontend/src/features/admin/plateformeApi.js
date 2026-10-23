import apiClient from '../../api/client'

/**
 * Console plateforme.
 *
 * Ces données vivent dans `dbmasterbacou`, partagée avec ECONOMAT et les autres
 * applications de la suite : ECOPRIM n'en est pas propriétaire. Tout y est en lecture,
 * sauf ce qui est listé plus bas : rattachement compte ↔ société, applications,
 * Société - Applications.
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

// Applications de la suite (comme parcauto console/applications). Une application encore
// utilisée par une société ou un compte ne se supprime pas : l'API refuse et dit pourquoi.
const application = (code) => `plateforme/applications/${encodeURIComponent(code)}`

export const fetchApplication = (code) =>
  apiClient.get(application(code)).then((r) => r.data)

export const creerApplication = (v) =>
  apiClient.post('plateforme/applications', v).then((r) => r.data)

export const modifierApplication = (code, v) =>
  apiClient.put(application(code), v).then((r) => r.data)

export const supprimerApplication = (code) =>
  apiClient.delete(application(code))

// Société - Applications (comme parcauto affectations-app) : enregistrer = la liste
// cochée devient LA liste de la société.
const societeApplications = (code) => `plateforme/societes-applications/${encodeURIComponent(code)}`

export const fetchSocietesApplications = () =>
  apiClient.get('plateforme/societes-applications').then((r) => r.data)

export const synchroniserApplicationsSociete = (code, applications) =>
  apiClient.put(societeApplications(code), { applications }).then((r) => r.data)

export const detacherApplicationSociete = (code, codeApp) =>
  apiClient.delete(`${societeApplications(code)}/${encodeURIComponent(codeApp)}`)

export const retirerApplicationsSociete = (code) =>
  apiClient.delete(societeApplications(code)).then((r) => r.data)
