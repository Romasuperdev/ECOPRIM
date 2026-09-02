import { Check } from 'lucide-react'

/**
 * Fil d'étapes d'un formulaire en assistant. `etape` est l'index (0-based) de l'étape courante.
 * Les étapes franchies sont cochées, la courante encerclée, les suivantes grisées.
 */
export default function StepIndicator({ etapes, etape, onAller }) {
  return (
    <div className="mb-6 flex flex-wrap items-center gap-y-2">
      {etapes.map((label, index) => {
        const franchie = index < etape
        const courante = index === etape
        const cliquable = franchie && typeof onAller === 'function'

        return (
          <div key={label} className="flex flex-1 items-center last:flex-none">
            <button
              type="button"
              disabled={!cliquable}
              onClick={cliquable ? () => onAller(index) : undefined}
              className={`flex items-center gap-2 text-left ${cliquable ? 'cursor-pointer hover:opacity-80' : 'cursor-default'}`}
            >
              <span
                className={`flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-xs font-semibold ${
                  franchie
                    ? 'bg-primary-600 text-white'
                    : courante
                      ? 'bg-primary-100 text-primary-700 ring-2 ring-primary-500'
                      : 'bg-slate-100 text-slate-400'
                }`}
              >
                {franchie ? <Check size={14} /> : index + 1}
              </span>
              <span className={`text-sm font-medium ${index <= etape ? 'text-slate-800' : 'text-slate-400'}`}>
                {label}
              </span>
            </button>
            {index < etapes.length - 1 && <div className="mx-3 h-px flex-1 bg-slate-200" />}
          </div>
        )
      })}
    </div>
  )
}
