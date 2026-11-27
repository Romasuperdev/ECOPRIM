import { useEffect } from 'react'
import { Outlet, useLocation, useNavigate } from 'react-router-dom'
import { useQueryClient } from '@tanstack/react-query'
import { LogOut } from 'lucide-react'
import Button from '../ui/Button'
import BasculeTheme from '../ui/BasculeTheme'
import Logo from '../ui/Logo'
import { useAuthStore } from '../../store/authStore'
import { logout as logoutApi } from '../../features/auth/authApi'
import { marquerNotificationLue } from '../../features/notifications/notificationsApi'
import { demarrerFileAttente } from '../../lib/fileAttenteAppels'
import ErrorBoundary from '../ErrorBoundary'
import { ID_HOTE_FORMULAIRE } from '../ui/ModaleFormulaire'
import BanniereInstallation from './BanniereInstallation'
import BarreNavigationPortail from './BarreNavigationPortail'
import ClocheNotifications from './ClocheNotifications'

const TITRES = {
  enseignant: 'Espace Enseignant',
  parent: 'Espace Parent',
}

// Portails restreints (Enseignant, Parent) : pas de grande sidebar comme l'application
// complète — un en-tête simple et le contenu de la page. Sur téléphone, une barre de
// navigation en bas d'écran (utilisable d'une main) et la cloche des notifications.
export default function PortailLayout() {
  const location = useLocation()
  const navigate = useNavigate()
  const qc = useQueryClient()
  const { user, typePortail, logout } = useAuthStore()

  const handleLogout = async () => {
    await logoutApi()
    logout()
  }

  // Enseignant : les appels faits sans réseau repartent dès que la connexion revient.
  useEffect(() => {
    if (typePortail === 'enseignant') demarrerFileAttente()
  }, [typePortail])

  // Ouverte depuis une notification du téléphone (sw.js ajoute « ?lue=ID ») : on la marque
  // lue, puis on retire le paramètre de l'adresse.
  useEffect(() => {
    const params = new URLSearchParams(location.search)
    const id = params.get('lue')
    if (!id) return
    params.delete('lue')
    navigate({ pathname: location.pathname, search: params.toString() }, { replace: true })
    marquerNotificationLue(id)
      .then(() => {
        qc.invalidateQueries({ queryKey: ['notifications'] })
        qc.invalidateQueries({ queryKey: ['notifications-compteur'] })
      })
      .catch(() => { /* déjà lue, ou plus la sienne : rien à faire */ })
  }, [location.search, location.pathname, navigate, qc])

  return (
    <div className="min-h-screen" style={{ background: 'var(--bg)' }}>
      <header
        className="sticky top-0 z-20 flex items-center justify-between px-4 py-3 sm:px-6 lg:px-8"
        style={{
          background: 'color-mix(in srgb, var(--bg) 80%, transparent)',
          backdropFilter: 'blur(8px)',
          borderBottom: '1px solid var(--border)',
          paddingTop: 'max(0.75rem, env(safe-area-inset-top))',
        }}
      >
        <div className="flex min-w-0 items-center gap-3">
          <Logo variant="light" size={30} showText={false} />
          <div className="min-w-0">
            <div className="truncate text-base font-bold text-heading">
              {TITRES[typePortail] ?? 'Mon espace'}
            </div>
            <div className="truncate text-xs font-semibold text-muted">{user?.name}</div>
          </div>
        </div>
        <div className="flex shrink-0 items-center gap-1">
          <ClocheNotifications />
          <BasculeTheme />
          <Button variant="outline" onClick={handleLogout} aria-label="Se déconnecter" className="!px-3 sm:!px-5">
            <LogOut size={16} className="sm:hidden" />
            <span className="hidden sm:inline">Se déconnecter</span>
          </Button>
        </div>
      </header>
      {/* En bas, la place de la barre de navigation du téléphone (et de l'encoche). */}
      <div className="p-4 pb-[calc(76px+env(safe-area-inset-bottom))] sm:p-6 md:pb-6 lg:p-8">
        <div className="mx-auto w-full max-w-5xl">
          <BanniereInstallation />
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
      </div>
      <BarreNavigationPortail />
    </div>
  )
}
