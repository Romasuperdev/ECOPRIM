import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import Button from '../../components/ui/Button'
import { fetchEnseignantsPage } from './enseignantsApi'

// Lecture seule : enseignants issus d'ECONOMAT (T_PROFESSEUR).
export default function EnseignantListPage() {
  const [page, setPage] = useState(1)
  const { data, isLoading } = useQuery({ queryKey: ['enseignants', page], queryFn: () => fetchEnseignantsPage(page) })

  return (
    <div>
      <div className="mb-6">
        <h1 className="text-2xl font-bold text-slate-800">Enseignants</h1>
        <p className="mt-1 text-sm text-slate-500">Consultation (source : ECONOMAT).</p>
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
              <th className="px-4 py-3 font-medium">Matière</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {isLoading && (
              <tr><td colSpan={7} className="px-4 py-6 text-center text-slate-400">Chargement…</td></tr>
            )}
            {!isLoading && data?.data?.length === 0 && (
              <tr><td colSpan={7} className="px-4 py-6 text-center text-slate-400">Aucun enseignant.</td></tr>
            )}
            {data?.data?.map((e) => (
              <tr key={e.id} className="hover:bg-slate-50">
                <td className="px-4 py-3 text-slate-600">{e.matricule}</td>
                <td className="px-4 py-3 font-medium text-slate-800">{e.nom}</td>
                <td className="px-4 py-3 text-slate-600">{e.prenom}</td>
                <td className="px-4 py-3 text-slate-600">{e.email ?? '—'}</td>
                <td className="px-4 py-3 text-slate-600">{e.telephone ?? '—'}</td>
                <td className="px-4 py-3 text-slate-600">{e.statut ?? '—'}</td>
                <td className="px-4 py-3 text-slate-600">{e.matiere ?? '—'}</td>
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
