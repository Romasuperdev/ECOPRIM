import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import Button from '../../components/ui/Button'
import Input from '../../components/ui/Input'
import Select from '../../components/ui/Select'
import {
  activerSociete,
  createSociete,
  desactiverSociete,
  fetchAllSocietes,
  fetchSocietes,
  importerSocietes,
  updateSociete,
} from './adminApi'

const VIDE = { code: '', nom: '', ville: '', adresse: '', telephone: '', email: '', representant: '' }

export default function SocieteListPage() {
  const [page, setPage] = useState(1)
  const [form, setForm] = useState(null) // null = fermé ; sinon objet en édition/création
  const [erreurs, setErreurs] = useState({})
  const qc = useQueryClient()

  const { data, isLoading } = useQuery({ queryKey: ['societes', page], queryFn: () => fetchSocietes(page) })

  // Sociétés de US_SOCIETE pas encore reprises dans ECOPRIM : c'est la seule source de
  // création — jamais un code tapé à la main, toujours un code déjà réel dans US_SOCIETE.
  const { data: toutes } = useQuery({ queryKey: ['societes-toutes'], queryFn: fetchAllSocietes, enabled: !!form && !form.id })
  const aReprendre = (toutes ?? []).filter((s) => !s.repris)

  const invalider = () => qc.invalidateQueries({ queryKey: ['societes'] })

  const enregistrer = useMutation({
    mutationFn: (v) => (v.id ? updateSociete(v.id, v) : createSociete(v)),
    onSuccess: () => {
      invalider()
      setForm(null)
      setErreurs({})
    },
    onError: (e) => setErreurs(e?.response?.data?.errors ?? { _: [e?.response?.data?.message ?? 'Erreur'] }),
  })

  const basculer = useMutation({
    mutationFn: ({ id, actif }) => (actif ? desactiverSociete(id) : activerSociete(id)),
    onSuccess: invalider,
  })

  const importer = useMutation({
    mutationFn: importerSocietes,
    onSuccess: invalider,
  })

  const champ = (k, v) => setForm((f) => ({ ...f, [k]: v }))

  return (
    <div>
      <div className="mb-6 flex items-start justify-between">
        <div>
          <h1 className="text-2xl font-bold text-slate-800">Sociétés</h1>
          <p className="mt-1 text-sm text-slate-500">Sociétés réelles lues dans dbmasterbacou.US_SOCIETE, complétées par ECOPRIM.</p>
        </div>
        <div className="flex gap-2">
          <Button variant="outline" disabled={importer.isPending} onClick={() => importer.mutate()}>
            {importer.isPending ? 'Import…' : 'Importer depuis US_SOCIETE'}
          </Button>
          <Button onClick={() => { setErreurs({}); setForm({ ...VIDE }) }}>+ Reprendre une société</Button>
        </div>
      </div>

      <div className="overflow-hidden rounded-xl border border-slate-200 bg-white">
        <table className="w-full text-left text-sm">
          <thead className="border-b border-slate-200 bg-slate-50 text-slate-500">
            <tr>
              <th className="px-4 py-3 font-medium">Code</th>
              <th className="px-4 py-3 font-medium">Nom</th>
              <th className="px-4 py-3 font-medium">Ville</th>
              <th className="px-4 py-3 font-medium">Établissements</th>
              <th className="px-4 py-3 font-medium">Source</th>
              <th className="px-4 py-3 font-medium">Statut</th>
              <th className="px-4 py-3 font-medium text-right">Actions</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {isLoading && <tr><td colSpan={7} className="px-4 py-6 text-center text-slate-400">Chargement…</td></tr>}
            {!isLoading && data?.data?.length === 0 && <tr><td colSpan={7} className="px-4 py-6 text-center text-slate-400">Aucune société.</td></tr>}
            {data?.data?.map((s) => (
              <tr key={s.code} className="hover:bg-slate-50">
                <td className="px-4 py-3 font-medium text-slate-800">{s.code}</td>
                <td className="px-4 py-3 text-slate-600">{s.nom}</td>
                <td className="px-4 py-3 text-slate-600">{s.ville ?? '—'}</td>
                <td className="px-4 py-3 text-slate-600">{s.etablissements_count ?? s.nb_etab ?? 0}</td>
                <td className="px-4 py-3">
                  <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${s.repris ? 'bg-primary-50 text-primary-700' : 'bg-amber-50 text-amber-700'}`}>{s.source}</span>
                </td>
                <td className="px-4 py-3">
                  <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${s.actif ? 'bg-green-50 text-green-700' : 'bg-slate-100 text-slate-500'}`}>{s.actif ? 'actif' : 'inactif'}</span>
                </td>
                <td className="px-4 py-3 text-right">
                  <div className="flex justify-end gap-2">
                    <Button variant="outline" className="!px-3 !py-1" onClick={() => { setErreurs({}); setForm({ ...VIDE, ...s }) }}>
                      {s.repris ? 'Éditer' : 'Reprendre'}
                    </Button>
                    {s.repris && (
                      <Button variant="outline" className="!px-3 !py-1" disabled={basculer.isPending} onClick={() => basculer.mutate({ id: s.id, actif: s.actif })}>
                        {s.actif ? 'Désactiver' : 'Activer'}
                      </Button>
                    )}
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
          <div className="w-full max-w-lg rounded-xl bg-white p-6 shadow-xl" onClick={(e) => e.stopPropagation()}>
            <h2 className="mb-4 text-lg font-bold text-slate-800">{form.id ? 'Modifier la société' : (form.code ? `Reprendre la société ${form.code} dans ECOPRIM` : 'Reprendre une société')}</h2>
            <form
              onSubmit={(e) => { e.preventDefault(); enregistrer.mutate(form) }}
              className="space-y-3"
            >
              <div className="grid grid-cols-2 gap-3">
                {form.id ? (
                  <Input label="Code" value={form.code ?? ''} disabled onChange={() => {}} />
                ) : (
                  <Select
                    label="Société (US_SOCIETE) *"
                    value={form.code ?? ''}
                    error={erreurs.code?.[0]}
                    onChange={(e) => {
                      const s = aReprendre.find((x) => x.code === e.target.value)
                      setForm((f) => ({
                        ...f,
                        code: s?.code ?? '',
                        nom: s?.nom ?? '',
                        ville: s?.ville ?? '',
                        adresse: s?.adresse ?? '',
                        telephone: s?.telephone ?? '',
                        email: s?.email ?? '',
                        representant: s?.representant ?? '',
                      }))
                    }}
                  >
                    <option value="">— Choisir dans US_SOCIETE —</option>
                    {aReprendre.map((s) => (
                      <option key={s.code} value={s.code}>{s.nom} ({s.code})</option>
                    ))}
                  </Select>
                )}
                <Input label="Nom *" value={form.nom ?? ''} onChange={(e) => champ('nom', e.target.value)} error={erreurs.nom?.[0]} />
                <Input label="Ville" value={form.ville ?? ''} onChange={(e) => champ('ville', e.target.value)} error={erreurs.ville?.[0]} />
                <Input label="Téléphone" value={form.telephone ?? ''} onChange={(e) => champ('telephone', e.target.value)} error={erreurs.telephone?.[0]} />
                <Input label="Email" value={form.email ?? ''} onChange={(e) => champ('email', e.target.value)} error={erreurs.email?.[0]} />
                <Input label="Représentant" value={form.representant ?? ''} onChange={(e) => champ('representant', e.target.value)} error={erreurs.representant?.[0]} />
              </div>
              <Input label="Adresse" value={form.adresse ?? ''} onChange={(e) => champ('adresse', e.target.value)} error={erreurs.adresse?.[0]} />
              {!form.id && (
                <p className="text-xs text-slate-400">
                  Reprise : seule la copie ECOPRIM est créée, US_SOCIETE reste inchangée. ECOPRIM
                  n'invente jamais de société — la liste ci-dessus vient exclusivement de US_SOCIETE.
                </p>
              )}
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
