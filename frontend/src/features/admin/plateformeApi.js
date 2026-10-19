import apiClient from '../../api/client'

/**
 * Console plateforme — LECTURE SEULE.
 *
 * Ces données vivent dans `dbmasterbacou`, partagée avec ECONOMAT et les autres
 * applications de la suite : ECOPRIM n'en est pas propriétaire. D'où l'absence de verbes
 * d'écriture ici, et rien qui y ressemble.
 */
export const fetchApplications = () =>
  apiClient.get('plateforme/applications').then((r) => r.data)

export const fetchAffectationsPlateforme = () =>
  apiClient.get('plateforme/affectations').then((r) => r.data)

export const fetchAbonnements = () =>
  apiClient.get('plateforme/abonnements').then((r) => r.data)

export const fetchParametresSysteme = () =>
  apiClient.get('plateforme/parametres').then((r) => r.data)
