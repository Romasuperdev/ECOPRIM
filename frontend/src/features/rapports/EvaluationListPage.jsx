import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Lock, Plus, Trash2 } from 'lucide-react'
import Select from '../../components/ui/Select'
import Input from '../../components/ui/Input'
import Button from '../../components/ui/Button'
import ModaleFormulaire from '../../components/ui/ModaleFormulaire'
import MessageRefus from '../../components/ui/MessageRefus'
import { fetchEvaluations } from './rapportsApi'
import { fetchAllClasses } from '../reference/referenceApi'
import {
  creerEvaluationPlanifiee,
  fetchEvaluationsPlanifiees,
  fetchReferentielsEvaluation,
  modifierEvaluationPlanifiee,
  supprimerEvaluationPlanifiee,
} from './evaluationsPlanifieesApi'

const TYPE_LABELS = {
  devoir: 'Devoir',
  composition: 'Composition',
  interrogation: 'Interrogation',
}

const VIDE = {
  titre: '', classe: '', matiere: '', enseignant: '', type: '',
  date: '', heure_debut: '', heure_fin: '', coefficient: '1', note_maximale: '20',
}

/** Planification d'un devoir/composition à venir — table propre à NEXORA (pas ECONOMAT). */
function SectionPlanification() {
  const qc = useQueryClient()
  const [form, setForm] = useState(null)
  const [erreurs, setErreurs] = useState({})
  const [refus, setRefus] = useState(null)

  const { data: ref } = useQuery({ queryKey: ['evaluations-planifiees-ref'], queryFn: fetchReferentielsEvaluation })
  const { data: evaluations, isLoading } = useQuery({
    queryKey: ['evaluations-planifiees'],
    queryFn: () => fetchEvaluationsPlanifiees(),
  })

  const verrouille = Boolean(ref?.annee_cloturee)
  const invalider = () => qc.invalidateQueries({ queryKey: ['evaluations-planifiees'] })
  const fermer = () => { setForm(null); setErreurs({}) }

  const enregistrer = useMutation({
    mutationFn: (v) => (v.id ? modifierEvaluationPlanifiee(v.id, v) : creerEvaluationPlanifiee(v)),
    onSuccess: () => { invalider(); fermer() },
    onError: (e) => setErreurs(e?.response?.data?.errors ?? { _: [e?.response?.data?.message ?? 'Erreur'] }),
  })

  const supprimer = useMutation({
    mutationFn: supprimerEvaluationPlanifiee,
    onSuccess: () => { invalider(); setRefus(null) },
    onError: (e) => setRefus(e?.response?.data?.message ?? 'Suppression impossible.'),
  })

  const champ = (k, v) => setForm((f) => ({ ...f, [k]: v }))

  return (
    <div className="mb-10">
      <div className="mb-4 flex items-start justify-between">
        <div>
          <h2 className="text-lg font-semibold text-slate-800">Évaluations planifiées</h2>
          <p className="text-sm text-slate-500">
            Devoirs et compositions annoncés, avant toute note saisie.
          </p>
        </div>
        <Button
          disabled={verrouille}
          title={verrouille ? 'Année clôturée : consultation seule.' : undefined}
          onClick={() => { setErreurs({}); setForm({ ...VIDE }) }}
        >
          <Plus size={16} className="mr-1.5 inline" />
          Planifier une évaluation
        </Button>
      </div>

      {verrouille && (
        <div className="mb-4 flex items-start gap-2 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
          <Lock size={16} className="mt-0.5 shrink-0" />
          <span>L’année <strong>{ref?.annee}</strong> est clôturée : consultation seule.</span>
        </div>
      )}

      <MessageRefus message={refus} onFermer={() => setRefus(null)} />

      <div className="overflow-hidden rounded-xl border border-slate-200 bg-white">
        <table className="w-full text-left text-sm">
          <thead className="border-b border-slate-200 bg-slate-50 text-slate-500">
            <tr>
              <th className="px-4 py-3 font-medium">Titre</th>
              <th className="px-4 py-3 font-medium">Classe</th>
              <th className="px-4 py-3 font-medium">Matière</th>
              <th className="px-4 py-3 font-medium">Enseignant</th>
              <th className="px-4 py-3 font-medium">Type</th>
              <th className="px-4 py-3 font-medium">Date</th>
              <th className="px-4 py-3 font-medium">Horaire</th>
              <th className="px-4 py-3 font-medium">Coef.</th>
              <th className="px-4 py-3 font-medium text-right">Actions</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {isLoading && (
              <tr><td colSpan={9} className="px-4 py-6 text-center text-slate-400">Chargement…</td></tr>
            )}
            {!isLoading && evaluations?.length === 0 && (
              <tr><td colSpan={9} className="px-4 py-6 text-center text-slate-400">Aucune évaluation planifiée.</td></tr>
            )}
            {evaluations?.map((e) => (
              <tr key={e.id} className="hover:bg-slate-50">
                <td className="px-4 py-3 font-medium text-slate-800">{e.titre}</td>
                <td className="px-4 py-3 text-slate-600">{e.classe_libelle}</td>
                <td className="px-4 py-3 text-slate-600">{e.matiere_libelle}</td>
                <td className="px-4 py-3 text-slate-600">{e.enseignant_nom ?? '—'}</td>
                <td className="px-4 py-3 text-slate-600">{e.type}</td>
                <td className="px-4 py-3 text-slate-600">{e.date}</td>
                <td className="px-4 py-3 text-slate-600">
                  {e.heure_debut ? `${e.heure_debut}${e.heure_fin ? ' – '+e.heure_fin : ''}` : '—'}
                </td>
                <td className="px-4 py-3 text-slate-600">{e.coefficient}</td>
                <td className="px-4 py-3 text-right">
                  <Button
                    variant="outline" className="!px-3 !py-1 mr-2" disabled={verrouille || e.annee_cloturee}
                    onClick={() => {
                      setErreurs({})
                      setForm({
                        id: e.id, titre: e.titre, classe: e.classe, matiere: e.matiere,
                        enseignant: e.enseignant ?? '', type: e.type, date: e.date,
                        heure_debut: e.heure_debut ?? '', heure_fin: e.heure_fin ?? '',
                        coefficient: String(e.coefficient), note_maximale: String(e.note_maximale),
                      })
                    }}
                  >
                    Éditer
                  </Button>
                  <Button
                    variant="outline" className="!px-3 !py-1" disabled={verrouille || e.annee_cloturee}
                    title="Retirer cette évaluation planifiée"
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
          titre={form.id ? 'Modifier l’évaluation' : 'Planifier une évaluation'}
          onFermer={fermer}
          onValider={() => enregistrer.mutate(form)}
          enCours={enregistrer.isPending}
          valideDesactive={!form.titre || !form.classe || !form.matiere || !form.type || !form.date}
        >
          <Input label="Titre *" placeholder="Devoir de Mathématiques N°1" value={form.titre}
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
            <Select label="Type *" value={form.type} error={erreurs.type?.[0]}
                    onChange={(e) => champ('type', e.target.value)}>
              <option value="">— Choisir —</option>
              {ref?.types?.map((t) => <option key={t} value={t}>{t}</option>)}
            </Select>
          </div>
          <Input label="Date *" type="date" value={form.date} error={erreurs.date?.[0]}
                 onChange={(e) => champ('date', e.target.value)} />
          <div className="grid grid-cols-2 gap-3">
            <Input label="Heure de début" placeholder="08:00" value={form.heure_debut}
                   error={erreurs.heure_debut?.[0]} onChange={(e) => champ('heure_debut', e.target.value)} />
            <Input label="Heure de fin" placeholder="09:00" value={form.heure_fin}
                   error={erreurs.heure_fin?.[0]} onChange={(e) => champ('heure_fin', e.target.value)} />
          </div>
          <div className="grid grid-cols-2 gap-3">
            <Input label="Coefficient *" type="number" min="0.1" max="20" step="0.5" value={form.coefficient}
                   error={erreurs.coefficient?.[0]} onChange={(e) => champ('coefficient', e.target.value)} />
            <Input label="Note maximale *" type="number" min="1" max="100" value={form.note_maximale}
                   error={erreurs.note_maximale?.[0]} onChange={(e) => champ('note_maximale', e.target.value)} />
          </div>
          {erreurs._ && <p className="text-sm text-red-600">{erreurs._[0]}</p>}
        </ModaleFormulaire>
      )}
    </div>
  )
}

export default function EvaluationListPage() {
  const [classeId, setClasseId] = useState('')

  const { data: classes } = useQuery({ queryKey: ['classes', 'all'], queryFn: fetchAllClasses })
  const { data: evaluations, isLoading } = useQuery({
    queryKey: ['evaluations', classeId],
    queryFn: () => fetchEvaluations(classeId || undefined),
  })

  return (
    <div>
      <h1 className="mb-6 text-2xl font-bold text-slate-800">Évaluations</h1>

      <SectionPlanification />

      <h2 className="mb-2 text-lg font-semibold text-slate-800">Notes déjà saisies</h2>
      <p className="mb-4 text-sm text-slate-500">
        Vue d'ensemble des sessions de notation déjà saisies dans ECONOMAT (regroupées par matière, classe, date et type).
      </p>

      <div className="mb-6 max-w-xs">
        <Select label="Filtrer par classe" value={classeId} onChange={(e) => setClasseId(e.target.value)}>
          <option value="">Toutes les classes</option>
          {classes?.map((classe) => (
            <option key={classe.id} value={classe.id}>
              {classe.nom}
            </option>
          ))}
        </Select>
      </div>

      <div className="overflow-hidden rounded-xl border border-slate-200 bg-white">
        <table className="w-full text-left text-sm">
          <thead className="border-b border-slate-200 bg-slate-50 text-slate-500">
            <tr>
              <th className="px-4 py-3 font-medium">Date</th>
              <th className="px-4 py-3 font-medium">Classe</th>
              <th className="px-4 py-3 font-medium">Matière</th>
              <th className="px-4 py-3 font-medium">Type</th>
              <th className="px-4 py-3 font-medium">Notes saisies</th>
              <th className="px-4 py-3 font-medium">Moyenne</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {isLoading && (
              <tr>
                <td colSpan={6} className="px-4 py-6 text-center text-slate-400">
                  Chargement…
                </td>
              </tr>
            )}
            {!isLoading && evaluations?.length === 0 && (
              <tr>
                <td colSpan={6} className="px-4 py-6 text-center text-slate-400">
                  Aucune évaluation pour l'instant.
                </td>
              </tr>
            )}
            {evaluations?.map((evaluation, index) => (
              <tr key={index} className="hover:bg-slate-50">
                <td className="px-4 py-3 text-slate-600">{evaluation.date_evaluation}</td>
                <td className="px-4 py-3 font-medium text-slate-800">{evaluation.classe}</td>
                <td className="px-4 py-3 text-slate-600">{evaluation.matiere}</td>
                <td className="px-4 py-3 text-slate-600">
                  {TYPE_LABELS[evaluation.type_evaluation] ?? evaluation.type_evaluation}
                </td>
                <td className="px-4 py-3 text-slate-600">{evaluation.nombre_notes}</td>
                <td className="px-4 py-3">
                  <span
                    className={`rounded-full px-2 py-0.5 text-xs font-semibold ${
                      evaluation.moyenne >= 10 ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700'
                    }`}
                  >
                    {evaluation.moyenne}/20
                  </span>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  )
}
