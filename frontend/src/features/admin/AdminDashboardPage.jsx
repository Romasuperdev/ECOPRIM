import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { Building2, School, Users, UserCheck, ChevronRight } from 'lucide-react'
import { useAuthStore } from '../../store/authStore'
import { fetchConsoleTableauDeBord } from './consoleContexteApi'

function StatCard({ icon: Icon, label, value, to }) {
  const contenu = (
    <>
      <div className="rounded-lg bg-primary-50 p-3 text-primary-700">
        <Icon size={22} />
      </div>
      <div className="flex-1">
        <p className="text-2xl font-bold text-heading">{value ?? '—'}</p>
        <p className="text-sm text-muted">{label}</p>
      </div>
      {to && <ChevronRight size={18} className="text-muted" />}
    </>
  )

  if (to) {
    return (
      <Link to={to} className="card flex items-center gap-4 rounded-xl p-5 transition hover:shadow-md">
        {contenu}
      </Link>
    )
  }

  return <div className="card flex items-center gap-4 rounded-xl p-5">{contenu}</div>
}

/**
 * Accueil de la console, pour les deux sortes d'administrateur.
 *
 * Un seul appel borné au périmètre, au lieu de trois listes dont l'une (/societes) est
 * réservée au Super Admin : un Admin Société arrivait sur une page vide. Le titre et les
 * tuiles disent ce qu'il administre — sa société, et non « toutes ».
 */
export default function AdminDashboardPage() {
  const { user, superAdmin } = useAuthStore()
  const { data } = useQuery({ queryKey: ['console-tableau-de-bord'], queryFn: fetchConsoleTableauDeBord, retry: false })

  const generale = data?.vue_generale
  const titre = generale
    ? 'Console Administrative'
    : `Console — ${data?.societe_nom ?? data?.societe_code ?? '…'}`

  return (
    <div>
      <div
        className="relative overflow-hidden rounded-2xl p-8"
        style={{ background: 'linear-gradient(135deg, var(--sidebar-2), var(--sidebar))', boxShadow: 'var(--shadow)' }}
      >
        <div className="pointer-events-none absolute -left-16 top-1/2 h-64 w-64 -translate-y-1/2 rounded-full bg-primary-500/30 blur-3xl" />
        <div className="pointer-events-none absolute -right-10 -top-16 h-56 w-56 rounded-full bg-primary-400/30 blur-3xl" />
        <h1 className="relative text-2xl font-bold text-white">{titre}</h1>
        <p className="relative mt-1" style={{ color: 'var(--sidebar-text)' }}>
          Connecté en tant que {user?.name} · {superAdmin ? 'Super Admin' : 'Admin Société'}
        </p>
      </div>

      <div className="mt-6 grid grid-cols-1 gap-4 md:grid-cols-3">
        {/* Le nombre de sociétés n'a de sens qu'en vue générale. */}
        {generale && <StatCard icon={Building2} label="Sociétés" value={data?.societes} to="/admin/societes" />}
        <StatCard icon={School} label="Établissements" value={data?.etablissements} to="/admin/etablissements" />
        <StatCard icon={Users} label="Utilisateurs" value={data?.utilisateurs} to="/admin/utilisateurs" />
        {!generale && <StatCard icon={UserCheck} label="Affectations" value={data?.affectations} />}
      </div>

      {!generale && (
        <p className="mt-3 text-xs text-muted">
          Ces chiffres ne portent que sur {data?.societe_nom ?? 'la société sélectionnée'}.
        </p>
      )}
    </div>
  )
}
