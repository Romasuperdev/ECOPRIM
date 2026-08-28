import apiClient from '../../api/client'

export async function fetchMoyennesClasse(classeId, periodeId) {
  const { data } = await apiClient.get(`/classes/${classeId}/moyennes`, {
    params: periodeId ? { periode_id: periodeId } : {},
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

export function bulletinDownloadUrl(eleveId, periodeId) {
  const base = `${apiClient.defaults.baseURL}/eleves/${eleveId}/bulletin`
  return periodeId ? `${base}?periode_id=${periodeId}` : base
}
