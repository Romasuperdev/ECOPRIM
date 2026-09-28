import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { AlertTriangle, CheckCircle2, Lock, RotateCcw, Save } from 'lucide-react'
import Button from '../../components/ui/Button'
import MessageErreur from '../../components/ui/MessageErreur'
import MessageRefus from '../../components/ui/MessageRefus'
import { enregistrerCoefficients, fetchGrilleCoefficients } from './coefficientsApi'

const cle = (matiere, niveau) => `${matiere}|${niveau}`

/** Un nombre positif, ou vide. Le reste n'est pas un barème. */
const valide = (v) => v === '' || v === null || (/^\d+([.,]\d{1,2})?$/.test(String(v)) && Number(String(v).replace(',', '.')) >= 0)

const nombre = (v) => (v === '' || v === null ? null : Number(String(v).replace(',', '.')))

/**
 * Barèmes et coefficients des matières.
 *
 * LA GRILLE EST LE DOCUMENT. Les écoles tiennent ce réglage sur une feuille — matières en
 * lignes, niveaux en colonnes — et c'est cette feuille qu'on retrouve ici. Un formulaire
 * ligne à ligne aurait obligé à trente allers-retours pour saisir ce qu'on lit d'un coup.
 *
 * UNE CASE VIDE VEUT DIRE « PAS ENSEIGNÉE À CE NIVEAU », pas « coefficient zéro ». Vider une
 * case retire la surcharge de l'école : la matière retombe sur la grille commune. Un zéro,
 * lui, se saisit et signifie « comptée pour rien ».
 *
 * Ce réglage ne calcule aucune moyenne : c'est ECONOMAT qui calcule. La grille agit en
 * fournissant le barème et le coefficient au moment où une note est saisie ou importée.
 */
export default function CoefficientsPage() {
  const qc = useQueryClient()
  // Seules les cases TOUCHÉES sont gardées en état : le reste se lit dans la réponse du
  // serveur. Recopier toute la grille dans un état aurait demandé de la resynchroniser à
  // chaque rechargement, et c'est ce va-et-vient qui fait diverger l'affiché du réel.
  const [retouches, setRetouches] = useState({})
  const [baremeForce, setBaremeForce] = useState(null)
  const [refus, setRefus] = useState(null)
  const [succes, setSucces] = useState(null)

  const { data, isLoading, error } = useQuery({
    queryKey: ['coefficients-grille'],
    queryFn: fetchGrilleCoefficients,
    // Un refus d'accès ne se répare pas en réessayant trois fois.
    retry: false,
  })

  /** La grille telle que le serveur l'a résolue — ce qui sert de référence au « modifié ». */
  const initiales = useMemo(() => {
    const depart = {}
    for (const matiere of data?.matieres ?? []) {
      for (const niveau of data?.niveaux ?? []) {
        const cellule = data.cellules?.[matiere.code]?.[niveau.code]
        depart[cle(matiere.code, niveau.code)] = {
          coefficient: cellule ? String(cellule.coefficient) : '',
          note_max: cellule?.note_max != null ? String(cellule.note_max) : '',
          surcharge: Boolean(cellule?.surcharge),
        }
      }
    }
    return depart
  }, [data])

  const valeurs = useMemo(
    () => ({ ...initiales, ...retouches }),
    [initiales, retouches],
  )

  const verrouille = Boolean(data?.annee_cloturee)

  // La case n'est cochée d'office que si la grille porte déjà un barème distinct ; une fois
  // que l'utilisateur y touche, c'est son choix qui vaut.
  const baremeDistinct = baremeForce
    ?? Object.values(initiales).some((c) => c.note_max !== '')

  const modifiees = useMemo(
    () => Object.keys(retouches).filter((k) =>
      retouches[k]?.coefficient !== initiales[k]?.coefficient
      || retouches[k]?.note_max !== initiales[k]?.note_max),
    [retouches, initiales],
  )

  const invalides = useMemo(
    () => Object.keys(valeurs).filter((k) =>
      !valide(valeurs[k]?.coefficient) || !valide(valeurs[k]?.note_max)),
    [valeurs],
  )

  const surchargees = useMemo(
    () => Object.keys(initiales).filter((k) => initiales[k]?.surcharge),
    [initiales],
  )

  const champ = (matiere, niveau, quoi, v) => {
    setSucces(null)
    const k = cle(matiere, niveau)
    setRetouches((etat) => ({
      ...etat,
      [k]: { ...(etat[k] ?? initiales[k]), [quoi]: v },
    }))
  }

  const lignesDe = (clefs) => clefs.map((k) => {
    const [matiere_code, niveau_code] = k.split('|')
    return {
      niveau_code,
      matiere_code,
      coefficient: nombre(valeurs[k]?.coefficient),
      note_max: baremeDistinct ? nombre(valeurs[k]?.note_max) : null,
    }
  })

  const enregistrer = useMutation({
    mutationFn: (lignes) => enregistrerCoefficients(lignes),
    onSuccess: (bilan) => {
      setRefus(null)
      setSucces(bilan)
      setRetouches({})
      qc.invalidateQueries({ queryKey: ['coefficients-grille'] })
    },
    onError: (e) => setRefus(
      e?.response?.data?.errors?.etablissement?.[0]
      ?? e?.response?.data?.message
      ?? 'Enregistrement impossible.',
    ),
  })

  // Réinitialiser = vider les cases surchargées. Le serveur supprime alors la ligne de
  // l'école, et la grille commune reprend la main — on ne réécrit pas des valeurs par
  // dessus, ce qui laisserait une surcharge identique au défaut mais toujours présente.
  const reinitialiser = () => {
    if (surchargees.length === 0) return
    if (!window.confirm(
      `Retirer les ${surchargees.length} valeurs propres à votre établissement ? `
      + 'La grille commune reprendra la main.',
    )) return

    enregistrer.mutate(surchargees.map((k) => {
      const [matiere_code, niveau_code] = k.split('|')
      return { niveau_code, matiere_code, coefficient: null, note_max: null }
    }))
  }

  if (isLoading) return <p className="font-medium text-muted">Chargement…</p>

  // Sans données, tout ce qui suit lirait `data.niveaux` : on s'arrête ici et on dit
  // pourquoi, plutôt que de casser l'écran sur une lecture impossible.
  if (!data) {
    return (
      <div>
        <h1 className="mb-6 text-2xl font-bold text-heading">Barèmes et coefficients</h1>
        <MessageErreur erreur={error} permission="Gérer les barèmes et coefficients" />
      </div>
    )
  }

  return (
    <div>
      <header className="mb-6 flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold text-heading">Barèmes et coefficients</h1>
          <p className="mt-1 max-w-3xl text-sm font-medium text-muted">
            Année <strong>{data?.annee}</strong>
            {data?.etablissement_nom && <> — établissement <strong>{data.etablissement_nom}</strong></>}.
            Une case vide signifie que la matière n’est pas enseignée à ce niveau. Ce réglage
            ne calcule aucune moyenne : il fournit le barème et le coefficient au moment où une
            note est saisie ou importée, et c’est ECONOMAT qui calcule à partir de là.
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Button
            variant="outline"
            disabled={verrouille || surchargees.length === 0 || enregistrer.isPending}
            onClick={reinitialiser}
          >
            <RotateCcw size={16} />
            Réinitialiser aux valeurs par défaut
          </Button>
          <Button
            disabled={verrouille || modifiees.length === 0 || invalides.length > 0 || enregistrer.isPending}
            onClick={() => enregistrer.mutate(lignesDe(modifiees))}
          >
            <Save size={16} />
            {enregistrer.isPending
              ? 'Enregistrement…'
              : `Enregistrer${modifiees.length ? ` (${modifiees.length})` : ''}`}
          </Button>
        </div>
      </header>

      <MessageRefus message={refus} onFermer={() => setRefus(null)} />

      {verrouille && (
        <p className="mb-4 flex items-start gap-2 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-medium text-amber-800">
          <Lock size={16} className="mt-0.5 shrink-0" />
          <span>L’année <strong>{data.annee}</strong> est clôturée : consultation seule.</span>
        </p>
      )}

      {!data?.etablissement && (
        <p className="mb-4 flex items-start gap-2 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-medium text-amber-800">
          <AlertTriangle size={16} className="mt-0.5 shrink-0" />
          <span>
            Aucun établissement de travail n’est choisi : vous voyez la grille commune, mais
            l’enregistrement sera refusé. Choisissez un établissement dans l’en-tête.
          </span>
        </p>
      )}

      {invalides.length > 0 && (
        <p className="mb-4 flex items-start gap-2 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-700">
          <AlertTriangle size={16} className="mt-0.5 shrink-0" />
          <span>{invalides.length} case{invalides.length > 1 ? 's' : ''} ne contien{invalides.length > 1 ? 'nent' : 't'} pas un nombre positif.</span>
        </p>
      )}

      {succes && (
        <p
          className="mb-4 flex items-start gap-2 rounded-xl border px-4 py-3 text-sm font-medium"
          style={{
            borderColor: 'var(--success-border)',
            background: 'var(--success-bg)',
            color: 'var(--success-text)',
          }}
        >
          <CheckCircle2 size={16} className="mt-0.5 shrink-0" />
          <span>
            {succes.enregistrees} valeur{succes.enregistrees > 1 ? 's' : ''} enregistrée
            {succes.enregistrees > 1 ? 's' : ''}
            {succes.supprimees > 0 && <>, {succes.supprimees} surcharge{succes.supprimees > 1 ? 's' : ''} retirée{succes.supprimees > 1 ? 's' : ''}</>}.
          </span>
        </p>
      )}

      <label className="mb-3 inline-flex cursor-pointer items-center gap-2 text-sm font-semibold text-heading">
        <input
          type="checkbox"
          checked={baremeDistinct}
          onChange={(e) => setBaremeForce(e.target.checked)}
          className="size-4 accent-[var(--brand-accent)]"
        />
        Barème distinct du coefficient
        <span className="font-medium text-muted">
          (pour une école qui note tout sur 20 puis pondère)
        </span>
      </label>

      {data.matieres.length === 0 ? (
        <p className="rounded-xl border border-slate-200 bg-card px-4 py-10 text-center text-sm font-medium text-muted">
          Aucune matière au référentiel. Créez-les depuis l’écran Matières, ou posez la grille
          commune avec la commande <code>coefficients:import</code>.
        </p>
      ) : (
        <div className="overflow-x-auto rounded-xl border border-slate-200 bg-card">
          <table className="w-full text-left text-sm">
            <thead className="border-b border-slate-200 bg-slate-50 text-muted">
              <tr>
                <th className="sticky left-0 z-10 bg-slate-50 px-4 py-3 font-bold">Matière</th>
                {data.niveaux.map((n) => (
                  <th key={n.code} className="px-3 py-3 text-center font-bold" title={n.libelle}>
                    {n.code}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {data.matieres.map((m) => (
                <tr key={m.code}>
                  <td className="sticky left-0 z-10 bg-card px-4 py-2 font-semibold text-heading">
                    {m.libelle}
                  </td>
                  {data.niveaux.map((n) => {
                    const k = cle(m.code, n.code)
                    const cellule = valeurs[k] ?? {}
                    const surcharge = initiales[k]?.surcharge
                    const faux = !valide(cellule.coefficient) || !valide(cellule.note_max)

                    return (
                      <td key={n.code} className="px-1.5 py-1.5 align-top">
                        <input
                          type="text"
                          inputMode="decimal"
                          value={cellule.coefficient ?? ''}
                          disabled={verrouille}
                          onChange={(e) => champ(m.code, n.code, 'coefficient', e.target.value)}
                          aria-label={`${m.libelle} en ${n.libelle}`}
                          // Le liseré signale une valeur propre à l'établissement, par
                          // opposition à la grille commune : sans lui, on ne sait pas ce
                          // qu'un « Réinitialiser » effacerait.
                          className="w-16 rounded-lg border px-2 py-1.5 text-center text-sm font-semibold"
                          style={{
                            background: 'var(--surface)',
                            color: 'var(--text)',
                            borderColor: faux
                              ? 'var(--danger)'
                              : surcharge ? 'var(--brand-accent)' : 'var(--border)',
                            borderWidth: surcharge || faux ? '2px' : '1px',
                          }}
                          title={surcharge ? 'Valeur propre à votre établissement' : 'Grille commune'}
                        />
                        {baremeDistinct && (
                          <input
                            type="text"
                            inputMode="decimal"
                            placeholder="sur"
                            value={cellule.note_max ?? ''}
                            disabled={verrouille}
                            onChange={(e) => champ(m.code, n.code, 'note_max', e.target.value)}
                            aria-label={`Barème de ${m.libelle} en ${n.libelle}`}
                            className="mt-1 w-16 rounded-lg border px-2 py-1 text-center text-xs font-semibold"
                            style={{
                              background: 'var(--surface-2)',
                              color: 'var(--text)',
                              borderColor: 'var(--border)',
                            }}
                          />
                        )}
                      </td>
                    )
                  })}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      <p className="mt-3 text-xs font-semibold text-muted">
        Bordure bleue : valeur propre à votre établissement, qui surcharge la grille commune.
        Vider une case retire cette surcharge. Un 0 se saisit et signifie « comptée pour rien ».
      </p>
    </div>
  )
}
