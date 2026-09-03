import apiClient from '../../api/client'

export async function fetchReferentiels() {
  const { data } = await apiClient.get('affectations-enseignants/referentiels')
  return data
}

export async function fetchAffectations(filtres = {}) {
  const { data } = await apiClient.get('affectations-enseignants', {
    params: { classe: filtres.classe || undefined, enseignant: filtres.enseignant || undefined },
  })
  return data
}

export async function createAffectation(payload) {
  const { data } = await apiClient.post('affectations-enseignants', payload)
  return data
}

export async function updateAffectation(id, payload) {
  const { data } = await apiClient.put(`affectations-enseignants/${id}`, payload)
  return data
}

export async function deleteAffectation(id) {
  await apiClient.delete(`affectations-enseignants/${id}`)
}
