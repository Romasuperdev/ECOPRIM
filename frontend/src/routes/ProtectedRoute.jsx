import { Navigate } from 'react-router-dom'
import { useAuthStore } from '../store/authStore'

// Protège une route selon l'authentification, un rôle requis, ou l'accès à la console.
// « exigeConsole » couvre les trois sortes d'administrateur ; « exigeSuperAdmin » réserve
// une page à la console générale ; « exigeNiveauSociete » la réserve au Super Admin et à
// l'Admin Société, et renvoie l'Admin Établissement (comme l'Admin Société pour
// exigeSuperAdmin) à son accueil de console plutôt que de lui montrer une page qui
// répondra 403.
export default function ProtectedRoute({ children, allowedRoles, exigeConsole, exigeSuperAdmin, exigeNiveauSociete }) {
  const { isAuthenticated, roles, peutConsole, superAdmin, niveauSociete } = useAuthStore()

  if (!isAuthenticated) return <Navigate to="/login" replace />
  if (exigeSuperAdmin && !superAdmin) return <Navigate to="/admin" replace />
  if (exigeNiveauSociete && !niveauSociete) return <Navigate to="/admin" replace />
  if (exigeConsole && !peutConsole) return <Navigate to="/" replace />
  if (allowedRoles && !allowedRoles.some((r) => roles.includes(r))) {
    return <Navigate to="/" replace />
  }

  return children
}
