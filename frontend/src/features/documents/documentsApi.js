import apiClient from '../../api/client'

export async function fetchDocuments(documentableType, documentableId) {
  const { data } = await apiClient.get('/documents', {
    params: { documentable_type: documentableType, documentable_id: documentableId },
  })
  return data
}

export async function uploadDocument({ documentableType, documentableId, categorie, fichier }) {
  const formData = new FormData()
  formData.append('documentable_type', documentableType)
  formData.append('documentable_id', documentableId)
  if (categorie) formData.append('categorie', categorie)
  formData.append('fichier', fichier)

  const { data } = await apiClient.post('/documents', formData, {
    headers: { 'Content-Type': 'multipart/form-data' },
  })
  return data
}

export async function deleteDocument(id) {
  await apiClient.delete(`/documents/${id}`)
}

export function documentDownloadUrl(id) {
  return `${apiClient.defaults.baseURL}/documents/${id}/telecharger`
}

// --- Documents établissement ---

export async function fetchDocumentsEtablissement() {
  const { data } = await apiClient.get('/documents-etablissement')
  return data
}

export async function uploadDocumentEtablissement({ categorie, fichier }) {
  const formData = new FormData()
  if (categorie) formData.append('categorie', categorie)
  formData.append('fichier', fichier)

  const { data } = await apiClient.post('/documents-etablissement', formData, {
    headers: { 'Content-Type': 'multipart/form-data' },
  })
  return data
}

export async function deleteDocumentEtablissement(id) {
  await apiClient.delete(`/documents-etablissement/${id}`)
}

export function documentEtablissementDownloadUrl(id) {
  return `${apiClient.defaults.baseURL}/documents-etablissement/${id}/telecharger`
}
