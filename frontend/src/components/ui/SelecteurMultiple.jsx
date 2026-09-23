import { useEffect, useRef, useState } from 'react'
import { Check, ChevronDown, X } from 'lucide-react'

/**
 * Liste déroulante à cases à cocher.
 *
 * Un `<select multiple>` natif a été écarté : il oblige à garder Ctrl enfoncé pour
 * choisir plusieurs valeurs — personne ne le devine —, et il n'affiche pas ce qui est
 * retenu une fois refermé. Ici, chaque ligne se coche, et les valeurs retenues restent
 * visibles sous le champ, retirables d'un clic.
 *
 * @param {{valeur: string, libelle: string}[]} options
 * @param {string[]} selection   Valeurs cochées.
 * @param {(valeurs: string[]) => void} onChange
 */
export default function SelecteurMultiple({
  label,
  options = [],
  selection = [],
  onChange,
  placeholder = '— Choisir —',
  error,
  id,
}) {
  const [ouvert, setOuvert] = useState(false)
  const boite = useRef(null)

  // Fermeture au clic extérieur et à Échap : même comportement que les formulaires
  // en fenêtre, pour ne pas surprendre.
  useEffect(() => {
    if (!ouvert) return undefined

    const auClic = (e) => { if (boite.current && !boite.current.contains(e.target)) setOuvert(false) }
    const auClavier = (e) => { if (e.key === 'Escape') setOuvert(false) }
    document.addEventListener('mousedown', auClic)
    document.addEventListener('keydown', auClavier)

    return () => {
      document.removeEventListener('mousedown', auClic)
      document.removeEventListener('keydown', auClavier)
    }
  }, [ouvert])

  const basculer = (valeur) =>
    onChange(selection.includes(valeur) ? selection.filter((v) => v !== valeur) : [...selection, valeur])

  const libelleDe = (valeur) => options.find((o) => o.valeur === valeur)?.libelle ?? valeur

  return (
    <div className="space-y-1">
      {label && (
        <label htmlFor={id} className="mb-1.5 block text-sm font-bold text-heading">
          {label}
        </label>
      )}

      <div className="relative" ref={boite}>
        <button
          id={id}
          type="button"
          onClick={() => setOuvert((v) => !v)}
          className={`field field--action flex items-center justify-between text-left ${error ? '!border-red-400' : ''}`}
        >
          <span className={selection.length ? 'text-heading' : 'text-muted'}>
            {selection.length === 0
              ? placeholder
              : `${selection.length} sélectionnée${selection.length > 1 ? 's' : ''}`}
          </span>
          <ChevronDown
            size={16}
            className={`absolute right-4 top-1/2 -translate-y-1/2 transition-transform ${ouvert ? 'rotate-180' : ''}`}
          />
        </button>

        {ouvert && (
          <div
            className="absolute z-30 mt-1 max-h-60 w-full overflow-y-auto rounded-2xl p-1.5 shadow-xl"
            style={{ background: 'var(--surface)', border: '1px solid var(--border)' }}
          >
            {options.length === 0 && (
              <p className="px-3 py-3 text-sm font-medium text-muted">Aucune option disponible.</p>
            )}
            {options.map((o) => {
              const coche = selection.includes(o.valeur)
              return (
                <button
                  key={o.valeur}
                  type="button"
                  onClick={() => basculer(o.valeur)}
                  className="flex w-full items-center gap-2.5 rounded-xl px-3 py-2 text-left text-sm font-medium hover:bg-slate-100"
                >
                  <span
                    className="flex h-4 w-4 shrink-0 items-center justify-center rounded"
                    style={{
                      background: coche ? 'var(--brand-accent)' : 'var(--surface-2)',
                      border: coche ? 'none' : '1.5px solid var(--border)',
                      color: 'var(--brand-accent-ink)',
                    }}
                  >
                    {coche && <Check size={12} strokeWidth={3.5} />}
                  </span>
                  <span className="text-heading">{o.libelle}</span>
                </button>
              )
            })}
          </div>
        )}
      </div>

      {/* Ce qui est retenu reste sous les yeux, même la liste refermée. */}
      {selection.length > 0 && (
        <div className="flex flex-wrap gap-1.5 pt-1">
          {selection.map((v) => (
            <span
              key={v}
              className="inline-flex items-center gap-1 rounded-full bg-primary-100 px-2.5 py-1 text-xs font-bold text-primary-800"
            >
              {libelleDe(v)}
              <button
                type="button"
                onClick={() => basculer(v)}
                title={`Retirer ${libelleDe(v)}`}
                className="rounded-full hover:opacity-70"
              >
                <X size={12} strokeWidth={3} />
              </button>
            </span>
          ))}
        </div>
      )}

      {error && <p className="text-sm text-red-600">{error}</p>}
    </div>
  )
}
