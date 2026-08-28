import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus, Pencil, Trash2, ChevronLeft, ChevronRight } from 'lucide-react'
import Button from '../../components/ui/Button'
import { fetchMatieresPage, deleteMatiere } from './matieresApi'

export default function MatiereListPage() {
  const [page, setPage] = useState(1)
  const queryClient = useQueryClient()

  const { data, isLoading, isError } = useQuery({
    queryKey: ['matieres-page', page],
    queryFn: () => fetchMatieresPage(page),
  })

  const deleteMutation = useMutation({
    mutationFn: deleteMatiere,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['matieres-page'] }),
  })

  const handleDelete = (matiere) => {
    if (confirm(`Supprimer la matière ${matiere.libelle} ?`)) {
      deleteMutation.mutate(matiere.id)
    }
  }

  return (
    <div>
      <div className="mb-6 flex items-center justify-between">
        <h1 className="text-2xl font-bold text-slate-800">Matières</h1>
        <Link to="/matieres/nouvelle">
          <Button>
            <span className="flex items-center gap-2">
              <Plus size={16} /> Ajouter une matière
            </span>
          </Button>
        </Link>
      </div>

      <div className="overflow-hidden rounded-xl border border-slate-200 bg-white">
        <table className="w-full text-left text-sm">
          <thead className="border-b border-slate-200 bg-slate-50 text-slate-500">
            <tr>
              <th className="px-4 py-3 font-medium">Code</th>
              <th className="px-4 py-3 font-medium">Libellé</th>
              <th className="px-4 py-3 font-medium">Coefficient par défaut</th>
              <th className="px-4 py-3 font-medium text-right">Actions</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {isLoading && (
              <tr>
                <td colSpan={4} className="px-4 py-6 text-center text-slate-400">
                  Chargement…
                </td>
              </tr>
            )}
            {isError && (
              <tr>
                <td colSpan={4} className="px-4 py-6 text-center text-red-500">
                  Erreur lors du chargement des matières.
                </td>
              </tr>
            )}
            {!isLoading && data?.data.length === 0 && (
              <tr>
                <td colSpan={4} className="px-4 py-6 text-center text-slate-400">
                  Aucune matière enregistrée.
                </td>
              </tr>
            )}
            {data?.data.map((matiere) => (
              <tr key={matiere.id} className="hover:bg-slate-50">
                <td className="px-4 py-3 text-slate-600">{matiere.code}</td>
                <td className="px-4 py-3 font-medium text-slate-800">{matiere.libelle}</td>
                <td className="px-4 py-3 text-slate-600">{matiere.coefficient_defaut ?? '—'}</td>
                <td className="px-4 py-3">
                  <div className="flex justify-end gap-2">
                    <Link
                      to={`/matieres/${matiere.id}/modifier`}
                      className="rounded p-1.5 text-slate-500 hover:bg-slate-100 hover:text-primary-700"
                    >
                      <Pencil size={16} />
                    </Link>
                    <button
                      onClick={() => handleDelete(matiere)}
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
            Page {data.current_page} sur {data.last_page} ({data.total} matières)
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
