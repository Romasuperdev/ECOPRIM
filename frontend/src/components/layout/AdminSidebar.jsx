import { NavLink } from 'react-router-dom'
import Logo from '../ui/Logo'
import { LayoutDashboard, Building2, School, Users, ShieldCheck, ArrowLeftCircle } from 'lucide-react'
import { useAuthStore } from '../../store/authStore'

// « generale » : réservé au Super Admin. Le reste est la console d'une société, ouverte
// aussi à l'Admin Société. On masque plutôt que de laisser cliquer vers un 403.
const ITEMS = [
  { to: '/admin', end: true, icon: LayoutDashboard, label: 'Tableau de bord' },
  { to: '/admin/societes', icon: Building2, label: 'Sociétés', generale: true },
  { to: '/admin/etablissements', icon: School, label: 'Établissements' },
  { to: '/admin/utilisateurs', icon: Users, label: 'Utilisateurs & Accès' },
  { to: '/admin/roles', icon: ShieldCheck, label: 'Rôles & permissions', generale: true },
]

const A_VENIR = ['Applications & licences', 'Abonnements']

export default function AdminSidebar() {
  const superAdmin = useAuthStore((s) => s.superAdmin)
  const items = ITEMS.filter((i) => !i.generale || superAdmin)

  return (
    <aside
      className="flex h-full w-60 shrink-0 flex-col overflow-y-auto p-4"
      style={{ background: 'var(--sidebar-2)', color: 'var(--sidebar-text)' }}
    >
      <div className="mb-1 px-2">
        <Logo />
      </div>
      <div className="mb-4 px-2 text-[10px] font-semibold" style={{ color: 'var(--accent)' }}>
        CONSOLE ADMINISTRATIVE
      </div>
      <NavLink
        to="/"
        className="mb-4 flex items-center gap-2 px-2 text-xs font-medium opacity-70 hover:opacity-100"
      >
        <ArrowLeftCircle size={14} /> Retour à l’application
      </NavLink>

      <nav className="flex-1 space-y-1 text-sm">
        {items.map(({ to, end, icon: Icon, label }) => (
          <NavLink
            key={to}
            to={to}
            end={end}
            className={({ isActive }) =>
              `flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm transition ${isActive ? 'font-semibold' : 'hover:bg-white/5'}`
            }
            style={({ isActive }) =>
              isActive ? { background: 'var(--accent)', color: 'var(--accent-ink)' } : { color: 'var(--sidebar-text)' }
            }
          >
            <Icon size={19} />
            {label}
          </NavLink>
        ))}

        <div className="mt-4 pt-4" style={{ borderTop: '1px solid rgba(255,255,255,.08)' }}>
          <p
            className="mb-1 px-3 text-xs font-semibold uppercase tracking-wide"
            style={{ color: 'color-mix(in srgb, var(--sidebar-text) 50%, transparent)' }}
          >
            À venir
          </p>
          {A_VENIR.map((label) => (
            <div
              key={label}
              className="flex items-center justify-between rounded-xl px-3 py-2 text-sm"
              style={{ color: 'color-mix(in srgb, var(--sidebar-text) 40%, transparent)' }}
            >
              {label}
              <span
                className="rounded-full bg-white/10 px-1.5 py-0.5 text-[10px] font-medium"
                style={{ color: 'color-mix(in srgb, var(--sidebar-text) 60%, transparent)' }}
              >
                Bientôt
              </span>
            </div>
          ))}
        </div>
      </nav>
    </aside>
  )
}
