import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import Button from '../../components/ui/Button'
import apiClient from '../../api/client'

const fetchSocietes = async (page) => (await apiClient.get('/societes', { params: { page } })).data

export default function SocieteListPage() {
  const [page, setPage] = useState(1)
  const { data, isLoading } = useQuery({ queryKey: ['societes', page], queryFn: () => fetchSocietes(page) })

  return (
    <div>
      <div className="mb-6">
        <h1 className="text-2xl font-bold text-slate-800">Sociétés</h1>
        <p className="mt-1 text-sm text-slate-500">Consultation (source : dbmasterbacou).</p>
      </div>
      <div className="overflow-hidden rounded-xl border border-slate-200 bg-white">
        <table className="w-full text-left text-sm">
          <thead className="border-b border-slate-200 bg-slate-50 text-slate-500">
            <tr>
              <th className="px-4 py-3 font-medium">Code</th>
              <th className="px-4 py-3 font-medium">Nom</th>
              <th className="px-4 py-3 font-medium">Ville</th>
              <th className="px-4 py-3 font-medium">Statut</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {isLoading && <tr><td colSpan={4} className="px-4 py-6 text-center text-slate-400">Chargement…</td></tr>}
            {!isLoading && data?.data?.length === 0 && <tr><td colSpan={4} className="px-4 py-6 text-center text-slate-400">Aucune société.</td></tr>}
            {data?.data?.map((s) => (
              <tr key={s.id} className="hover:bg-slate-50">
                <td className="px-4 py-3 font-medium text-slate-800">{s.code}</td>
                <td className="px-4 py-3 text-slate-600">{s.nom}</td>
                <td className="px-4 py-3 text-slate-600">{s.ville ?? '—'}</td>
                <td className="px-4 py-3">
                  <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${s.statut === 'actif' ? 'bg-green-50 text-green-700' : 'bg-slate-100 text-slate-500'}`}>{s.statut}</span>
                </td>
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
