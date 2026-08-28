import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus, Pencil, Trash2, ChevronLeft, ChevronRight } from 'lucide-react'
import Button from '../../components/ui/Button'
import { fetchEleves, deleteEleve } from './elevesApi'

export default function EleveListPage() {
  const [page, setPage] = useState(1)
  const queryClient = useQueryClient()

  const { data, isLoading, isError } = useQuery({
    queryKey: ['eleves', page],
    queryFn: () => fetchEleves(page),
  })

  const deleteMutation = useMutation({
    mutationFn: deleteEleve,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['eleves'] }),
  })

  const handleDelete = (eleve) => {
    if (confirm(`Supprimer l'élève ${eleve.prenom} ${eleve.nom} ?`)) {
      deleteMutation.mutate(eleve.id)
    }
  }

  return (
    <div>
      <div className="mb-6 flex items-center justify-between">
        <h1 className="text-2xl font-bold text-slate-800">Élèves</h1>
        <Link to="/eleves/nouveau">
          <Button>
            <span className="flex items-center gap-2">
              <Plus size={16} /> Ajouter un élève
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
              <th className="px-4 py-3 font-medium">Classe</th>
              <th className="px-4 py-3 font-medium">Statut</th>
              <th className="px-4 py-3 font-medium text-right">Actions</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {isLoading && (
              <tr>
                <td colSpan={6} className="px-4 py-6 text-center text-slate-400">
                  Chargement…
                </td>
              </tr>
            )}
            {isError && (
              <tr>
                <td colSpan={6} className="px-4 py-6 text-center text-red-500">
                  Erreur lors du chargement des élèves.
                </td>
              </tr>
            )}
            {!isLoading && data?.data.length === 0 && (
              <tr>
                <td colSpan={6} className="px-4 py-6 text-center text-slate-400">
                  Aucun élève enregistré.
                </td>
              </tr>
            )}
            {data?.data.map((eleve) => (
              <tr key={eleve.id} className="hover:bg-slate-50">
                <td className="px-4 py-3 text-slate-600">{eleve.matricule}</td>
                <td className="px-4 py-3 font-medium text-slate-800">{eleve.nom}</td>
                <td className="px-4 py-3 text-slate-600">{eleve.prenom}</td>
                <td className="px-4 py-3 text-slate-600">{eleve.classe?.nom ?? '—'}</td>
                <td className="px-4 py-3">
                  <span className="rounded-full bg-primary-50 px-2 py-0.5 text-xs font-medium text-primary-700">
                    {eleve.statut}
                  </span>
                </td>
                <td className="px-4 py-3">
                  <div className="flex justify-end gap-2">
                    <Link
                      to={`/eleves/${eleve.id}/modifier`}
                      className="rounded p-1.5 text-slate-500 hover:bg-slate-100 hover:text-primary-700"
                    >
                      <Pencil size={16} />
                    </Link>
                    <button
                      onClick={() => handleDelete(eleve)}
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
            Page {data.current_page} sur {data.last_page} ({data.total} élèves)
          </span>
          <div className="flex gap-2">
            <Button
              variant="outline"
              disabled={!data.prev_page_url}
              onClick={() => setPage((p) => p - 1)}
            >
              <ChevronLeft size={16} />
            </Button>
            <Button
              variant="outline"
              disabled={!data.next_page_url}
              onClick={() => setPage((p) => p + 1)}
            >
              <ChevronRight size={16} />
            </Button>
          </div>
        </div>
      )}
    </div>
  )
}
