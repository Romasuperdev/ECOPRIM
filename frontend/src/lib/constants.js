// Doit correspondre exactement aux noms de rôles seedés côté backend (RoleSeeder)
export const ROLES = {
  SUPER_ADMIN: 'Super Admin',
  DIRECTION: 'Direction',
  ENSEIGNANT: 'Enseignant',
  SECRETAIRE: 'Secretaire',
  PARENT: 'Parent',
}

export const API_BASE_URL = import.meta.env.VITE_API_URL || '/api/v1'
