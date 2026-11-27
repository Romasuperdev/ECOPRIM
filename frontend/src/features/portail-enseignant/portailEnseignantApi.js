import apiClient from '../../api/client'

const BASE = '/mon-espace/enseignant'

// Un code de classe peut porter une espace ou un caractère accentué (« CE1 A ») : il est
// encodé quand il entre dans le chemin, jamais quand il part en paramètre.
const c = (classe) => encodeURIComponent(classe)

export async function fetchMesClasses() {
  const { data } = await apiClient.get(`${BASE}/classes`)
  return data
}

export async function fetchMesEleves(classe) {
  const { data } = await apiClient.get(`${BASE}/classes/${c(classe)}/eleves`, { params: { per_page: 200 } })
  return data?.data ?? data ?? []
}

export async function fetchCahierReferentiels(classe) {
  const { data } = await apiClient.get(`${BASE}/cahier-textes/referentiels`, { params: { classe } })
  return data
}

export async function fetchCahiers(classe) {
  const { data } = await apiClient.get(`${BASE}/cahier-textes`, { params: { classe } })
  return data
}

export async function creerCahier(payload) {
  const { data } = await apiClient.post(`${BASE}/cahier-textes`, payload)
  return data
}

export async function modifierCahier(id, payload) {
  const { data } = await apiClient.put(`${BASE}/cahier-textes/${id}`, payload)
  return data
}

export async function supprimerCahier(id) {
  await apiClient.delete(`${BASE}/cahier-textes/${id}`)
}

export async function enregistrerLigneCahier(id, payload) {
  const { data } = await apiClient.put(`${BASE}/cahier-textes/${id}/lignes`, payload)
  return data
}

export async function supprimerLigneCahier(id, matiere) {
  await apiClient.delete(`${BASE}/cahier-textes/${id}/lignes/${encodeURIComponent(matiere)}`)
}

export async function fetchMonEmploi(classe) {
  const { data } = await apiClient.get(`${BASE}/emplois-du-temps`, { params: { classe } })
  return data
}

export async function fetchMonEmploiReferentiels() {
  const { data } = await apiClient.get(`${BASE}/emplois-du-temps/referentiels`)
  return data
}

export async function fetchMesAbsences(classeCode) {
  const { data } = await apiClient.get(`${BASE}/absences`, { params: { classe_code: classeCode, per_page: 200 } })
  return data?.data ?? data ?? []
}

export async function creerAbsence(payload) {
  const { data } = await apiClient.post(`${BASE}/absences`, payload)
  return data
}

export async function modifierAbsence(id, payload) {
  const { data } = await apiClient.put(`${BASE}/absences/${id}`, payload)
  return data
}

export async function supprimerAbsence(id) {
  await apiClient.delete(`${BASE}/absences/${id}`)
}

export async function fetchProchainsCours() {
  const { data } = await apiClient.get(`${BASE}/prochains-cours`)
  return data
}

export async function fetchMesDevoirs(classe) {
  const { data } = await apiClient.get(`${BASE}/devoirs`, { params: classe ? { classe } : {} })
  return data
}

export async function fetchMesEvaluationsPlanifiees(classe) {
  const { data } = await apiClient.get(`${BASE}/evaluations-planifiees`, { params: classe ? { classe } : {} })
  return data
}

export async function fetchMesEvenements() {
  const { data } = await apiClient.get(`${BASE}/evenements`)
  return data
}

// --- Saisie des notes : mêmes appels que côté personnel, sur les routes du portail.
// L'autorité n'est pas une permission mais le fait d'enseigner cette matière dans cette
// classe : le serveur le vérifie sur T_CORPROFCLASSE à chaque appel.

export async function fetchMesMatieres(classe) {
  const { data } = await apiClient.get(`${BASE}/classes/${c(classe)}/matieres`)
  return data
}

export const apiNotesEnseignant = {
  structure: async () => (await apiClient.get(`${BASE}/notes/structure`)).data,
  feuille: async (criteres) => (await apiClient.get(`${BASE}/notes/feuille`, { params: criteres })).data,
  enregistrer: async ({ criteres, notes }) =>
    (await apiClient.post(`${BASE}/notes/feuille`, { ...criteres, notes })).data,
}

// --- Appel et justificatifs ---------------------------------------------------------

export async function fetchFeuilleAppel({ classe, date, creneau }) {
  const { data } = await apiClient.get(`${BASE}/appel`, { params: { classe, date, creneau } })
  return data
}

/** { classe, date, creneau, absents: [], retards: [], envoi } */
export async function envoyerAppel(appel) {
  const { classe, date, creneau, absents, retards, envoi } = appel
  const { data } = await apiClient.post(`${BASE}/appel`, { classe, date, creneau, absents, retards, envoi })
  return data
}

export const apiJustificatifsEnseignant = {
  lister: async (statut = 'en_attente') =>
    (await apiClient.get(`${BASE}/justificatifs`, { params: { statut } })).data,
  decider: async (id, decision, commentaire) =>
    (await apiClient.post(`${BASE}/justificatifs/${id}/decision`, { decision, commentaire })).data,
  fichier: async (id) =>
    (await apiClient.get(`${BASE}/justificatifs/${id}/fichier`, { responseType: 'blob' })).data,
}

// --- Évaluations notées (création, saisie, brouillon, publication) -------------------

export const apiEvaluationsNotees = {
  lister: async (classe) =>
    (await apiClient.get(`${BASE}/evaluations-notees`, { params: { classe } })).data,
  creer: async (payload) => (await apiClient.post(`${BASE}/evaluations-notees`, payload)).data,
  feuille: async (entete) => (await apiClient.get(`${BASE}/evaluations-notees/${entete}`)).data,
  enregistrer: async (entete, notes, publier = false) =>
    (await apiClient.put(`${BASE}/evaluations-notees/${entete}/notes`, { notes, publier })).data,
}
