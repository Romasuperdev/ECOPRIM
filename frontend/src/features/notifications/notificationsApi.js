import apiClient from '../../api/client'

export async function fetchNotifications() {
  const { data } = await apiClient.get('/mon-espace/notifications')
  return data
}

export async function fetchCompteurNotifications() {
  const { data } = await apiClient.get('/mon-espace/notifications/compteur')
  return data
}

export async function marquerNotificationLue(id) {
  const { data } = await apiClient.post(`/mon-espace/notifications/${id}/lue`)
  return data
}

export async function toutMarquerLu() {
  const { data } = await apiClient.post('/mon-espace/notifications/tout-lu')
  return data
}
