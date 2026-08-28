import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Search } from 'lucide-react'
import Input from '../../components/ui/Input'
import { fetchUtilisateurs } from './adminApi'

export default function UtilisateurListPage() {
  const [q, setQ] = useState('')
  const { data, isLoading } = useQuery({ queryKey: ['admin', 'utilisateurs', 1, q], queryFn: () => fetchUtilisateurs(1, q) })

  return (
    <div>
      <div className="mb-6 flex items-center justify-between">
        <h1 className="text-2xl font-bold text-slate-800">Utilisateurs & Accès</h1>
        <div className="w-64">
          <Input icon={Search} placeholder="Rechercher…" value={q} onChange={(e) => setQ(e.target.value)} />
        </div>
      </div>

      <p className="mb-4 text-sm text-slate-500">
        Les comptes sont créés automatiquement à la première connexion. Ouvrez une fiche pour gérer ses affectations
        (société, établissement, rôle).
      </p>

      <div className="overflow-hidden rounded-xl border border-slate-200 bg-white">
        <table className="w-full text-left text-sm">
          <thead className="border-b border-slate-200 bg-slate-50 text-slate-500">
            <tr>
              <th className="px-4 py-3 font-medium">Nom</th>
              <th className="px-4 py-3 font-medium">Email</th>
              <th className="px-4 py-3 font-medium">Rôles</th>
              <th className="px-4 py-3 font-medium">Affectations</th>
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
            {data?.data?.map((user) => (
              <tr key={user.id} className="hover:bg-slate-50">
                <td className="px-4 py-3">
                  <Link to={`/admin/utilisateurs/${user.id}`} className="font-medium text-primary-700 hover:underline">
                    {user.name}
                  </Link>
                </td>
                <td className="px-4 py-3 text-slate-600">{user.email}</td>
                <td className="px-4 py-3">
                  <div className="flex flex-wrap gap-1">
                    {user.roles?.length ? (
                      user.roles.map((role) => (
                        <span key={role.id} className="rounded-full bg-primary-50 px-2 py-0.5 text-xs font-medium text-primary-700">
                          {role.name}
                        </span>
                      ))
                    ) : (
                      <span className="text-slate-300">—</span>
                    )}
                  </div>
                </td>
                <td className="px-4 py-3 text-slate-600">{user.affectations_count}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  )
}
