import { create } from 'zustand'

// Store global léger : utilisateur connecté, rôles, et accès console.
// peutConsole / superAdmin ne servent qu'à l'affichage et au routage : l'autorisation
// réelle est décidée par le serveur à chaque appel.
export const useAuthStore = create((set) => ({
  user: null,
  roles: [],
  isAuthenticated: false,
  peutConsole: false,
  superAdmin: false,
  setUser: (user) =>
    set({
      user,
      roles: user?.roles ?? [],
      isAuthenticated: !!user,
      peutConsole: Boolean(user?.peut_console),
      superAdmin: Boolean(user?.super_admin),
    }),
  logout: () => set({ user: null, roles: [], isAuthenticated: false, peutConsole: false, superAdmin: false }),
}))
