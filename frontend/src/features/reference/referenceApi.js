import apiClient from '../../api/client'

// Données de référence utilisées dans les formulaires (listes déroulantes)
export async function fetchNiveaux() {
  const { data } = await apiClient.get('/niveaux')
  return data
}

export async function fetchAnneesScolaires() {
  const { data } = await apiClient.get('/annees-scolaires')
  return data
}

export async function fetchEnseignants() {
  const { data } = await apiClient.get('/enseignants', { params: { per_page: 200 } })
  return data.data
}

export async function fetchMatieres() {
  const { data } = await apiClient.get('/matieres', { params: { per_page: 200 } })
  return data.data
}

export async function fetchPeriodes(anneeScolaireId) {
  const { data } = await apiClient.get('/periodes', {
    params: anneeScolaireId ? { annee_scolaire_id: anneeScolaireId } : {},
  })
  return data
}

export async function fetchAllEleves() {
  const { data } = await apiClient.get('/eleves', { params: { per_page: 200 } })
  return data.data
}

export async function fetchAllClasses() {
  const { data } = await apiClient.get('/classes', { params: { per_page: 200 } })
  return data.data
}
