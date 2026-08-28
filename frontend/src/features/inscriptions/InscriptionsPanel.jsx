import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Trash2, Plus } from 'lucide-react'
import Button from '../../components/ui/Button'
import Select from '../../components/ui/Select'
import Input from '../../components/ui/Input'
import { fetchInscriptions, createInscription, deleteInscription } from './inscriptionsApi'
import { fetchAllClasses, fetchAnneesScolaires } from '../reference/referenceApi'

const TYPE_LABELS = {
  inscription: 'Inscription',
  reinscription: 'Réinscription',
  transfert_entrant: 'Transfert entrant',
  transfert_sortant: 'Transfert sortant',
}

export default function InscriptionsPanel({ eleveId }) {
  const [showForm, setShowForm] = useState(false)
  const [type, setType] = useState('inscription')
  const [anneeScolaireId, setAnneeScolaireId] = useState('')
  const [classeId, setClasseId] = useState('')
  const [dateMouvement, setDateMouvement] = useState('')
  const queryClient = useQueryClient()

  const { data } = useQuery({
    queryKey: ['inscriptions', eleveId],
    queryFn: () => fetchInscriptions({ eleve_id: eleveId }),
  })
  const { data: classes } = useQuery({ queryKey: ['classes', 'all'], queryFn: fetchAllClasses })
  const { data: anneesScolaires } = useQuery({ queryKey: ['annees-scolaires'], queryFn: fetchAnneesScolaires })

  const createMutation = useMutation({
    mutationFn: () =>
      createInscription({
        eleve_id: eleveId,
        annee_scolaire_id: anneeScolaireId,
        classe_id: classeId || null,
        type,
        date_mouvement: dateMouvement,
      }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['inscriptions', eleveId] })
      queryClient.invalidateQueries({ queryKey: ['eleves', String(eleveId)] })
      setShowForm(false)
      setAnneeScolaireId('')
      setClasseId('')
      setDateMouvement('')
    },
  })

  const deleteMutation = useMutation({
    mutationFn: deleteInscription,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['inscriptions', eleveId] }),
  })

  return (
    <div className="rounded-xl border border-slate-200 bg-white p-6">
      <div className="mb-4 flex items-center justify-between">
        <h2 className="text-lg font-semibold text-slate-800">Historique de scolarité</h2>
        <Button variant="outline" onClick={() => setShowForm((v) => !v)}>
          <span className="flex items-center gap-2">
            <Plus size={16} /> Ajouter un mouvement
          </span>
        </Button>
      </div>

      {showForm && (
        <div className="mb-4 space-y-3 rounded-lg border border-slate-100 bg-slate-50 p-4">
          <div className="grid grid-cols-2 gap-3">
            <Select label="Type" value={type} onChange={(e) => setType(e.target.value)}>
              {Object.entries(TYPE_LABELS).map(([value, label]) => (
                <option key={value} value={value}>
                  {label}
                </option>
              ))}
            </Select>
            <Input type="date" label="Date" value={dateMouvement} onChange={(e) => setDateMouvement(e.target.value)} />
          </div>
          <div className="grid grid-cols-2 gap-3">
            <Select label="Année scolaire" value={anneeScolaireId} onChange={(e) => setAnneeScolaireId(e.target.value)}>
              <option value="">— Sélectionner —</option>
              {anneesScolaires?.map((a) => (
                <option key={a.id} value={a.id}>
                  {a.libelle}
                </option>
              ))}
            </Select>
            <Select label="Classe (optionnel)" value={classeId} onChange={(e) => setClasseId(e.target.value)}>
              <option value="">—</option>
              {classes?.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.nom}
                </option>
              ))}
            </Select>
          </div>
          <div className="flex justify-end">
            <Button
              disabled={!anneeScolaireId || !dateMouvement || createMutation.isPending}
              onClick={() => createMutation.mutate()}
            >
              Enregistrer
            </Button>
          </div>
        </div>
      )}

      {data?.data.length ? (
        <ul className="divide-y divide-slate-100">
          {data.data.map((mouvement) => (
            <li key={mouvement.id} className="flex items-center justify-between py-2 text-sm">
              <span>
                <span className="rounded-full bg-primary-50 px-2 py-0.5 text-xs font-medium text-primary-700">
                  {TYPE_LABELS[mouvement.type] ?? mouvement.type}
                </span>{' '}
                {mouvement.date_mouvement} — {mouvement.annee_scolaire?.libelle}
                {mouvement.classe && ` (${mouvement.classe.nom})`}
              </span>
              <button
                onClick={() => deleteMutation.mutate(mouvement.id)}
                className="rounded p-1.5 text-slate-500 hover:bg-red-50 hover:text-red-600"
              >
                <Trash2 size={16} />
              </button>
            </li>
          ))}
        </ul>
      ) : (
        <p className="text-sm text-slate-400">Aucun mouvement enregistré.</p>
      )}
    </div>
  )
}
