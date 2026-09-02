import apiClient from '../../api/client'

export async function fetchReferentielsEmploi() {
  const { data } = await apiClient.get('/emplois-du-temps/referentiels')
  return data
}

export async function fetchEmploi(classe) {
  const { data } = await apiClient.get('/emplois-du-temps', { params: { classe } })
  return data
}

export async function createCreneau(payload) {
  const { data } = await apiClient.post('/emplois-du-temps', payload)
  return data
}

export async function updateCreneau(id, payload) {
  const { data } = await apiClient.put(`/emplois-du-temps/${id}`, payload)
  return data
}

export async function deleteCreneau(id) {
  await apiClient.delete(`/emplois-du-temps/${id}`)
}

// Classes et matières servent à remplir la grille.
export async function fetchClassesEtMatieres() {
  const [classes, matieres] = await Promise.all([
    apiClient.get('/classes', { params: { per_page: 200 } }),
    apiClient.get('/matieres', { params: { per_page: 200 } }),
  ])
  const liste = (r) => r.data?.data ?? r.data ?? []
  return { classes: liste(classes), matieres: liste(matieres) }
}
