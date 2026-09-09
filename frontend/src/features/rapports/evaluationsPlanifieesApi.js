import apiClient from '../../api/client'

export async function fetchReferentielsEvaluation() {
  const { data } = await apiClient.get('/evaluations-planifiees/referentiels')
  return data
}

export async function fetchEvaluationsPlanifiees(classe) {
  const { data } = await apiClient.get('/evaluations-planifiees', { params: classe ? { classe } : {} })
  return data
}

export async function creerEvaluationPlanifiee(payload) {
  const { data } = await apiClient.post('/evaluations-planifiees', payload)
  return data
}

export async function modifierEvaluationPlanifiee(id, payload) {
  const { data } = await apiClient.put(`/evaluations-planifiees/${id}`, payload)
  return data
}

export async function supprimerEvaluationPlanifiee(id) {
  await apiClient.delete(`/evaluations-planifiees/${id}`)
}
