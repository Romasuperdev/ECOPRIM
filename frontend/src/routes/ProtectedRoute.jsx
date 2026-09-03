import { Navigate } from 'react-router-dom'
import { useAuthStore } from '../store/authStore'

// Protège une route selon l'authentification, un rôle requis, ou l'accès à la console.
// « exigeConsole » couvre les deux sortes d'administrateur ; « exigeSuperAdmin » réserve
// une page à la console générale, et renvoie l'Admin Société à son accueil de console
// plutôt que de lui montrer une page qui répondra 403.
export default function ProtectedRoute({ children, allowedRoles, exigeConsole, exigeSuperAdmin }) {
  const { isAuthenticated, roles, peutConsole, superAdmin } = useAuthStore()

  if (!isAuthenticated) return <Navigate to="/login" replace />
  if (exigeSuperAdmin && !superAdmin) return <Navigate to="/admin" replace />
  if (exigeConsole && !peutConsole) return <Navigate to="/" replace />
  if (allowedRoles && !allowedRoles.some((r) => roles.includes(r))) {
    return <Navigate to="/" replace />
  }

  return children
}
