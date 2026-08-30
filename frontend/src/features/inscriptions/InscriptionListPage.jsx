import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus, Trash2, X } from 'lucide-react'
import Button from '../../components/ui/Button'
import Select from '../../components/ui/Select'
import Input from '../../components/ui/Input'
import Textarea from '../../components/ui/Textarea'
import { createInscription, deleteInscription, fetchInscriptions } from './inscriptionsApi'
import { fetchAllClasses, fetchAllEleves, fetchAnneesScolaires } from '../reference/referenceApi'

const TYPE_LABELS = {
  inscription: 'Inscription',
  reinscription: 'Réinscription',
  transfert_entrant: 'Transfert entrant',
  transfert_sortant: 'Transfert sortant',
}

const FORM_VIDE = {
  eleve_id: '',
  type: 'inscription',
  date_mouvement: new Date().toISOString().slice(0, 10),
  annee_scolaire_id: '',
  classe_id: '',
  etablissement_origine: '',
  etablissement_destination: '',
  observation: '',
}

export default function InscriptionListPage() {
  const [type, setType] = useState('')
  const [showForm, setShowForm] = useState(false)
  const [form, setForm] = useState(FORM_VIDE)
  const queryClient = useQueryClient()

  const { data, isLoading } = useQuery({
    queryKey: ['inscriptions-list', type],
    queryFn: () => fetchInscriptions(type ? { type } : {}),
  })
  const { data: eleves } = useQuery({ queryKey: ['eleves', 'all'], queryFn: fetchAllEleves })
  const { data: classes } = useQuery({ queryKey: ['classes', 'all'], queryFn: fetchAllClasses })
  const { data: anneesScolaires } = useQuery({ queryKey: ['annees-scolaires'], queryFn: fetchAnneesScolaires })

  const set = (champ) => (e) => setForm((f) => ({ ...f, [champ]: e.target.value }))

  const montreClasse = form.type !== 'transfert_sortant'
  const montreOrigine = form.type === 'transfert_entrant'
  const montreDestination = form.type === 'transfert_sortant'

  const valide = form.eleve_id && form.annee_scolaire_id && form.type && form.date_mouvement

  const createMutation = useMutation({
    mutationFn: () =>
      createInscription({
        eleve_id: form.eleve_id,
        annee_scolaire_id: form.annee_scolaire_id,
        classe_id: montreClasse && form.classe_id ? form.classe_id : null,
        type: form.type,
        date_mouvement: form.date_mouvement,
        etablissement_origine: montreOrigine ? form.etablissement_origine || null : null,
        etablissement_destination: montreDestination ? form.etablissement_destination || null : null,
        observation: form.observation || null,
      }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['inscriptions-list'] })
      setForm(FORM_VIDE)
      setShowForm(false)
    },
  })

  const deleteMutation = useMutation({
    mutationFn: deleteInscription,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['inscriptions-list'] }),
  })

  const messageErreur = useMemo(() => {
    const err = createMutation.error
    if (!err) return null
    return err.response?.data?.message || 'Enregistrement impossible. Vérifiez les champs.'
  }, [createMutation.error])

  return (
    <div>
      <div className="mb-6 flex items-center justify-between gap-4">
        <h1 className="text-2xl font-bold text-slate-800">Inscriptions, réinscriptions &amp; transferts</h1>
        <Button
          onClick={() => {
            setShowForm((v) => !v)
            createMutation.reset()
          }}
        >
          <span className="flex items-center gap-2">
            {showForm ? <X size={16} /> : <Plus size={16} />}
            {showForm ? 'Fermer' : 'Nouveau mouvement'}
          </span>
        </Button>
      </div>

      {showForm && (
        <div className="mb-6 space-y-4 rounded-xl border border-slate-200 bg-white p-6">
          <div className="grid gap-4 md:grid-cols-2">
            <Select label="Élève" value={form.eleve_id} onChange={set('eleve_id')}>
              <option value="">— Sélectionner un élève —</option>
              {eleves?.map((el) => (
                <option key={el.id} value={el.id}>
                  {el.prenom} {el.nom}
                </option>
              ))}
            </Select>

            <Select label="Type de mouvement" value={form.type} onChange={set('type')}>
              {Object.entries(TYPE_LABELS).map(([value, label]) => (
                <option key={value} value={value}>
                  {label}
                </option>
              ))}
            </Select>

            <Input type="date" label="Date du mouvement" value={form.date_mouvement} onChange={set('date_mouvement')} />

            <Select label="Année scolaire" value={form.annee_scolaire_id} onChange={set('annee_scolaire_id')}>
              <option value="">— Sélectionner —</option>
              {anneesScolaires?.map((a) => (
                <option key={a.id} value={a.id}>
                  {a.libelle}
                </option>
              ))}
            </Select>

            {montreClasse && (
              <Select label="Classe (optionnel)" value={form.classe_id} onChange={set('classe_id')}>
                <option value="">—</option>
                {classes?.map((c) => (
                  <option key={c.id} value={c.id}>
                    {c.nom}
                  </option>
                ))}
              </Select>
            )}

            {montreOrigine && (
              <Input
                label="Établissement d'origine"
                placeholder="École précédente"
                value={form.etablissement_origine}
                onChange={set('etablissement_origine')}
              />
            )}

            {montreDestination && (
              <Input
                label="Établissement de destination"
                placeholder="École d'accueil"
                value={form.etablissement_destination}
                onChange={set('etablissement_destination')}
              />
            )}
          </div>

          <Textarea label="Observation (optionnel)" rows={2} value={form.observation} onChange={set('observation')} />

          {messageErreur && (
            <div className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-600">{messageErreur}</div>
          )}

          <div className="flex justify-end gap-3">
            <Button variant="outline" onClick={() => setShowForm(false)}>
              Annuler
            </Button>
            <Button disabled={!valide || createMutation.isPending} onClick={() => createMutation.mutate()}>
              {createMutation.isPending ? 'Enregistrement…' : 'Enregistrer le mouvement'}
            </Button>
          </div>
        </div>
      )}

      <div className="mb-6 max-w-xs">
        <Select label="Filtrer par type" value={type} onChange={(e) => setType(e.target.value)}>
          <option value="">Tous les mouvements</option>
          {Object.entries(TYPE_LABELS).map(([value, label]) => (
            <option key={value} value={value}>
              {label}
            </option>
          ))}
        </Select>
      </div>

      <div className="overflow-hidden rounded-xl border border-slate-200 bg-white">
        <table className="w-full text-left text-sm">
          <thead className="border-b border-slate-200 bg-slate-50 text-slate-500">
            <tr>
              <th className="px-4 py-3 font-medium">Élève</th>
              <th className="px-4 py-3 font-medium">Type</th>
              <th className="px-4 py-3 font-medium">Date</th>
              <th className="px-4 py-3 font-medium">Année scolaire</th>
              <th className="px-4 py-3 font-medium">Classe</th>
              <th className="px-4 py-3 font-medium">Origine / Destination</th>
              <th className="px-4 py-3" />
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {isLoading && (
              <tr>
                <td colSpan={7} className="px-4 py-6 text-center text-slate-400">
                  Chargement…
                </td>
              </tr>
            )}
            {!isLoading && data?.data.length === 0 && (
              <tr>
                <td colSpan={7} className="px-4 py-6 text-center text-slate-400">
                  Aucun mouvement enregistré.
                </td>
              </tr>
            )}
            {data?.data.map((mouvement) => (
              <tr key={mouvement.id} className="hover:bg-slate-50">
                <td className="px-4 py-3 font-medium text-slate-800">
                  {mouvement.eleve?.prenom} {mouvement.eleve?.nom}
                </td>
                <td className="px-4 py-3">
                  <span className="rounded-full bg-primary-50 px-2 py-0.5 text-xs font-medium text-primary-700">
                    {TYPE_LABELS[mouvement.type] ?? mouvement.type}
                  </span>
                </td>
                <td className="px-4 py-3 text-slate-600">{mouvement.date_mouvement}</td>
                <td className="px-4 py-3 text-slate-600">{mouvement.annee_scolaire?.libelle}</td>
                <td className="px-4 py-3 text-slate-600">{mouvement.classe?.nom ?? '—'}</td>
                <td className="px-4 py-3 text-slate-600">
                  {mouvement.etablissement_origine || mouvement.etablissement_destination || '—'}
                </td>
                <td className="px-4 py-3 text-right">
                  <button
                    onClick={() => deleteMutation.mutate(mouvement.id)}
                    className="rounded p-1.5 text-slate-500 hover:bg-red-50 hover:text-red-600"
                    title="Supprimer"
                  >
                    <Trash2 size={16} />
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  )
}
