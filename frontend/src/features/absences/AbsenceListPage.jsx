import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus, Pencil, Trash2, ChevronLeft, ChevronRight } from 'lucide-react'
import Button from '../../components/ui/Button'
import { fetchAbsences, deleteAbsence } from './absencesApi'

export default function AbsenceListPage() {
  const [page, setPage] = useState(1)
  const queryClient = useQueryClient()

  const { data, isLoading, isError } = useQuery({
    queryKey: ['absences', page],
    queryFn: () => fetchAbsences(page),
  })

  const deleteMutation = useMutation({
    mutationFn: deleteAbsence,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['absences'] }),
  })

  const handleDelete = (absence) => {
    if (confirm(`Supprimer cette absence de ${absence.eleve?.prenom} ${absence.eleve?.nom} ?`)) {
      deleteMutation.mutate(absence.id)
    }
  }

  return (
    <div>
      <div className="mb-6 flex items-center justify-between">
        <h1 className="text-2xl font-bold text-slate-800">Absences</h1>
        <Link to="/absences/nouvelle">
          <Button>
            <span className="flex items-center gap-2">
              <Plus size={16} /> Signaler une absence
            </span>
          </Button>
        </Link>
      </div>

      <div className="overflow-hidden rounded-xl border border-slate-200 bg-white">
        <table className="w-full text-left text-sm">
          <thead className="border-b border-slate-200 bg-slate-50 text-slate-500">
            <tr>
              <th className="px-4 py-3 font-medium">Élève</th>
              <th className="px-4 py-3 font-medium">Date</th>
              <th className="px-4 py-3 font-medium">Période</th>
              <th className="px-4 py-3 font-medium">Matière</th>
              <th className="px-4 py-3 font-medium">Motif</th>
              <th className="px-4 py-3 font-medium">Justifiée</th>
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
                  Erreur lors du chargement des absences.
                </td>
              </tr>
            )}
            {!isLoading && data?.data.length === 0 && (
              <tr>
                <td colSpan={7} className="px-4 py-6 text-center text-slate-400">
                  Aucune absence enregistrée.
                </td>
              </tr>
            )}
            {data?.data.map((absence) => (
              <tr key={absence.id} className="hover:bg-slate-50">
                <td className="px-4 py-3 font-medium text-slate-800">
                  {absence.eleve?.prenom} {absence.eleve?.nom}
                </td>
                <td className="px-4 py-3 text-slate-600">{absence.date_absence}</td>
                <td className="px-4 py-3 text-slate-600">
                  {{ matin: 'Matin', apres_midi: 'Après-midi', journee: 'Journée' }[absence.periode_jour] ??
                    absence.periode_jour}
                </td>
                <td className="px-4 py-3 text-slate-600">{absence.matiere?.libelle ?? '—'}</td>
                <td className="px-4 py-3 text-slate-600">{absence.motif ?? '—'}</td>
                <td className="px-4 py-3">
                  <span
                    className={`rounded-full px-2 py-0.5 text-xs font-medium ${
                      absence.justifiee ? 'bg-green-50 text-green-700' : 'bg-amber-50 text-amber-700'
                    }`}
                  >
                    {absence.justifiee ? 'Justifiée' : 'Non justifiée'}
                  </span>
                </td>
                <td className="px-4 py-3">
                  <div className="flex justify-end gap-2">
                    <Link
                      to={`/absences/${absence.id}/modifier`}
                      className="rounded p-1.5 text-slate-500 hover:bg-slate-100 hover:text-primary-700"
                    >
                      <Pencil size={16} />
                    </Link>
                    <button
                      onClick={() => handleDelete(absence)}
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
            Page {data.current_page} sur {data.last_page} ({data.total} absences)
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
