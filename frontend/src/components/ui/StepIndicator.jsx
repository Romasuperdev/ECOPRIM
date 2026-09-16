import { Check } from 'lucide-react'

/**
 * Fil d'étapes d'un formulaire en assistant. `etape` est l'index (0-based) de
 * l'étape courante. Les étapes franchies sont cochées, la courante mise en
 * avant, les suivantes en retrait.
 *
 * Le libellé est placé SOUS la pastille, et le trait de liaison relie les
 * pastilles entre elles : le parcours se lit d'un coup d'œil, alors qu'avec les
 * libellés à côté, six étapes formaient une ligne de texte indistincte.
 *
 * Sous `sm`, les libellés des étapes non courantes sont masqués : à six étapes
 * sur un téléphone, ils se chevauchaient. On garde les pastilles — la
 * progression reste lisible — et le libellé de l'étape en cours, le seul utile.
 */
export default function StepIndicator({ etapes, etape, onAller }) {
  return (
    <div className="mb-6 flex items-start">
      {etapes.map((label, index) => {
        const franchie = index < etape
        const courante = index === etape
        const cliquable = franchie && typeof onAller === 'function'

        return (
          <div key={label} className="flex min-w-0 flex-1 items-start last:flex-none">
            <div className="flex min-w-0 flex-col items-center gap-1.5">
              <button
                type="button"
                disabled={!cliquable}
                onClick={cliquable ? () => onAller(index) : undefined}
                title={label}
                className={`flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-xs font-bold transition ${
                  cliquable ? 'cursor-pointer hover:opacity-80' : 'cursor-default'
                }`}
                style={
                  franchie || courante
                    ? { background: 'var(--brand-accent)', color: 'var(--brand-accent-ink)' }
                    : { background: 'var(--surface-2)', color: 'var(--muted-text)' }
                }
              >
                {franchie ? <Check size={15} strokeWidth={3} /> : index + 1}
              </button>
              <span
                className={`max-w-[9rem] truncate px-1 text-center text-xs font-semibold ${
                  courante ? '' : 'hidden sm:block'
                }`}
                style={{ color: index <= etape ? 'var(--heading)' : 'var(--muted-text)' }}
              >
                {label}
              </span>
            </div>
            {index < etapes.length - 1 && (
              <div
                className="mx-1 mt-4 h-0.5 min-w-4 flex-1 rounded-full sm:mx-2"
                style={{
                  background: franchie ? 'var(--brand-accent)' : 'var(--border)',
                }}
              />
            )}
          </div>
        )
      })}
    </div>
  )
}
