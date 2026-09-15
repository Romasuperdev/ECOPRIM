import { useState } from 'react'
import { Outlet, useLocation } from 'react-router-dom'
import { Menu } from 'lucide-react'
import AdminSidebar from './AdminSidebar'
import Button from '../ui/Button'
import { useAuthStore } from '../../store/authStore'
import { logout as logoutApi } from '../../features/auth/authApi'
import ErrorBoundary from '../ErrorBoundary'
import SocieteBarre from '../../features/admin/SocieteBarre'

export default function AdminLayout() {
  const location = useLocation()
  const { user, logout, superAdmin } = useAuthStore()
  // Même principe que l'application : sous `lg`, le menu s'ouvre en tiroir.
  const [menuOuvert, setMenuOuvert] = useState(false)

  const handleLogout = async () => {
    await logoutApi()
    logout()
  }

  return (
    <div className="flex h-dvh">
      <div className="hidden lg:flex">
        <AdminSidebar />
      </div>

      {menuOuvert && (
        <div className="fixed inset-0 z-40 lg:hidden">
          <div className="absolute inset-0 bg-black/40" onClick={() => setMenuOuvert(false)} />
          <div className="relative h-full w-64 max-w-[85vw] shadow-xl">
            <AdminSidebar dansTiroir onFermer={() => setMenuOuvert(false)} />
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
          }}
        >
          <div className="flex min-w-0 flex-wrap items-center gap-3">
            <button
              type="button"
              onClick={() => setMenuOuvert(true)}
              title="Ouvrir le menu"
              className="-ml-1 shrink-0 rounded-lg p-1.5 text-heading hover:bg-black/5 lg:hidden"
            >
              <Menu size={20} />
            </button>
            <span className="min-w-0 text-sm text-muted">
              {user?.name} · {superAdmin ? 'Super Admin' : 'Admin Société'}
            </span>
            <SocieteBarre />
          </div>
          <Button variant="outline" className="shrink-0" onClick={handleLogout}>
            <span className="hidden sm:inline">Se déconnecter</span>
            <span className="sm:hidden">Quitter</span>
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
