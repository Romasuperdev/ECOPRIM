import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { fetchMatieresPage } from './matieresApi'
import Button from '../../components/ui/Button'

// Lecture seule : matières issues d'ECONOMAT (T_MATIERE).
export default function MatiereListPage() {
  const [page, setPage] = useState(1)
  const { data, isLoading, isError } = useQuery({
    queryKey: ['matieres', page],
    queryFn: () => fetchMatieresPage(page),
  })

  return (
    <div>
      <div className="mb-6">
        <h1 className="text-2xl font-bold text-slate-800">Matières</h1>
        <p className="mt-1 text-sm text-slate-500">Consultation (source : ECONOMAT).</p>
      </div>

      <div className="overflow-hidden rounded-xl border border-slate-200 bg-white">
        <table className="w-full text-left text-sm">
          <thead className="border-b border-slate-200 bg-slate-50 text-slate-500">
            <tr>
              <th className="px-4 py-3 font-medium">Code</th>
              <th className="px-4 py-3 font-medium">Libellé</th>
              <th className="px-4 py-3 font-medium">Type</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {isLoading && (
              <tr><td colSpan={3} className="px-4 py-6 text-center text-slate-400">Chargement…</td></tr>
            )}
            {isError && (
              <tr><td colSpan={3} className="px-4 py-6 text-center text-red-500">Erreur de chargement.</td></tr>
            )}
            {!isLoading && data?.data?.length === 0 && (
              <tr><td colSpan={3} className="px-4 py-6 text-center text-slate-400">Aucune matière.</td></tr>
            )}
            {data?.data?.map((matiere) => (
              <tr key={matiere.id} className="hover:bg-slate-50">
                <td className="px-4 py-3 text-slate-600">{matiere.code}</td>
                <td className="px-4 py-3 font-medium text-slate-800">{matiere.libelle}</td>
                <td className="px-4 py-3 text-slate-600">{matiere.type ?? '—'}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {data && (
        <div className="mt-4 flex justify-end gap-2">
          <Button variant="outline" disabled={!data.prev_page_url} onClick={() => setPage((p) => p - 1)}>
            Précédent
          </Button>
          <Button variant="outline" disabled={!data.next_page_url} onClick={() => setPage((p) => p + 1)}>
            Suivant
          </Button>
        </div>
      )}
    </div>
  )
}
