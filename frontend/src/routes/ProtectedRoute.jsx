import { Navigate } from 'react-router-dom'
import { useAuthStore } from '../store/authStore'

// Protège une route selon l'authentification, un rôle requis, ou l'accès à la console.
// « exigeConsole » couvre les deux sortes d'administrateur : Super Admin et Admin Société.
export default function ProtectedRoute({ children, allowedRoles, exigeConsole }) {
  const { isAuthenticated, roles, peutConsole } = useAuthStore()

  if (!isAuthenticated) return <Navigate to="/login" replace />
  if (exigeConsole && !peutConsole) return <Navigate to="/" replace />
  if (allowedRoles && !allowedRoles.some((r) => roles.includes(r))) {
    return <Navigate to="/" replace />
  }

  return children
}
