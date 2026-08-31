import { useParams, Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { ArrowLeft } from 'lucide-react'
import apiClient from '../../api/client'

const fetchUser = async (id) => (await apiClient.get(`/utilisateurs/${id}`)).data

function Champ({ label, value }) {
  return (
    <div>
      <dt className="text-xs font-medium uppercase tracking-wide text-slate-400">{label}</dt>
      <dd className="text-sm text-slate-800">{value || '—'}</dd>
    </div>
  )
}

export default function UtilisateurDetailPage() {
  const { id } = useParams()
  const { data: user, isLoading } = useQuery({ queryKey: ['utilisateurs', id], queryFn: () => fetchUser(id) })

  if (isLoading) return <p className="text-slate-400">Chargement…</p>
  if (!user) return <p className="text-slate-400">Utilisateur introuvable.</p>

  return (
    <div className="space-y-6">
      <div className="flex items-center gap-3">
        <Link to="/admin/utilisateurs" className="rounded p-1.5 text-slate-500 hover:bg-slate-100"><ArrowLeft size={18} /></Link>
        <h1 className="text-2xl font-bold text-slate-800">{user.name}</h1>
      </div>

      <section className="rounded-xl border border-slate-200 bg-white p-6">
        <h2 className="mb-4 text-lg font-semibold text-slate-800">Compte</h2>
        <dl className="grid grid-cols-2 gap-4 md:grid-cols-3">
          <Champ label="Login" value={user.login} />
          <Champ label="Matricule" value={user.matricule} />
          <Champ label="Email" value={user.email} />
          <Champ label="Établissement" value={user.etab} />
          <Champ label="Actif" value={user.actif ? 'Oui' : 'Non'} />
        </dl>
      </section>

      <section className="rounded-xl border border-slate-200 bg-white p-6">
        <h2 className="mb-4 text-lg font-semibold text-slate-800">Rôles</h2>
        {user.roles?.length ? (
          <div className="flex flex-wrap gap-2">
            {user.roles.map((r) => (
              <span key={r} className="rounded-full bg-primary-50 px-2.5 py-1 text-xs font-medium text-primary-700">{r}</span>
            ))}
          </div>
        ) : <p className="text-sm text-slate-400">Aucun rôle.</p>}
      </section>

      <section className="rounded-xl border border-slate-200 bg-white p-6">
        <h2 className="mb-4 text-lg font-semibold text-slate-800">Sociétés (périmètre)</h2>
        {user.societes?.length ? (
          <div className="flex flex-wrap gap-2">
            {user.societes.map((c) => (
              <span key={c} className="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-600">{c}</span>
            ))}
          </div>
        ) : <p className="text-sm text-slate-400">Aucune société rattachée.</p>}
      </section>
    </div>
  )
}
