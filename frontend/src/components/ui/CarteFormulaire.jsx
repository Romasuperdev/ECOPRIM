import { Link } from 'react-router-dom'
import { ArrowLeft, Save, X } from 'lucide-react'

/*
 * Les formulaires de l'application, sur le modèle de parcauto :
 *
 *   Titre de la page (icône)                                   [← Retour]
 *   ┌────────────────────────────────────────────────────────────────────┐
 *   │ ＋ Enregistrer un …            (en-tête bleu dégradé)                │
 *   ├────────────────────────────────────────────────────────────────────┤
 *   │ [récapitulatif des erreurs, en rouge]                                │
 *   │ Onglets soulignés (formulaires longs)                                │
 *   │ Libellé bleu *        Libellé bleu          Libellé bleu             │
 *   │ [champ          ]     [champ          ]     [champ          ]        │
 *   │                                             (✕ Annuler) (💾 Enregistrer) │
 *   └────────────────────────────────────────────────────────────────────┘
 *
 * Les couleurs restent celles de la charte Bleu & Blanc ; c'est la constitution qui est
 * celle de parcauto. Les champs eux-mêmes (`.field`, libellés bleus, astérisque rouge)
 * sont mis en forme par Input / Select / Textarea.
 */

const PILULE = 'inline-flex items-center gap-1.5 rounded-full px-4 py-2 text-sm font-semibold transition hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-60'

/**
 * Les deux boutons de bas de formulaire : Annuler (gris) et Enregistrer (bleu).
 * `libelleAnnuler` / `iconeAnnuler` : le bouton gris peut s'appeler « Réinitialiser »
 * quand il vide le formulaire au lieu de le quitter (écrans d'affectation de la console).
 */
export function BoutonsFormulaire({
  onAnnuler, annulerVers, enCours = false, desactive = false, libelle = 'Enregistrer', type = 'submit', onValider, avant,
  titre, libelleAnnuler = 'Annuler', iconeAnnuler: IconeAnnuler = X,
}) {
  const gris = { background: 'var(--muted-text)', color: '#ffffff' }
  const contenuAnnuler = <><IconeAnnuler size={15} /> {libelleAnnuler}</>
  const annuler = annulerVers
    ? <Link to={annulerVers} className={PILULE} style={gris}>{contenuAnnuler}</Link>
    : <button type="button" onClick={onAnnuler} className={PILULE} style={gris}>{contenuAnnuler}</button>

  return (
    <div className="mt-6 flex flex-wrap items-center justify-end gap-2.5">
      {avant}
      {annuler}
      <button
        type={type} onClick={onValider} disabled={enCours || desactive} title={titre}
        className={PILULE} style={{ background: 'var(--brand-accent)', color: 'var(--brand-accent-ink)' }}
      >
        <Save size={15} /> {enCours ? 'Enregistrement…' : libelle}
      </button>
    </div>
  )
}

/** Récapitulatif des erreurs, en tête du formulaire (`@if($errors->any())` de parcauto). */
export function RecapErreurs({ erreurs }) {
  const liste = Array.isArray(erreurs)
    ? erreurs
    : Object.values(erreurs ?? {}).flat()
  const messages = liste.filter(Boolean)
  if (messages.length === 0) return null

  return (
    <div
      role="alert"
      className="mb-4 rounded-lg px-4 py-2.5 text-sm"
      style={{ background: 'var(--danger-bg)', color: 'var(--danger-text)' }}
    >
      <ul className="list-disc space-y-0.5 pl-4">
        {messages.map((m, i) => <li key={i}>{m}</li>)}
      </ul>
    </div>
  )
}

/** L'en-tête bleu dégradé de la carte. */
export function EnteteCarte({ icon: Icone, titre, onFermer }) {
  return (
    <div
      className="flex items-center justify-between gap-3 px-6 py-4 text-white"
      style={{ background: 'linear-gradient(135deg, var(--brand-surface-2), var(--brand-surface))' }}
    >
      <h2 className="m-0 flex items-center gap-2 text-base font-bold text-white">
        {Icone && <Icone size={17} />} {titre}
      </h2>
      {onFermer && (
        <button type="button" onClick={onFermer} aria-label="Fermer" className="rounded-full p-1 text-white/80 hover:bg-white/15 hover:text-white">
          <X size={18} />
        </button>
      )}
    </div>
  )
}

/**
 * Page de formulaire complète : titre + bouton Retour au-dessus, puis la carte.
 * `titreCarte` est la phrase d'action de l'en-tête (« Enregistrer un enseignant »).
 */
export default function CarteFormulaire({
  icon, titre, retour, onRetour, titreCarte, iconeCarte, erreurs, children, largeur = 'max-w-5xl',
}) {
  const gris = { background: 'var(--muted-text)', color: '#ffffff' }
  return (
    <div className={`mx-auto ${largeur}`}>
      <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
        <h1 className="flex items-center gap-2 text-2xl font-bold text-heading">
          {icon && (() => { const I = icon; return <I size={24} style={{ color: 'var(--brand-accent)' }} /> })()}
          {titre}
        </h1>
        {/* `retour` : une adresse ; `onRetour` : un geste (formulaire ouvert sur une liste). */}
        {retour && (
          <Link to={retour} className={PILULE} style={gris}>
            <ArrowLeft size={15} /> Retour
          </Link>
        )}
        {!retour && onRetour && (
          <button type="button" onClick={onRetour} className={PILULE} style={gris}>
            <ArrowLeft size={15} /> Retour
          </button>
        )}
      </div>

      <div className="overflow-hidden rounded-[20px]" style={{ background: 'var(--surface)', boxShadow: '0 15px 50px rgba(20, 40, 70, .15)' }}>
        <EnteteCarte icon={iconeCarte} titre={titreCarte ?? titre} />
        <div className="p-6 sm:p-8">
          <RecapErreurs erreurs={erreurs} />
          {children}
        </div>
      </div>
    </div>
  )
}

/** Grille de champs : 1 colonne sur téléphone, 2 ou 3 au-delà (`md:grid-cols-3`). */
export function GrilleFormulaire({ colonnes = 3, children }) {
  const classes = { 1: '', 2: 'md:grid-cols-2', 3: 'md:grid-cols-3' }
  return <div className={`grid grid-cols-1 gap-4 ${classes[colonnes] ?? classes[3]}`}>{children}</div>
}

/** Onglets soulignés des formulaires longs (« Informations générales », « Données techniques »…). */
export function OngletsFormulaire({ onglets, actif, onChanger }) {
  return (
    <div className="mb-6 flex flex-wrap gap-1 border-b" style={{ borderColor: 'var(--border)' }} role="tablist">
      {onglets.map((o) => {
        const courant = o.cle === actif
        const Icone = o.icon
        return (
          <button
            key={o.cle} type="button" role="tab" aria-selected={courant}
            onClick={() => onChanger(o.cle)}
            className="-mb-px inline-flex items-center gap-1.5 border-b-2 px-4 py-2 text-sm font-medium transition"
            style={courant
              ? { borderColor: 'var(--brand-accent)', color: 'var(--brand-accent)' }
              : { borderColor: 'transparent', color: 'var(--muted-text)' }}
          >
            {Icone && <Icone size={15} />} {o.libelle}
            {o.erreur && <span className="h-1.5 w-1.5 rounded-full" style={{ background: 'var(--danger)' }} aria-label="contient une erreur" />}
          </button>
        )
      })}
    </div>
  )
}
