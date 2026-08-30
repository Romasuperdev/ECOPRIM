import { NavLink, useLocation } from 'react-router-dom'
import { ChevronDown, LayoutDashboard } from 'lucide-react'
import { useAuthStore } from '../../store/authStore'
import { ROLES } from '../../lib/constants'

// Arborescence cible de l'application, réorganisée selon la taxonomie à 7 modules
// (Paramètre / Traitement / Programme / Affectation / Évaluation & Résultats /
// Parents-Tuteurs / Communication) — Administration & Traçabilité restent dans la
// Console, non affectées par ce regroupement. `to: null` = module pas encore construit
// (affiché mais non cliquable, avec une étiquette "Bientôt").
const GROUPS = [
  {
    label: '⚙️ Paramètre',
    items: [
      { to: '/annees-scolaires', label: 'Années scolaires' },
      { to: null, label: 'Calendrier scolaire' },
      { to: '/niveaux', label: 'Cycles / Niveaux' },
      { to: '/classes', label: 'Classes' },
      { to: '/matieres', label: 'Matières' },
      { to: null, label: 'Compétences' },
      { to: null, label: 'Barèmes' },
      { to: null, label: 'Salles & créneaux horaires' },
    ],
  },
  {
    label: '📝 Traitement',
    items: [
      { to: null, label: 'Préinscriptions' },
      { to: '/inscriptions', label: 'Inscriptions' },
      { to: '/eleves', label: 'Élèves' },
      { to: '/enseignants', label: 'Enseignants' },
      { to: '/absences', label: 'Absences' },
      { to: '/retards', label: 'Retards' },
      { to: '/sanctions', label: 'Discipline' },
      { to: '/eleves', label: 'Documents élèves' },
      { to: '/enseignants', label: 'Documents enseignants' },
      { to: '/documents-etablissement', label: 'Documents établissement' },
    ],
  },
  {
    label: '📚 Programme',
    items: [
      { to: null, label: 'Emplois du temps' },
      { to: '/programmes', label: 'Cours' },
      { to: '/seances', label: 'Cahier de textes' },
      { to: '/ressources', label: 'Ressources pédagogiques' },
    ],
  },
  {
    label: '🔗 Affectation',
    items: [
      { to: '/classes', label: 'Élève → Classe' },
      { to: '/classes', label: 'Enseignant → Classe / Matière' },
      { to: null, label: 'Classe → Salle' },
    ],
  },
  {
    label: '📊 Évaluation & Résultats',
    items: [
      { to: '/evaluations', label: 'Évaluations' },
      { to: '/notes', label: 'Saisie des notes' },
      { to: '/moyennes', label: 'Moyennes' },
      { to: '/moyennes', label: 'Classements' },
      { to: '/moyennes', label: 'Résultats' },
      { to: '/moyennes', label: 'Bulletins' },
      { to: '/conseils-classe', label: 'Conseils de classe' },
      { to: '/conseils-classe', label: 'Délibérations' },
      { to: '/assiduite', label: 'Assiduité' },
      { to: null, label: 'Statistiques' },
      { to: null, label: 'Effectifs' },
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
      { to: '/annonces', label: 'Annonces' },
      { to: '/messages', label: 'Messages' },
      { to: '/annonces', label: 'Notifications' },
      { to: null, label: 'Réunions' },
    ],
  },
  {
    label: '⚙️ Administration',
    superAdminOnly: true,
    items: [
      { to: '/admin/societes', label: 'Sociétés' },
      { to: '/admin/etablissements', label: 'Établissements' },
      { to: '/admin/utilisateurs', label: 'Utilisateurs & Accès' },
      { to: null, label: 'Rôles & permissions' },
      { to: null, label: 'Paramètres' },
      { to: '/admin/journal-activite', label: "Journal d'activité" },
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
      <div className="mb-6 px-2 text-base font-extrabold tracking-tight text-white">
        ECOPRIM <span className="font-semibold opacity-80">🎓</span>
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
