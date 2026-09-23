import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Lock, Plus, Trash2 } from 'lucide-react'
import Select from '../../components/ui/Select'
import Input from '../../components/ui/Input'
import Textarea from '../../components/ui/Textarea'
import Button from '../../components/ui/Button'
import ModaleFormulaire from '../../components/ui/ModaleFormulaire'
import MessageRefus from '../../components/ui/MessageRefus'
import {
  creerEvenement,
  fetchEvenements,
  fetchReferentielsEvenement,
  modifierEvenement,
  supprimerEvenement,
} from './calendrierApi'

const VIDE = { titre: '', type: '', description: '', date_debut: '', date_fin: '', lieu: '', classe: '' }

const COULEUR_TYPE = {
  vacances: 'bg-amber-50 text-amber-700',
  reunion: 'bg-blue-50 text-blue-700',
  sortie: 'bg-green-50 text-green-700',
  activite: 'bg-purple-50 text-purple-700',
}

export default function CalendrierScolairePage() {
  const qc = useQueryClient()
  const [typeFiltre, setTypeFiltre] = useState('')
  const [form, setForm] = useState(null)
  const [erreurs, setErreurs] = useState({})
  const [refus, setRefus] = useState(null)

  const { data: ref } = useQuery({ queryKey: ['evenements-ref'], queryFn: fetchReferentielsEvenement })
  const { data: evenements, isLoading } = useQuery({
    queryKey: ['evenements', typeFiltre],
    queryFn: () => fetchEvenements(typeFiltre || undefined),
  })

  const verrouille = Boolean(ref?.annee_cloturee)
  const invalider = () => qc.invalidateQueries({ queryKey: ['evenements'] })
  const fermer = () => { setForm(null); setErreurs({}) }

  const enregistrer = useMutation({
    mutationFn: (v) => (v.id ? modifierEvenement(v.id, v) : creerEvenement(v)),
    onSuccess: () => { invalider(); fermer() },
    onError: (e) => setErreurs(e?.response?.data?.errors ?? { _: [e?.response?.data?.message ?? 'Erreur'] }),
  })

  const supprimer = useMutation({
    mutationFn: supprimerEvenement,
    onSuccess: () => { invalider(); setRefus(null) },
    onError: (e) => setRefus(e?.response?.data?.message ?? 'Suppression impossible.'),
  })

  const champ = (k, v) => setForm((f) => ({ ...f, [k]: v }))

  return (
    <div>
      <div className="mb-6 flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold text-slate-800">Calendrier scolaire</h1>
          <p className="mt-1 text-sm text-slate-500">
            Congés et vacances, réunions parents-professeurs, sorties et activités pédagogiques.
          </p>
        </div>
        <Button
          disabled={verrouille}
          title={verrouille ? 'Année clôturée : consultation seule.' : undefined}
          onClick={() => { setErreurs({}); setForm({ ...VIDE }) }}
        >
          <Plus size={16} className="mr-1.5 inline" />
          Ajouter un événement
        </Button>
      </div>

      {verrouille && (
        <div className="mb-4 flex items-start gap-2 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
          <Lock size={16} className="mt-0.5 shrink-0" />
          <span>L'année <strong>{ref?.annee}</strong> est clôturée : consultation seule.</span>
        </div>
      )}

      <MessageRefus message={refus} onFermer={() => setRefus(null)} />

      <div className="mb-4 max-w-xs">
        <Select label="Filtrer par type" value={typeFiltre} onChange={(e) => setTypeFiltre(e.target.value)}>
          <option value="">Tous les types</option>
          {ref?.types?.map((t) => <option key={t.code} value={t.code}>{t.libelle}</option>)}
        </Select>
      </div>

      <div className="overflow-x-auto rounded-xl border border-slate-200 bg-card">
        <table className="w-full text-left text-sm">
          <thead className="border-b border-slate-200 bg-slate-50 text-slate-500">
            <tr>
              <th className="px-4 py-3 font-medium">Titre</th>
              <th className="px-4 py-3 font-medium">Type</th>
              <th className="px-4 py-3 font-medium">Période</th>
              <th className="px-4 py-3 font-medium">Classe</th>
              <th className="px-4 py-3 font-medium">Lieu</th>
              <th className="px-4 py-3 font-medium text-right">Actions</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {isLoading && (
              <tr><td colSpan={6} className="px-4 py-6 text-center text-muted">Chargement…</td></tr>
            )}
            {!isLoading && evenements?.length === 0 && (
              <tr><td colSpan={6} className="px-4 py-6 text-center text-muted">Aucun événement.</td></tr>
            )}
            {evenements?.map((e) => (
              <tr key={e.id} className="hover:bg-slate-50">
                <td className="px-4 py-3 font-medium text-slate-800">{e.titre}</td>
                <td className="px-4 py-3">
                  <span className={`rounded-full px-2 py-0.5 text-xs font-semibold ${COULEUR_TYPE[e.type] ?? 'bg-slate-100 text-slate-600'}`}>
                    {e.type_libelle}
                  </span>
                </td>
                <td className="px-4 py-3 text-slate-600">
                  {e.date_debut}{e.date_fin !== e.date_debut ? ` → ${e.date_fin}` : ''}
                </td>
                <td className="px-4 py-3 text-slate-600">{e.classe_libelle ?? 'Tout l’établissement'}</td>
                <td className="px-4 py-3 text-slate-600">{e.lieu ?? '—'}</td>
                <td className="px-4 py-3 text-right">
                  <Button
                    variant="outline" className="!px-3 !py-1 mr-2" disabled={verrouille || e.annee_cloturee}
                    onClick={() => {
                      setErreurs({})
                      setForm({
                        id: e.id, titre: e.titre, type: e.type, description: e.description ?? '',
                        date_debut: e.date_debut, date_fin: e.date_fin, lieu: e.lieu ?? '', classe: e.classe ?? '',
                      })
                    }}
                  >
                    Éditer
                  </Button>
                  <Button
                    variant="outline" className="!px-3 !py-1" disabled={verrouille || e.annee_cloturee}
                    title="Retirer cet événement"
                    onClick={() => supprimer.mutate(e.id)}
                  >
                    <Trash2 size={14} />
                  </Button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {form && (
        <ModaleFormulaire
          titre={form.id ? 'Modifier l’événement' : 'Ajouter un événement'}
          onFermer={fermer}
          onValider={() => enregistrer.mutate(form)}
          enCours={enregistrer.isPending}
          valideDesactive={!form.titre || !form.type || !form.date_debut || !form.date_fin}
        >
          <Input label="Titre *" placeholder="Vacances de la Toussaint" value={form.titre}
                 error={erreurs.titre?.[0]} onChange={(e) => champ('titre', e.target.value)} />
          <Select label="Type *" value={form.type} error={erreurs.type?.[0]}
                  onChange={(e) => champ('type', e.target.value)}>
            <option value="">— Choisir —</option>
            {ref?.types?.map((t) => <option key={t.code} value={t.code}>{t.libelle}</option>)}
          </Select>
          <div className="grid grid-cols-2 gap-3">
            <Input label="Date de début *" type="date" value={form.date_debut} error={erreurs.date_debut?.[0]}
                   onChange={(e) => champ('date_debut', e.target.value)} />
            <Input label="Date de fin *" type="date" value={form.date_fin} error={erreurs.date_fin?.[0]}
                   onChange={(e) => champ('date_fin', e.target.value)} />
          </div>
          <div className="grid grid-cols-2 gap-3">
            <Select label="Classe concernée" value={form.classe} error={erreurs.classe?.[0]}
                    onChange={(e) => champ('classe', e.target.value)}>
              <option value="">— Tout l’établissement —</option>
              {ref?.classes?.map((c) => <option key={c.code} value={c.code}>{c.libelle}</option>)}
            </Select>
            <Input label="Lieu" placeholder="Cour de l’école, Salle CM2 A…" value={form.lieu}
                   error={erreurs.lieu?.[0]} onChange={(e) => champ('lieu', e.target.value)} />
          </div>
          <Textarea label="Description" placeholder="Détails utiles aux parents et enseignants." value={form.description}
                    error={erreurs.description?.[0]} onChange={(e) => champ('description', e.target.value)} />
          {erreurs._ && <p className="text-sm text-red-600">{erreurs._[0]}</p>}
        </ModaleFormulaire>
      )}
    </div>
  )
}
