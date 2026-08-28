import { create } from 'zustand'

// Store global léger : utilisateur connecté + rôles (issus de spatie/laravel-permission)
export const useAuthStore = create((set) => ({
  user: null,
  roles: [],
  isAuthenticated: false,
  setUser: (user) =>
    set({
      user,
      roles: user?.roles ?? [],
      isAuthenticated: !!user,
    }),
  logout: () => set({ user: null, roles: [], isAuthenticated: false }),
}))
