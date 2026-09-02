import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Lock, Plus, Printer, Trash2 } from 'lucide-react'
import Button from '../../components/ui/Button'
import Select from '../../components/ui/Select'
import {
  createCreneau,
  deleteCreneau,
  fetchClassesEtMatieres,
  fetchEmploi,
  fetchReferentielsEmploi,
  updateCreneau,
} from './emploiApi'
import { imprimerEmploiDuTemps, imprimerListeClasse } from '../impressions/impressionApi'

export default function EmploiDuTempsPage() {
  const qc = useQueryClient()
  const [classe, setClasse] = useState('')
  const [cellule, setCellule] = useState(null)   // { jour, heure, creneau? }
  const [saisie, setSaisie] = useState({ matiere: '', salle: '' })
  const [erreur, setErreur] = useState(null)

  const { data: ref } = useQuery({ queryKey: ['emploi-referentiels'], queryFn: fetchReferentielsEmploi })
  const { data: listes } = useQuery({ queryKey: ['classes-matieres'], queryFn: fetchClassesEtMatieres })
  const { data: emploi, isLoading } = useQuery({
    queryKey: ['emploi', classe],
    queryFn: () => fetchEmploi(classe),
    enabled: Boolean(classe),
  })

  const rafraichir = () => qc.invalidateQueries({ queryKey: ['emploi'] })
  const fermer = () => { setCellule(null); setErreur(null); setSaisie({ matiere: '', salle: '' }) }

  const enregistrer = useMutation({
    mutationFn: () => {
      const charge = {
        jour: cellule.jour, heure: cellule.heure, classe,
        matiere: saisie.matiere, salle: saisie.salle || null,
      }
      return cellule.creneau ? updateCreneau(cellule.creneau.id, charge) : createCreneau(charge)
    },
    onSuccess: () => { rafraichir(); fermer() },
    onError: (e) => {
      const err = e?.response?.data?.errors
      setErreur(err ? Object.values(err).flat()[0] : (e?.response?.data?.message ?? 'Erreur'))
    },
  })

  const supprimer = useMutation({
    mutationFn: (id) => deleteCreneau(id),
    onSuccess: () => { rafraichir(); fermer() },
    onError: (e) => setErreur(e?.response?.data?.message ?? 'Erreur'),
  })

  const jours = ref?.jours ?? []
  const heures = ref?.heures ?? []
  const verrouille = Boolean(emploi?.annee_cloturee)

  const creneauDe = (jour, heure) =>
    emploi?.creneaux?.find((c) => c.jour === jour && c.heure === heure)

  const ouvrir = (jour, heure) => {
    if (verrouille) return
    const existant = creneauDe(jour, heure)
    setErreur(null)
    setCellule({ jour, heure, creneau: existant })
    setSaisie({ matiere: existant?.matiere ?? '', salle: existant?.salle ?? '' })
  }

  return (
    <div>
      <div className="mb-6">
        <h1 className="text-2xl font-bold text-slate-800">Emplois du temps</h1>
        <p className="mt-1 text-sm text-slate-500">
          Grille par classe pour l’année {emploi?.annee ?? 'en cours'} (source : ECONOMAT.T_EMPLOIDUTEMPS).
          L’enseignant est déduit de son affectation à la matière.
        </p>
      </div>

      <div className="mb-4 flex flex-wrap items-end gap-3">
        <div className="max-w-sm flex-1">
          <Select label="Classe" value={classe} onChange={(e) => { setClasse(e.target.value); fermer() }}>
            <option value="">— Choisir une classe —</option>
            {listes?.classes?.map((c) => (
              <option key={c.code} value={c.code}>{c.nom ?? c.code}</option>
            ))}
          </Select>
        </div>
        <Button variant="outline" disabled={!classe}
                title="Imprimer la grille horaire de la classe"
                onClick={() => imprimerEmploiDuTemps({ classe })}>
          <Printer size={16} className="mr-1.5 inline" />
          Imprimer la grille
        </Button>
        <Button variant="outline" disabled={!classe}
                title="Imprimer la liste nominative de la classe"
                onClick={() => imprimerListeClasse({ classe })}>
          <Printer size={16} className="mr-1.5 inline" />
          Liste de la classe
        </Button>
      </div>

      {verrouille && (
        <div className="mb-4 flex items-start gap-2 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
          <Lock size={16} className="mt-0.5 shrink-0" />
          <span>
            L’année <strong>{emploi.annee}</strong> est clôturée : la grille est en consultation seule.
          </span>
        </div>
      )}

      {!classe && (
        <p className="rounded-xl border border-slate-200 bg-white px-4 py-10 text-center text-sm text-slate-400">
          Choisissez une classe pour afficher sa grille.
        </p>
      )}

      {classe && isLoading && <p className="text-slate-400">Chargement…</p>}

      {classe && !isLoading && (heures.length === 0 || jours.length === 0) && (
        <div className="rounded-xl border border-amber-200 bg-amber-50 px-4 py-6 text-sm text-amber-800">
          La trame horaire est vide : renseignez les jours (<code>T_EMPJOUR</code>) et les plages
          horaires (<code>T_HORAIRE</code>) dans ECONOMAT pour pouvoir bâtir une grille.
        </div>
      )}

      {classe && !isLoading && heures.length > 0 && jours.length > 0 && (
        <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
          <table className="w-full border-collapse text-sm">
            <thead>
              <tr className="bg-slate-50 text-slate-500">
                <th className="border-b border-r border-slate-200 px-3 py-2 text-left font-medium">Horaire</th>
                {jours.map((j) => (
                  <th key={j.code} className="border-b border-slate-200 px-3 py-2 text-left font-medium">
                    {j.libelle}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {heures.map((h) => (
                <tr key={h.code}>
                  <th className="whitespace-nowrap border-b border-r border-slate-200 bg-slate-50 px-3 py-2 text-left text-xs font-medium text-slate-500">
                    {h.libelle || h.code}
                  </th>
                  {jours.map((j) => {
                    const c = creneauDe(j.code, h.code)
                    return (
                      <td key={j.code} className="border-b border-slate-100 p-1 align-top">
                        <button
                          type="button"
                          disabled={verrouille}
                          onClick={() => ouvrir(j.code, h.code)}
                          className={`flex h-full min-h-[62px] w-full flex-col justify-center rounded-lg px-2 py-1.5 text-left transition ${
                            c
                              ? 'bg-primary-50 hover:bg-primary-100'
                              : verrouille ? 'cursor-default' : 'hover:bg-slate-50'
                          }`}
                        >
                          {c ? (
                            <>
                              <span className="text-xs font-semibold text-primary-800">{c.matiere_libelle}</span>
                              {c.enseignant && <span className="text-[11px] text-slate-500">{c.enseignant}</span>}
                              {c.salle_libelle && <span className="text-[11px] text-slate-400">{c.salle_libelle}</span>}
                            </>
                          ) : (
                            !verrouille && <Plus size={14} className="mx-auto text-slate-300" />
                          )}
                        </button>
                      </td>
                    )
                  })}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {cellule && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" onClick={fermer}>
          <div className="w-full max-w-md rounded-xl bg-white p-6 shadow-xl" onClick={(e) => e.stopPropagation()}>
            <h2 className="mb-1 text-lg font-bold text-slate-800">
              {cellule.creneau ? 'Modifier le créneau' : 'Nouveau créneau'}
            </h2>
            <p className="mb-4 text-xs text-slate-400">
              {jours.find((j) => j.code === cellule.jour)?.libelle}
              {' · '}
              {heures.find((h) => h.code === cellule.heure)?.libelle}
            </p>

            <form
              onSubmit={(e) => { e.preventDefault(); enregistrer.mutate() }}
              className="space-y-3"
            >
              <Select label="Matière *" value={saisie.matiere}
                      onChange={(e) => setSaisie((s) => ({ ...s, matiere: e.target.value }))}>
                <option value="">— Choisir —</option>
                {listes?.matieres?.map((m) => (
                  <option key={m.code} value={m.code}>{m.libelle ?? m.nom ?? m.code}</option>
                ))}
              </Select>

              <Select label="Salle" value={saisie.salle}
                      onChange={(e) => setSaisie((s) => ({ ...s, salle: e.target.value }))}>
                <option value="">— Aucune —</option>
                {ref?.salles?.map((s) => (
                  <option key={s.code} value={s.code}>
                    {s.libelle}{s.places ? ` (${s.places} places)` : ''}
                  </option>
                ))}
              </Select>

              {erreur && <p className="text-sm text-red-600">{erreur}</p>}

              <p className="text-xs text-slate-400">
                Un conflit de classe, de salle ou d’enseignant est refusé, avec le motif précis.
              </p>

              <div className="flex items-center justify-between pt-2">
                {cellule.creneau ? (
                  <Button type="button" variant="outline" className="!px-3"
                          disabled={supprimer.isPending}
                          onClick={() => supprimer.mutate(cellule.creneau.id)}>
                    <Trash2 size={15} /> Vider la case
                  </Button>
                ) : <span />}

                <div className="flex gap-2">
                  <Button type="button" variant="outline" onClick={fermer}>Annuler</Button>
                  <Button type="submit" disabled={enregistrer.isPending || !saisie.matiere}>
                    {enregistrer.isPending ? 'Enregistrement…' : 'Enregistrer'}
                  </Button>
                </div>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  )
}
