import { useEffect, useRef, useState } from 'react'

/**
 * Nombre qui monte jusqu'à sa valeur à l'affichage (≈ 0,8 s, ralenti en fin de
 * course). Une valeur non numérique (« — », « 12 % ») s'affiche telle quelle.
 */
export default function Compteur({ valeur, duree = 800 }) {
  const cible =
    typeof valeur === 'number' ? valeur : /^\d+$/.test(String(valeur ?? '')) ? Number(valeur) : Number.NaN
  const [affiche, setAffiche] = useState(0)
  const depart = useRef(0)

  useEffect(() => {
    if (!Number.isFinite(cible)) return undefined
    const d = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ? 0 : duree

    const de = depart.current
    const t0 = performance.now()
    let image
    const pas = (t) => {
      const p = d ? Math.min(1, (t - t0) / d) : 1
      const adouci = 1 - (1 - p) ** 3
      setAffiche(Math.round(de + (cible - de) * adouci))
      if (p < 1) image = requestAnimationFrame(pas)
      else depart.current = cible
    }
    image = requestAnimationFrame(pas)
    return () => cancelAnimationFrame(image)
  }, [cible, duree])

  return Number.isFinite(cible) ? affiche.toLocaleString('fr-FR') : valeur
}
