import { useEffect, useRef, useState } from 'react'
import { Outlet, useLocation } from 'react-router-dom'
import { ChevronDown, LogOut, Menu, Settings, UserCircle } from 'lucide-react'
import AdminSidebar from './AdminSidebar'
import BasculeTheme from '../ui/BasculeTheme'
import { useAuthStore } from '../../store/authStore'
import { logout as logoutApi } from '../../features/auth/authApi'
import ErrorBoundary from '../ErrorBoundary'
import { ID_HOTE_FORMULAIRE } from '../ui/ModaleFormulaire'
import SocieteBarre from '../../features/admin/SocieteBarre'

const CLE_REPLIE = 'nexora-console-repliee'

function lireReplie() {
  try {
    return localStorage.getItem(CLE_REPLIE) === '1'
  } catch {
    return false
  }
}

function initiales(nom) {
  return (nom ?? '?').split(/\s+/).filter(Boolean).slice(0, 2).map((m) => m[0]).join('').toUpperCase()
}

/**
 * Coquille de la console — constitution de la console parcauto : menu à gauche, et en
 * haut ☰ · « Console d'administration » · bouton de thème · compte (menu Déconnexion)
 * · avatar.
 *
 * ☰ réduit le menu aux icônes sur grand écran (mémorisé d'une visite à l'autre) ; sur
 * petit écran, il ouvre le menu en tiroir par-dessus le contenu.
 */
export default function AdminLayout() {
  const location = useLocation()
  const { user, logout } = useAuthStore()
  const [replie, setReplie] = useState(lireReplie)
  const [tiroir, setTiroir] = useState(false)
  const [compteOuvert, setCompteOuvert] = useState(false)
  const compte = useRef(null)

  useEffect(() => {
    try {
      localStorage.setItem(CLE_REPLIE, replie ? '1' : '0')
    } catch {
      // Confort seulement : silencieux si le stockage est indisponible.
    }
  }, [replie])

  // Le menu du compte se ferme au clic ailleurs, comme le `@click.outside` de parcauto.
  useEffect(() => {
    if (!compteOuvert) return undefined
    const fermer = (e) => { if (!compte.current?.contains(e.target)) setCompteOuvert(false) }
    document.addEventListener('mousedown', fermer)
    return () => document.removeEventListener('mousedown', fermer)
  }, [compteOuvert])

  const handleLogout = async () => {
    await logoutApi()
    logout()
  }

  const basculerMenu = () => {
    if (window.matchMedia('(min-width: 1024px)').matches) setReplie((r) => !r)
    else setTiroir(true)
  }

  const identifiant = user?.login ?? user?.name ?? 'Compte'

  return (
    <div className="flex h-dvh">
      <div className="hidden lg:flex">
        <AdminSidebar replie={replie} />
      </div>

      {tiroir && (
        <div className="fixed inset-0 z-40 lg:hidden">
          <div className="absolute inset-0 bg-black/40" onClick={() => setTiroir(false)} />
          <div className="relative h-full w-64 max-w-[85vw] shadow-xl">
            <AdminSidebar dansTiroir onFermer={() => setTiroir(false)} />
          </div>
        </div>
      )}

      <main className="flex min-w-0 flex-1 flex-col overflow-y-auto">
        <header
          className="sticky top-0 z-20 flex h-16 shrink-0 items-center gap-3 px-4 sm:px-6"
          style={{ background: 'var(--surface)', borderBottom: '1px solid var(--border)', boxShadow: '0 1px 2px rgba(20, 40, 70, .04)' }}
        >
          <button
            type="button" onClick={basculerMenu} title="Menu" aria-label="Menu"
            className="rounded-lg p-1.5 nav-survol" style={{ color: 'var(--muted-text)' }}
          >
            <Menu size={20} />
          </button>
          <span className="flex min-w-0 items-center gap-1.5 font-semibold" style={{ color: 'var(--nav-text)' }}>
            <Settings size={17} className="shrink-0" style={{ color: 'var(--warning)' }} />
            <span className="truncate">Console d’administration</span>
          </span>

          <div className="ml-auto flex items-center gap-3">
            {/* Société administrée : propre à ECOPRIM (un Super Admin choisit sur laquelle
                portent Utilisateurs et Affectations). Masquée sur petit écran. */}
            <div className="hidden md:block"><SocieteBarre /></div>

            <span className="rounded-lg border" style={{ borderColor: 'var(--border)' }}>
              <BasculeTheme />
            </span>

            <div className="relative" ref={compte}>
              <button
                type="button" onClick={() => setCompteOuvert((o) => !o)}
                className="flex items-center gap-2 rounded-lg px-1.5 py-1 text-sm font-medium nav-survol"
                style={{ color: 'var(--nav-text)' }}
                aria-expanded={compteOuvert}
              >
                <UserCircle size={18} />
                <span className="hidden max-w-40 truncate sm:inline">{identifiant}</span>
                <ChevronDown size={14} className="hidden sm:block" />
                <span
                  className="flex h-9 w-9 items-center justify-center rounded-full text-xs font-extrabold"
                  style={{ background: 'var(--nav-active-bg)', color: 'var(--nav-active-ink)' }}
                  aria-hidden
                >
                  {initiales(user?.name ?? identifiant)}
                </span>
              </button>
              {compteOuvert && (
                <div
                  className="console-sous-menu absolute right-0 z-40 mt-2 w-52 overflow-hidden rounded-xl border py-1 text-sm shadow-lg"
                  style={{ background: 'var(--surface)', borderColor: 'var(--border)' }}
                >
                  <div className="px-4 py-2 text-xs" style={{ color: 'var(--muted-text)' }}>{user?.name}</div>
                  <button
                    type="button" onClick={handleLogout}
                    className="flex w-full items-center gap-2 px-4 py-2 text-left font-medium nav-survol"
                    style={{ color: 'var(--danger-text)' }}
                  >
                    <LogOut size={16} /> Déconnexion
                  </button>
                </div>
              )}
            </div>
          </div>
        </header>

        <div className="flex-1 p-4 sm:p-6">
          {/* Formulaires « Nouveau / Modifier » : ils s'affichent ici, en page, à la place
              du contenu (voir ModaleFormulaire). */}
          <div id={ID_HOTE_FORMULAIRE} className="hote-formulaire" />
          <div className="zone-contenu">
            <ErrorBoundary resetKey={location.pathname}>
              {/* Clé = chemin : chaque page entre en fondu glissé au lieu d'apparaître d'un coup. */}
              <div key={location.pathname} className="animate-fade-up">
                <Outlet />
              </div>
            </ErrorBoundary>
          </div>
        </div>
      </main>
    </div>
  )
}
