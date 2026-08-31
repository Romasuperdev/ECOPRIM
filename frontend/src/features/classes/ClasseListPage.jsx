import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import Button from '../../components/ui/Button'
import { fetchClasses } from './classesApi'

// Lecture seule : classes issues d'ECONOMAT (T_CLASSE).
export default function ClasseListPage() {
  const [page, setPage] = useState(1)
  const { data, isLoading } = useQuery({ queryKey: ['classes', page], queryFn: () => fetchClasses(page) })

  return (
    <div>
      <div className="mb-6">
        <h1 className="text-2xl font-bold text-slate-800">Classes</h1>
        <p className="mt-1 text-sm text-slate-500">Consultation (source : ECONOMAT).</p>
      </div>

      <div className="overflow-hidden rounded-xl border border-slate-200 bg-white">
        <table className="w-full text-left text-sm">
          <thead className="border-b border-slate-200 bg-slate-50 text-slate-500">
            <tr>
              <th className="px-4 py-3 font-medium">Code</th>
              <th className="px-4 py-3 font-medium">Nom</th>
              <th className="px-4 py-3 font-medium">Niveau</th>
              <th className="px-4 py-3 font-medium">Année</th>
              <th className="px-4 py-3 font-medium">Série</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {isLoading && (
              <tr><td colSpan={5} className="px-4 py-6 text-center text-slate-400">Chargement…</td></tr>
            )}
            {!isLoading && data?.data?.length === 0 && (
              <tr><td colSpan={5} className="px-4 py-6 text-center text-slate-400">Aucune classe.</td></tr>
            )}
            {data?.data?.map((classe) => (
              <tr key={classe.id} className="hover:bg-slate-50">
                <td className="px-4 py-3 text-slate-600">{classe.code}</td>
                <td className="px-4 py-3 font-medium text-slate-800">{classe.nom}</td>
                <td className="px-4 py-3 text-slate-600">{classe.niveau?.libelle ?? classe.niveau_code ?? '—'}</td>
                <td className="px-4 py-3 text-slate-600">{classe.annee ?? '—'}</td>
                <td className="px-4 py-3 text-slate-600">{classe.serie ?? '—'}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {data && (
        <div className="mt-4 flex justify-end gap-2">
          <Button variant="outline" disabled={!data.prev_page_url} onClick={() => setPage((p) => p - 1)}>Précédent</Button>
          <Button variant="outline" disabled={!data.next_page_url} onClick={() => setPage((p) => p + 1)}>Suivant</Button>
        </div>
      )}
    </div>
  )
}
