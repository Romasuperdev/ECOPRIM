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
        `flex items-center gap-2 rounded-xl px-3 py-2 text-sm transition ${isActive ? 'font-bold' : 'font-medium hover:bg-white/5'}`
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
  const { Icone } = group
  // Les trois teintes du relief viennent du module ; elles sont posées en
  // variables plutôt qu'en classes, le dégradé n'étant pas exprimable autrement.
  const relief = group.module
    ? {
        '--pastille-haut': `var(--module-${group.module}-haut)`,
        '--pastille-bas': `var(--module-${group.module}-bas)`,
        '--pastille-encre': `var(--module-${group.module}-encre)`,
      }
    : undefined

  return (
    <details className="group/section" open={hasActiveItem}>
      <summary
        // Sans capitales : à cette taille et en gras, elles élargissent tellement
        // le mot que « Configuration administrative » ne tenait plus. Le libellé
        // peut passer sur deux lignes plutôt que d'être coupé — un intitulé
        // tronqué ne se devine pas.
        className="flex cursor-pointer select-none items-start gap-2.5 rounded-xl px-2 py-2.5 text-sm font-bold leading-tight hover:bg-white/5"
        style={{ color: accent ?? 'var(--sidebar-text)' }}
      >
        {Icone && (
          <span
            className="pastille-relief flex h-8 w-8 shrink-0 items-center justify-center rounded-xl"
            style={relief}
          >
            <Icone size={17} strokeWidth={2.6} />
          </span>
        )}
        <span className="min-w-0 flex-1 pt-1.5">{group.label}</span>
        <ChevronDown size={15} strokeWidth={2.5} className="mt-2 shrink-0 transition-transform group-open/section:rotate-180" />
      </summary>
      {/* Le filet vertical rattache visuellement les entrées à leur module. Posé
          en marge extérieure, il ne décale pas les libellés entre eux. */}
      <div
        className="mt-1 ml-6 space-y-0.5 pl-2.5"
        style={{
          borderLeft: `2px solid ${accent ? `color-mix(in srgb, ${accent} 70%, transparent)` : 'transparent'}`,
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
