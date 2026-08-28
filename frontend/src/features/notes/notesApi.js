import apiClient from '../../api/client'

export async function fetchNotes(page = 1) {
  const { data } = await apiClient.get('/notes', { params: { page } })
  return data
}

export async function fetchNote(id) {
  const { data } = await apiClient.get(`/notes/${id}`)
  return data
}

export async function createNote(payload) {
  const { data } = await apiClient.post('/notes', payload)
  return data
}

export async function updateNote(id, payload) {
  const { data } = await apiClient.put(`/notes/${id}`, payload)
  return data
}

export async function deleteNote(id) {
  await apiClient.delete(`/notes/${id}`)
}
