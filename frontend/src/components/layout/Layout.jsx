import { Outlet, useLocation } from 'react-router-dom'
import Sidebar from './Sidebar'
import Button from '../ui/Button'
import { useAuthStore } from '../../store/authStore'
import { logout as logoutApi } from '../../features/auth/authApi'
import ContexteBarre from '../../features/contexte/ContexteBarre'
import ErrorBoundary from '../ErrorBoundary'

export default function Layout() {
  const location = useLocation()
  const { user, roles, logout } = useAuthStore()

  const handleLogout = async () => {
    await logoutApi()
    logout()
  }

  return (
    <div className="flex h-screen">
      <Sidebar />
      <main className="flex-1 min-w-0 overflow-y-auto">
        <div
          className="sticky top-0 z-20 flex items-center justify-between px-4 py-3 sm:px-6 lg:px-8"
          style={{
            background: 'color-mix(in srgb, var(--bg) 80%, transparent)',
            backdropFilter: 'blur(8px)',
            borderBottom: '1px solid var(--border)',
          }}
        >
          <div className="flex flex-wrap items-center gap-x-3 gap-y-2">
            <div className="text-sm font-medium text-heading">
              {user?.name}
              {roles.length > 0 && (
                <span className="ml-1.5 text-xs font-normal text-muted">{roles.join(', ')}</span>
              )}
            </div>
            <ContexteBarre />
          </div>
          <Button variant="outline" onClick={handleLogout}>
            Se déconnecter
          </Button>
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
