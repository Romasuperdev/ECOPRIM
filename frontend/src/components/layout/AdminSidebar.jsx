import { useState } from 'react'
import { NavLink, useLocation } from 'react-router-dom'
import {
  ArrowLeft, Bell, Building, Building2, ChevronDown, CircleHelp, LayoutGrid, Link2, Settings, Users, X,
} from 'lucide-react'
import { useAuthStore } from '../../store/authStore'

/*
 * Menu de la console — même constitution que la console parcauto :
 *
 *   Sociétés · Établissements · Applications · Utilisateurs
 *   Affectations ▾  (Société - Applications · Société - Utilisateurs · Utilisateur - Établissements)
 *   AUTRES : Aide & Documentation · Nouveautés · Retour à l'application
 *
 * Aide & Documentation et Nouveautés sont la GESTION de ce que les utilisateurs lisent
 * dans l'application (Super Admin) ; les autres administrateurs consultent l'aide comme
 * tout le monde.
 *
 * Les rubriques propres à ECOPRIM qui n'existent pas dans parcauto (tableau de bord,
 * traçabilité, abonnements, paramètres système) ont quitté le menu à la demande de
 * l'utilisateur ; leurs pages restent atteignables par leur adresse.
 *
 * On masque ce que le compte ne peut pas ouvrir plutôt que de laisser cliquer vers un
 * 403 : « generale » = Super Admin seul, « niveauSociete » = Super Admin ou Admin Société.
 */
const ITEMS = [
  { to: '/admin/societes', icon: Building2, label: 'Sociétés', generale: true },
  { to: '/admin/etablissements', icon: Building, label: 'Établissements', niveauSociete: true },
  { to: '/admin/applications', icon: LayoutGrid, label: 'Applications', generale: true },
  { to: '/admin/utilisateurs', icon: Users, label: 'Utilisateurs' },
]

const AFFECTATIONS = [
  { to: '/admin/affectations-applications', label: 'Société - Applications', generale: true },
  { to: '/admin/affectations', label: 'Société - Utilisateurs', generale: true },
  { to: '/admin/affectations-etablissement', label: 'Utilisateur - Établissements' },
]

const CLASSE_LIEN = 'flex items-center gap-3 rounded-lg px-4 py-2.5 text-[15px] font-medium transition'

function styleLien({ isActive }) {
  return isActive
    ? { background: 'var(--nav-active-bg)', color: 'var(--nav-active-ink)' }
    : { color: 'var(--nav-text)' }
}

function Lien({ to, icon: Icone, label, end, replie, onNaviguer }) {
  return (
    <NavLink
      to={to} end={end} onClick={onNaviguer} title={replie ? label : undefined}
      className={`${CLASSE_LIEN} nav-survol ${replie ? 'justify-center px-0' : ''}`}
      style={styleLien}
    >
      <Icone size={18} className="shrink-0" />
      {!replie && <span className="truncate">{label}</span>}
    </NavLink>
  )
}

/**
 * @param {boolean} replie     Menu réduit aux icônes (bouton ☰ de l'en-tête, sur grand écran).
 * @param {boolean} dansTiroir Ouvert par-dessus le contenu sur petit écran.
 */
export default function AdminSidebar({ replie = false, dansTiroir = false, onFermer }) {
  const location = useLocation()
  const superAdmin = useAuthStore((s) => s.superAdmin)
  const niveauSociete = useAuthStore((s) => s.niveauSociete)
  const autorise = (i) => (!i.generale || superAdmin) && (!i.niveauSociete || niveauSociete)

  const items = ITEMS.filter(autorise)
  const affectations = AFFECTATIONS.filter(autorise)
  const dansAffectations = affectations.some((a) => location.pathname.startsWith(a.to))
  // Le sous-menu s'ouvre de lui-même sur une page d'affectation, mais reste repliable :
  // le choix de l'utilisateur prime, tant qu'il ne change pas de « côté » (entrer dans
  // les affectations ou en sortir rend la main à l'ouverture automatique). L'ancien
  // `choix || dansAffectations` l'y tenait ouvert de force.
  const [choix, setChoix] = useState(null) // { ouvert, dans } — null : pas de choix
  const ouvert = choix && choix.dans === dansAffectations ? choix.ouvert : dansAffectations
  const reduit = replie && !dansTiroir
  const fermer = dansTiroir ? onFermer : undefined

  return (
    <aside
      className={`flex h-full shrink-0 flex-col overflow-y-auto transition-[width] duration-300 ${dansTiroir ? 'w-full' : reduit ? 'w-[4.5rem]' : 'w-60'}`}
      style={{ background: 'var(--nav-bg)', borderRight: '1px solid var(--border)' }}
    >
      {/* Marque */}
      <div className="flex h-16 shrink-0 items-center gap-2.5 px-5" style={{ borderBottom: '1px solid var(--border)' }}>
        <span
          className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl text-white shadow-sm"
          style={{ background: 'linear-gradient(135deg, var(--blue-500), var(--brand-surface))' }}
        >
          <Settings size={18} />
        </span>
        {!reduit && <span className="flex-1 truncate font-bold" style={{ color: 'var(--nav-title)' }}>Console d’admin</span>}
        {dansTiroir && (
          <button type="button" onClick={onFermer} title="Fermer le menu" className="rounded-lg p-1.5 nav-survol">
            <X size={18} />
          </button>
        )}
      </div>

      <nav className="flex-1 space-y-1 px-3 py-4">
        {items.map((i) => <Lien key={i.to} {...i} replie={reduit} onNaviguer={fermer} />)}

        {affectations.length > 0 && (
          <div className="pt-3">
            <button
              type="button"
              onClick={() => setChoix({ ouvert: !ouvert, dans: dansAffectations })}
              title={reduit ? 'Affectations' : undefined}
              className={`${CLASSE_LIEN} nav-survol w-full ${reduit ? 'justify-center px-0' : ''}`}
              style={{ color: dansAffectations ? 'var(--nav-active-ink)' : 'var(--nav-text)' }}
              aria-expanded={ouvert}
            >
              <Link2 size={18} className="shrink-0" />
              {!reduit && (
                <>
                  <span className="flex-1 text-left">Affectations</span>
                  <ChevronDown size={16} className={`transition-transform ${ouvert ? 'rotate-180' : ''}`} />
                </>
              )}
            </button>
            {ouvert && !reduit && (
              <div className="console-sous-menu ml-6 mt-1 space-y-0.5 pl-3" style={{ borderLeft: '2px solid var(--border)' }}>
                {affectations.map((a) => (
                  <NavLink
                    key={a.to} to={a.to} onClick={fermer}
                    className="nav-survol block rounded-lg px-3 py-2 text-sm font-medium"
                    style={styleLien}
                  >
                    {a.label}
                  </NavLink>
                ))}
              </div>
            )}
          </div>
        )}

        <div className="pt-4">
          {!reduit && (
            <p className="px-4 pb-1.5 text-xs font-bold uppercase tracking-wider" style={{ color: 'var(--muted-text)' }}>
              Autres
            </p>
          )}
          <Lien to={superAdmin ? '/admin/aide' : '/aide'} icon={CircleHelp} label="Aide & Documentation" replie={reduit} onNaviguer={fermer} />
          {superAdmin && <Lien to="/admin/nouveautes" icon={Bell} label="Nouveautés" replie={reduit} onNaviguer={fermer} />}
          <Lien to="/" end icon={ArrowLeft} label="Retour à l’application" replie={reduit} onNaviguer={fermer} />
        </div>
      </nav>
    </aside>
  )
}
