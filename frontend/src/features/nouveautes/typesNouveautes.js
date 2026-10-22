/**
 * Pastilles de type d'élément. La charte n'a pas de vert : Nouveauté en bleu,
 * Amélioration en neutre, Correction en rouge discret.
 */
export const TYPES_ELEMENT = {
  nouveaute: { libelle: 'Nouveauté', style: { background: 'var(--info-bg)', color: 'var(--info-text)' } },
  amelioration: { libelle: 'Amélioration', style: { background: 'var(--surface-2)', color: 'var(--text)' } },
  correction: { libelle: 'Correction', style: { background: 'var(--danger-bg)', color: 'var(--danger-text)' } },
}

/** « 2026-10-22 » → « 22 octobre 2026 », sans passer par l'UTC (pas de décalage d'un jour). */
export const formaterDate = (iso) => {
  if (!iso) return ''
  const [a, m, j] = iso.split('-').map(Number)
  return new Date(a, m - 1, j).toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' })
}
