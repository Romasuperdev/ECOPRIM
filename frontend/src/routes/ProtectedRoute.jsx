import { Navigate } from 'react-router-dom'
import { useAuthStore } from '../store/authStore'

// Protège une route selon l'authentification et (optionnellement) le rôle requis
export default function ProtectedRoute({ children, allowedRoles }) {
  const { isAuthenticated, roles } = useAuthStore()

  if (!isAuthenticated) return <Navigate to="/login" replace />
  if (allowedRoles && !allowedRoles.some((r) => roles.includes(r))) {
    return <Navigate to="/" replace />
  }

  return children
}
