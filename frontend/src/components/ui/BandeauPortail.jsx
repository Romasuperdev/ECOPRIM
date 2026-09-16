/**
 * Bandeau d'accueil des espaces Enseignant et Parent.
 *
 * Même aplat bleu nuit que le tableau de bord du personnel, et pour la même raison :
 * c'est par cette surface qu'on reconnaît la plateforme. Un parent qui ouvre son
 * espace doit voir tout de suite qu'il est dans l'école de son enfant, pas sur un
 * écran étranger. Elle ne suit donc pas le thème (voir --brand-surface).
 *
 * @param {ReactNode} droite  Contenu aligné à droite — en général un grand chiffre.
 */
export default function BandeauPortail({ titre, sousTitre, droite }) {
  return (
    <div
      className="relative overflow-hidden rounded-2xl p-6 sm:p-7"
      style={{
        background: 'linear-gradient(135deg, var(--brand-surface), var(--brand-surface-2))',
        boxShadow: 'var(--shadow)',
      }}
    >
      <div className="pointer-events-none absolute -right-10 -top-16 h-52 w-52 rounded-full bg-primary-400/30 blur-3xl" />
      <div className="pointer-events-none absolute -bottom-16 left-1/4 h-52 w-52 rounded-full bg-primary-300/20 blur-3xl" />
      <div className="relative flex flex-wrap items-end justify-between gap-4">
        <div className="min-w-0">
          <h1 className="text-xl font-bold text-white sm:text-2xl">{titre}</h1>
          {sousTitre && (
            <p className="mt-1 text-sm font-semibold" style={{ color: 'var(--sidebar-text)' }}>
              {sousTitre}
            </p>
          )}
        </div>
        {droite && <div className="shrink-0 text-right">{droite}</div>}
      </div>
    </div>
  )
}
