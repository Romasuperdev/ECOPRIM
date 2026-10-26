import { useEffect } from 'react'

/**
 * Ferme une modale à la touche Échap — même geste que le bouton Annuler, juste un
 * raccourci clavier. `onFermer` peut être `undefined` (modale non ouverte) : rien ne
 * s'écoute alors.
 *
 * Un Échap déjà « consommé » (`defaultPrevented`) est ignoré : une liste déroulante
 * ouverte dans le formulaire (SelecteurMultiple) se referme seule, sans emporter le
 * formulaire avec elle. Elle écoute en phase de capture, donc avant ce hook.
 */
export default function useEchap(onFermer) {
  useEffect(() => {
    if (!onFermer) return undefined

    const surEchap = (e) => {
      if (e.key === 'Escape' && !e.defaultPrevented) onFermer()
    }
    document.addEventListener('keydown', surEchap)

    return () => document.removeEventListener('keydown', surEchap)
  }, [onFermer])
}
