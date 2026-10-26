/**
 * Libellé de l'année scolaire pour un sous-titre.
 *
 * Le serveur renvoie l'année telle qu'ECONOMAT la nomme : tantôt « 2026-2027 »,
 * tantôt « Année Scolaire 2026-2027 ». Préfixer aveuglément par « Année » donnait
 * alors « Année Année Scolaire 2026-2027 ».
 */
export function libelleAnnee(annee) {
  const texte = String(annee ?? '').trim()
  if (!texte) return 'Année en cours'

  return /^ann[ée]e\b/i.test(texte) ? texte : `Année ${texte}`
}

/**
 * L'année sans son préfixe (« Année Scolaire 2026-2027 » → « 2026-2027 »), pour les
 * phrases qui disent déjà « l'année » : « Classes de l'année 2026-2027 ».
 */
export function anneeSeule(annee) {
  const texte = String(annee ?? '').trim()
  if (!texte) return 'en cours'

  return texte.replace(/^ann[ée]e(\s+scolaire)?\s*/i, '') || texte
}
