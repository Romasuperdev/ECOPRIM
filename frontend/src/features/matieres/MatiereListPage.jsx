import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Trash2 } from 'lucide-react'
import { createMatiere, deleteMatiere, fetchMatieresPage, updateMatiere } from './matieresApi'
import Button from '../../components/ui/Button'
import Input from '../../components/ui/Input'
import Select from '../../components/ui/Select'
import ModaleFormulaire from '../../components/ui/ModaleFormulaire'
import MessageRefus from '../../components/ui/MessageRefus'
import { fetchCycles } from '../niveaux/cyclesApi'

const VIDE = { code: '', libelle: '', type: '', cycle_code: '', composition: false }

/**
 * Matières — ECONOMAT.T_MATIERE.
 *
 * Une matière traverse les années scolaires : il n'y a pas de verrou de clôture ici. La
 * suppression est refusée dès qu'une affectation d'enseignant, un créneau ou une note s'y
 * réfère.
 */
export default function MatiereListPage() {
  const qc = useQueryClient()
  const [page, setPage] = useState(1)
  const [form, setForm] = useState(null)
  const [erreurs, setErreurs] = useState({})
  const [refus, setRefus] = useState(null)

  const { data, isLoading, isError } = useQuery({
    queryKey: ['matieres', page],
    queryFn: () => fetchMatieresPage(page),
  })
  const { data: cycles } = useQuery({ queryKey: ['cycles'], queryFn: fetchCycles })

  const rafraichir = () => qc.invalidateQueries({ queryKey: ['matieres'] })
  const fermer = () => { setForm(null); setErreurs({}) }

  const enregistrer = useMutation({
    mutationFn: (v) => (v.id ? updateMatiere(v.id, v) : createMatiere(v)),
    onSuccess: () => { rafraichir(); fermer() },
    onError: (e) => setErreurs(e?.response?.data?.errors ?? { _: [e?.response?.data?.message ?? 'Erreur'] }),
  })

  const supprimer = useMutation({
    mutationFn: deleteMatiere,
    onSuccess: () => { rafraichir(); setRefus(null) },
    onError: (e) => setRefus(e?.response?.data?.message ?? 'Suppression impossible.'),
  })

  const champ = (k, v) => setForm((f) => ({ ...f, [k]: v }))

  return (
    <div>
      <div className="mb-6 flex items-start justify-between">
        <div>
          <h1 className="text-2xl font-bold text-slate-800">Matières</h1>
          <p className="mt-1 text-sm text-slate-500">Source : ECONOMAT.T_MATIERE.</p>
        </div>
        <Button onClick={() => { setErreurs({}); setForm({ ...VIDE }) }}>+ Nouvelle matière</Button>
      </div>

      <MessageRefus message={refus} onFermer={() => setRefus(null)} />

      <div className="overflow-hidden rounded-xl border border-slate-200 bg-white">
        <table className="w-full text-left text-sm">
          <thead className="border-b border-slate-200 bg-slate-50 text-slate-500">
            <tr>
              <th className="px-4 py-3 font-medium">Code</th>
              <th className="px-4 py-3 font-medium">Libellé</th>
              <th className="px-4 py-3 font-medium">Type</th>
              <th className="px-4 py-3 font-medium">Cycle</th>
              <th className="px-4 py-3 font-medium text-right">Actions</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {isLoading && (
              <tr><td colSpan={5} className="px-4 py-6 text-center text-slate-400">Chargement…</td></tr>
            )}
            {isError && (
              <tr><td colSpan={5} className="px-4 py-6 text-center text-red-500">Erreur de chargement.</td></tr>
            )}
            {!isLoading && data?.data?.length === 0 && (
              <tr><td colSpan={5} className="px-4 py-6 text-center text-slate-400">Aucune matière.</td></tr>
            )}
            {data?.data?.map((matiere) => (
              <tr key={matiere.id} className="hover:bg-slate-50">
                <td className="px-4 py-3 text-slate-600">{matiere.code}</td>
                <td className="px-4 py-3 font-medium text-slate-800">{matiere.libelle}</td>
                <td className="px-4 py-3 text-slate-600">{matiere.type ?? '—'}</td>
                <td className="px-4 py-3 text-slate-600">{matiere.cycle_code ?? '—'}</td>
                <td className="px-4 py-3 text-right">
                  <Button
                    variant="outline" className="!px-3 !py-1 mr-2"
                    onClick={() => {
                      setErreurs({})
                      setForm({
                        id: matiere.id,
                        code: matiere.code ?? '',
                        libelle: matiere.libelle ?? '',
                        type: matiere.type ?? '',
                        cycle_code: matiere.cycle_code ?? '',
                        composition: Boolean(matiere.composition),
                      })
                    }}
                  >
                    Éditer
                  </Button>
                  <Button
                    variant="outline" className="!px-3 !py-1"
                    title="Supprimer — refusé si des affectations, créneaux ou notes s’y rattachent"
                    onClick={() => supprimer.mutate(matiere.id)}
                  >
                    <Trash2 size={14} />
                  </Button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {data && (
        <div className="mt-4 flex justify-end gap-2">
          <Button variant="outline" disabled={!data.prev_page_url} onClick={() => setPage((p) => p - 1)}>
            Précédent
          </Button>
          <Button variant="outline" disabled={!data.next_page_url} onClick={() => setPage((p) => p + 1)}>
            Suivant
          </Button>
        </div>
      )}

      {form && (
        <ModaleFormulaire
          titre={form.id ? `Modifier ${form.libelle}` : 'Nouvelle matière'}
          onFermer={fermer}
          onValider={() => enregistrer.mutate(form)}
          enCours={enregistrer.isPending}
          valideDesactive={!form.code || !form.libelle}
        >
          <Input label="Code" placeholder="MATH" value={form.code}
                 error={erreurs.code?.[0]} onChange={(e) => champ('code', e.target.value)} />
          <Input label="Libellé" placeholder="Mathématiques" value={form.libelle}
                 error={erreurs.libelle?.[0]} onChange={(e) => champ('libelle', e.target.value)} />
          <Input label="Type (facultatif)" value={form.type}
                 error={erreurs.type?.[0]} onChange={(e) => champ('type', e.target.value)} />
          <Select label="Cycle" value={form.cycle_code} error={erreurs.cycle_code?.[0]}
                  onChange={(e) => champ('cycle_code', e.target.value)}>
            <option value="">— Tous —</option>
            {cycles?.map((c) => <option key={c.id ?? c.code} value={c.code}>{c.libelle}</option>)}
          </Select>
          <label className="flex items-center gap-2 text-sm text-slate-700">
            <input type="checkbox" checked={Boolean(form.composition)}
                   onChange={(e) => champ('composition', e.target.checked)} />
            Matière de composition
          </label>
          {erreurs._ && <p className="text-sm text-red-600">{erreurs._[0]}</p>}
        </ModaleFormulaire>
      )}
    </div>
  )
}
