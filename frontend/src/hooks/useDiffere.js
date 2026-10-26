import { useEffect, useState } from 'react'

/**
 * Laisse la frappe se poser avant d'interroger le serveur : la valeur rendue ne suit
 * `valeur` qu'après `delai` ms sans changement. Une recherche ne part donc qu'une fois,
 * à la fin de la saisie, au lieu d'une requête (et d'un clignotement) par touche.
 */
export default function useDiffere(valeur, delai = 300) {
  const [differee, setDifferee] = useState(valeur)
  useEffect(() => {
    const t = setTimeout(() => setDifferee(valeur), delai)
    return () => clearTimeout(t)
  }, [valeur, delai])
  return differee
}
