import apiClient from '../../api/client'

export async function fetchMoyennesClasse(classeId, session) {
  const { data } = await apiClient.get(`/classes/${classeId}/moyennes`, {
    params: session ? { session } : {},
  })
  return data
}

export async function fetchAssiduiteClasse(classeId) {
  const { data } = await apiClient.get(`/classes/${classeId}/assiduite`)
  return data
}

export async function fetchEvaluations(classeId) {
  const { data } = await apiClient.get('/evaluations', {
    params: classeId ? { classe_id: classeId } : {},
  })
  return data
}
