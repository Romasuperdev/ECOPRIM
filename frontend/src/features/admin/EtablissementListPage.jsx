import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import Button from '../../components/ui/Button'
import Input from '../../components/ui/Input'
import Select from '../../components/ui/Select'
import {
  activerEtablissement,
  createEtablissement,
  desactiverEtablissement,
  fetchEtablissements,
  updateEtablissement,
} from './adminApi'
import { fetchConsoleContexte } from './consoleContexteApi'

const VIDE = {
  code: '', intitule: '', type: '', adresse: '', ville: '', pays: 'Côte d’Ivoire',
  telephone: '', email: '', site_web: '', societe_code: '',
}

const TYPES = ['PRIVE', 'PUBLIC', 'CONFESSIONNEL']

export default function EtablissementListPage() {
  const [page, setPage] = useState(1)
  const [q, setQ] = useState('')
  const [filtreSociete, setFiltreSociete] = useState('')
  const [form, setForm] = useState(null)
  const [erreurs, setErreurs] = useState({})
  const qc = useQueryClient()

  const { data, isLoading } = useQuery({
    queryKey: ['etablissements', page, q, filtreSociete],
    queryFn: () => fetchEtablissements(page, { q, societe_code: filtreSociete }),
  })
  // La liste des sociétés vient du contexte de la console, pas de /societes : cette
  // dernière est réservée au Super Admin, et un Admin Société se retrouvait sans aucune
  // société sélectionnable — donc incapable de créer un établissement.
  const { data: contexte } = useQuery({ queryKey: ['console-contexte'], queryFn: fetchConsoleContexte, retry: false })
  const societes = contexte?.societes

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
          <p className="mt-1 text-sm text-slate-500">
            Établissements réels lus dans ECONOMAT.BEtablissements, complétés par ECOPRIM.
          </p>
        </div>
        <Button disabled={!societes?.length} onClick={() => { setErreurs({}); setForm({ ...VIDE }) }}>
          + Nouvel établissement
        </Button>
      </div>

      {/* Filtres */}
      <div className="mb-4 flex flex-wrap items-end gap-3">
        <div className="min-w-[220px] flex-1">
          <Input label="Rechercher" placeholder="Intitulé, code ou ville…" value={q}
                 onChange={(e) => { setPage(1); setQ(e.target.value) }} />
        </div>
        <div className="min-w-[200px]">
          <Select label="Société" value={filtreSociete} onChange={(e) => { setPage(1); setFiltreSociete(e.target.value) }}>
            <option value="">Toutes les sociétés</option>
            {societes?.map((s) => <option key={s.code} value={s.code}>{s.nom}</option>)}
          </Select>
        </div>
      </div>

      <div className="overflow-hidden rounded-xl border border-slate-200 bg-white">
        <table className="w-full text-left text-sm">
          <thead className="border-b border-slate-200 bg-slate-50 text-slate-500">
            <tr>
              <th className="px-4 py-3 font-medium">Code</th>
              <th className="px-4 py-3 font-medium">Intitulé & localisation</th>
              <th className="px-4 py-3 font-medium">Société</th>
              <th className="px-4 py-3 font-medium">Contact</th>
              <th className="px-4 py-3 font-medium">Source</th>
              <th className="px-4 py-3 font-medium">Statut</th>
              <th className="px-4 py-3 font-medium text-right">Actions</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {isLoading && <tr><td colSpan={7} className="px-4 py-6 text-center text-slate-400">Chargement…</td></tr>}
            {!isLoading && data?.data?.length === 0 && (
              <tr><td colSpan={7} className="px-4 py-6 text-center text-slate-400">Aucun établissement.</td></tr>
            )}
            {data?.data?.map((e) => (
              <tr key={e.code} className="hover:bg-slate-50">
                <td className="px-4 py-3 font-mono text-xs font-medium text-slate-800">{e.code}</td>
                <td className="px-4 py-3">
                  <Link to={`/admin/etablissements/${e.code}`} className="font-medium text-primary-700 hover:underline">
                    {e.intitule}
                  </Link>
                  <div className="text-xs text-slate-400">{[e.ville, e.pays].filter(Boolean).join(', ') || '—'}</div>
                </td>
                <td className="px-4 py-3 text-slate-600">{e.societe_code ?? '—'}</td>
                <td className="px-4 py-3 text-xs text-slate-600">
                  <div>{e.telephone ?? '—'}</div>
                  <div className="text-slate-400">{e.email ?? ''}</div>
                </td>
                <td className="px-4 py-3">
                  <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${e.repris ? 'bg-primary-50 text-primary-700' : 'bg-amber-50 text-amber-700'}`}>
                    {e.source}
                  </span>
                </td>
                <td className="px-4 py-3">
                  <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${e.actif ? 'bg-green-50 text-green-700' : 'bg-slate-100 text-slate-500'}`}>
                    {e.actif ? 'actif' : 'inactif'}
                  </span>
                </td>
                <td className="px-4 py-3 text-right">
                  <div className="flex justify-end gap-2">
                    <Button variant="outline" className="!px-3 !py-1"
                            onClick={() => { setErreurs({}); setForm({ ...VIDE, ...e }) }}>
                      {e.repris ? 'Éditer' : 'Reprendre'}
                    </Button>
                    {e.repris && (
                      <Button variant="outline" className="!px-3 !py-1" disabled={basculer.isPending}
                              onClick={() => basculer.mutate({ id: e.id, actif: e.actif })}>
                        {e.actif ? 'Désactiver' : 'Activer'}
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
          <div className="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-xl bg-white p-6 shadow-xl" onClick={(ev) => ev.stopPropagation()}>
            <h2 className="mb-4 text-lg font-bold text-slate-800">
              {form.id ? "Modifier l'établissement" : (form.code ? `Reprendre ${form.code} dans ECOPRIM` : 'Nouvel établissement')}
            </h2>
            <form onSubmit={(ev) => { ev.preventDefault(); enregistrer.mutate(form) }} className="space-y-4">

              <p className="text-xs font-semibold uppercase tracking-wide text-slate-400">Identité</p>
              <div className="grid grid-cols-2 gap-3">
                <Input label="Code *" value={form.code ?? ''} onChange={(ev) => champ('code', ev.target.value)}
                       error={erreurs.code?.[0]} disabled={!!form.id} />
                <Input label="Intitulé *" value={form.intitule ?? ''} onChange={(ev) => champ('intitule', ev.target.value)} error={erreurs.intitule?.[0]} />
                <Select label="Société *" value={form.societe_code ?? ''} onChange={(ev) => champ('societe_code', ev.target.value)} error={erreurs.societe_code?.[0]}>
                  <option value="">— Choisir —</option>
                  {societes?.map((s) => <option key={s.code} value={s.code}>{s.nom} ({s.code})</option>)}
                </Select>
                <Select label="Type" value={form.type ?? ''} onChange={(ev) => champ('type', ev.target.value)} error={erreurs.type?.[0]}>
                  <option value="">— Non précisé —</option>
                  {TYPES.map((t) => <option key={t} value={t}>{t === 'PRIVE' ? 'Privé' : t === 'PUBLIC' ? 'Public' : 'Confessionnel'}</option>)}
                </Select>
              </div>

              <p className="text-xs font-semibold uppercase tracking-wide text-slate-400">Localisation</p>
              <div className="grid grid-cols-2 gap-3">
                <Input label="Adresse *" value={form.adresse ?? ''} onChange={(ev) => champ('adresse', ev.target.value)} error={erreurs.adresse?.[0]} />
                <Input label="Pays *" value={form.pays ?? ''} onChange={(ev) => champ('pays', ev.target.value)} error={erreurs.pays?.[0]} />
                <Input label="Ville" value={form.ville ?? ''} onChange={(ev) => champ('ville', ev.target.value)} error={erreurs.ville?.[0]} />
              </div>

              <p className="text-xs font-semibold uppercase tracking-wide text-slate-400">Coordonnées</p>
              <div className="grid grid-cols-2 gap-3">
                <Input label="Téléphone" value={form.telephone ?? ''} onChange={(ev) => champ('telephone', ev.target.value)} error={erreurs.telephone?.[0]} />
                <Input label="Email" value={form.email ?? ''} onChange={(ev) => champ('email', ev.target.value)} error={erreurs.email?.[0]} />
                <Input label="Site web" value={form.site_web ?? ''} onChange={(ev) => champ('site_web', ev.target.value)} error={erreurs.site_web?.[0]} />
              </div>

              {!form.id && (
                <p className="text-xs text-slate-400">
                  {form.repris
                    ? 'Reprise : seule la copie ECOPRIM est créée, BEtablissements reste inchangée.'
                    : 'Nouvel établissement : il sera enregistré dans BEtablissements puis dans ECOPRIM.'}
                </p>
              )}
              {erreurs._ && <p className="text-sm text-red-600">{erreurs._[0]}</p>}

              <div className="flex justify-end gap-2 pt-2">
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
