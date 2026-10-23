import { MessageFlash } from './kit'

/*
 * Écrans « formulaire à gauche, liste à droite » de la console parcauto (les deux écrans
 * d'affectation) : une grille 5/7 de deux cartes à en-tête dégradé, sous un seul message.
 *
 * CarteConsole (kit) porte son propre message : deux cartes côte à côte l'auraient affiché
 * deux fois. D'où cette carte-ci, même en-tête, sans message.
 */

export function GrilleConsole({ gauche, droite }) {
  return (
    <div className="mx-auto max-w-6xl">
      <MessageFlash />
      <div className="grid grid-cols-1 items-start gap-4 lg:grid-cols-12">
        <div className="lg:col-span-5">{gauche}</div>
        <div className="lg:col-span-7">{droite}</div>
      </div>
    </div>
  )
}

export function CarteGrille({ icon: Icone, titre, children }) {
  return (
    <div className="overflow-hidden rounded-2xl shadow-lg" style={{ background: 'var(--surface)' }}>
      <div
        className="px-5 py-4 font-bold text-white"
        style={{ background: 'linear-gradient(135deg, var(--brand-surface-2), var(--brand-surface))' }}
      >
        <h2 className="m-0 flex items-center gap-2 text-base font-bold text-white">
          {Icone && <Icone size={18} />} {titre}
        </h2>
      </div>
      <div className="p-5">{children}</div>
    </div>
  )
}

/** Boutons bas de formulaire : Réinitialiser à gauche, Enregistrer à droite. */
export function BoutonsFormulaire({ onReinitialiser, enCours, desactive, libelle = 'Enregistrer l’affectation' }) {
  return (
    <div className="flex justify-between gap-2 border-t pt-4" style={{ borderColor: 'var(--border)' }}>
      <button
        type="button" onClick={onReinitialiser}
        className="rounded-[var(--radius)] border px-4 py-2 text-sm font-bold"
        style={{ borderColor: 'var(--border)', color: 'var(--text)', background: 'var(--surface)' }}
      >
        Réinitialiser
      </button>
      <button
        type="submit" disabled={enCours || desactive}
        className="rounded-[var(--radius)] px-4 py-2 text-sm font-bold disabled:opacity-60"
        style={{ background: 'var(--brand-accent)', color: 'var(--brand-accent-ink)' }}
      >
        {enCours ? 'Enregistrement…' : libelle}
      </button>
    </div>
  )
}
