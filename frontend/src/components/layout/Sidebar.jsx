import { useEffect, useState } from 'react'
import { NavLink, useLocation } from 'react-router-dom'
import { ChevronDown, LayoutDashboard, PanelLeftClose, PanelLeftOpen, X } from 'lucide-react'
import { useAuthStore } from '../../store/authStore'
import Logo from '../ui/Logo'
import { ROLES } from '../../lib/constants'

const CLE_REPLIE = 'nexora-sidebar-repliee'

// Navigation NEXORA. Seules les pages réellement fonctionnelles sont listées ; les
// entrées non construites ont été retirées. (Le mécanisme « Bientôt » — une entrée
// `to: null` — reste disponible, mais plus aucune ne l'utilise.)
// Le groupe « Parents / Tuteurs » a été retiré : son annuaire n'était qu'une relecture
// des fiches élèves, où ces coordonnées se lisent et se corrigent déjà ; et son entrée
// « Espace parent » annonçait un portail qui existe désormais pour de bon (/mon-espace).
// Les pages adossées à des tables inexistantes (documents/annonces/messages) ont été
// supprimées ; Communication passe par l'envoi SMS/Mail.
// Le groupe « Affectation » a été retiré : sa seule entrée renvoyait vers /classes, déjà
// listée sous Paramètre, et l'affectation enseignant-classe a désormais sa vraie page.
// Cours, Ressources, Conseils et Sanctions ont été retirés : ils reposaient sur le
// schéma local d'avant le pivot et répondaient 500. Le Cahier de textes, lui, a été
// reconstruit sur ECONOMAT.T_ENTETE_JOURNAL / T_CAHIER_JOURNAL.
const GROUPS = [
  {
    label: '⚙️ Paramètre',
    items: [
      { to: '/annees-scolaires', label: 'Années scolaires' },
      { to: '/niveaux', label: 'Niveaux' },
      { to: '/classes', label: 'Classes' },
      { to: '/matieres', label: 'Matières' },
      { to: '/parametres/documents-eleves', label: 'Documents élèves' },
      { to: '/parametres/sms', label: 'Passerelle SMS' },
      { to: '/parametres/mail', label: 'Messagerie (SMTP)' },
      { to: '/affectations-enseignants', label: 'Affectation enseignant – classe' },
    ],
  },
  {
    label: '📝 Traitement',
    items: [
      { to: '/inscriptions', label: 'Inscriptions' },
      { to: '/eleves', label: 'Élèves' },
      { to: '/enseignants', label: 'Enseignants' },
      { to: '/absences', label: 'Absences' },
    ],
  },
  {
    label: '📚 Programme',
    items: [
      { to: '/emplois-du-temps', label: 'Emplois du temps' },
      { to: '/cahier-textes', label: 'Cahier de textes' },
      { to: '/devoirs', label: 'Devoirs' },
      { to: '/calendrier-scolaire', label: 'Calendrier scolaire' },
    ],
  },
  {
    label: '📊 Évaluation & Résultats',
    items: [
      { to: '/evaluations', label: 'Évaluations' },
      { to: '/notes', label: 'Saisie des notes' },
      { to: '/notes/consultation', label: 'Consultation des notes' },
      { to: '/moyennes', label: 'Résultats & bulletins' },
      { to: '/assiduite', label: 'Assiduité' },
    ],
  },
  {
    label: '🔔 Communication',
    items: [
      { to: '/communication/envoi', label: 'Envoi SMS / Mail' },
      { to: '/communication/historique', label: 'Historique des envois' },
    ],
  },
  {
    label: '⚙️ Administration',
    superAdminOnly: true,
    items: [
      { to: '/admin/societes', label: 'Sociétés' },
      { to: '/admin/etablissements', label: 'Établissements' },
    ],
  },
  // Le quotidien d'un Directeur / Admin Établissement : gérer les comptes de son
  // établissement, leurs rôles et ce que chaque rôle a le droit de faire. Ouvert dès que
  // peutConsole est vrai (Super Admin, Admin Société ou Admin Établissement) — pas
  // superAdminOnly comme le groupe ci-dessus, qui reste la console générale/société.
  {
    label: '🔐 Configuration administrative',
    peutConsoleOnly: true,
    items: [
      { to: '/admin/utilisateurs', label: 'Utilisateurs' },
      { to: '/admin/roles', label: 'Rôles' },
      { to: '/admin/permissions', label: 'Permissions' },
    ],
  },
]

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
  return (
    <details className="group/section" open={hasActiveItem}>
      <summary
        className="flex cursor-pointer select-none items-center justify-between rounded-lg px-3 py-2 text-xs font-semibold uppercase tracking-wide hover:bg-white/5"
        style={{ color: 'color-mix(in srgb, var(--sidebar-text) 70%, transparent)' }}
      >
        {group.label}
        <ChevronDown size={14} className="transition-transform group-open/section:rotate-180" />
      </summary>
      <div className="mt-0.5 space-y-0.5">
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
  const isSuperAdmin = roles.includes(ROLES.SUPER_ADMIN)
  const visibleGroups = GROUPS.filter(
    (group) => (!group.superAdminOnly || isSuperAdmin) && (!group.peutConsoleOnly || peutConsole)
  )

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
