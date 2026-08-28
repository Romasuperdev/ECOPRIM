import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus, Pencil, Trash2, ChevronLeft, ChevronRight } from 'lucide-react'
import Button from '../../components/ui/Button'
import { fetchParentsPage, deleteParent } from './parentsApi'

export default function ParentListPage() {
  const [page, setPage] = useState(1)
  const queryClient = useQueryClient()

  const { data, isLoading, isError } = useQuery({
    queryKey: ['parents-page', page],
    queryFn: () => fetchParentsPage(page),
  })

  const deleteMutation = useMutation({
    mutationFn: deleteParent,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['parents-page'] }),
  })

  const handleDelete = (parent) => {
    if (confirm(`Supprimer le parent ${parent.prenom} ${parent.nom} ?`)) {
      deleteMutation.mutate(parent.id)
    }
  }

  return (
    <div>
      <div className="mb-6 flex items-center justify-between">
        <h1 className="text-2xl font-bold text-slate-800">Parents</h1>
        <Link to="/parents/nouveau">
          <Button>
            <span className="flex items-center gap-2">
              <Plus size={16} /> Ajouter un parent
            </span>
          </Button>
        </Link>
      </div>

      <div className="overflow-hidden rounded-xl border border-slate-200 bg-white">
        <table className="w-full text-left text-sm">
          <thead className="border-b border-slate-200 bg-slate-50 text-slate-500">
            <tr>
              <th className="px-4 py-3 font-medium">Nom</th>
              <th className="px-4 py-3 font-medium">Prénom</th>
              <th className="px-4 py-3 font-medium">Téléphone</th>
              <th className="px-4 py-3 font-medium">Email</th>
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
                  Erreur lors du chargement des parents.
                </td>
              </tr>
            )}
            {!isLoading && data?.data.length === 0 && (
              <tr>
                <td colSpan={5} className="px-4 py-6 text-center text-slate-400">
                  Aucun parent enregistré.
                </td>
              </tr>
            )}
            {data?.data.map((parent) => (
              <tr key={parent.id} className="hover:bg-slate-50">
                <td className="px-4 py-3 font-medium text-slate-800">{parent.nom}</td>
                <td className="px-4 py-3 text-slate-600">{parent.prenom}</td>
                <td className="px-4 py-3 text-slate-600">{parent.telephone ?? '—'}</td>
                <td className="px-4 py-3 text-slate-600">{parent.email ?? '—'}</td>
                <td className="px-4 py-3">
                  <div className="flex justify-end gap-2">
                    <Link
                      to={`/parents/${parent.id}/modifier`}
                      className="rounded p-1.5 text-slate-500 hover:bg-slate-100 hover:text-primary-700"
                    >
                      <Pencil size={16} />
                    </Link>
                    <button
                      onClick={() => handleDelete(parent)}
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
            Page {data.current_page} sur {data.last_page} ({data.total} parents)
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
