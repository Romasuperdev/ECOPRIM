import apiClient from '../../api/client'

// Sociétés
export async function fetchSocietes(page = 1) {
  const { data } = await apiClient.get('/societes', { params: { page } })
  return data
}

export async function fetchAllSocietes() {
  const { data } = await apiClient.get('/societes', { params: { per_page: 200 } })
  return data.data
}

export async function importerSocietes() {
  const { data } = await apiClient.post('/societes/importer')
  return data
}

export async function createSociete(payload) {
  const { data } = await apiClient.post('/societes', payload)
  return data
}

export async function updateSociete(id, payload) {
  const { data } = await apiClient.put(`/societes/${id}`, payload)
  return data
}

export async function activerSociete(id) {
  const { data } = await apiClient.post(`/societes/${id}/activer`)
  return data
}

export async function desactiverSociete(id) {
  const { data } = await apiClient.post(`/societes/${id}/desactiver`)
  return data
}

// Établissements
export async function fetchEtablissements(page = 1, filtres = {}) {
  const { data } = await apiClient.get('/etablissements', {
    params: { page, q: filtres.q || undefined, societe_code: filtres.societe_code || undefined },
  })
  return data
}

export async function fetchAllEtablissements(societeCode) {
  const { data } = await apiClient.get('/etablissements', { params: { per_page: 200, societe_code: societeCode || undefined } })
  return data.data
}

export async function fetchEtablissement(id) {
  const { data } = await apiClient.get(`/etablissements/${id}`)
  return data
}

export async function fetchEtablissementParCode(code) {
  const { data } = await apiClient.get(`/etablissements/${code}`)
  return data
}

export async function createEtablissement(payload) {
  const { data } = await apiClient.post('/etablissements', payload)
  return data
}

export async function updateEtablissement(id, payload) {
  const { data } = await apiClient.put(`/etablissements/${id}`, payload)
  return data
}

export async function activerEtablissement(id) {
  const { data } = await apiClient.post(`/etablissements/${id}/activer`)
  return data
}

export async function desactiverEtablissement(id) {
  const { data } = await apiClient.post(`/etablissements/${id}/desactiver`)
  return data
}

// Utilisateurs (lecture seule — création via synchronisation RH_USER à la connexion)
export async function fetchUtilisateurs(page = 1, q = '') {
  const { data } = await apiClient.get('/utilisateurs', { params: { page, q: q || undefined } })
  return data
}

export async function fetchUtilisateur(id) {
  const { data } = await apiClient.get(`/utilisateurs/${id}`)
  return data
}

export async function createUtilisateur(payload) {
  const { data } = await apiClient.post('/utilisateurs', payload)
  return data
}

export async function updateUtilisateur(id, payload) {
  const { data } = await apiClient.put(`/utilisateurs/${id}`, payload)
  return data
}

export async function activerUtilisateur(id) {
  const { data } = await apiClient.post(`/utilisateurs/${id}/activer`)
  return data
}

export async function desactiverUtilisateur(id) {
  const { data } = await apiClient.post(`/utilisateurs/${id}/desactiver`)
  return data
}

export async function reinitialiserMotDePasseUtilisateur(id, mot_de_passe) {
  const { data } = await apiClient.post(`/utilisateurs/${id}/reinitialiser-mot-de-passe`, { mot_de_passe })
  return data
}

// Rôles (catalogue)
export async function fetchRoles() {
  const { data } = await apiClient.get('/roles')
  return data
}

export async function createRole(payload) {
  const { data } = await apiClient.post('/roles', payload)
  return data
}

export async function deleteRole(id) {
  await apiClient.delete(`/roles/${id}`)
}

// Permissions accordées à un rôle
export async function fetchPermissionsRole(roleId) {
  const { data } = await apiClient.get(`/roles/${roleId}/permissions`)
  return data
}

export async function enregistrerPermissionsRole(roleId, permissions) {
  const { data } = await apiClient.put(`/roles/${roleId}/permissions`, { permissions })
  return data
}

// Affectations
export async function createAffectation(payload) {
  const { data } = await apiClient.post('/affectations', payload)
  return data
}

export async function terminerAffectation(id) {
  const { data } = await apiClient.delete(`/affectations/${id}`)
  return data
}

// Enfants rattachés à une affectation du rôle Parent (console_affectation_eleves).
export async function definirEnfantsAffectation(affectationId, matricules) {
  const { data } = await apiClient.put(`/affectations/${affectationId}/eleves`, { eleves: matricules })
  return data
}

export async function rechercherEleves(q) {
  const { data } = await apiClient.get('/eleves', { params: { q, per_page: 20 } })
  return data?.data ?? data ?? []
}

// Journal d'activité (lecture seule, immuable)

// Sociétés et établissements ne se suppriment pas : ils se désactivent
// (activerSociete / desactiverSociete, et l'équivalent établissement).

// Console : listes complètes, toutes pages réunies. Les tableaux de la console trient,
// cherchent et paginent côté écran (comme les DataTables de parcauto) : il leur faut
// tout, pas une page. 200 est le maximum que l'API accepte par page.
async function toutesLesPages(url, params = {}) {
  const lignes = []
  for (let page = 1; ; page++) {
    const { data } = await apiClient.get(url, { params: { ...params, page, per_page: 200 } })
    lignes.push(...(data.data ?? []))
    if (!data.last_page || page >= data.last_page) return lignes
  }
}

export const fetchTousUtilisateurs = () => toutesLesPages('/utilisateurs')

export const fetchTousEtablissements = (societeCode) =>
  toutesLesPages('/etablissements', { societe_code: societeCode || undefined })

// Sociétés de la console (comme parcauto console/societes) : la liste fusionnée, et
// l'écriture dans le registre des sociétés de la plateforme, reprise aussitôt dans
// ECOPRIM. « Supprimer » désactive ; une société désactivée se réactive.
export const fetchToutesSocietes = () => toutesLesPages('/societes')

const societePlateforme = (code) => `plateforme/societes/${encodeURIComponent(code)}`

export const fetchSocietePlateforme = (code) =>
  apiClient.get(societePlateforme(code)).then((r) => r.data)

export const creerSocietePlateforme = (v) =>
  apiClient.post('plateforme/societes', v).then((r) => r.data)

export const modifierSocietePlateforme = (code, v) =>
  apiClient.put(societePlateforme(code), v).then((r) => r.data)

export const activerSocietePlateforme = (code) =>
  apiClient.post(`${societePlateforme(code)}/activer`).then((r) => r.data)

export const desactiverSocietePlateforme = (code) =>
  apiClient.post(`${societePlateforme(code)}/desactiver`).then((r) => r.data)
