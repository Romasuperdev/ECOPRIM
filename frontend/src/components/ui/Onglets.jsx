/**
 * Barre d'onglets. Les deux portails en avaient chacun une, écrite à la main et
 * légèrement différente ; elles n'en font plus qu'une.
 *
 * Onglets en pastilles plutôt qu'un soulignement : l'espace Parent en compte neuf,
 * qui défilent horizontalement sur un téléphone. Un trait sous l'onglet actif se
 * perd dès qu'il sort du champ ; une pastille pleine se repère du coin de l'œil.
 *
 * @param {{cle: string, libelle: string}[]} onglets
 */
export default function Onglets({ onglets, actif, onChanger, className = '' }) {
  return (
    <div
      className={`mb-6 flex gap-1 overflow-x-auto rounded-full p-1 ${className}`}
      style={{ background: 'var(--surface-2)' }}
      role="tablist"
    >
      {onglets.map((o) => {
        const courant = o.cle === actif
        return (
          <button
            key={o.cle}
            type="button"
            role="tab"
            aria-selected={courant}
            onClick={() => onChanger(o.cle)}
            className="shrink-0 whitespace-nowrap rounded-full px-3.5 py-1.5 text-sm font-bold transition"
            style={
              courant
                ? { background: 'var(--brand-accent)', color: 'var(--brand-accent-ink)' }
                : { color: 'var(--muted-text)' }
            }
          >
            {o.libelle}
          </button>
        )
      })}
    </div>
  )
}
