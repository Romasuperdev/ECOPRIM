/**
 * Mouvements de l'interface — posés une fois pour toute l'application.
 *
 * Plutôt que d'animer écran par écran (plus de 50 pages), un seul observateur
 * regarde ce qui apparaît dans la page et lui donne une entrée douce :
 *   — les cartes (`.card`) et les blocs marqués `data-anime` glissent et
 *     apparaissent, en cascade (40 ms d'écart, plafonné pour ne jamais faire
 *     attendre) ;
 *   — les lignes de tableau apparaissent en fondu, sans déplacement, pour que
 *     la grille ne danse pas.
 * S'y ajoute une onde au clic sur les boutons.
 *
 * Tout est coupé si le système demande moins d'animations.
 */

const ECART_MS = 40
const DELAI_MAX_MS = 360
const LIGNES_MAX = 25

const reduit = () => window.matchMedia?.('(prefers-reduced-motion: reduce)').matches

function retirerApresAnimation(el, classe) {
  // La classe est retirée une fois jouée : une animation restée « collée » garde
  // un `transform`, qui fausserait la position des éléments fixes contenus
  // (fenêtres modales, menus) — et rejouerait si l'élément était déplacé.
  // Retirée aussi si l'animation est interrompue (élément masqué en cours de route :
  // liste cachée par un formulaire), sans quoi elle rejouerait à son retour. Seule SA
  // propre animation compte : celles des descendants (onde d'un bouton) remontent
  // jusqu'ici et ne doivent pas la clore avant l'heure.
  const finir = (e) => {
    if (e.target !== el) return
    el.removeEventListener('animationend', finir)
    el.removeEventListener('animationcancel', finir)
    el.classList.remove(classe)
    el.style.removeProperty('--delai')
  }
  el.addEventListener('animationend', finir)
  el.addEventListener('animationcancel', finir)
}

function animer(elements, classe) {
  elements.forEach((el, i) => {
    el.dataset.vu = '1'
    el.style.setProperty('--delai', `${Math.min(i * ECART_MS, DELAI_MAX_MS)}ms`)
    el.classList.add(classe)
    retirerApresAnimation(el, classe)
  })
}

function collecter(racines) {
  const blocs = new Set()
  const lignes = new Set()

  for (const racine of racines) {
    if (!(racine instanceof HTMLElement)) continue
    // Le menu latéral reste immobile : il est là avant et après chaque page.
    if (racine.closest('aside')) continue

    const candidats = [racine, ...racine.querySelectorAll('.card, [data-anime], tbody tr')]
    for (const el of candidats) {
      if (el.dataset.vu || el.closest('aside')) continue
      if (el.matches('tbody tr')) lignes.add(el)
      else if (el.matches('.card, [data-anime]')) blocs.add(el)
    }
  }

  // Une carte dans une carte qui entre déjà : une seule animation suffit.
  const blocsRacines = [...blocs].filter((el) => {
    const parent = el.parentElement?.closest('.card, [data-anime]')
    return !parent || !blocs.has(parent)
  })
  ;[...blocs].forEach((el) => {
    el.dataset.vu = '1'
  })

  return { blocs: blocsRacines, lignes: [...lignes].slice(0, LIGNES_MAX), autresLignes: [...lignes].slice(LIGNES_MAX) }
}

function observerEntrees() {
  let enAttente = []
  let prevu = false

  const traiter = () => {
    prevu = false
    const racines = enAttente
    enAttente = []
    if (reduit()) return

    const { blocs, lignes, autresLignes } = collecter(racines)
    animer(blocs, 'anime-entree')
    animer(lignes, 'anime-ligne')
    autresLignes.forEach((el) => {
      el.dataset.vu = '1'
    })
  }

  const observateur = new MutationObserver((mutations) => {
    for (const m of mutations) enAttente.push(...m.addedNodes)
    if (!prevu && enAttente.length) {
      prevu = true
      // Regroupe tout ce qu'un rendu React ajoute en une seule cascade.
      requestAnimationFrame(traiter)
    }
  })

  const racine = document.getElementById('root') ?? document.body
  observateur.observe(racine, { childList: true, subtree: true })
  enAttente.push(racine)
  requestAnimationFrame(traiter)
}

// Boutons portant au moins une onde : ce qu'il faudra leur rendre quand la DERNIÈRE se
// termine. Deux clics rapprochés font deux ondes ; la première, en finissant, ne doit pas
// retirer le `position` / `overflow` dont la seconde a encore besoin.
const ondesEnCours = new WeakMap()

function brancherOnde() {
  document.addEventListener(
    'pointerdown',
    (e) => {
      if (reduit() || e.button !== 0) return
      const bouton = e.target.closest?.('button, [role="button"], a.card')
      if (!bouton || bouton.disabled || bouton.closest('[data-sans-onde]')) return

      const zone = bouton.getBoundingClientRect()
      // Trop petit (icône seule) : l'onde y ferait une tache plutôt qu'un effet.
      if (zone.width < 44 || zone.height < 24) return

      let suivi = ondesEnCours.get(bouton)
      if (!suivi) {
        const style = getComputedStyle(bouton)
        suivi = {
          nombre: 0,
          restaurerPosition: style.position === 'static',
          restaurerOverflow: style.overflow !== 'hidden',
        }
        if (suivi.restaurerPosition) bouton.style.position = 'relative'
        if (suivi.restaurerOverflow) bouton.style.overflow = 'hidden'
        ondesEnCours.set(bouton, suivi)
      }
      suivi.nombre += 1

      const taille = Math.max(zone.width, zone.height) * 2.2
      const onde = document.createElement('span')
      onde.className = 'onde'
      onde.style.width = onde.style.height = `${taille}px`
      onde.style.left = `${e.clientX - zone.left - taille / 2}px`
      onde.style.top = `${e.clientY - zone.top - taille / 2}px`
      bouton.appendChild(onde)

      // Fin normale OU interruption (bouton masqué pendant l'onde : le clic a ouvert un
      // formulaire qui cache la liste). Sans cela, l'onde resterait dans le bouton et
      // rejouerait quand il réapparaît. `animationcancel` n'étant pas émis par tous les
      // navigateurs, un minuteur un peu plus long que l'animation (0,55 s) fait filet.
      let fini = false
      const finir = () => {
        if (fini) return
        fini = true
        clearTimeout(filet)
        onde.removeEventListener('animationend', finir)
        onde.removeEventListener('animationcancel', finir)
        onde.remove()
        suivi.nombre -= 1
        if (suivi.nombre > 0) return
        ondesEnCours.delete(bouton)
        if (suivi.restaurerPosition) bouton.style.removeProperty('position')
        if (suivi.restaurerOverflow) bouton.style.removeProperty('overflow')
      }
      const filet = setTimeout(finir, 800)
      onde.addEventListener('animationend', finir)
      onde.addEventListener('animationcancel', finir)
    },
    { passive: true }
  )
}

let demarre = false

export function demarrerMouvements() {
  if (demarre || typeof window === 'undefined') return
  demarre = true
  observerEntrees()
  brancherOnde()
}
