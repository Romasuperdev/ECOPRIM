import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import Button from '../../components/ui/Button'
import Input from '../../components/ui/Input'
import Select from '../../components/ui/Select'
import {
  activerEtablissement,
  createEtablissement,
  desactiverEtablissement,
  fetchAllSocietes,
  fetchEtablissements,
  updateEtablissement,
} from './adminApi'

const VIDE = { code: '', intitule: '', type: '', ville: '', pays: '', adresse: '', telephone: '', email: '', site_web: '', societe_code: '' }

export default function EtablissementListPage() {
  const [page, setPage] = useState(1)
  const [form, setForm] = useState(null)
  const [erreurs, setErreurs] = useState({})
  const qc = useQueryClient()

  const { data, isLoading } = useQuery({ queryKey: ['etablissements', page], queryFn: () => fetchEtablissements(page) })
  const { data: societes } = useQuery({ queryKey: ['societes-all'], queryFn: fetchAllSocietes })

  const invalider = () => qc.invalidateQueries({ queryKey: ['etablissements'] })

  const enregistrer = useMutation({
    mutationFn: (v) => (v.id ? updateEtablissement(v.id, v) : createEtablissement(v)),
    onSuccess: () => { invalider(); setForm(null); setErreurs({}) },
    onError: (e) => setErreurs(e?.response?.data?.errors ?? { _: [e?.response?.data?.message ?? 'Erreur'] }),
  })

  const basculer = useMutation({
    mutationFn: ({ id, actif }) => (actif ? desactiverEtablissement(id) : activerEtablissement(id)),
    onSuccess: invalider,
  })

  const champ = (k, v) => setForm((f) => ({ ...f, [k]: v }))

  return (
    <div>
      <div className="mb-6 flex items-start justify-between">
        <div>
          <h1 className="text-2xl font-bold text-slate-800">Établissements</h1>
          <p className="mt-1 text-sm text-slate-500">Chaque établissement est rattaché à une seule société.</p>
        </div>
        <Button disabled={!societes?.length} onClick={() => { setErreurs({}); setForm({ ...VIDE }) }}>+ Nouvel établissement</Button>
      </div>

      <div className="overflow-hidden rounded-xl border border-slate-200 bg-white">
        <table className="w-full text-left text-sm">
          <thead className="border-b border-slate-200 bg-slate-50 text-slate-500">
            <tr>
              <th className="px-4 py-3 font-medium">Code</th>
              <th className="px-4 py-3 font-medium">Intitulé</th>
              <th className="px-4 py-3 font-medium">Société</th>
              <th className="px-4 py-3 font-medium">Ville</th>
              <th className="px-4 py-3 font-medium">Statut</th>
              <th className="px-4 py-3 font-medium text-right">Actions</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {isLoading && <tr><td colSpan={6} className="px-4 py-6 text-center text-slate-400">Chargement…</td></tr>}
            {!isLoading && data?.data?.length === 0 && <tr><td colSpan={6} className="px-4 py-6 text-center text-slate-400">Aucun établissement.</td></tr>}
            {data?.data?.map((e) => (
              <tr key={e.id} className="hover:bg-slate-50">
                <td className="px-4 py-3 font-medium text-slate-800">{e.code}</td>
                <td className="px-4 py-3 text-slate-600">{e.intitule}</td>
                <td className="px-4 py-3 text-slate-600">{e.societe?.nom ?? e.societe_code}</td>
                <td className="px-4 py-3 text-slate-600">{e.ville ?? '—'}</td>
                <td className="px-4 py-3">
                  <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${e.actif ? 'bg-green-50 text-green-700' : 'bg-slate-100 text-slate-500'}`}>{e.actif ? 'actif' : 'inactif'}</span>
                </td>
                <td className="px-4 py-3 text-right">
                  <div className="flex justify-end gap-2">
                    <Button variant="outline" className="!px-3 !py-1" onClick={() => { setErreurs({}); setForm({ ...VIDE, ...e, societe_code: e.societe_code ?? e.societe?.code ?? '' }) }}>Éditer</Button>
                    <Button variant="outline" className="!px-3 !py-1" disabled={basculer.isPending} onClick={() => basculer.mutate({ id: e.id, actif: e.actif })}>
                      {e.actif ? 'Désactiver' : 'Activer'}
                    </Button>
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {data && (
        <div className="mt-4 flex justify-end gap-2">
          <Button variant="outline" disabled={!data.prev_page_url} onClick={() => setPage((p) => p - 1)}>Précédent</Button>
          <Button variant="outline" disabled={!data.next_page_url} onClick={() => setPage((p) => p + 1)}>Suivant</Button>
        </div>
      )}

      {form && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" onClick={() => setForm(null)}>
          <div className="w-full max-w-lg rounded-xl bg-white p-6 shadow-xl" onClick={(ev) => ev.stopPropagation()}>
            <h2 className="mb-4 text-lg font-bold text-slate-800">{form.id ? "Modifier l'établissement" : 'Nouvel établissement'}</h2>
            <form onSubmit={(ev) => { ev.preventDefault(); enregistrer.mutate(form) }} className="space-y-3">
              <Select label="Société *" value={form.societe_code ?? ''} onChange={(ev) => champ('societe_code', ev.target.value)} error={erreurs.societe_code?.[0]}>
                <option value="">— Choisir une société —</option>
                {societes?.map((s) => <option key={s.code} value={s.code}>{s.nom} ({s.code})</option>)}
              </Select>
              <div className="grid grid-cols-2 gap-3">
                <Input label="Code *" value={form.code ?? ''} onChange={(ev) => champ('code', ev.target.value)} error={erreurs.code?.[0]} disabled={!!form.id} />
                <Input label="Intitulé *" value={form.intitule ?? ''} onChange={(ev) => champ('intitule', ev.target.value)} error={erreurs.intitule?.[0]} />
                <Input label="Type" value={form.type ?? ''} onChange={(ev) => champ('type', ev.target.value)} error={erreurs.type?.[0]} />
                <Input label="Ville" value={form.ville ?? ''} onChange={(ev) => champ('ville', ev.target.value)} error={erreurs.ville?.[0]} />
                <Input label="Pays" value={form.pays ?? ''} onChange={(ev) => champ('pays', ev.target.value)} error={erreurs.pays?.[0]} />
                <Input label="Téléphone" value={form.telephone ?? ''} onChange={(ev) => champ('telephone', ev.target.value)} error={erreurs.telephone?.[0]} />
                <Input label="Email" value={form.email ?? ''} onChange={(ev) => champ('email', ev.target.value)} error={erreurs.email?.[0]} />
                <Input label="Site web" value={form.site_web ?? ''} onChange={(ev) => champ('site_web', ev.target.value)} error={erreurs.site_web?.[0]} />
              </div>
              <Input label="Adresse" value={form.adresse ?? ''} onChange={(ev) => champ('adresse', ev.target.value)} error={erreurs.adresse?.[0]} />
              {erreurs._ && <p className="text-sm text-red-600">{erreurs._[0]}</p>}
              <div className="mt-4 flex justify-end gap-2">
                <Button type="button" variant="outline" onClick={() => setForm(null)}>Annuler</Button>
                <Button type="submit" disabled={enregistrer.isPending}>{enregistrer.isPending ? 'Enregistrement…' : 'Enregistrer'}</Button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  )
}
