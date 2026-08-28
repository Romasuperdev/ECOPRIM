import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus, Pencil, Trash2, ChevronLeft, ChevronRight } from 'lucide-react'
import Button from '../../components/ui/Button'
import { fetchSeances, deleteSeance } from './seancesApi'

export default function SeanceListPage() {
  const [page, setPage] = useState(1)
  const queryClient = useQueryClient()

  const { data, isLoading, isError } = useQuery({
    queryKey: ['seances', page],
    queryFn: () => fetchSeances(page),
  })

  const deleteMutation = useMutation({
    mutationFn: deleteSeance,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['seances'] }),
  })

  const handleDelete = (seance) => {
    if (confirm(`Supprimer cette séance du ${seance.date_seance} ?`)) {
      deleteMutation.mutate(seance.id)
    }
  }

  return (
    <div>
      <div className="mb-6 flex items-center justify-between">
        <h1 className="text-2xl font-bold text-slate-800">Cahier de textes</h1>
        <Link to="/seances/nouvelle">
          <Button>
            <span className="flex items-center gap-2">
              <Plus size={16} /> Ajouter une séance
            </span>
          </Button>
        </Link>
      </div>

      <div className="overflow-hidden rounded-xl border border-slate-200 bg-white">
        <table className="w-full text-left text-sm">
          <thead className="border-b border-slate-200 bg-slate-50 text-slate-500">
            <tr>
              <th className="px-4 py-3 font-medium">Date</th>
              <th className="px-4 py-3 font-medium">Classe</th>
              <th className="px-4 py-3 font-medium">Matière</th>
              <th className="px-4 py-3 font-medium">Contenu</th>
              <th className="px-4 py-3 font-medium text-right">Actions</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {isLoading && (
              <tr>
                <td colSpan={5} className="px-4 py-6 text-center text-slate-400">
                  Chargement…
                </td>
              </tr>
            )}
            {isError && (
              <tr>
                <td colSpan={5} className="px-4 py-6 text-center text-red-500">
                  Erreur lors du chargement.
                </td>
              </tr>
            )}
            {!isLoading && data?.data.length === 0 && (
              <tr>
                <td colSpan={5} className="px-4 py-6 text-center text-slate-400">
                  Aucune séance enregistrée.
                </td>
              </tr>
            )}
            {data?.data.map((seance) => (
              <tr key={seance.id} className="hover:bg-slate-50">
                <td className="px-4 py-3 text-slate-600">{seance.date_seance}</td>
                <td className="px-4 py-3 font-medium text-slate-800">{seance.classe?.nom}</td>
                <td className="px-4 py-3 text-slate-600">{seance.matiere?.libelle}</td>
                <td className="px-4 py-3 text-slate-600">
                  {seance.contenu?.length > 80 ? `${seance.contenu.slice(0, 80)}…` : seance.contenu}
                </td>
                <td className="px-4 py-3">
                  <div className="flex justify-end gap-2">
                    <Link
                      to={`/seances/${seance.id}/modifier`}
                      className="rounded p-1.5 text-slate-500 hover:bg-slate-100 hover:text-primary-700"
                    >
                      <Pencil size={16} />
                    </Link>
                    <button
                      onClick={() => handleDelete(seance)}
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
            Page {data.current_page} sur {data.last_page} ({data.total} séances)
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
