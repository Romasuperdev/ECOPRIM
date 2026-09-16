import { useState } from 'react'
import { Outlet, useLocation } from 'react-router-dom'
import { Menu } from 'lucide-react'
import Sidebar from './Sidebar'
import { couleurModulePourChemin } from '../../lib/navigation'
import Button from '../ui/Button'
import BasculeTheme from '../ui/BasculeTheme'
import { useAuthStore } from '../../store/authStore'
import { logout as logoutApi } from '../../features/auth/authApi'
import ContexteBarre from '../../features/contexte/ContexteBarre'
import SocieteBarre from '../../features/admin/SocieteBarre'
import ErrorBoundary from '../ErrorBoundary'

export default function Layout() {
  const location = useLocation()
  const { user, roles, logout, niveauSociete } = useAuthStore()
  // Sous `lg`, le menu ne tient pas à côté du contenu : il s'ouvre en tiroir par-dessus.
  const [menuOuvert, setMenuOuvert] = useState(false)
  // Rappel du module courant sur l'en-tête. Surtout utile quand le menu est
  // replié ou fermé en tiroir : c'est alors le seul repère de section restant.
  const accentModule = couleurModulePourChemin(location.pathname)

  const handleLogout = async () => {
    await logoutApi()
    logout()
  }

  return (
    <div className="flex h-dvh">
      <div className="hidden lg:flex">
        <Sidebar />
      </div>

      {menuOuvert && (
        <div className="fixed inset-0 z-40 lg:hidden">
          <div className="absolute inset-0 bg-black/40" onClick={() => setMenuOuvert(false)} />
          <div className="relative h-full w-64 max-w-[85vw] shadow-xl">
            <Sidebar dansTiroir onFermer={() => setMenuOuvert(false)} />
          </div>
        </div>
      )}

      <main className="flex-1 min-w-0 overflow-y-auto">
        <div
          className="sticky top-0 z-20 flex items-center justify-between gap-3 px-4 py-3 sm:px-6 lg:px-8"
          style={{
            background: 'color-mix(in srgb, var(--bg) 80%, transparent)',
            backdropFilter: 'blur(8px)',
            borderBottom: '1px solid var(--border)',
            // Le liseré remplace la bordure haute quand on est dans un module ;
            // hors module (tableau de bord), l'en-tête reste nu.
            borderTop: accentModule ? `3px solid ${accentModule}` : '3px solid transparent',
          }}
        >
          <div className="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-2">
            <button
              type="button"
              onClick={() => setMenuOuvert(true)}
              title="Ouvrir le menu"
              className="-ml-1 shrink-0 rounded-lg p-1.5 text-heading hover:bg-black/5 lg:hidden"
            >
              <Menu size={20} />
            </button>
            <div className="min-w-0 text-sm font-medium text-heading">
              {user?.name}
              {roles.length > 0 && (
                <span className="ml-1.5 text-xs font-normal text-muted">{roles.join(', ')}</span>
              )}
            </div>
            <ContexteBarre />
            {/* Société administrée : utile pour choisir sur laquelle portent Utilisateurs/
                Rôles/Permissions quand on en gère plusieurs (Super Admin, Admin Société).
                Sans objet pour un Admin Établissement, borné à la sienne — masqué pour lui. */}
            {niveauSociete && <SocieteBarre />}
          </div>
          <div className="flex shrink-0 items-center gap-1">
            <BasculeTheme />
            <Button variant="outline" onClick={handleLogout}>
              <span className="hidden sm:inline">Se déconnecter</span>
              <span className="sm:hidden">Quitter</span>
            </Button>
          </div>
        </div>
        <div className="p-4 sm:p-6 lg:p-8 lg:pt-6">
          <div className="mx-auto w-full max-w-6xl">
            <ErrorBoundary resetKey={location.pathname}>
              <Outlet />
            </ErrorBoundary>
          </div>
        </div>
      </main>
    </div>
  )
}
