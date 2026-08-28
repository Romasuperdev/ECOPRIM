import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus, Pencil, Trash2, ChevronLeft, ChevronRight } from 'lucide-react'
import Button from '../../components/ui/Button'
import { fetchEnseignantsPage, deleteEnseignant } from './enseignantsApi'

export default function EnseignantListPage() {
  const [page, setPage] = useState(1)
  const queryClient = useQueryClient()

  const { data, isLoading, isError } = useQuery({
    queryKey: ['enseignants-page', page],
    queryFn: () => fetchEnseignantsPage(page),
  })

  const deleteMutation = useMutation({
    mutationFn: deleteEnseignant,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['enseignants-page'] }),
  })

  const handleDelete = (enseignant) => {
    if (confirm(`Supprimer l'enseignant ${enseignant.prenom} ${enseignant.nom} ?`)) {
      deleteMutation.mutate(enseignant.id)
    }
  }

  return (
    <div>
      <div className="mb-6 flex items-center justify-between">
        <h1 className="text-2xl font-bold text-slate-800">Enseignants</h1>
        <Link to="/enseignants/nouveau">
          <Button>
            <span className="flex items-center gap-2">
              <Plus size={16} /> Ajouter un enseignant
            </span>
          </Button>
        </Link>
      </div>

      <div className="overflow-hidden rounded-xl border border-slate-200 bg-white">
        <table className="w-full text-left text-sm">
          <thead className="border-b border-slate-200 bg-slate-50 text-slate-500">
            <tr>
              <th className="px-4 py-3 font-medium">Matricule</th>
              <th className="px-4 py-3 font-medium">Nom</th>
              <th className="px-4 py-3 font-medium">Prénom</th>
              <th className="px-4 py-3 font-medium">Email</th>
              <th className="px-4 py-3 font-medium">Téléphone</th>
              <th className="px-4 py-3 font-medium">Statut</th>
              <th className="px-4 py-3 font-medium text-right">Actions</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {isLoading && (
              <tr>
                <td colSpan={7} className="px-4 py-6 text-center text-slate-400">
                  Chargement…
                </td>
              </tr>
            )}
            {isError && (
              <tr>
                <td colSpan={7} className="px-4 py-6 text-center text-red-500">
                  Erreur lors du chargement des enseignants.
                </td>
              </tr>
            )}
            {!isLoading && data?.data.length === 0 && (
              <tr>
                <td colSpan={7} className="px-4 py-6 text-center text-slate-400">
                  Aucun enseignant enregistré.
                </td>
              </tr>
            )}
            {data?.data.map((enseignant) => (
              <tr key={enseignant.id} className="hover:bg-slate-50">
                <td className="px-4 py-3 text-slate-600">{enseignant.matricule}</td>
                <td className="px-4 py-3 font-medium text-slate-800">{enseignant.nom}</td>
                <td className="px-4 py-3 text-slate-600">{enseignant.prenom}</td>
                <td className="px-4 py-3 text-slate-600">{enseignant.email ?? '—'}</td>
                <td className="px-4 py-3 text-slate-600">{enseignant.telephone ?? '—'}</td>
                <td className="px-4 py-3">
                  <div className="flex items-center gap-1">
                    <span
                      className={`rounded-full px-2 py-0.5 text-xs font-medium ${
                        enseignant.statut === 'vacataire' ? 'bg-secondary-400/20 text-secondary-500' : 'bg-primary-50 text-primary-700'
                      }`}
                    >
                      {enseignant.statut === 'vacataire' ? 'Vacataire' : 'Titulaire'}
                    </span>
                    {!enseignant.actif && (
                      <span className="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-500">
                        Inactif
                      </span>
                    )}
                  </div>
                </td>
                <td className="px-4 py-3">
                  <div className="flex justify-end gap-2">
                    <Link
                      to={`/enseignants/${enseignant.id}/modifier`}
                      className="rounded p-1.5 text-slate-500 hover:bg-slate-100 hover:text-primary-700"
                    >
                      <Pencil size={16} />
                    </Link>
                    <button
                      onClick={() => handleDelete(enseignant)}
                      className="rounded p-1.5 text-slate-500 hover:bg-red-50 hover:text-red-600"
                    >
                      <Trash2 size={16} />
                    </button>
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {data && data.last_page > 1 && (
        <div className="mt-4 flex items-center justify-between text-sm text-slate-500">
          <span>
            Page {data.current_page} sur {data.last_page} ({data.total} enseignants)
          </span>
          <div className="flex gap-2">
            <Button variant="outline" disabled={!data.prev_page_url} onClick={() => setPage((p) => p - 1)}>
              <ChevronLeft size={16} />
            </Button>
            <Button variant="outline" disabled={!data.next_page_url} onClick={() => setPage((p) => p + 1)}>
              <ChevronRight size={16} />
            </Button>
          </div>
        </div>
      )}
    </div>
  )
}
