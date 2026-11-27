/** « mardi 24 novembre » à partir de « 2026-11-24 » (midi, pour ne pas glisser d'un jour). */
export function dateLisible(iso) {
  if (!iso) return '—'
  const d = new Date(`${String(iso).slice(0, 10)}T12:00:00`)
  return Number.isNaN(d.getTime()) ? iso : d.toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' })
}
