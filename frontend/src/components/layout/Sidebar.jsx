import { NavLink, useLocation } from 'react-router-dom'
import { ChevronDown, LayoutDashboard } from 'lucide-react'
import { useAuthStore } from '../../store/authStore'
import Logo from '../ui/Logo'
import { ROLES } from '../../lib/constants'

// Navigation NEXORA. Seules les pages réellement fonctionnelles sont listées : les
// entrées non construites ont été retirées, à une exception assumée et validée
// (Espace parent) qui reste affichée en « Bientôt » via `to: null`.
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
      { to: '/affectations-enseignants', label: 'Affectation enseignant – classe' },
      { to: '/emplois-du-temps', label: 'Emplois du temps' },
      { to: '/cahier-textes', label: 'Cahier de textes' },
    ],
  },
  {
    label: '📊 Évaluation & Résultats',
    items: [
      { to: '/evaluations', label: 'Évaluations' },
      { to: '/notes', label: 'Saisie des notes' },
      { to: '/moyennes', label: 'Résultats & bulletins' },
      { to: '/assiduite', label: 'Assiduité' },
    ],
  },
  {
    label: '👨‍👩‍👧 Parents / Tuteurs',
    items: [
      { to: '/parents', label: 'Parents / Tuteurs' },
      { to: null, label: 'Espace parent' },
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
      { to: '/admin/utilisateurs', label: 'Utilisateurs & Accès' },
      { to: '/admin/roles', label: 'Rôles & permissions' },
    ],
  },
]

function NavItem({ to, end, children }) {
  return (
    <NavLink
      to={to}
      end={end}
      className={({ isActive }) =>
        `flex items-center gap-2 rounded-xl px-3 py-2 text-sm transition ${isActive ? 'font-semibold' : 'hover:bg-white/5'}`
      }
      style={({ isActive }) =>
        isActive ? { background: 'var(--accent)', color: 'var(--accent-ink)' } : { color: 'var(--sidebar-text)' }
      }
    >
      {children}
    </NavLink>
  )
}

function GroupSection({ group, hasActiveItem }) {
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
            <NavItem key={`${item.label}-${index}`} to={item.to}>
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

export default function Sidebar() {
  const location = useLocation()
  const roles = useAuthStore((state) => state.roles)
  const isSuperAdmin = roles.includes(ROLES.SUPER_ADMIN)
  const visibleGroups = GROUPS.filter((group) => !group.superAdminOnly || isSuperAdmin)

  return (
    <aside
      className="flex h-full w-60 shrink-0 flex-col overflow-y-auto p-4"
      style={{ background: 'var(--sidebar)', color: 'var(--sidebar-text)' }}
    >
      <div className="mb-6 px-2">
        <Logo />
      </div>
      <nav className="space-y-0.5 pb-6 text-sm">
        <div className="mb-2">
          <NavItem to="/" end>
            <LayoutDashboard size={16} />
            Tableau de bord
          </NavItem>
        </div>
        {visibleGroups.map((group) => (
          <GroupSection
            key={group.label}
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
