import { useEffect } from 'react'

/**
 * Ferme une modale à la touche Échap — même geste que le bouton Annuler, juste un
 * raccourci clavier. `onFermer` peut être `undefined` (modale non ouverte) : rien ne
 * s'écoute alors.
 */
export default function useEchap(onFermer) {
  useEffect(() => {
    if (!onFermer) return undefined

    const surEchap = (e) => { if (e.key === 'Escape') onFermer() }
    document.addEventListener('keydown', surEchap)

    return () => document.removeEventListener('keydown', surEchap)
  }, [onFermer])
}
