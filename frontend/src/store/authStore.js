import { create } from 'zustand'

// Store global léger : utilisateur connecté, rôles, et accès console.
// peutConsole / superAdmin ne servent qu'à l'affichage et au routage : l'autorisation
// réelle est décidée par le serveur à chaque appel.
const VIDE = {
  user: null,
  roles: [],
  isAuthenticated: false,
  peutConsole: false,
  superAdmin: false,
  adminSociete: false,
  adminEtablissement: false,
  // Niveau société : Super Admin ou Admin Société — gère établissements, utilisateurs,
  // catalogue de rôles. L'Admin Établissement (niveauSociete = false) ne fait qu'affecter
  // un rôle existant à un utilisateur de son établissement.
  niveauSociete: false,
}

export const useAuthStore = create((set) => ({
  ...VIDE,
  setUser: (user) =>
    set({
      user,
      roles: user?.roles ?? [],
      isAuthenticated: !!user,
      peutConsole: Boolean(user?.peut_console),
      superAdmin: Boolean(user?.super_admin),
      adminSociete: Boolean(user?.admin_societe),
      adminEtablissement: Boolean(user?.admin_etablissement),
      niveauSociete: Boolean(user?.super_admin || user?.admin_societe),
    }),
  logout: () => set({ ...VIDE }),
}))
