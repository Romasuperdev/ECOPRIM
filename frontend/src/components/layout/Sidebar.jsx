import { useEffect, useState } from 'react'
import { NavLink, useLocation } from 'react-router-dom'
import { ChevronDown, LayoutDashboard, PanelLeftClose, PanelLeftOpen, X } from 'lucide-react'
import { useAuthStore } from '../../store/authStore'
import Logo from '../ui/Logo'
import { ROLES } from '../../lib/constants'
import { GROUPS, couleurNav } from '../../lib/navigation'

const CLE_REPLIE = 'nexora-sidebar-repliee'


function NavItem({ to, end, children, onNaviguer }) {
  return (
    <NavLink
      to={to}
      end={end}
      onClick={onNaviguer}
      className={({ isActive }) =>
        `flex items-center gap-2 rounded-xl px-3 py-2 text-sm transition ${isActive ? 'font-semibold' : 'hover:bg-white/5'}`
      }
      style={({ isActive }) =>
        isActive ? { background: 'var(--brand-accent)', color: 'var(--brand-accent-ink)' } : { color: 'var(--sidebar-text)' }
      }
    >
      {children}
    </NavLink>
  )
}


function GroupSection({ group, hasActiveItem, onNaviguer }) {
  const accent = couleurNav(group.module)
  return (
    <details className="group/section" open={hasActiveItem}>
      <summary
        className="flex cursor-pointer select-none items-center justify-between rounded-lg px-3 py-2 text-xs font-semibold uppercase tracking-wide hover:bg-white/5"
        style={{ color: accent ?? 'color-mix(in srgb, var(--sidebar-text) 70%, transparent)' }}
      >
        {group.label}
        <ChevronDown size={14} className="transition-transform group-open/section:rotate-180" />
      </summary>
      {/* Le filet vertical rattache visuellement les entrées à leur module. Posé
          en marge extérieure, il ne décale pas les libellés entre eux. */}
      <div
        className="mt-0.5 ml-3 space-y-0.5 pl-2"
        style={{
          borderLeft: `2px solid ${accent ? `color-mix(in srgb, ${accent} 50%, transparent)` : 'transparent'}`,
        }}
      >
        {group.items.map((item, index) =>
          item.to ? (
            <NavItem key={`${item.label}-${index}`} to={item.to} onNaviguer={onNaviguer}>
              {item.label}
            </NavItem>
          ) : (
            <div
              key={`${item.label}-${index}`}
              className="flex items-center justify-between rounded-xl px-3 py-2 text-sm"
              style={{ color: 'color-mix(in srgb, var(--sidebar-text) 40%, transparent)' }}
            >
              {item.label}
              <span
                className="rounded-full bg-white/10 px-1.5 py-0.5 text-[10px] font-medium"
                style={{ color: 'color-mix(in srgb, var(--sidebar-text) 60%, transparent)' }}
              >
                Bientôt
              </span>
            </div>
          )
        )}
      </div>
    </details>
  )
}

/** Lue une seule fois au montage : évite un aller-retour visible replié → déplié. */
function lireRepliee() {
  try {
    return localStorage.getItem(CLE_REPLIE) === '1'
  } catch {
    return false
  }
}

/**
 * @param {boolean} dansTiroir  Ouvert en tiroir sur petit écran : le menu occupe déjà tout
 *   l'espace disponible, le replier n'aurait pas de sens — on propose « fermer » à la place.
 */
export default function Sidebar({ dansTiroir = false, onFermer }) {
  const location = useLocation()
  const roles = useAuthStore((state) => state.roles)
  const peutConsole = useAuthStore((state) => state.peutConsole)
  const permissions = useAuthStore((state) => state.permissions)
  const isSuperAdmin = roles.includes(ROLES.SUPER_ADMIN)
  // Une entrée peut exiger une permission (`permission`) : on la masque plutôt que de
  // mener vers un écran qui refusera d'agir. Le groupe disparaît s'il n'en reste aucune.
  const visibleGroups = GROUPS
    .filter((group) => (!group.superAdminOnly || isSuperAdmin) && (!group.peutConsoleOnly || peutConsole))
    .map((group) => ({
      ...group,
      items: group.items.filter((item) => !item.permission || permissions.includes(item.permission)),
    }))
    .filter((group) => group.items.length > 0)

  const [repliee, setRepliee] = useState(lireRepliee)

  useEffect(() => {
    try {
      localStorage.setItem(CLE_REPLIE, repliee ? '1' : '0')
    } catch {
      // Confort seulement (mémorise l'état d'un rechargement à l'autre) : silencieux si indisponible.
    }
  }, [repliee])

  // En tiroir, l'état replié n'a pas cours : on affiche toujours le menu entier.
  if (repliee && ! dansTiroir) {
    return (
      <aside
        className="flex h-full w-14 shrink-0 flex-col items-center gap-2 overflow-y-auto py-4"
        style={{ background: 'var(--sidebar)', color: 'var(--sidebar-text)' }}
      >
        <button
          type="button"
          onClick={() => setRepliee(false)}
          title="Déplier le menu"
          className="rounded-lg p-2.5 hover:bg-white/10"
        >
          <PanelLeftOpen size={18} />
        </button>
        <NavLink
          to="/"
          end
          title="Tableau de bord"
          className="rounded-lg p-2.5 hover:bg-white/10"
          style={({ isActive }) =>
            isActive ? { background: 'var(--brand-accent)', color: 'var(--brand-accent-ink)' } : { color: 'var(--sidebar-text)' }
          }
        >
          <LayoutDashboard size={18} />
        </NavLink>
      </aside>
    )
  }

  return (
    <aside
      className={`flex h-full flex-col overflow-y-auto p-4 ${dansTiroir ? 'w-full' : 'w-60 shrink-0'}`}
      style={{ background: 'var(--sidebar)', color: 'var(--sidebar-text)' }}
    >
      <div className="mb-6 flex items-center justify-between px-2">
        <Logo />
        <button
          type="button"
          onClick={() => (dansTiroir ? onFermer?.() : setRepliee(true))}
          title={dansTiroir ? 'Fermer le menu' : 'Replier le menu'}
          className="shrink-0 rounded-lg p-1.5 hover:bg-white/10"
        >
          {dansTiroir ? <X size={18} /> : <PanelLeftClose size={18} />}
        </button>
      </div>
      <nav className="space-y-0.5 pb-6 text-sm">
        <div className="mb-2">
          {/* En tiroir, ouvrir une page referme le menu : sur un téléphone, on veut voir
              la page demandée, pas rester devant le menu. Déplier un groupe, en revanche,
              ne ferme rien — d'où un rappel posé sur les liens, pas sur tout le tiroir. */}
          <NavItem to="/" end onNaviguer={dansTiroir ? onFermer : undefined}>
            <LayoutDashboard size={16} />
            Tableau de bord
          </NavItem>
        </div>
        {visibleGroups.map((group) => (
          <GroupSection
            key={group.label}
            onNaviguer={dansTiroir ? onFermer : undefined}
            group={group}
            hasActiveItem={group.items.some(
              (item) => item.to && (location.pathname === item.to || location.pathname.startsWith(`${item.to}/`))
            )}
          />
        ))}
      </nav>
    </aside>
  )
}
