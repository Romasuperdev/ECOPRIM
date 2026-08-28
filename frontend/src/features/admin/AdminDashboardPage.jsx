import { useQuery } from '@tanstack/react-query'
import { Building2, School, Users } from 'lucide-react'
import { useAuthStore } from '../../store/authStore'
import { fetchSocietes, fetchEtablissements, fetchUtilisateurs } from './adminApi'

function StatCard({ icon: Icon, label, value }) {
  return (
    <div className="card flex items-center gap-4 rounded-xl p-5">
      <div className="rounded-lg bg-primary-50 p-3 text-primary-700">
        <Icon size={22} />
      </div>
      <div>
        <p className="text-2xl font-bold text-heading">{value ?? '—'}</p>
        <p className="text-sm text-muted">{label}</p>
      </div>
    </div>
  )
}

export default function AdminDashboardPage() {
  const { user } = useAuthStore()
  const { data: societes } = useQuery({ queryKey: ['admin', 'societes', 1], queryFn: () => fetchSocietes(1) })
  const { data: etablissements } = useQuery({ queryKey: ['admin', 'etablissements', 1], queryFn: () => fetchEtablissements(1) })
  const { data: utilisateurs } = useQuery({ queryKey: ['admin', 'utilisateurs', 1], queryFn: () => fetchUtilisateurs(1) })

  return (
    <div>
      <div
        className="relative overflow-hidden rounded-2xl p-8"
        style={{ background: 'linear-gradient(135deg, var(--sidebar-2), var(--sidebar))', boxShadow: 'var(--shadow)' }}
      >
        <div className="pointer-events-none absolute -left-16 top-1/2 h-64 w-64 -translate-y-1/2 rounded-full bg-primary-500/30 blur-3xl" />
        <div className="pointer-events-none absolute -right-10 -top-16 h-56 w-56 rounded-full bg-primary-400/30 blur-3xl" />
        <h1 className="relative text-2xl font-bold text-white">Console Administrative</h1>
        <p className="relative mt-1" style={{ color: 'var(--sidebar-text)' }}>
          Connecté en tant que {user?.name}
        </p>
      </div>

      <div className="mt-6 grid grid-cols-1 gap-4 md:grid-cols-3">
        <StatCard icon={Building2} label="Sociétés" value={societes?.total} />
        <StatCard icon={School} label="Établissements" value={etablissements?.total} />
        <StatCard icon={Users} label="Utilisateurs" value={utilisateurs?.total} />
      </div>
    </div>
  )
}
