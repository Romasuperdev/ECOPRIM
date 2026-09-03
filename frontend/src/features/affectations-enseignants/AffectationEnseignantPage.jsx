import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Lock, Star, Trash2, UserPlus } from 'lucide-react'
import Button from '../../components/ui/Button'
import Select from '../../components/ui/Select'
import {
  createAffectation,
  deleteAffectation,
  fetchAffectations,
  fetchReferentiels,
  updateAffectation,
} from './affectationEnseignantApi'

/**
 * Qui enseigne quoi, dans quelle classe.
 *
 * Cette page est en amont de l'emploi du temps : celui-ci ne stocke pas l'enseignant, il
 * le déduit de ces affectations. Une matière n'a donc qu'un enseignant par classe, et le
 * retrait d'une affectation est refusé quand des créneaux ou des notes en dépendent — la
 * ligne l'annonce avant qu'on clique.
 */
export default function AffectationEnseignantPage() {
  const qc = useQueryClient()
  const [vue, setVue] = useState('classe') // 'classe' | 'enseignant'
  const [classe, setClasse] = useState('')
  const [enseignant, setEnseignant] = useState('')
  const [form, setForm] = useState(null)
  const [erreurs, setErreurs] = useState({})
  const [refus, setRefus] = useState(null)

  const { data: ref } = useQuery({ queryKey: ['affectations-ens-ref'], queryFn: fetchReferentiels })

  const parClasse = vue === 'classe'
  const cible = parClasse ? classe : enseignant
  const { data, isLoading } = useQuery({
    queryKey: ['affectations-ens', vue, cible],
    queryFn: () => fetchAffectations(parClasse ? { classe } : { enseignant }),
    enabled: Boolean(cible),
  })

  const verrouille = Boolean(data?.annee_cloturee ?? ref?.annee_cloturee)

  const rafraichir = () => {
    qc.invalidateQueries({ queryKey: ['affectations-ens'] })
    // L'emploi du temps déduit son enseignant d'ici : sa grille doit se rafraîchir aussi.
    qc.invalidateQueries({ queryKey: ['emploi'] })
  }

  const fermer = () => { setForm(null); setErreurs({}) }

  const enregistrer = useMutation({
    mutationFn: (v) => (v.id
      ? updateAffectation(v.id, { enseignant: Number(v.enseignant), principale: v.principale })
      : createAffectation({ classe: v.classe, matiere: v.matiere, enseignant: Number(v.enseignant), principale: v.principale })),
    onSuccess: () => { rafraichir(); fermer() },
    onError: (e) => setErreurs(e?.response?.data?.errors ?? { _: [e?.response?.data?.message ?? 'Erreur'] }),
  })

  const retirer = useMutation({
    mutationFn: deleteAffectation,
    onSuccess: () => { rafraichir(); setRefus(null) },
    // 409 : des créneaux ou des notes en dépendent. Le message du serveur dit lesquels.
    onError: (e) => setRefus(e?.response?.data?.message ?? 'Retrait impossible.'),
  })

  const titulaire = useMutation({
    mutationFn: ({ id, enseignant: ens }) => updateAffectation(id, { enseignant: ens, principale: true }),
    onSuccess: rafraichir,
  })

  const champ = (k, v) => setForm((f) => ({ ...f, [k]: v }))
  const lignes = data?.affectations ?? []

  return (
    <div>
      <div className="mb-6 flex items-start justify-between">
        <div>
          <h1 className="text-2xl font-bold text-slate-800">Affectation enseignant – classe</h1>
          <p className="mt-1 text-sm text-slate-500">
            Qui enseigne quelle matière, dans quelle classe, pour l’année {data?.annee ?? ref?.annee ?? 'en cours'}.
            L’emploi du temps en déduit l’enseignant de chaque créneau.
          </p>
        </div>
        <Button
          disabled={verrouille || !classe || !parClasse}
          title={
            verrouille ? 'Année clôturée : consultation seule.'
              : !parClasse ? 'Choisissez la vue par classe pour affecter.'
                : !classe ? 'Choisissez d’abord une classe.' : undefined
          }
          onClick={() => {
            setErreurs({})
            setForm({ classe, matiere: '', enseignant: '', principale: false })
          }}
        >
          <UserPlus size={16} className="mr-1.5 inline" />
          Affecter un enseignant
        </Button>
      </div>

      {verrouille && (
        <div className="mb-4 flex items-start gap-2 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
          <Lock size={16} className="mt-0.5 shrink-0" />
          <span>
            L’année <strong>{data?.annee ?? ref?.annee}</strong> est clôturée : les affectations
            sont en consultation seule.
          </span>
        </div>
      )}

      {/* Deux lectures : la grille d'une classe, ou le service d'un enseignant. */}
      <div className="mb-4 flex flex-wrap items-end gap-3">
        <div className="min-w-[180px]">
          <Select label="Consulter par" value={vue} onChange={(e) => setVue(e.target.value)}>
            <option value="classe">Classe</option>
            <option value="enseignant">Enseignant</option>
          </Select>
        </div>
        {parClasse ? (
          <div className="min-w-[220px]">
            <Select label="Classe" value={classe} onChange={(e) => setClasse(e.target.value)}>
              <option value="">— Choisir une classe —</option>
              {ref?.classes?.map((c) => <option key={c.code} value={c.code}>{c.libelle}</option>)}
            </Select>
          </div>
        ) : (
          <div className="min-w-[220px]">
            <Select label="Enseignant" value={enseignant} onChange={(e) => setEnseignant(e.target.value)}>
              <option value="">— Choisir un enseignant —</option>
              {ref?.enseignants?.map((p) => <option key={p.code} value={p.code}>{p.nom}</option>)}
            </Select>
          </div>
        )}
      </div>

      {refus && (
        <div className="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
          {refus}
          <button type="button" className="ml-2 underline" onClick={() => setRefus(null)}>Fermer</button>
        </div>
      )}

      {!cible && (
        <p className="rounded-xl border border-slate-200 bg-white px-4 py-10 text-center text-sm text-slate-400">
          {parClasse ? 'Choisissez une classe pour voir son équipe pédagogique.' : 'Choisissez un enseignant pour voir son service.'}
        </p>
      )}

      {cible && isLoading && <p className="text-slate-400">Chargement…</p>}

      {cible && !isLoading && (
        <div className="overflow-hidden rounded-xl border border-slate-200 bg-white">
          <table className="w-full text-left text-sm">
            <thead className="border-b border-slate-200 bg-slate-50 text-slate-500">
              <tr>
                {!parClasse && <th className="px-4 py-3 font-medium">Classe</th>}
                <th className="px-4 py-3 font-medium">Matière</th>
                <th className="px-4 py-3 font-medium">Enseignant</th>
                <th className="px-4 py-3 font-medium">Titulaire</th>
                <th className="px-4 py-3 font-medium">Dépendances</th>
                <th className="px-4 py-3 font-medium text-right">Actions</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {lignes.length === 0 && (
                <tr>
                  <td colSpan={parClasse ? 5 : 6} className="px-4 py-6 text-center text-slate-400">
                    Aucune affectation.
                  </td>
                </tr>
              )}
              {lignes.map((a) => (
                <tr key={a.id} className="hover:bg-slate-50">
                  {!parClasse && <td className="px-4 py-3 text-slate-600">{a.classe_libelle}</td>}
                  <td className="px-4 py-3 font-medium text-slate-800">{a.matiere_libelle}</td>
                  <td className="px-4 py-3 text-slate-600">{a.enseignant_nom ?? '—'}</td>
                  <td className="px-4 py-3">
                    <button
                      type="button"
                      disabled={verrouille || a.principale}
                      title={a.principale ? 'Titulaire de la classe' : 'Désigner comme titulaire de la classe'}
                      onClick={() => titulaire.mutate({ id: a.id, enseignant: a.enseignant })}
                      className="disabled:cursor-default"
                    >
                      <Star
                        size={16}
                        className={a.principale ? 'text-amber-500' : 'text-slate-300 hover:text-amber-400'}
                        fill={a.principale ? 'currentColor' : 'none'}
                      />
                    </button>
                  </td>
                  <td className="px-4 py-3 text-xs text-slate-500">
                    {a.creneaux === 0 && a.notes === 0
                      ? '—'
                      : [
                          a.creneaux > 0 ? `${a.creneaux} créneau${a.creneaux > 1 ? 'x' : ''}` : null,
                          a.notes > 0 ? `${a.notes} note${a.notes > 1 ? 's' : ''}` : null,
                        ].filter(Boolean).join(' · ')}
                  </td>
                  <td className="px-4 py-3 text-right">
                    <Button
                      variant="outline"
                      className="!px-3 !py-1 mr-2"
                      disabled={verrouille}
                      onClick={() => {
                        setErreurs({})
                        setForm({ id: a.id, classe: a.classe, matiere: a.matiere, enseignant: a.enseignant ?? '', principale: a.principale })
                      }}
                    >
                      Remplacer
                    </Button>
                    <Button
                      variant="outline"
                      className="!px-3 !py-1"
                      disabled={verrouille || !a.retirable}
                      title={a.retirable
                        ? 'Retirer cette affectation'
                        : 'Des créneaux ou des notes en dépendent : remplacez l’enseignant plutôt que de retirer l’affectation.'}
                      onClick={() => retirer.mutate(a.id)}
                    >
                      <Trash2 size={14} />
                    </Button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      <p className="mt-3 text-xs text-slate-400">
        Source : ECONOMAT.T_CORPROFCLASSE. Une matière n’a qu’un enseignant par classe et par
        année : pour changer de professeur, on remplace l’affectation plutôt que d’en ajouter une.
      </p>

      {/* Formulaire */}
      {form && (
        <div className="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4">
          <div className="w-full max-w-md rounded-2xl bg-white p-6">
            <h2 className="text-lg font-semibold text-slate-800">
              {form.id ? 'Remplacer l’enseignant' : 'Affecter un enseignant'}
            </h2>
            <p className="mt-1 text-sm text-slate-500">
              {ref?.classes?.find((c) => c.code === form.classe)?.libelle ?? form.classe}
            </p>

            <form
              className="mt-4 space-y-4"
              onSubmit={(e) => { e.preventDefault(); enregistrer.mutate(form) }}
            >
              {!form.id && (
                <Select
                  label="Matière"
                  value={form.matiere}
                  error={erreurs.matiere?.[0]}
                  onChange={(e) => champ('matiere', e.target.value)}
                >
                  <option value="">— Choisir —</option>
                  {ref?.matieres?.map((m) => <option key={m.code} value={m.code}>{m.libelle}</option>)}
                </Select>
              )}

              <Select
                label="Enseignant"
                value={form.enseignant}
                error={erreurs.enseignant?.[0]}
                onChange={(e) => champ('enseignant', e.target.value)}
              >
                <option value="">— Choisir —</option>
                {ref?.enseignants?.map((p) => <option key={p.code} value={p.code}>{p.nom}</option>)}
              </Select>

              <label className="flex items-center gap-2 text-sm text-slate-700">
                <input
                  type="checkbox"
                  checked={Boolean(form.principale)}
                  onChange={(e) => champ('principale', e.target.checked)}
                />
                Titulaire de la classe
                <span className="text-xs text-slate-400">(un seul par classe)</span>
              </label>

              {erreurs._ && <p className="text-sm text-red-600">{erreurs._[0]}</p>}

              <div className="flex justify-end gap-2 pt-2">
                <Button type="button" variant="outline" onClick={fermer}>Annuler</Button>
                <Button type="submit" disabled={enregistrer.isPending || !form.enseignant || (!form.id && !form.matiere)}>
                  {enregistrer.isPending ? 'Enregistrement…' : 'Enregistrer'}
                </Button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  )
}
