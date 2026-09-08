import { Outlet, useLocation } from 'react-router-dom'
import Button from '../ui/Button'
import Logo from '../ui/Logo'
import { useAuthStore } from '../../store/authStore'
import { logout as logoutApi } from '../../features/auth/authApi'
import ErrorBoundary from '../ErrorBoundary'

const TITRES = {
  enseignant: 'Espace Enseignant',
  parent: 'Espace Parent',
}

// Portails restreints (Enseignant, Parent) : pas de grande sidebar comme l'application
// complète — un en-tête simple et le contenu de la page, cohérent avec le peu d'écrans
// que chaque portail propose.
export default function PortailLayout() {
  const location = useLocation()
  const { user, typePortail, logout } = useAuthStore()

  const handleLogout = async () => {
    await logoutApi()
    logout()
  }

  return (
    <div className="min-h-screen" style={{ background: 'var(--bg)' }}>
      <header
        className="sticky top-0 z-20 flex items-center justify-between px-4 py-3 sm:px-6 lg:px-8"
        style={{
          background: 'color-mix(in srgb, var(--bg) 80%, transparent)',
          backdropFilter: 'blur(8px)',
          borderBottom: '1px solid var(--border)',
        }}
      >
        <div className="flex items-center gap-3">
          <Logo variant="light" size={28} />
          <div>
            <div className="text-sm font-bold text-heading">{TITRES[typePortail] ?? 'Mon espace'}</div>
            <div className="text-xs text-muted">{user?.name}</div>
          </div>
        </div>
        <Button variant="outline" onClick={handleLogout}>Se déconnecter</Button>
      </header>
      <div className="p-4 sm:p-6 lg:p-8">
        <div className="mx-auto w-full max-w-5xl">
          <ErrorBoundary resetKey={location.pathname}>
            <Outlet />
          </ErrorBoundary>
        </div>
      </div>
    </div>
  )
}
