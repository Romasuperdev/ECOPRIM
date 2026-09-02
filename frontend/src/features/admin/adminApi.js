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

export async function deleteSociete(id) {
  await apiClient.delete(`/societes/${id}`)
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

export async function deleteEtablissement(id) {
  await apiClient.delete(`/etablissements/${id}`)
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

// Affectations
export async function createAffectation(payload) {
  const { data } = await apiClient.post('/affectations', payload)
  return data
}

export async function terminerAffectation(id) {
  const { data } = await apiClient.delete(`/affectations/${id}`)
  return data
}

// Journal d'activité (lecture seule, immuable)
export async function fetchJournalActivite(page = 1) {
  const { data } = await apiClient.get('/journal-activite', { params: { page } })
  return data
}
