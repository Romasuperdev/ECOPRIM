import apiClient from '../../api/client'

export async function fetchContexte() {
  const { data } = await apiClient.get('/contexte')
  return data
}

export async function choisirEtablissement(code) {
  const { data } = await apiClient.post('/contexte/etablissement', { code })
  return data
}

export async function quitterEtablissement() {
  const { data } = await apiClient.delete('/contexte/etablissement')
  return data
}
