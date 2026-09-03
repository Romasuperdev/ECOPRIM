import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus, Trash2, X } from 'lucide-react'
import Button from '../../components/ui/Button'
import Input from '../../components/ui/Input'
import MessageRefus from '../../components/ui/MessageRefus'
import { createCycle, deleteCycle, fetchCycles, updateCycle } from './cyclesApi'

const VIDE = { code: '', libelle: '', primaire: false }

/**
 * Cycles — ECONOMAT.T_CYCLE.
 *
 * Un cycle n'est pas rattaché à une année : il traverse les années scolaires, il n'y a
 * donc pas de verrou de clôture ici. La suppression est refusée dès qu'un niveau ou une
 * matière s'y réfère, et le message du serveur dit lequel.
 *
 * L'API adresse un cycle par son CODE : c'est la clé de T_CYCLE côté ECONOMAT.
 */
export default function CyclesPanel() {
  const qc = useQueryClient()
  const [form, setForm] = useState(null)
  const [erreurs, setErreurs] = useState({})
  const [refus, setRefus] = useState(null)

  const { data: cycles, isLoading } = useQuery({ queryKey: ['cycles'], queryFn: fetchCycles })

  const rafraichir = () => {
    qc.invalidateQueries({ queryKey: ['cycles'] })
    // Les niveaux affichent le libellé de leur cycle.
    qc.invalidateQueries({ queryKey: ['niveaux'] })
  }
  const fermer = () => { setForm(null); setErreurs({}) }

  const enregistrer = useMutation({
    mutationFn: (v) => (v.code_initial ? updateCycle(v.code_initial, v) : createCycle(v)),
    onSuccess: () => { rafraichir(); fermer() },
    onError: (e) => setErreurs(e?.response?.data?.errors ?? { _: [e?.response?.data?.message ?? 'Erreur'] }),
  })

  const supprimer = useMutation({
    mutationFn: deleteCycle,
    onSuccess: () => { rafraichir(); setRefus(null) },
    onError: (e) => setRefus(e?.response?.data?.message ?? 'Suppression impossible.'),
  })

  const champ = (k, v) => setForm((f) => ({ ...f, [k]: v }))

  return (
    <div className="mb-6 rounded-xl border border-slate-200 bg-white p-6">
      <div className="mb-3 flex items-center justify-between">
        <h2 className="text-lg font-semibold text-slate-800">Cycles</h2>
        <Button variant="outline" onClick={() => { setErreurs({}); setForm(form ? null : { ...VIDE }) }}>
          <span className="flex items-center gap-2">
            <Plus size={16} /> Ajouter un cycle
          </span>
        </Button>
      </div>

      <MessageRefus message={refus} onFermer={() => setRefus(null)} />

      {form && (
        <form
          onSubmit={(e) => { e.preventDefault(); enregistrer.mutate(form) }}
          className="mb-4 space-y-3 rounded-lg border border-slate-100 bg-slate-50 p-4"
        >
          <div className="flex items-center justify-between">
            <span className="text-sm font-medium text-slate-600">
              {form.code_initial ? `Modifier ${form.libelle}` : 'Nouveau cycle'}
            </span>
            <button type="button" onClick={fermer} className="text-slate-400 hover:text-slate-600">
              <X size={16} />
            </button>
          </div>
          <div className="grid grid-cols-2 gap-3">
            <Input label="Code" value={form.code} error={erreurs.code?.[0]}
                   onChange={(e) => champ('code', e.target.value)} />
            <Input label="Libellé" value={form.libelle} error={erreurs.libelle?.[0]}
                   onChange={(e) => champ('libelle', e.target.value)} />
          </div>
          <label className="flex items-center gap-2 text-sm text-slate-700">
            <input type="checkbox" checked={Boolean(form.primaire)}
                   onChange={(e) => champ('primaire', e.target.checked)} />
            Cycle primaire
          </label>
          {erreurs._ && <p className="text-sm text-red-600">{erreurs._[0]}</p>}
          <div className="flex justify-end">
            <Button type="submit" disabled={enregistrer.isPending || !form.code || !form.libelle}>
              {form.code_initial ? 'Enregistrer' : 'Ajouter'}
            </Button>
          </div>
        </form>
      )}

      {isLoading ? (
        <p className="text-sm text-slate-400">Chargement…</p>
      ) : (
        <ul className="divide-y divide-slate-100">
          {cycles?.length === 0 && <li className="py-2 text-sm text-slate-400">Aucun cycle.</li>}
          {cycles?.map((cycle) => (
            <li key={cycle.id ?? cycle.code} className="flex items-center justify-between py-2 text-sm">
              <span>
                <span className="font-medium text-slate-800">{cycle.libelle}</span>{' '}
                <span className="text-slate-400">({cycle.code})</span>
                {cycle.primaire && (
                  <span className="ml-2 rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-500">Primaire</span>
                )}
              </span>
              <span className="flex items-center gap-1">
                <Button
                  variant="outline" className="!px-3 !py-1"
                  onClick={() => {
                    setErreurs({})
                    setForm({
                      code_initial: cycle.code,
                      code: cycle.code ?? '',
                      libelle: cycle.libelle ?? '',
                      primaire: Boolean(cycle.primaire),
                    })
                  }}
                >
                  Éditer
                </Button>
                <button
                  type="button"
                  title="Supprimer — refusé si des niveaux ou des matières s’y rattachent"
                  onClick={() => supprimer.mutate(cycle.code)}
                  className="rounded p-1.5 text-slate-500 hover:bg-red-50 hover:text-red-600"
                >
                  <Trash2 size={16} />
                </button>
              </span>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
