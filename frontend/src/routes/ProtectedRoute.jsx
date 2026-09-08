import { Navigate } from 'react-router-dom'
import { useAuthStore } from '../store/authStore'

// Protège une route selon l'authentification, un rôle requis, ou l'accès à la console.
// « exigeConsole » couvre les trois sortes d'administrateur ; « exigeSuperAdmin » réserve
// une page à la console générale ; « exigeNiveauSociete » la réserve au Super Admin et à
// l'Admin Société, et renvoie l'Admin Établissement (comme l'Admin Société pour
// exigeSuperAdmin) à son accueil de console plutôt que de lui montrer une page qui
// répondra 403. « portailAutorise » réserve une branche de route à un type de compte
// (« staff », ou un portail restreint « enseignant »/« parent ») et renvoie les autres
// vers leur propre accueil plutôt que de les laisser sur une page qui répondra 403 —
// l'autorisation réelle reste décidée par le serveur, ceci n'évite qu'un aller-retour inutile.
export default function ProtectedRoute({
  children, allowedRoles, exigeConsole, exigeSuperAdmin, exigeNiveauSociete, portailAutorise,
}) {
  const { isAuthenticated, roles, peutConsole, superAdmin, niveauSociete, typePortail } = useAuthStore()

  if (!isAuthenticated) return <Navigate to="/login" replace />
  if (portailAutorise && !portailAutorise.includes(typePortail)) {
    return <Navigate to={typePortail === 'staff' ? '/' : '/mon-espace'} replace />
  }
  if (exigeSuperAdmin && !superAdmin) return <Navigate to="/admin" replace />
  if (exigeNiveauSociete && !niveauSociete) return <Navigate to="/admin" replace />
  if (exigeConsole && !peutConsole) return <Navigate to="/" replace />
  if (allowedRoles && !allowedRoles.some((r) => roles.includes(r))) {
    return <Navigate to="/" replace />
  }

  return children
}
