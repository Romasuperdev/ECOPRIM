import apiClient from '../../api/client'

export async function fetchMessages(boite = 'reception', page = 1) {
  const { data } = await apiClient.get('/messages', { params: { boite, page } })
  return data
}

export async function fetchDestinataires() {
  const { data } = await apiClient.get('/destinataires')
  return data
}

export async function sendMessage(payload) {
  const { data } = await apiClient.post('/messages', payload)
  return data
}

export async function markMessageAsRead(id) {
  const { data } = await apiClient.post(`/messages/${id}/lire`)
  return data
}

export async function fetchAnnonces(page = 1) {
  const { data } = await apiClient.get('/annonces', { params: { page } })
  return data
}

export async function createAnnonce(payload) {
  const { data } = await apiClient.post('/annonces', payload)
  return data
}

export async function deleteAnnonce(id) {
  await apiClient.delete(`/annonces/${id}`)
}
