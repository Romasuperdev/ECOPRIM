import apiClient from '../../api/client'

export async function fetchAbsences({ classe_code, date, page } = {}) {
  const { data } = await apiClient.get('/absences', {
    params: { classe_code: classe_code || undefined, date: date || undefined, page },
  })
  return data
}

/** Effectif d'une classe, pour choisir l'élève absent dans la liste plutôt qu'au clavier. */
export async function fetchEffectifClasse(classeCode) {
  const { data } = await apiClient.get('/eleves', {
    params: { classe_code: classeCode, per_page: 200 },
  })
  return data.data ?? []
}

export async function createAbsence(payload) {
  const { data } = await apiClient.post('/absences', payload)
  return data
}

export async function updateAbsence(id, payload) {
  const { data } = await apiClient.put(`/absences/${id}`, payload)
  return data
}

export async function deleteAbsence(id) {
  await apiClient.delete(`/absences/${id}`)
}
