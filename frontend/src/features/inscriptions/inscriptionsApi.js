import apiClient from '../../api/client'

export async function fetchInscriptions(params = {}) {
  const { data } = await apiClient.get('/inscriptions', { params })
  return data
}

export async function fetchInscription(id) {
  const { data } = await apiClient.get(`/inscriptions/${id}`)
  return data
}

export async function createInscription(payload) {
  const { data } = await apiClient.post('/inscriptions', payload)
  return data
}

export async function updateInscription(id, payload) {
  const { data } = await apiClient.put(`/inscriptions/${id}`, payload)
  return data
}

// Photo de l'élève : le fichier part vers le dossier partagé lu par ECONOMAT.
export async function televerserPhoto(id, fichier) {
  const corps = new FormData()
  corps.append('photo', fichier)
  const { data } = await apiClient.post(`/inscriptions/${id}/photo`, corps, {
    headers: { 'Content-Type': 'multipart/form-data' },
  })
  return data
}

/** La photo est protégée par la session : on la récupère via axios, pas par <img src>. */
export async function fetchPhotoBlob(id) {
  const { data } = await apiClient.get(`/inscriptions/${id}/photo`, { responseType: 'blob' })
  return data
}

// Listes de référence pour alimenter le formulaire.
export async function fetchReferentiels() {
  const [annees, cycles, niveaux, classes] = await Promise.all([
    apiClient.get('/annees-scolaires', { params: { per_page: 200 } }),
    apiClient.get('/cycles', { params: { per_page: 200 } }),
    apiClient.get('/niveaux', { params: { per_page: 200 } }),
    apiClient.get('/classes', { params: { per_page: 200 } }),
  ])
  const liste = (r) => r.data?.data ?? r.data ?? []
  return {
    annees: liste(annees),
    cycles: liste(cycles),
    niveaux: liste(niveaux),
    classes: liste(classes),
  }
}
