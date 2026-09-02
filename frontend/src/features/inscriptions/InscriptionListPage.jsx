import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import Button from '../../components/ui/Button'
import Input from '../../components/ui/Input'
import Select from '../../components/ui/Select'
import {
  createInscription,
  fetchInscriptions,
  fetchReferentiels,
  updateInscription,
} from './inscriptionsApi'

const MOUVEMENTS = [
  { cle: 'inscription', label: 'Inscription' },
  { cle: 'reinscription', label: 'Réinscription' },
  { cle: 'transfert_entrant', label: 'Transfert entrant' },
  { cle: 'transfert_sortant', label: 'Transfert sortant' },
]

const VIDE = {
  mouvement: 'inscription',
  matricule: '', nom: '', prenom: '', sexe: '', date_naissance: '', lieu_naissance: '', nationalite: '',
  adresse: '', ville: '', commune: '', quartier: '', telephone: '', email: '',
  annee: '', cycle_code: '', niveau_code: '', classe_code: '', redoublant: '',
  etab_origine: '', niveau_origine: '', date_inscription: '',
  pere_nom: '', pere_prenom: '', pere_profession: '', pere_telephone: '', pere_email: '',
  mere_nom: '', mere_prenom: '', mere_profession: '', mere_telephone: '', mere_email: '',
}

export default function InscriptionListPage() {
  const [page, setPage] = useState(1)
  const [filtres, setFiltres] = useState({ q: '', annee: '', classe: '', mouvement: '' })
  const [form, setForm] = useState(null)
  const [erreurs, setErreurs] = useState({})
  const qc = useQueryClient()

  const { data, isLoading } = useQuery({
    queryKey: ['inscriptions', page, filtres],
    queryFn: () => fetchInscriptions({
      page,
      q: filtres.q || undefined,
      annee: filtres.annee || undefined,
      classe: filtres.classe || undefined,
      mouvement: filtres.mouvement || undefined,
    }),
  })
  const { data: ref } = useQuery({ queryKey: ['referentiels'], queryFn: fetchReferentiels })

  const invalider = () => qc.invalidateQueries({ queryKey: ['inscriptions'] })

  const enregistrer = useMutation({
    mutationFn: (v) => (v.id ? updateInscription(v.id, v) : createInscription(v)),
    onSuccess: () => { invalider(); setForm(null); setErreurs({}) },
    onError: (e) => setErreurs(e?.response?.data?.errors ?? { _: [e?.response?.data?.message ?? 'Erreur'] }),
  })

  const champ = (k, v) => setForm((f) => ({ ...f, [k]: v }))
  const err = (k) => erreurs[k]?.[0]

  // Les niveaux et classes se restreignent au cycle / niveau choisi quand l'info existe.
  const niveauxFiltres = (ref?.niveaux ?? []).filter((n) => !form?.cycle_code || n.cycle_code === form.cycle_code)
  const classesFiltrees = (ref?.classes ?? []).filter((c) => !form?.niveau_code || c.niveau_code === form.niveau_code)

  return (
    <div>
      <div className="mb-6 flex items-start justify-between">
        <div>
          <h1 className="text-2xl font-bold text-slate-800">Inscriptions</h1>
          <p className="mt-1 text-sm text-slate-500">
            Inscriptions, réinscriptions et transferts — saisie directe dans ECONOMAT.T_ETUDIANT.
          </p>
        </div>
        <Button onClick={() => {
          setErreurs({})
          setForm({ ...VIDE, annee: filtres.annee || ref?.annees?.find((a) => a.active)?.libelle || '' })
        }}>
          + Nouvelle inscription
        </Button>
      </div>

      {/* Filtres */}
      <div className="mb-4 flex flex-wrap items-end gap-3">
        <div className="min-w-[200px] flex-1">
          <Input label="Rechercher" placeholder="Nom, prénom ou matricule…" value={filtres.q}
                 onChange={(e) => { setPage(1); setFiltres((f) => ({ ...f, q: e.target.value })) }} />
        </div>
        <div className="min-w-[160px]">
          <Select label="Type de mouvement" value={filtres.mouvement}
                  onChange={(e) => { setPage(1); setFiltres((f) => ({ ...f, mouvement: e.target.value })) }}>
            <option value="">Tous</option>
            {MOUVEMENTS.map((m) => <option key={m.cle} value={m.cle}>{m.label}</option>)}
          </Select>
        </div>
        <div className="min-w-[150px]">
          <Select label="Année" value={filtres.annee}
                  onChange={(e) => { setPage(1); setFiltres((f) => ({ ...f, annee: e.target.value })) }}>
            <option value="">Toutes</option>
            {ref?.annees?.map((a) => <option key={a.id} value={a.libelle}>{a.libelle}</option>)}
          </Select>
        </div>
        <div className="min-w-[150px]">
          <Select label="Classe" value={filtres.classe}
                  onChange={(e) => { setPage(1); setFiltres((f) => ({ ...f, classe: e.target.value })) }}>
            <option value="">Toutes</option>
            {ref?.classes?.map((c) => <option key={c.id ?? c.code} value={c.code}>{c.libelle ?? c.code}</option>)}
          </Select>
        </div>
      </div>

      <div className="overflow-hidden rounded-xl border border-slate-200 bg-white">
        <table className="w-full text-left text-sm">
          <thead className="border-b border-slate-200 bg-slate-50 text-slate-500">
            <tr>
              <th className="px-4 py-3 font-medium">Matricule</th>
              <th className="px-4 py-3 font-medium">Élève</th>
              <th className="px-4 py-3 font-medium">Classe</th>
              <th className="px-4 py-3 font-medium">Année</th>
              <th className="px-4 py-3 font-medium">Né(e) le</th>
              <th className="px-4 py-3 font-medium text-right">Actions</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {isLoading && <tr><td colSpan={6} className="px-4 py-6 text-center text-slate-400">Chargement…</td></tr>}
            {!isLoading && data?.data?.length === 0 && (
              <tr><td colSpan={6} className="px-4 py-6 text-center text-slate-400">Aucune inscription.</td></tr>
            )}
            {data?.data?.map((e) => (
              <tr key={e.id} className="hover:bg-slate-50">
                <td className="px-4 py-3 font-mono text-xs text-slate-700">{e.matricule ?? '—'}</td>
                <td className="px-4 py-3">
                  <div className="font-medium text-slate-800">{e.nom} {e.prenom}</div>
                  <div className="text-xs text-slate-400">{e.sexe ?? ''}</div>
                </td>
                <td className="px-4 py-3 text-slate-600">{e.classe_code ?? '—'}</td>
                <td className="px-4 py-3 text-slate-600">{e.annee ?? '—'}</td>
                <td className="px-4 py-3 text-slate-600">{e.date_naissance ?? '—'}</td>
                <td className="px-4 py-3 text-right">
                  <Button variant="outline" className="!px-3 !py-1"
                          onClick={() => {
                            setErreurs({})
                            setForm({ ...VIDE, ...Object.fromEntries(Object.entries(e).map(([k, v]) => [k, v ?? ''])), id: e.id })
                          }}>
                    Éditer
                  </Button>
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

      <p className="mt-3 text-xs text-slate-400">
        Les élèves sont partagés avec ECONOMAT : ils peuvent être créés et modifiés ici, jamais
        supprimés. Le volet financier (scolarité, versements, remises) reste géré dans ECONOMAT.
      </p>

      {form && (
        <div className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 p-4" onClick={() => setForm(null)}>
          <div className="my-8 w-full max-w-3xl rounded-xl bg-white p-6 shadow-xl" onClick={(ev) => ev.stopPropagation()}>
            <h2 className="mb-1 text-lg font-bold text-slate-800">
              {form.id ? `Modifier — ${form.nom} ${form.prenom}` : 'Nouvelle inscription'}
            </h2>
            <p className="mb-5 text-xs text-slate-400">Formulaire alimenté par ECONOMAT.T_ETUDIANT.</p>

            <form onSubmit={(ev) => { ev.preventDefault(); enregistrer.mutate(form) }} className="space-y-5">

              <div>
                <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">Type de mouvement</p>
                <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                  {MOUVEMENTS.map((m) => (
                    <button key={m.cle} type="button" onClick={() => champ('mouvement', m.cle)}
                            className={`rounded-xl border px-3 py-2 text-xs font-semibold transition ${
                              form.mouvement === m.cle
                                ? 'border-primary-500 bg-primary-50 text-primary-700'
                                : 'border-slate-200 text-slate-500 hover:bg-slate-50'}`}>
                      {m.label}
                    </button>
                  ))}
                </div>
                {err('mouvement') && <p className="mt-1 text-sm text-red-600">{err('mouvement')}</p>}
              </div>

              <div>
                <p className="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-400">Identité de l’élève</p>
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                  <Input label="Matricule" value={form.matricule} onChange={(e) => champ('matricule', e.target.value)} error={err('matricule')} />
                  <Input label="Nom *" value={form.nom} onChange={(e) => champ('nom', e.target.value)} error={err('nom')} />
                  <Input label="Prénom(s) *" value={form.prenom} onChange={(e) => champ('prenom', e.target.value)} error={err('prenom')} />
                  <Select label="Sexe" value={form.sexe} onChange={(e) => champ('sexe', e.target.value)} error={err('sexe')}>
                    <option value="">—</option>
                    <option value="M">Masculin</option>
                    <option value="F">Féminin</option>
                  </Select>
                  <Input label="Date de naissance" type="date" value={form.date_naissance} onChange={(e) => champ('date_naissance', e.target.value)} error={err('date_naissance')} />
                  <Input label="Lieu de naissance" value={form.lieu_naissance} onChange={(e) => champ('lieu_naissance', e.target.value)} error={err('lieu_naissance')} />
                  <Input label="Nationalité" value={form.nationalite} onChange={(e) => champ('nationalite', e.target.value)} error={err('nationalite')} />
                </div>
              </div>

              <div>
                <p className="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-400">Coordonnées</p>
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                  <Input label="Adresse" value={form.adresse} onChange={(e) => champ('adresse', e.target.value)} error={err('adresse')} />
                  <Input label="Ville" value={form.ville} onChange={(e) => champ('ville', e.target.value)} error={err('ville')} />
                  <Input label="Commune" value={form.commune} onChange={(e) => champ('commune', e.target.value)} error={err('commune')} />
                  <Input label="Quartier" value={form.quartier} onChange={(e) => champ('quartier', e.target.value)} error={err('quartier')} />
                  <Input label="Téléphone" value={form.telephone} onChange={(e) => champ('telephone', e.target.value)} error={err('telephone')} />
                  <Input label="Email" value={form.email} onChange={(e) => champ('email', e.target.value)} error={err('email')} />
                </div>
              </div>

              <div>
                <p className="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-400">Scolarité</p>
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                  <Select label="Année scolaire *" value={form.annee} onChange={(e) => champ('annee', e.target.value)} error={err('annee')}>
                    <option value="">— Choisir —</option>
                    {ref?.annees?.map((a) => <option key={a.id} value={a.libelle}>{a.libelle}</option>)}
                  </Select>
                  <Select label="Cycle" value={form.cycle_code} onChange={(e) => { champ('cycle_code', e.target.value); champ('niveau_code', ''); champ('classe_code', '') }} error={err('cycle_code')}>
                    <option value="">—</option>
                    {ref?.cycles?.map((c) => <option key={c.id ?? c.code} value={c.code}>{c.libelle ?? c.code}</option>)}
                  </Select>
                  <Select label="Niveau" value={form.niveau_code} onChange={(e) => { champ('niveau_code', e.target.value); champ('classe_code', '') }} error={err('niveau_code')}>
                    <option value="">—</option>
                    {niveauxFiltres.map((n) => <option key={n.id ?? n.code} value={n.code}>{n.libelle ?? n.code}</option>)}
                  </Select>
                  <Select label="Classe" value={form.classe_code} onChange={(e) => champ('classe_code', e.target.value)} error={err('classe_code')}>
                    <option value="">—</option>
                    {classesFiltrees.map((c) => <option key={c.id ?? c.code} value={c.code}>{c.libelle ?? c.code}</option>)}
                  </Select>
                  <Select label="Redoublant" value={form.redoublant} onChange={(e) => champ('redoublant', e.target.value)} error={err('redoublant')}>
                    <option value="">—</option>
                    <option value="OUI">Oui</option>
                    <option value="NON">Non</option>
                  </Select>
                  <Input label="Date d’inscription" type="date" value={form.date_inscription} onChange={(e) => champ('date_inscription', e.target.value)} error={err('date_inscription')} />
                </div>
                {(form.mouvement === 'transfert_entrant' || form.mouvement === 'transfert_sortant') && (
                  <div className="mt-3 grid grid-cols-2 gap-3">
                    <Input label="Établissement d’origine / d’accueil" value={form.etab_origine} onChange={(e) => champ('etab_origine', e.target.value)} error={err('etab_origine')} />
                    <Input label="Niveau d’origine" value={form.niveau_origine} onChange={(e) => champ('niveau_origine', e.target.value)} error={err('niveau_origine')} />
                  </div>
                )}
              </div>

              <div>
                <p className="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-400">Père / Tuteur</p>
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                  <Input label="Nom" value={form.pere_nom} onChange={(e) => champ('pere_nom', e.target.value)} error={err('pere_nom')} />
                  <Input label="Prénom(s)" value={form.pere_prenom} onChange={(e) => champ('pere_prenom', e.target.value)} error={err('pere_prenom')} />
                  <Input label="Profession" value={form.pere_profession} onChange={(e) => champ('pere_profession', e.target.value)} error={err('pere_profession')} />
                  <Input label="Téléphone" value={form.pere_telephone} onChange={(e) => champ('pere_telephone', e.target.value)} error={err('pere_telephone')} />
                  <Input label="Email" value={form.pere_email} onChange={(e) => champ('pere_email', e.target.value)} error={err('pere_email')} />
                </div>
              </div>

              <div>
                <p className="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-400">Mère</p>
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                  <Input label="Nom" value={form.mere_nom} onChange={(e) => champ('mere_nom', e.target.value)} error={err('mere_nom')} />
                  <Input label="Prénom(s)" value={form.mere_prenom} onChange={(e) => champ('mere_prenom', e.target.value)} error={err('mere_prenom')} />
                  <Input label="Profession" value={form.mere_profession} onChange={(e) => champ('mere_profession', e.target.value)} error={err('mere_profession')} />
                  <Input label="Téléphone" value={form.mere_telephone} onChange={(e) => champ('mere_telephone', e.target.value)} error={err('mere_telephone')} />
                  <Input label="Email" value={form.mere_email} onChange={(e) => champ('mere_email', e.target.value)} error={err('mere_email')} />
                </div>
              </div>

              {erreurs._ && <p className="text-sm text-red-600">{erreurs._[0]}</p>}

              <div className="flex justify-end gap-2 border-t border-slate-100 pt-4">
                <Button type="button" variant="outline" onClick={() => setForm(null)}>Annuler</Button>
                <Button type="submit" disabled={enregistrer.isPending}>
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
