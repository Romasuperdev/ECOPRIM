import apiClient from '../../api/client'

// Documents / prérequis élèves (ECONOMAT.T_PREREQUIS)
export async function fetchPrerequis(filtres = {}) {
  const { data } = await apiClient.get('/parametres/prerequis', {
    params: { annee: filtres.annee || undefined, niveau: filtres.niveau || undefined, q: filtres.q || undefined },
  })
  return data.data
}

export async function createPrerequis(payload) {
  const { data } = await apiClient.post('/parametres/prerequis', payload)
  return data
}

export async function updatePrerequis(id, payload) {
  const { data } = await apiClient.put(`/parametres/prerequis/${id}`, payload)
  return data
}

// Passerelle SMS (ECONOMAT.ECO_SMS_CONFIG)
export async function fetchConfigSms() {
  const { data } = await apiClient.get('/parametres/sms')
  return data
}

export async function saveConfigSms(payload) {
  const { data } = await apiClient.post('/parametres/sms', payload)
  return data
}

// Messagerie SMTP (ECONOMAT.T_MAIL_DIFFUSION)
export async function fetchConfigMail() {
  const { data } = await apiClient.get('/parametres/mail')
  return data
}

export async function saveConfigMail(payload) {
  const { data } = await apiClient.post('/parametres/mail', payload)
  return data
}
