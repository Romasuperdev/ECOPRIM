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
  creerDevoir,
  fetchDevoirs,
  fetchReferentielsDevoir,
  modifierDevoir,
  supprimerDevoir,
} from './devoirsApi'

const VIDE = { titre: '', consigne: '', classe: '', matiere: '', enseignant: '', date_remise: '' }

export default function DevoirListPage() {
  const qc = useQueryClient()
  const [classeFiltre, setClasseFiltre] = useState('')
  const [form, setForm] = useState(null)
  const [erreurs, setErreurs] = useState({})
  const [refus, setRefus] = useState(null)

  const { data: ref } = useQuery({ queryKey: ['devoirs-ref'], queryFn: fetchReferentielsDevoir })
  const { data: devoirs, isLoading } = useQuery({
    queryKey: ['devoirs', classeFiltre],
    queryFn: () => fetchDevoirs(classeFiltre || undefined),
  })

  const verrouille = Boolean(ref?.annee_cloturee)
  const invalider = () => qc.invalidateQueries({ queryKey: ['devoirs'] })
  const fermer = () => { setForm(null); setErreurs({}) }

  const enregistrer = useMutation({
    mutationFn: (v) => (v.id ? modifierDevoir(v.id, v) : creerDevoir(v)),
    onSuccess: () => { invalider(); fermer() },
    onError: (e) => setErreurs(e?.response?.data?.errors ?? { _: [e?.response?.data?.message ?? 'Erreur'] }),
  })

  const supprimer = useMutation({
    mutationFn: supprimerDevoir,
    onSuccess: () => { invalider(); setRefus(null) },
    onError: (e) => setRefus(e?.response?.data?.message ?? 'Suppression impossible.'),
  })

  const champ = (k, v) => setForm((f) => ({ ...f, [k]: v }))

  return (
    <div>
      <div className="mb-6 flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold text-slate-800">Devoirs</h1>
          <p className="mt-1 text-sm text-slate-500">
            Travaux donnés à une classe, avec une date de remise — distinct du cahier de textes.
          </p>
        </div>
        <Button
          disabled={verrouille}
          title={verrouille ? 'Année clôturée : consultation seule.' : undefined}
          onClick={() => { setErreurs({}); setForm({ ...VIDE }) }}
        >
          <Plus size={16} className="mr-1.5 inline" />
          Donner un devoir
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
        <Select label="Filtrer par classe" value={classeFiltre} onChange={(e) => setClasseFiltre(e.target.value)}>
          <option value="">Toutes les classes</option>
          {ref?.classes?.map((c) => <option key={c.code} value={c.code}>{c.libelle}</option>)}
        </Select>
      </div>

      <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table className="w-full text-left text-sm">
          <thead className="border-b border-slate-200 bg-slate-50 text-slate-500">
            <tr>
              <th className="px-4 py-3 font-medium">Titre</th>
              <th className="px-4 py-3 font-medium">Classe</th>
              <th className="px-4 py-3 font-medium">Matière</th>
              <th className="px-4 py-3 font-medium">Enseignant</th>
              <th className="px-4 py-3 font-medium">À rendre le</th>
              <th className="px-4 py-3 font-medium text-right">Actions</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {isLoading && (
              <tr><td colSpan={6} className="px-4 py-6 text-center text-slate-400">Chargement…</td></tr>
            )}
            {!isLoading && devoirs?.length === 0 && (
              <tr><td colSpan={6} className="px-4 py-6 text-center text-slate-400">Aucun devoir donné.</td></tr>
            )}
            {devoirs?.map((d) => {
              const enRetard = !verrouille && !d.annee_cloturee && new Date(d.date_remise) < new Date(new Date().toDateString())

              return (
                <tr key={d.id} className="hover:bg-slate-50">
                  <td className="px-4 py-3 font-medium text-slate-800">{d.titre}</td>
                  <td className="px-4 py-3 text-slate-600">{d.classe_libelle}</td>
                  <td className="px-4 py-3 text-slate-600">{d.matiere_libelle}</td>
                  <td className="px-4 py-3 text-slate-600">{d.enseignant_nom ?? '—'}</td>
                  <td className="px-4 py-3">
                    <span className={`rounded-full px-2 py-0.5 text-xs font-semibold ${enRetard ? 'bg-red-50 text-red-700' : 'bg-slate-100 text-slate-600'}`}>
                      {d.date_remise}
                    </span>
                  </td>
                  <td className="px-4 py-3 text-right">
                    <Button
                      variant="outline" className="!px-3 !py-1 mr-2" disabled={verrouille || d.annee_cloturee}
                      onClick={() => {
                        setErreurs({})
                        setForm({
                          id: d.id, titre: d.titre, consigne: d.consigne ?? '', classe: d.classe,
                          matiere: d.matiere, enseignant: d.enseignant ?? '', date_remise: d.date_remise,
                        })
                      }}
                    >
                      Éditer
                    </Button>
                    <Button
                      variant="outline" className="!px-3 !py-1" disabled={verrouille || d.annee_cloturee}
                      title="Retirer ce devoir"
                      onClick={() => supprimer.mutate(d.id)}
                    >
                      <Trash2 size={14} />
                    </Button>
                  </td>
                </tr>
              )
            })}
          </tbody>
        </table>
      </div>

      {form && (
        <ModaleFormulaire
          titre={form.id ? 'Modifier le devoir' : 'Donner un devoir'}
          onFermer={fermer}
          onValider={() => enregistrer.mutate(form)}
          enCours={enregistrer.isPending}
          valideDesactive={!form.titre || !form.classe || !form.matiere || !form.date_remise}
        >
          <Input label="Titre *" placeholder="Exercices sur les fractions" value={form.titre}
                 error={erreurs.titre?.[0]} onChange={(e) => champ('titre', e.target.value)} />
          <div className="grid grid-cols-2 gap-3">
            <Select label="Classe *" value={form.classe} error={erreurs.classe?.[0]}
                    onChange={(e) => champ('classe', e.target.value)}>
              <option value="">— Choisir —</option>
              {ref?.classes?.map((c) => <option key={c.code} value={c.code}>{c.libelle}</option>)}
            </Select>
            <Select label="Matière *" value={form.matiere} error={erreurs.matiere?.[0]}
                    onChange={(e) => champ('matiere', e.target.value)}>
              <option value="">— Choisir —</option>
              {ref?.matieres?.map((m) => <option key={m.code} value={m.code}>{m.libelle}</option>)}
            </Select>
          </div>
          <div className="grid grid-cols-2 gap-3">
            <Select label="Enseignant" value={form.enseignant} error={erreurs.enseignant?.[0]}
                    onChange={(e) => champ('enseignant', e.target.value)}>
              <option value="">— Non renseigné —</option>
              {ref?.enseignants?.map((p) => <option key={p.code} value={p.code}>{p.nom}</option>)}
            </Select>
            <Input label="À rendre le *" type="date" value={form.date_remise} error={erreurs.date_remise?.[0]}
                   onChange={(e) => champ('date_remise', e.target.value)} />
          </div>
          <Textarea label="Consigne" placeholder="Faire les exercices 1 à 5 page 32." value={form.consigne}
                    error={erreurs.consigne?.[0]} onChange={(e) => champ('consigne', e.target.value)} />
          {erreurs._ && <p className="text-sm text-red-600">{erreurs._[0]}</p>}
        </ModaleFormulaire>
      )}
    </div>
  )
}
