import { useEffect, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import useEchap from '../../hooks/useEchap'
import CarteFormulaire, { BoutonsFormulaire, EnteteCarte } from './CarteFormulaire'

/**
 * Point d'accueil des formulaires « Nouveau / Modifier », posé par les coquilles
 * (Layout, AdminLayout, PortailLayout) juste avant le contenu de la page. Quand il porte
 * un formulaire, la page courante est masquée (règle `.hote-formulaire` d'index.css) mais
 * reste montée : en revenant, la liste retrouve ses filtres, sa page, sa saisie.
 */
export const ID_HOTE_FORMULAIRE = 'hote-formulaire'

/**
 * Le bouton Retour du navigateur ferme le formulaire au lieu de quitter l'écran : une
 * entrée d'historique est ajoutée à l'ouverture (même adresse, même état pour le routeur),
 * et retirée à la fermeture si elle est encore la courante.
 *
 * Le report de ce retrait (setTimeout) laisse au mode strict de React le temps de
 * remonter le composant : démonté puis remonté aussitôt, il garde son entrée.
 *
 * Entrées « mortes » : quitter la page par le menu, formulaire ouvert, empile la nouvelle
 * adresse PAR-DESSUS l'entrée du formulaire, qu'on ne peut plus retirer. Revenir en
 * arrière y ferait halte — même adresse que la liste, rien ne change à l'écran, il
 * faudrait presser Retour deux fois. Une écoute unique la saute donc : une entrée de
 * formulaire qui n'a plus de formulaire monté est franchie dans le sens du déplacement,
 * déduit de l'index que le routeur range dans l'état (`idx`).
 */
const formulairesOuverts = new Set()
let indexConnu = null
let sautInstalle = false

function installerSautEntreesMortes() {
  if (sautInstalle) return
  sautInstalle = true
  indexConnu = window.history.state?.idx ?? null
  window.addEventListener('popstate', () => {
    const etat = window.history.state
    const marque = etat?.formulaireNexora
    if (marque && !formulairesOuverts.has(marque)) {
      // L'entrée morte porte l'index de la page qu'elle doublait : venir d'un index plus
      // grand, c'est reculer.
      const enRecul = indexConnu == null || etat.idx == null || etat.idx < indexConnu
      indexConnu = etat.idx ?? indexConnu
      if (enRecul) window.history.back()
      else window.history.forward()
      return
    }
    indexConnu = etat?.idx ?? indexConnu
  })
}

function useEntreeHistorique(onFermer) {
  const fermer = useRef(onFermer)
  const marque = useRef(null)
  const monte = useRef(false)

  useEffect(() => { fermer.current = onFermer }, [onFermer])

  useEffect(() => {
    installerSautEntreesMortes()
    monte.current = true
    if (!marque.current) {
      marque.current = `formulaire-${Date.now()}-${Math.random().toString(36).slice(2)}`
      window.history.pushState({ ...window.history.state, formulaireNexora: marque.current }, '')
    }
    formulairesOuverts.add(marque.current)
    const surRetour = () => {
      if (window.history.state?.formulaireNexora !== marque.current) fermer.current?.()
    }
    window.addEventListener('popstate', surRetour)

    return () => {
      monte.current = false
      window.removeEventListener('popstate', surRetour)
      setTimeout(() => {
        if (monte.current) return
        formulairesOuverts.delete(marque.current)
        if (window.history.state?.formulaireNexora === marque.current) window.history.back()
        // Parti ailleurs (menu) : on note où l'on est, pour savoir plus tard dans quel sens
        // franchir l'entrée laissée derrière.
        else indexConnu = window.history.state?.idx ?? indexConnu
      }, 0)
    }
  }, [])
}

/**
 * Formulaire de création / modification, sur le modèle des formulaires parcauto.
 *
 * Il s'affiche EN PAGE, à la place du contenu : titre + pastille grise « Retour » en haut,
 * puis la carte aux coins arrondis, en-tête bleu dégradé, boutons Annuler (gris) et
 * Enregistrer (bleu) en pastilles en bas à droite — le gabarit de CarteFormulaire. Hors
 * d'une coquille qui l'accueille, il retombe en fenêtre par-dessus l'écran.
 *
 * Retour, Annuler, Échap et le bouton Retour du navigateur ont le même effet : `onFermer`.
 */
export default function ModaleFormulaire({
  titre,
  // Titre de la page, au-dessus de la carte (« Rôles ») ; à défaut, `titre`.
  titrePage,
  sousTitre,
  icon,
  onFermer,
  onValider,
  enCours = false,
  valideDesactive = false,
  libelleValider = 'Enregistrer',
  // Formulaires plus riches (texte long, liste d'éléments) : une carte plus large.
  large = false,
  // Boutons supplémentaires, avant Annuler (« Vider la case »…).
  avant,
  // Bulle d'aide du bouton Enregistrer (motif d'une désactivation).
  titreValider,
  // `false` : la touche Entrée dans un champ n'enregistre pas — formulaires longs à
  // onglets, où un Entrée réflexe enverrait une saisie inachevée. Seul le clic compte.
  entreeValide = true,
  children,
}) {
  useEchap(onFermer)
  useEntreeHistorique(onFermer)
  const refTitre = useRef(null)

  // Lu une fois, à l'ouverture, PENDANT le premier rendu : la coquille est alors forcément
  // en place, et la liste encore visible. Dès que le formulaire occupe l'hôte, la liste
  // est masquée (display: none) : la hauteur à faire défiler s'effondre, la position est
  // ramenée à zéro, et l'élément qui avait le focus (le bouton « Modifier ») le perd. Les
  // lire dans un effet, c'est-à-dire après coup, ne rendait plus que des valeurs écrasées.
  const [ouverture] = useState(() => {
    const hote = document.getElementById(ID_HOTE_FORMULAIRE)
    // Le contenu défile dans <main> (Layout, AdminLayout), ou avec la fenêtre (portails).
    const defilant = hote ? (hote.closest('main') ?? document.scrollingElement) : null
    return {
      hote,
      defilant,
      position: defilant?.scrollTop ?? 0,
      focusPrecedent: document.activeElement instanceof HTMLElement ? document.activeElement : null,
    }
  })
  const { hote } = ouverture

  // La page s'ouvre en haut, le focus sur son titre (un lecteur d'écran annonce ainsi le
  // formulaire) ; en la fermant, la liste retrouve sa position, et le focus l'élément qui
  // l'avait — s'il existe encore.
  useEffect(() => {
    const { defilant, position, focusPrecedent } = ouverture
    if (!ouverture.hote) return undefined
    defilant?.scrollTo({ top: 0 })
    refTitre.current?.focus({ preventScroll: true })
    return () => {
      defilant?.scrollTo({ top: position })
      if (focusPrecedent?.isConnected) focusPrecedent.focus({ preventScroll: true })
    }
  }, [ouverture])

  const formulaire = (
    <form
      noValidate
      onSubmit={(e) => { e.preventDefault(); onValider() }}
      onKeyDown={entreeValide ? undefined : (e) => {
        if (e.key === 'Enter' && (e.target.tagName === 'INPUT' || e.target.tagName === 'SELECT')) e.preventDefault()
      }}
    >
      {sousTitre && <p className="mb-4 text-sm text-muted">{sousTitre}</p>}
      <div className="space-y-4">{children}</div>
      <BoutonsFormulaire
        onAnnuler={onFermer} enCours={enCours} desactive={valideDesactive} libelle={libelleValider}
        avant={avant} titre={titreValider}
      />
    </form>
  )

  if (hote) {
    // Les événements React traversent un portail jusqu'aux ancêtres du composant (ligne de
    // tableau cliquable…) : comme l'ancienne fenêtre, on les arrête au bord du formulaire.
    return createPortal(
      <div className="animate-fade-up" onClick={(e) => e.stopPropagation()}>
        <CarteFormulaire
          icon={icon} titre={titrePage ?? titre} titreCarte={titre} iconeCarte={icon}
          onRetour={onFermer} largeur={large ? 'max-w-5xl' : 'max-w-3xl'} refTitre={refTitre}
        >
          {formulaire}
        </CarteFormulaire>
      </div>,
      hote,
    )
  }

  return (
    <div
      className="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4"
      onClick={onFermer}
    >
      <div
        className={`flex max-h-[90vh] w-full ${large ? 'max-w-3xl' : 'max-w-lg'} flex-col overflow-hidden rounded-[20px]`}
        style={{ background: 'var(--surface)', boxShadow: '0 15px 50px rgba(20, 40, 70, .25)' }}
        onClick={(e) => e.stopPropagation()}
      >
        <EnteteCarte icon={icon} titre={titre} onFermer={onFermer} />
        <div className="overflow-y-auto p-6">{formulaire}</div>
      </div>
    </div>
  )
}
