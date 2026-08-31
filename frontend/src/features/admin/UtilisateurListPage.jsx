import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import Button from '../../components/ui/Button'
import apiClient from '../../api/client'

const fetchUsers = async (page) => (await apiClient.get('/utilisateurs', { params: { page } })).data

export default function UtilisateurListPage() {
  const [page, setPage] = useState(1)
  const { data, isLoading } = useQuery({ queryKey: ['utilisateurs', page], queryFn: () => fetchUsers(page) })

  return (
    <div>
      <div className="mb-6">
        <h1 className="text-2xl font-bold text-slate-800">Utilisateurs &amp; Accès</h1>
        <p className="mt-1 text-sm text-slate-500">Consultation (source : dbmasterbacou.RH_USER).</p>
      </div>
      <div className="overflow-hidden rounded-xl border border-slate-200 bg-white">
        <table className="w-full text-left text-sm">
          <thead className="border-b border-slate-200 bg-slate-50 text-slate-500">
            <tr>
              <th className="px-4 py-3 font-medium">Nom</th>
              <th className="px-4 py-3 font-medium">Login</th>
              <th className="px-4 py-3 font-medium">Email</th>
              <th className="px-4 py-3 font-medium">Établissement</th>
              <th className="px-4 py-3 font-medium">Actif</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {isLoading && <tr><td colSpan={5} className="px-4 py-6 text-center text-slate-400">Chargement…</td></tr>}
            {!isLoading && data?.data?.length === 0 && <tr><td colSpan={5} className="px-4 py-6 text-center text-slate-400">Aucun utilisateur.</td></tr>}
            {data?.data?.map((u) => (
              <tr key={u.id} className="hover:bg-slate-50">
                <td className="px-4 py-3">
                  <Link to={`/admin/utilisateurs/${u.id}`} className="font-medium text-primary-700 hover:underline">{u.name}</Link>
                </td>
                <td className="px-4 py-3 text-slate-600">{u.login ?? '—'}</td>
                <td className="px-4 py-3 text-slate-600">{u.email ?? '—'}</td>
                <td className="px-4 py-3 text-slate-600">{u.etab ?? '—'}</td>
                <td className="px-4 py-3">
                  {u.actif
                    ? <span className="rounded-full bg-green-50 px-2 py-0.5 text-xs font-medium text-green-700">Oui</span>
                    : <span className="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-500">Non</span>}
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
