import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import Button from '../../components/ui/Button'
import apiClient from '../../api/client'

const fetchEtabs = async (page) => (await apiClient.get('/etablissements', { params: { page } })).data

export default function EtablissementListPage() {
  const [page, setPage] = useState(1)
  const { data, isLoading } = useQuery({ queryKey: ['etablissements', page], queryFn: () => fetchEtabs(page) })

  return (
    <div>
      <div className="mb-6">
        <h1 className="text-2xl font-bold text-slate-800">Établissements</h1>
        <p className="mt-1 text-sm text-slate-500">Consultation (source : dbmasterbacou).</p>
      </div>
      <div className="overflow-hidden rounded-xl border border-slate-200 bg-white">
        <table className="w-full text-left text-sm">
          <thead className="border-b border-slate-200 bg-slate-50 text-slate-500">
            <tr>
              <th className="px-4 py-3 font-medium">Code</th>
              <th className="px-4 py-3 font-medium">Raison sociale</th>
              <th className="px-4 py-3 font-medium">Type</th>
              <th className="px-4 py-3 font-medium">DREN</th>
              <th className="px-4 py-3 font-medium">Statut</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {isLoading && <tr><td colSpan={5} className="px-4 py-6 text-center text-slate-400">Chargement…</td></tr>}
            {!isLoading && data?.data?.length === 0 && <tr><td colSpan={5} className="px-4 py-6 text-center text-slate-400">Aucun établissement.</td></tr>}
            {data?.data?.map((e) => (
              <tr key={e.id} className="hover:bg-slate-50">
                <td className="px-4 py-3 font-medium text-slate-800">{e.code}</td>
                <td className="px-4 py-3 text-slate-600">{e.nom}</td>
                <td className="px-4 py-3 text-slate-600">{e.type ?? '—'}</td>
                <td className="px-4 py-3 text-slate-600">{e.dren ?? '—'}</td>
                <td className="px-4 py-3">
                  <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${e.statut === 'actif' ? 'bg-green-50 text-green-700' : 'bg-slate-100 text-slate-500'}`}>{e.statut ?? '—'}</span>
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
