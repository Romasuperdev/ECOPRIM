import apiClient from '../../api/client'
import ListeJustificatifs from './ListeJustificatifs'

const api = {
  lister: async (statut = 'en_attente') => (await apiClient.get('/justificatifs', { params: { statut } })).data,
  decider: async (id, decision, commentaire) =>
    (await apiClient.post(`/justificatifs/${id}/decision`, { decision, commentaire })).data,
  fichier: async (id) => (await apiClient.get(`/justificatifs/${id}/fichier`, { responseType: 'blob' })).data,
}

/** Application du personnel : les justificatifs d'absence de l'établissement. */
export default function JustificatifsPage() {
  return (
    <div className="max-w-3xl">
      <div className="mb-6">
        <h1 className="text-2xl font-bold text-slate-800">Justificatifs d’absence</h1>
        <p className="mt-1 text-sm text-slate-500">
          Envoyés par les parents depuis leur espace. L’enseignant de la classe peut aussi les traiter ;
          la décision est reportée sur l’absence.
        </p>
      </div>
      <ListeJustificatifs api={api} cleRequete="justificatifs-etablissement" />
    </div>
  )
}
