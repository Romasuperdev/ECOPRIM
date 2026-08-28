import { useQuery } from '@tanstack/react-query'
import {
  Users,
  School,
  GraduationCap,
  TrendingUp,
  CalendarX,
  ShieldAlert,
  Timer,
  CheckCircle2,
} from 'lucide-react'
import { useAuthStore } from '../../store/authStore'
import { fetchDashboardStats } from './dashboardApi'

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

export default function DashboardPage() {
  const { user } = useAuthStore()
  const { data: stats } = useQuery({ queryKey: ['dashboard-stats'], queryFn: fetchDashboardStats })

  return (
    <div>
      <div
        className="relative overflow-hidden rounded-2xl p-8"
        style={{ background: 'linear-gradient(135deg, var(--sidebar), var(--sidebar-2))', boxShadow: 'var(--shadow)' }}
      >
        <div className="pointer-events-none absolute -right-10 -top-16 h-56 w-56 rounded-full bg-primary-400/30 blur-3xl" />
        <div className="pointer-events-none absolute -bottom-16 left-1/4 h-56 w-56 rounded-full bg-primary-300/20 blur-3xl" />
        <div className="relative">
          <h1 className="text-2xl font-bold text-white">Bienvenue, {user?.name} 🎓</h1>
          {stats?.annee_scolaire_active && (
            <p className="mt-1" style={{ color: 'var(--sidebar-text)' }}>
              Année scolaire en cours : {stats.annee_scolaire_active}
            </p>
          )}
        </div>
      </div>

      <div className="mt-6 grid grid-cols-2 gap-4 md:grid-cols-4">
        <StatCard icon={Users} label="Élèves" value={stats?.effectifs?.total_eleves} />
        <StatCard icon={School} label="Classes" value={stats?.effectifs?.total_classes} />
        <StatCard icon={GraduationCap} label="Enseignants" value={stats?.effectifs?.total_enseignants} />
        <StatCard
          icon={TrendingUp}
          label="Moyenne générale"
          value={stats?.moyenne_generale ? `${stats.moyenne_generale}/20` : null}
        />
        <StatCard
          icon={CheckCircle2}
          label="Taux d'assiduité"
          value={stats?.taux_assiduite !== null && stats?.taux_assiduite !== undefined ? `${stats.taux_assiduite}%` : null}
        />
        <StatCard icon={CalendarX} label="Absences ce mois" value={stats?.absences_ce_mois} />
        <StatCard icon={Timer} label="Retards ce mois" value={stats?.retards_ce_mois} />
        <StatCard icon={ShieldAlert} label="Sanctions ce mois" value={stats?.sanctions_ce_mois} />
      </div>

      <div className="mt-6 grid gap-6 md:grid-cols-2">
        {stats?.effectif_par_classe?.length > 0 && (
          <div className="card rounded-xl p-6">
            <h2 className="mb-4 text-lg font-semibold text-heading">Effectif par classe</h2>
            <ul className="space-y-2">
              {stats.effectif_par_classe.map((row) => (
                <li key={row.classe} className="flex items-center justify-between text-sm">
                  <span className="text-muted">{row.classe}</span>
                  <span className="font-medium text-heading">{row.effectif} élève(s)</span>
                </li>
              ))}
            </ul>
          </div>
        )}

        {stats?.moyenne_par_classe?.length > 0 && (
          <div className="card rounded-xl p-6">
            <h2 className="mb-4 text-lg font-semibold text-heading">Moyenne par classe</h2>
            <ul className="space-y-2">
              {stats.moyenne_par_classe.map((row) => (
                <li key={row.classe} className="flex items-center justify-between text-sm">
                  <span className="text-muted">{row.classe}</span>
                  <span
                    className={`rounded-full px-2 py-0.5 text-xs font-semibold ${
                      row.moyenne >= 10 ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700'
                    }`}
                  >
                    {row.moyenne}/20
                  </span>
                </li>
              ))}
            </ul>
          </div>
        )}
      </div>
    </div>
  )
}
