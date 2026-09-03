import Button from './Button'

/**
 * Coquille commune aux formulaires de référentiel (années, cycles, niveaux, classes,
 * matières). Elle ne fait que porter la mise en page déjà utilisée ailleurs dans
 * l'application : même carte blanche arrondie, même voile, mêmes boutons — pour que les
 * quatre pages de Paramètres se ressemblent sans que chacune redéclare son gabarit.
 */
export default function ModaleFormulaire({
  titre,
  sousTitre,
  onFermer,
  onValider,
  enCours = false,
  valideDesactive = false,
  libelleValider = 'Enregistrer',
  children,
}) {
  return (
    <div className="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4">
      <div className="w-full max-w-md rounded-2xl bg-white p-6">
        <h2 className="text-lg font-semibold text-slate-800">{titre}</h2>
        {sousTitre && <p className="mt-1 text-sm text-slate-500">{sousTitre}</p>}

        <form
          className="mt-4 space-y-4"
          onSubmit={(e) => { e.preventDefault(); onValider() }}
        >
          {children}

          <div className="flex justify-end gap-2 pt-2">
            <Button type="button" variant="outline" onClick={onFermer}>Annuler</Button>
            <Button type="submit" disabled={enCours || valideDesactive}>
              {enCours ? 'Enregistrement…' : libelleValider}
            </Button>
          </div>
        </form>
      </div>
    </div>
  )
}
