import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import Button from '../../components/ui/Button'
import Input from '../../components/ui/Input'
import Select from '../../components/ui/Select'
import { Trash2 } from 'lucide-react'
import MessageRefus from '../../components/ui/MessageRefus'
import { createPrerequis, deletePrerequis, fetchPrerequis, updatePrerequis } from './parametresApi'

const VIDE = {
  libelle: '', type: '', code: '', niveau: '', annee: '',
  montant: '', quantite: '', a_inscription: true, a_scolarite: false,
}

export default function DocumentsElevesPage() {
  const [filtres, setFiltres] = useState({ q: '', annee: '', niveau: '' })
  const [form, setForm] = useState(null)
  const [erreurs, setErreurs] = useState({})
  const [refus, setRefus] = useState(null)
  const qc = useQueryClient()

  const { data: lignes, isLoading } = useQuery({
    queryKey: ['prerequis', filtres],
    queryFn: () => fetchPrerequis(filtres),
  })

  const invalider = () => qc.invalidateQueries({ queryKey: ['prerequis'] })

  const enregistrer = useMutation({
    mutationFn: (v) => {
      const charge = { ...v, montant: v.montant === '' ? null : Number(v.montant), quantite: v.quantite === '' ? null : Number(v.quantite) }
      return v.id ? updatePrerequis(v.id, charge) : createPrerequis(charge)
    },
    onSuccess: () => { invalider(); setForm(null); setErreurs({}) },
    onError: (e) => setErreurs(e?.response?.data?.errors ?? { _: [e?.response?.data?.message ?? 'Erreur'] }),
  })

  // Retrait refusé (409) si des élèves ont déjà ce document au dossier : T_PREREQUIS sert
  // à la fois de catalogue et de suivi par élève.
  const supprimer = useMutation({
    mutationFn: deletePrerequis,
    onSuccess: () => { invalider(); setRefus(null) },
    onError: (e) => setRefus(e?.response?.data?.message ?? 'Retrait impossible.'),
  })

  const champ = (k, v) => setForm((f) => ({ ...f, [k]: v }))
  const annees = [...new Set((lignes ?? []).map((l) => l.annee).filter(Boolean))].sort().reverse()
  const niveaux = [...new Set((lignes ?? []).map((l) => l.niveau).filter(Boolean))].sort()

  return (
    <div>
      <div className="mb-6 flex items-start justify-between">
        <div>
          <h1 className="text-2xl font-bold text-slate-800">Documents élèves</h1>
          <p className="mt-1 text-sm text-slate-500">
            Catalogue des documents et prérequis à fournir, par niveau et par année scolaire
            (source : ECONOMAT.T_PREREQUIS).
          </p>
        </div>
        <Button onClick={() => { setErreurs({}); setForm({ ...VIDE, annee: filtres.annee }) }}>
          + Nouveau document
        </Button>
      </div>

      <div className="mb-4 flex flex-wrap items-end gap-3">
        <div className="min-w-[220px] flex-1">
          <Input label="Rechercher" placeholder="Libellé du document…" value={filtres.q}
                 onChange={(e) => setFiltres((f) => ({ ...f, q: e.target.value }))} />
        </div>
        <div className="min-w-[150px]">
          <Select label="Année" value={filtres.annee} onChange={(e) => setFiltres((f) => ({ ...f, annee: e.target.value }))}>
            <option value="">Toutes</option>
            {annees.map((a) => <option key={a} value={a}>{a}</option>)}
          </Select>
        </div>
        <div className="min-w-[150px]">
          <Select label="Niveau" value={filtres.niveau} onChange={(e) => setFiltres((f) => ({ ...f, niveau: e.target.value }))}>
            <option value="">Tous</option>
            {niveaux.map((n) => <option key={n} value={n}>{n}</option>)}
          </Select>
        </div>
      </div>

      <MessageRefus message={refus} onFermer={() => setRefus(null)} />

      <div className="overflow-hidden rounded-xl border border-slate-200 bg-white">
        <table className="w-full text-left text-sm">
          <thead className="border-b border-slate-200 bg-slate-50 text-slate-500">
            <tr>
              <th className="px-4 py-3 font-medium">Document / prérequis</th>
              <th className="px-4 py-3 font-medium">Type</th>
              <th className="px-4 py-3 font-medium">Niveau</th>
              <th className="px-4 py-3 font-medium">Année</th>
              <th className="px-4 py-3 font-medium">Montant</th>
              <th className="px-4 py-3 font-medium">Exigé</th>
              <th className="px-4 py-3 font-medium text-right">Actions</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {isLoading && <tr><td colSpan={7} className="px-4 py-6 text-center text-slate-400">Chargement…</td></tr>}
            {!isLoading && lignes?.length === 0 && (
              <tr><td colSpan={7} className="px-4 py-6 text-center text-slate-400">Aucun document paramétré.</td></tr>
            )}
            {lignes?.map((l) => (
              <tr key={l.id} className="hover:bg-slate-50">
                <td className="px-4 py-3 font-medium text-slate-800">{l.libelle}</td>
                <td className="px-4 py-3 text-slate-600">{l.type ?? '—'}</td>
                <td className="px-4 py-3 text-slate-600">{l.niveau ?? 'Tous'}</td>
                <td className="px-4 py-3 text-slate-600">{l.annee ?? '—'}</td>
                <td className="px-4 py-3 text-slate-600">
                  {l.montant ? `${l.montant.toLocaleString('fr-FR')} F` : '—'}
                </td>
                <td className="px-4 py-3">
                  <div className="flex flex-wrap gap-1">
                    {l.a_inscription && <span className="rounded-full bg-primary-50 px-2 py-0.5 text-xs font-medium text-primary-700">Inscription</span>}
                    {l.a_scolarite && <span className="rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700">Scolarité</span>}
                    {!l.a_inscription && !l.a_scolarite && <span className="text-xs text-slate-400">—</span>}
                  </div>
                </td>
                <td className="px-4 py-3 text-right">
                  <Button variant="outline" className="!px-3 !py-1 mr-2"
                          onClick={() => { setErreurs({}); setForm({ ...VIDE, ...l, montant: l.montant ?? '', quantite: l.quantite ?? '' }) }}>
                    Éditer
                  </Button>
                  <Button variant="outline" className="!px-3 !py-1"
                          title="Retirer — refusé si des élèves ont déjà ce document au dossier"
                          onClick={() => supprimer.mutate(l.id)}>
                    <Trash2 size={14} />
                  </Button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <p className="mt-3 text-xs text-slate-400">
        Ces lignes sont partagées avec ECONOMAT : elles peuvent être créées, modifiées et
        retirées ici — le retrait étant refusé dès qu'un élève a déjà ce document au dossier.
      </p>

      {form && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" onClick={() => setForm(null)}>
          <div className="max-h-[90vh] w-full max-w-xl overflow-y-auto rounded-xl bg-white p-6 shadow-xl" onClick={(ev) => ev.stopPropagation()}>
            <h2 className="mb-4 text-lg font-bold text-slate-800">
              {form.id ? 'Modifier le document' : 'Nouveau document / prérequis'}
            </h2>
            <form onSubmit={(ev) => { ev.preventDefault(); enregistrer.mutate(form) }} className="space-y-3">
              <Input label="Libellé *" value={form.libelle ?? ''} onChange={(ev) => champ('libelle', ev.target.value)}
                     error={erreurs.libelle?.[0]} placeholder="ex. Extrait de naissance" />
              <div className="grid grid-cols-2 gap-3">
                <Input label="Type" value={form.type ?? ''} onChange={(ev) => champ('type', ev.target.value)}
                       error={erreurs.type?.[0]} placeholder="ex. DOCUMENT, FOURNITURE" />
                <Input label="Code" value={form.code ?? ''} onChange={(ev) => champ('code', ev.target.value)} error={erreurs.code?.[0]} />
                <Input label="Année *" value={form.annee ?? ''} onChange={(ev) => champ('annee', ev.target.value)}
                       error={erreurs.annee?.[0]} placeholder="ex. 2025-2026" />
                <Input label="Niveau (vide = tous)" value={form.niveau ?? ''} onChange={(ev) => champ('niveau', ev.target.value)} error={erreurs.niveau?.[0]} />
                <Input label="Montant (F CFA)" type="number" min="0" value={form.montant ?? ''}
                       onChange={(ev) => champ('montant', ev.target.value)} error={erreurs.montant?.[0]} />
                <Input label="Quantité" type="number" min="0" value={form.quantite ?? ''}
                       onChange={(ev) => champ('quantite', ev.target.value)} error={erreurs.quantite?.[0]} />
              </div>

              <div className="space-y-2 pt-1">
                <label className="flex items-center gap-2 text-sm text-slate-700">
                  <input type="checkbox" checked={!!form.a_inscription} onChange={(ev) => champ('a_inscription', ev.target.checked)} />
                  Exigé à l’inscription
                </label>
                <label className="flex items-center gap-2 text-sm text-slate-700">
                  <input type="checkbox" checked={!!form.a_scolarite} onChange={(ev) => champ('a_scolarite', ev.target.checked)} />
                  Exigé au titre de la scolarité
                </label>
              </div>

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
