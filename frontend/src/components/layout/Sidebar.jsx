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
        `flex items-center gap-2 rounded-xl px-3 py-2 text-sm transition ${isActive ? 'font-bold' : 'font-medium nav-survol'}`
      }
      style={({ isActive }) =>
        isActive ? { background: 'var(--nav-active-bg)', color: 'var(--nav-active-ink)' } : { color: 'var(--nav-text)' }
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
        className="flex cursor-pointer select-none items-start gap-2.5 rounded-xl px-2 py-2.5 text-sm font-bold leading-tight nav-survol"
        style={{ color: accent ?? 'var(--nav-title)' }}
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
        className="nav-groupe mt-1 ml-6 space-y-0.5 pl-2.5"
        style={{ borderLeft: '2px solid var(--border)' }}
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
              style={{ color: 'color-mix(in srgb, var(--nav-text) 40%, transparent)' }}
            >
              {item.label}
              <span
                className="rounded-full bg-slate-100 px-1.5 py-0.5 text-[10px] font-medium"
                style={{ color: 'color-mix(in srgb, var(--nav-text) 60%, transparent)' }}
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

/**
 * Menu replié : une icône par rubrique, et non plus le seul tableau de bord — replié,
 * le menu cachait toutes les rubriques et l'on ne trouvait plus aucun bouton.
 *
 * Un clic sur une icône ouvre, à droite, la liste des pages de la rubrique (comme le
 * panneau qui se déroule dans parcauto) ; un clic ailleurs, Échap ou le choix d'une
 * page la referme. Le panneau est en position fixe : le menu défile (`overflow-y-auto`),
 * et un panneau absolu y serait rogné.
 */
function MenuReplie({ groupes, pathname, onDeplier }) {
  const [ouvert, setOuvert] = useState(null) // { label, top }

  useEffect(() => {
    if (!ouvert) return undefined
    const fermer = (e) => {
      if (e.type === 'keydown' && e.key !== 'Escape') return
      if (e.type === 'mousedown' && e.target.closest?.('[data-menu-replie]')) return
      setOuvert(null)
    }
    document.addEventListener('mousedown', fermer)
    document.addEventListener('keydown', fermer)
    return () => {
      document.removeEventListener('mousedown', fermer)
      document.removeEventListener('keydown', fermer)
    }
  }, [ouvert])

  const groupeOuvert = ouvert && groupes.find((g) => g.label === ouvert.label)
  const estActif = (g) => g.items.some((i) => i.to && (pathname === i.to || pathname.startsWith(`${i.to}/`)))
  const styleIcone = (actif) => (actif
    ? { background: 'var(--nav-active-bg)', color: 'var(--nav-active-ink)' }
    : { color: 'var(--nav-text)' })

  return (
    <aside
      data-menu-replie
      className="flex h-full w-14 shrink-0 flex-col items-center gap-1.5 overflow-y-auto py-4"
      style={{ background: 'var(--nav-bg)', borderRight: '1px solid var(--border)', color: 'var(--nav-text)' }}
    >
      <button type="button" onClick={onDeplier} title="Déplier le menu" className="mb-2 rounded-lg p-2.5 nav-survol">
        <PanelLeftOpen size={18} />
      </button>
      <NavLink
        to="/" end title="Tableau de bord" className="rounded-lg p-2.5 nav-survol"
        style={({ isActive }) => styleIcone(isActive)}
      >
        <LayoutDashboard size={18} />
      </NavLink>

      {groupes.map((g) => {
        const Icone = g.Icone ?? LayoutDashboard
        const actif = estActif(g) || ouvert?.label === g.label
        return (
          <button
            key={g.label}
            type="button"
            title={g.label}
            aria-label={g.label}
            aria-expanded={ouvert?.label === g.label}
            onClick={(e) => {
              const zone = e.currentTarget.getBoundingClientRect()
              setOuvert((o) => (o?.label === g.label ? null : { label: g.label, top: zone.top }))
            }}
            className="rounded-lg p-2.5 nav-survol"
            style={styleIcone(actif)}
          >
            <Icone size={18} />
          </button>
        )
      })}

      {groupeOuvert && (
        <div
          className="console-sous-menu fixed z-50 w-64 overflow-hidden rounded-xl border py-2 text-sm shadow-lg"
          style={{
            left: '3.75rem',
            top: Math.min(ouvert.top, window.innerHeight - 40 - groupeOuvert.items.length * 36),
            background: 'var(--surface)',
            borderColor: 'var(--border)',
          }}
        >
          <p className="px-4 pb-1.5 pt-1 text-xs font-bold uppercase tracking-wider" style={{ color: 'var(--muted-text)' }}>
            {groupeOuvert.label}
          </p>
          {groupeOuvert.items.map((item, i) => (item.to ? (
            <NavLink
              key={`${item.label}-${i}`}
              to={item.to}
              onClick={() => setOuvert(null)}
              className="block px-4 py-2 font-medium nav-survol"
              style={({ isActive }) => styleIcone(isActive)}
            >
              {item.label}
            </NavLink>
          ) : (
            <span key={`${item.label}-${i}`} className="block px-4 py-2 opacity-50">{item.label} · Bientôt</span>
          )))}
        </div>
      )}
    </aside>
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
      <MenuReplie
        groupes={visibleGroups}
        pathname={location.pathname}
        onDeplier={() => setRepliee(false)}
      />
    )
  }

  return (
    <aside
      className={`flex h-full flex-col overflow-y-auto p-4 ${dansTiroir ? 'w-full' : 'w-60 shrink-0'}`}
      style={{ background: 'var(--nav-bg)', borderRight: '1px solid var(--border)', color: 'var(--nav-text)' }}
    >
      <div className="mb-6 flex items-center justify-between px-2">
        <Logo />
        <button
          type="button"
          onClick={() => (dansTiroir ? onFermer?.() : setRepliee(true))}
          title={dansTiroir ? 'Fermer le menu' : 'Replier le menu'}
          className="shrink-0 rounded-lg p-1.5 nav-survol"
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
