import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Lock, Trash2 } from 'lucide-react'
import Button from '../../components/ui/Button'
import Input from '../../components/ui/Input'
import Select from '../../components/ui/Select'
import ModaleFormulaire from '../../components/ui/ModaleFormulaire'
import MessageRefus from '../../components/ui/MessageRefus'
import { fetchContexte } from '../contexte/contexteApi'
import { fetchNiveaux } from '../reference/referenceApi'
import { createNiveau, deleteNiveau, updateNiveau } from './niveauxApi'
import { fetchCycles } from './cyclesApi'
import CyclesPanel from './CyclesPanel'

const VIDE = { code: '', libelle: '', cycle_code: '', ordre: '' }

/**
 * Cycles et niveaux — ECONOMAT.T_CYCLE et T_NIVEAU.
 *
 * Un niveau appartient à l'année de travail (le même CP1 est recréé chaque année) ; un
 * cycle, non : il traverse les années, et se gère dans le panneau du bas.
 */
export default function NiveauListPage() {
  const qc = useQueryClient()
  const [form, setForm] = useState(null)
  const [erreurs, setErreurs] = useState({})
  const [refus, setRefus] = useState(null)

  const { data: niveaux, isLoading } = useQuery({ queryKey: ['niveaux'], queryFn: fetchNiveaux })
  const { data: cycles } = useQuery({ queryKey: ['cycles'], queryFn: fetchCycles })
  const { data: contexte } = useQuery({ queryKey: ['contexte'], queryFn: fetchContexte, retry: false })

  const anneeCourante = contexte?.annees?.find((a) => a.libelle === contexte?.annee)
  const verrouille = Boolean(anneeCourante?.cloturee)

  const rafraichir = () => qc.invalidateQueries({ queryKey: ['niveaux'] })
  const fermer = () => { setForm(null); setErreurs({}) }

  const enregistrer = useMutation({
    mutationFn: (v) => {
      const charge = { ...v, ordre: v.ordre === '' ? null : Number(v.ordre) }
      return v.id ? updateNiveau(v.id, charge) : createNiveau(charge)
    },
    onSuccess: () => { rafraichir(); fermer() },
    onError: (e) => setErreurs(e?.response?.data?.errors ?? { _: [e?.response?.data?.message ?? 'Erreur'] }),
  })

  const supprimer = useMutation({
    mutationFn: deleteNiveau,
    onSuccess: () => { rafraichir(); setRefus(null) },
    onError: (e) => setRefus(e?.response?.data?.message ?? 'Suppression impossible.'),
  })

  const champ = (k, v) => setForm((f) => ({ ...f, [k]: v }))

  return (
    <div>
      <div className="mb-6 flex items-start justify-between">
        <div>
          <h1 className="text-2xl font-bold text-slate-800">Cycles / Niveaux</h1>
          <p className="mt-1 text-sm text-slate-500">
            Niveaux de l’année {contexte?.annee ?? 'en cours'} (source : ECONOMAT).
          </p>
        </div>
        <Button
          disabled={verrouille}
          title={verrouille ? 'Année clôturée : consultation seule.' : undefined}
          onClick={() => { setErreurs({}); setForm({ ...VIDE }) }}
        >
          + Nouveau niveau
        </Button>
      </div>

      {verrouille && (
        <div className="mb-4 flex items-start gap-2 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
          <Lock size={16} className="mt-0.5 shrink-0" />
          <span>L’année <strong>{contexte?.annee}</strong> est clôturée : les niveaux sont en consultation seule.</span>
        </div>
      )}

      <MessageRefus message={refus} onFermer={() => setRefus(null)} />

      <div className="overflow-hidden rounded-xl border border-slate-200 bg-white">
        <table className="w-full text-left text-sm">
          <thead className="border-b border-slate-200 bg-slate-50 text-slate-500">
            <tr>
              <th className="px-4 py-3 font-medium">Ordre</th>
              <th className="px-4 py-3 font-medium">Code</th>
              <th className="px-4 py-3 font-medium">Libellé</th>
              <th className="px-4 py-3 font-medium">Cycle</th>
              <th className="px-4 py-3 font-medium text-right">Actions</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {isLoading && (
              <tr><td colSpan={5} className="px-4 py-6 text-center text-slate-400">Chargement…</td></tr>
            )}
            {!isLoading && niveaux?.length === 0 && (
              <tr><td colSpan={5} className="px-4 py-6 text-center text-slate-400">Aucun niveau.</td></tr>
            )}
            {niveaux?.map((niveau) => (
              <tr key={niveau.id} className="hover:bg-slate-50">
                <td className="px-4 py-3 text-slate-600">{niveau.ordre ?? '—'}</td>
                <td className="px-4 py-3 font-medium text-slate-800">{niveau.code}</td>
                <td className="px-4 py-3 text-slate-600">{niveau.libelle}</td>
                <td className="px-4 py-3 text-slate-600">{niveau.cycle?.libelle ?? niveau.cycle_code ?? '—'}</td>
                <td className="px-4 py-3 text-right">
                  <Button
                    variant="outline" className="!px-3 !py-1 mr-2" disabled={verrouille}
                    onClick={() => {
                      setErreurs({})
                      setForm({
                        id: niveau.id,
                        code: niveau.code ?? '',
                        libelle: niveau.libelle ?? '',
                        cycle_code: niveau.cycle_code ?? '',
                        ordre: niveau.ordre ?? '',
                      })
                    }}
                  >
                    Éditer
                  </Button>
                  <Button
                    variant="outline" className="!px-3 !py-1" disabled={verrouille}
                    title="Supprimer — refusé si des classes ou des documents s’y rattachent"
                    onClick={() => supprimer.mutate(niveau.id)}
                  >
                    <Trash2 size={14} />
                  </Button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <div className="mt-8">
        <CyclesPanel />
      </div>

      {form && (
        <ModaleFormulaire
          titre={form.id ? `Modifier ${form.libelle}` : 'Nouveau niveau'}
          sousTitre={`Année ${contexte?.annee ?? 'en cours'}`}
          onFermer={fermer}
          onValider={() => enregistrer.mutate(form)}
          enCours={enregistrer.isPending}
          valideDesactive={!form.code || !form.libelle}
        >
          <Input label="Code" placeholder="CP1" value={form.code}
                 error={erreurs.code?.[0]} onChange={(e) => champ('code', e.target.value)} />
          <Input label="Libellé" placeholder="Cours préparatoire 1" value={form.libelle}
                 error={erreurs.libelle?.[0]} onChange={(e) => champ('libelle', e.target.value)} />
          <Select label="Cycle" value={form.cycle_code} error={erreurs.cycle_code?.[0]}
                  onChange={(e) => champ('cycle_code', e.target.value)}>
            <option value="">— Aucun —</option>
            {cycles?.map((c) => <option key={c.id} value={c.code}>{c.libelle}</option>)}
          </Select>
          <Input label="Ordre d’affichage" type="number" min="0" value={form.ordre}
                 error={erreurs.ordre?.[0]} onChange={(e) => champ('ordre', e.target.value)} />
          {erreurs._ && <p className="text-sm text-red-600">{erreurs._[0]}</p>}
        </ModaleFormulaire>
      )}
    </div>
  )
}
